<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\PosItem;
use App\Models\PosSale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** On-site point of sale: counter sales paid on the spot, or charged to an in-house guest's room. */
class PosService
{
    /**
     * @param  array{lines: array<int, array{item_id?: int|null, name?: string|null, price?: float|null, quantity: int}>, discount?: float|null,
     *     payment_method: string, payment_reference?: string|null, amount_tendered?: float|null, booking_id?: int|null, customer_name?: string|null}  $data
     */
    public function checkout(User $cashier, array $data): PosSale
    {
        return DB::transaction(function () use ($cashier, $data) {
            $tenantId = $cashier->tenant_id;
            // Serialize sales per business so the next receipt number cannot be issued twice.
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $booking = null;
            if (! empty($data['booking_id'])) {
                $booking = Booking::where('tenant_id', $tenantId)->whereKey($data['booking_id'])->lockForUpdate()->first();
                if (! $booking || ! in_array($booking->status, ['CONFIRMED', 'PAID', 'CHECKED_IN'], true)) {
                    throw ValidationException::withMessages(['booking_id' => 'Pick a guest who is confirmed or checked in.']);
                }
            }
            if ($data['payment_method'] === 'room_charge' && (! $booking || $booking->status !== 'CHECKED_IN'
                || $booking->check_in->toDateString() > today()->toDateString()
                || $booking->check_out->toDateString() < today()->toDateString())) {
                throw ValidationException::withMessages(['payment_method' => 'Room charges are available only for guests currently checked in.']);
            }

            $itemIds = collect($data['lines'])->pluck('item_id')->filter()->unique();
            $items = PosItem::where('tenant_id', $tenantId)->whereIn('id', $itemIds)->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            foreach ($data['lines'] as $i => $line) {
                $quantity = (int) $line['quantity'];
                if (! empty($line['item_id'])) {
                    $item = $items->get((int) $line['item_id']);
                    if (! $item || ! $item->active) {
                        throw ValidationException::withMessages(["lines.$i.item_id" => 'One of the items is no longer on sale.']);
                    }
                    $quantityInCart = collect($data['lines'])->where('item_id', $item->id)->sum('quantity');
                    if ($item->track_stock && $item->stock < $quantityInCart) {
                        throw ValidationException::withMessages(["lines.$i.quantity" => "Only {$item->stock} {$item->name} left in stock."]);
                    }
                    $lines[] = ['pos_item_id' => $item->id, 'name' => $item->name, 'unit_price' => (float) $item->price, 'quantity' => $quantity];
                } else {
                    // Open-price line for anything not in the catalog (a broken glass, a late check-out fee…).
                    if (blank($line['name'] ?? null) || ! isset($line['price'])) {
                        throw ValidationException::withMessages(["lines.$i.name" => 'Custom items need a name and a price.']);
                    }
                    $lines[] = ['pos_item_id' => null, 'name' => trim($line['name']), 'unit_price' => round((float) $line['price'], 2), 'quantity' => $quantity];
                }
            }
            $subtotal = round(collect($lines)->sum(fn ($l) => $l['unit_price'] * $l['quantity']), 2);
            $discount = round((float) ($data['discount'] ?? 0), 2);
            if ($discount > $subtotal) {
                throw ValidationException::withMessages(['discount' => 'The discount cannot be more than the subtotal.']);
            }
            $total = round($subtotal - $discount, 2);
            $roomCharge = $data['payment_method'] === 'room_charge';
            $tendered = null;
            if ($data['payment_method'] === 'cash') {
                $tendered = round((float) ($data['amount_tendered'] ?? $total), 2);
                if ($tendered + 0.001 < $total) {
                    throw ValidationException::withMessages(['amount_tendered' => 'Cash received is less than the total.']);
                }
            }

            $sale = PosSale::create([
                'tenant_id' => $tenantId, 'number' => $this->nextNumber($tenantId), 'booking_id' => $booking?->id,
                'customer_name' => $booking?->guest_name ?? (filled($data['customer_name'] ?? null) ? trim($data['customer_name']) : null),
                'subtotal' => $subtotal, 'discount' => $discount, 'total' => $total,
                'status' => $roomCharge ? 'UNPAID' : 'PAID', 'payment_method' => $data['payment_method'],
                'payment_reference' => $data['payment_reference'] ?? null, 'amount_tendered' => $tendered,
                'cashier_id' => $cashier->id, 'paid_at' => $roomCharge ? null : now(),
            ]);
            foreach ($lines as $line) {
                $sale->items()->create([...$line, 'amount' => round($line['unit_price'] * $line['quantity'], 2)]);
                if ($line['pos_item_id'] && $items[$line['pos_item_id']]->track_stock) {
                    PosItem::whereKey($line['pos_item_id'])->decrement('stock', $line['quantity']);
                }
            }
            $this->audit($cashier, $sale, 'pos.sale', ['total' => $total, 'method' => $data['payment_method']]);

            return $sale->load('items');
        });
    }

    public function void(User $actor, PosSale $sale, string $reason): void
    {
        DB::transaction(function () use ($actor, $sale, $reason) {
            $sale = PosSale::with('items')->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            if ($sale->status === 'VOID') {
                throw ValidationException::withMessages(['sale' => 'This sale is already void.']);
            }
            foreach ($sale->items as $line) {
                if ($line->pos_item_id) {
                    PosItem::whereKey($line->pos_item_id)->where('track_stock', true)->increment('stock', $line->quantity);
                }
            }
            $sale->update(['status' => 'VOID', 'voided_at' => now(), 'void_reason' => $reason]);
            $this->audit($actor, $sale, 'pos.void', ['reason' => $reason, 'total' => (float) $sale->total]);
        });
    }

    /** Settle room charges, e.g. at check-out. */
    public function settle(User $actor, iterable $sales, string $method, ?string $reference = null): float
    {
        return DB::transaction(function () use ($actor, $sales, $method, $reference) {
            $total = 0;
            foreach ($sales as $sale) {
                $sale = PosSale::whereKey($sale->id)->lockForUpdate()->firstOrFail();
                if ($sale->status !== 'UNPAID') {
                    continue;
                }
                $sale->update(['status' => 'PAID', 'payment_method' => $method, 'payment_reference' => $reference, 'paid_at' => now()]);
                $this->audit($actor, $sale, 'pos.settled', ['method' => $method, 'total' => (float) $sale->total]);
                $total += (float) $sale->total;
            }

            return round($total, 2);
        });
    }

    private function nextNumber(int $tenantId): string
    {
        // The tenant row is locked by checkout. Voided receipts remain in the sequence.
        $count = PosSale::where('tenant_id', $tenantId)->count();

        return 'R-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    private function audit(User $actor, PosSale $sale, string $action, array $meta): void
    {
        AuditLog::create(['actor_user_id' => $actor->id, 'tenant_id' => $sale->tenant_id, 'action' => $action,
            'entity_type' => 'PosSale', 'entity_id' => $sale->id, 'metadata' => ['number' => $sale->number, ...$meta],
            'ip_address' => request()?->ip()]);
    }
}
