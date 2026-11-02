<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\PosItem;
use App\Models\PosSale;
use App\Services\PosService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminPosController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d'], 'tab' => ['nullable', 'in:register,sales,items']]);
        $tenant = $request->user()->tenant;
        $date = CarbonImmutable::parse($data['date'] ?? today()->toDateString());
        $sales = PosSale::with('items', 'cashier:id,name', 'booking:id,reference,room_id', 'booking.room:id,name')->where('tenant_id', $tenant->id)
            ->whereBetween('created_at', [$date->startOfDay(), $date->endOfDay()])->latest('id')->get();
        $live = $sales->where('status', '!=', 'VOID');
        // Settlements of earlier room charges count toward the drawer on the day they are paid.
        $settledToday = PosSale::where('tenant_id', $tenant->id)->where('status', 'PAID')
            ->whereBetween('paid_at', [$date->startOfDay(), $date->endOfDay()])->get();

        return Inertia::render('Admin/Pos', [
            'date' => $date->toDateString(), 'today' => today()->toDateString(), 'tab' => $data['tab'] ?? 'register',
            'items' => PosItem::where('tenant_id', $tenant->id)->where('active', true)->orderBy('category')->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'category', 'price', 'track_stock', 'stock']),
            // Admins manage the full catalog, hidden items included, from the POS Items tab.
            'catalog' => $request->user()->role === 'ADMIN' ? $this->catalog($tenant->id) : null,
            'guests' => Booking::with('room:id,name')->where('tenant_id', $tenant->id)->whereIn('status', ['CONFIRMED', 'PAID', 'CHECKED_IN'])
                ->whereDate('check_in', '<=', today()->toDateString())->whereDate('check_out', '>=', today()->toDateString())
                ->orderByRaw("case when status = 'CHECKED_IN' then 0 else 1 end")->orderBy('guest_name')->get()
                ->map(fn (Booking $b) => ['id' => $b->id, 'guest_name' => $b->guest_name, 'unit' => $b->room?->name, 'status' => $b->status, 'reference' => $b->reference]),
            'sales' => $sales->map(fn (PosSale $s) => $this->saleRow($s)),
            'summary' => [
                'count' => $live->count(),
                'gross' => round((float) $live->sum('total'), 2),
                'discounts' => round((float) $live->sum('discount'), 2),
                'roomCharges' => round((float) $live->where('payment_method', 'room_charge')->sum('total'), 2),
                'byMethod' => collect(PosSale::METHODS)->except('room_charge')->map(fn ($label, $method) => ['method' => $method, 'label' => $label,
                    'total' => round((float) $settledToday->where('payment_method', $method)->sum('total'), 2)])->values(),
                'collected' => round((float) $settledToday->sum('total'), 2),
                'voids' => $sales->where('status', 'VOID')->count(),
            ],
            'methods' => PosSale::METHODS,
            'receipt' => ($id = $request->session()->get('receipt')) && ($sale = PosSale::with('items', 'cashier:id,name', 'booking.room:id,name')->where('tenant_id', $tenant->id)->find($id))
                ? $this->saleRow($sale) : null,
            'business' => $tenant->only('business_name', 'business_phone', 'business_address'),
        ]);
    }

    public function store(Request $request, PosService $pos)
    {
        $data = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.item_id' => ['nullable', 'integer'],
            'lines.*.name' => ['nullable', 'string', 'max:80'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', Rule::in(array_keys(PosSale::METHODS))],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'booking_id' => ['nullable', 'integer'],
            'customer_name' => ['nullable', 'string', 'max:120'],
        ]);
        $sale = $pos->checkout($request->user(), $data);

        return back()->with('success', ($sale->status === 'UNPAID' ? 'Charged to room · ' : 'Sale complete · ').$sale->number)
            ->with('receipt', $sale->id);
    }

    public function void(Request $request, PosSale $sale, PosService $pos)
    {
        abort_unless($sale->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $pos->void($request->user(), $sale, $data['reason']);

        return back()->with('success', "Sale {$sale->number} voided.");
    }

    public function settle(Request $request, PosSale $sale, PosService $pos)
    {
        abort_unless($sale->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['method' => ['required', 'in:cash,gcash,maya,card,bank_transfer'], 'reference' => ['nullable', 'string', 'max:100']]);
        abort_unless($sale->status === 'UNPAID', 422);
        $pos->settle($request->user(), [$sale], $data['method'], $data['reference'] ?? null);

        return back()->with('success', "Sale {$sale->number} settled.");
    }

    /** The same catalog manager is also on the POS Items tab. */
    public function items(Request $request)
    {
        return Inertia::render('Admin/PosItems', ['items' => $this->catalog($request->user()->tenant_id)]);
    }

    private function catalog(int $tenantId)
    {
        return PosItem::where('tenant_id', $tenantId)->orderBy('category')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'category', 'price', 'track_stock', 'stock', 'active']);
    }

    public function storeItem(Request $request)
    {
        $data = $this->validateItem($request);
        $item = PosItem::create(['tenant_id' => $request->user()->tenant_id, ...$data]);
        $this->audit($request, $item, 'pos_item.created');

        return back()->with('success', "{$item->name} added.");
    }

    public function updateItem(Request $request, PosItem $item)
    {
        abort_unless($item->tenant_id === $request->user()->tenant_id, 404);
        $item->update($this->validateItem($request, $item));
        $this->audit($request, $item, 'pos_item.updated');

        return back()->with('success', "{$item->name} saved.");
    }

    /** Add or remove stock after a delivery or a count. */
    public function adjustStock(Request $request, PosItem $item)
    {
        abort_unless($item->tenant_id === $request->user()->tenant_id, 404);
        $data = $request->validate(['change' => ['required', 'integer', 'between:-100000,100000', 'not_in:0']]);
        $item = DB::transaction(function () use ($item, $data) {
            $locked = PosItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->track_stock, 422);
            $next = $locked->stock + $data['change'];
            if ($next < 0) {
                throw ValidationException::withMessages(['change' => 'Stock cannot go below zero.']);
            }
            $locked->update(['stock' => $next]);

            return $locked;
        });
        $this->audit($request, $item, 'pos_item.stock_adjusted', ['change' => $data['change'], 'stock' => $item->stock]);

        return back()->with('success', "{$item->name}: {$item->stock} in stock.");
    }

    public function destroyItem(Request $request, PosItem $item)
    {
        abort_unless($item->tenant_id === $request->user()->tenant_id, 404);
        $item->delete();
        $this->audit($request, $item, 'pos_item.deleted');

        return back()->with('success', "{$item->name} removed. Past receipts keep its name and price.");
    }

    private function validateItem(Request $request, ?PosItem $item = null): array
    {
        // An item that already tracks stock may be edited without sending a count, so the edit cannot overwrite sales made meanwhile.
        $keepsStock = $item?->track_stock && $request->boolean('track_stock');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'category' => ['required', 'string', 'max:40'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'track_stock' => ['required', 'boolean'],
            'stock' => [$keepsStock ? 'sometimes' : 'required_if_accepted:track_stock', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'active' => ['required', 'boolean'],
        ]);

        return $keepsStock && ! isset($data['stock']) ? $data : [...$data, 'stock' => (int) ($data['stock'] ?? 0)];
    }

    private function saleRow(PosSale $s): array
    {
        return [...$s->only('id', 'number', 'customer_name', 'subtotal', 'discount', 'total', 'status', 'payment_method', 'payment_reference',
            'amount_tendered', 'void_reason', 'booking_id'),
            'created_at' => $s->created_at->toIso8601String(), 'paid_at' => $s->paid_at?->toIso8601String(),
            'cashier' => $s->cashier?->name, 'unit' => $s->booking?->room?->name, 'booking_reference' => $s->booking?->reference,
            'items' => $s->items->map(fn ($i) => $i->only('name', 'unit_price', 'quantity', 'amount'))];
    }

    private function audit(Request $request, PosItem $item, string $action, array $meta = []): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $item->tenant_id, 'action' => $action,
            'entity_type' => 'PosItem', 'entity_id' => $item->id, 'metadata' => ['name' => $item->name, ...$meta], 'ip_address' => $request->ip()]);
    }
}
