<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Room;
use App\Services\TenantSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AdminSettingsController extends Controller
{
    public function show(Request $request, string $section = 'general')
    {
        abort_unless(array_key_exists($section, TenantSettings::sections()), 404);

        return Inertia::render('Admin/Settings', [
            'section' => $section,
            'settings' => $request->user()->tenant->settings,
            'currencies' => TenantSettings::CURRENCIES, 'paymentMethods' => TenantSettings::PAYMENT_METHODS,
        ]);
    }

    public function update(Request $request, string $section)
    {
        $rules = TenantSettings::sections()[$section] ?? abort(404);
        if ($request->has('reference_prefix')) {
            $request->merge(['reference_prefix' => strtoupper(trim((string) $request->input('reference_prefix')))]);
        }
        foreach (['amenities', 'cleaning_checklist'] as $list) {
            if ($request->has($list)) {
                $request->merge([$list => array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) $request->input($list))]);
            }
        }
        $data = $request->validate($rules);
        if (isset($data['weekend_days'])) {
            $data['weekend_days'] = array_map('intval', $data['weekend_days']);
            sort($data['weekend_days']);
        }
        $tenant = $request->user()->tenant;
        $before = $tenant->settings;
        $tenant->update(['settings' => array_merge($before, $data)]);
        if (isset($data['amenities'])) {
            $this->dropRemovedAmenities($tenant->id, $data['amenities']);
        }
        $changed = array_keys(array_filter($data, fn ($value, $key) => $before[$key] != $value, ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $tenant->id,
                'action' => 'settings.updated', 'entity_type' => 'Tenant', 'entity_id' => $tenant->id,
                'metadata' => ['section' => $section, 'changed' => array_intersect_key($data, array_flip($changed))],
                'ip_address' => $request->ip()]);
        }

        return back()->with('success', 'Settings saved.');
    }

    /** Rooms only list amenities that still exist in the tenant's library. */
    private function dropRemovedAmenities(int $tenantId, array $library): void
    {
        Room::where('tenant_id', $tenantId)->whereNotNull('amenities')->get()->each(function (Room $room) use ($library) {
            $kept = array_values(array_intersect($room->amenities, $library));
            if ($kept !== $room->amenities) {
                $room->update(['amenities' => $kept]);
            }
        });
    }
}
