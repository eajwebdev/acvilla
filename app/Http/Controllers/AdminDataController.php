<?php

namespace App\Http\Controllers;

use App\Models\AddOn;
use App\Models\AuditLog;
use App\Models\BlockedDate;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\MessageTemplate;
use App\Models\PromoCode;
use App\Models\Property;
use App\Models\Review;
use App\Models\SeasonalRate;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Settings → Data & admin: audit log, deleted bookings and backups. */
class AdminDataController extends Controller
{
    public function audit(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $filters = $request->validate([
            'action' => 'nullable|string|max:60', 'user' => 'nullable|integer',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);
        $logs = AuditLog::with('actor:id,name')->where('tenant_id', $tenantId)
            ->when($filters['action'] ?? null, fn ($q, $action) => $q->where('action', 'like', $action.'%'))
            ->when($filters['user'] ?? null, fn ($q, $user) => $q->where('actor_user_id', $user))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate(40)->withQueryString();

        return Inertia::render('Admin/AuditLog', [
            'logs' => $logs, 'filters' => $filters,
            'areas' => AuditLog::where('tenant_id', $tenantId)->distinct()->pluck('action')
                ->map(fn ($action) => explode('.', $action)[0])->unique()->sort()->values(),
            'people' => User::where('tenant_id', $tenantId)->whereIn('role', ['ADMIN', 'STAFF'])->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function deletedBookings(Request $request)
    {
        return Inertia::render('Admin/DeletedBookings', [
            'bookings' => Booking::onlyTrashed()->with('property:id,name', 'room:id,name')->where('tenant_id', $request->user()->tenant_id)
                ->orderByDesc('deleted_at')->paginate(30),
        ]);
    }

    /** Only bookings that no longer hold dates can be deleted, so deleting or restoring never changes availability. */
    public function deleteBooking(Request $request, Booking $booking)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id, 404);
        abort_unless(in_array($booking->status, ['CANCELLED', 'EXPIRED', 'NO_SHOW'], true), 422, 'Cancel the booking before deleting it.');
        $booking->delete();
        $this->record($request, 'booking.deleted', 'Booking', $booking->id, ['reference' => $booking->reference]);

        return back()->with('success', "Booking {$booking->reference} moved to Deleted bookings.");
    }

    public function restoreBooking(Request $request, Booking $booking)
    {
        abort_unless($booking->tenant_id === $request->user()->tenant_id && $booking->trashed(), 404);
        $booking->restore();
        $this->record($request, 'booking.restored', 'Booking', $booking->id, ['reference' => $booking->reference]);

        return back()->with('success', "Booking {$booking->reference} restored.");
    }

    public function backup()
    {
        return Inertia::render('Admin/Backup');
    }

    public function download(Request $request)
    {
        $tenant = $request->user()->tenant;
        $id = $tenant->id;
        $data = [
            'exported_at' => now()->toIso8601String(), 'format' => 'eaj-tenant-backup/1',
            'business' => $tenant->only('name', 'slug', 'business_name', 'business_email', 'business_phone', 'business_address',
                'logo', 'primary_color', 'secondary_color', 'hero_headline', 'hero_description', 'hero_image'),
            'settings' => $tenant->settings,
            'team' => User::where('tenant_id', $id)->get(['id', 'name', 'email', 'role', 'deactivated_at', 'created_at']),
            'properties' => Property::with('rooms')->where('tenant_id', $id)->get(),
            'seasonal_rates' => SeasonalRate::where('tenant_id', $id)->get(),
            'promo_codes' => PromoCode::where('tenant_id', $id)->get(),
            'add_ons' => AddOn::where('tenant_id', $id)->get(),
            'message_templates' => MessageTemplate::where('tenant_id', $id)->get(),
            'customers' => Customer::where('tenant_id', $id)->get(),
            'reviews' => Review::where('tenant_id', $id)->get(),
            'blocked_dates' => BlockedDate::where('tenant_id', $id)->get(),
            'bookings' => Booking::withTrashed()->with('price', 'payments', 'messages')->where('tenant_id', $id)->orderBy('id')->get(),
        ];
        $this->record($request, 'backup.downloaded', 'Tenant', $id, ['bookings' => $data['bookings']->count()]);

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, "{$tenant->slug}-backup-".now()->format('Y-m-d').'.json', ['Content-Type' => 'application/json']);
    }

    private function record(Request $request, string $action, string $type, int $id, array $metadata): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $request->user()->tenant_id,
            'action' => $action, 'entity_type' => $type, 'entity_id' => $id, 'metadata' => $metadata, 'ip_address' => $request->ip()]);
    }
}
