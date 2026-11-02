<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Room;
use App\Models\SeasonalRate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminSeasonalRateController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return Inertia::render('Admin/SeasonalRates', [
            'seasons' => SeasonalRate::where('tenant_id', $tenantId)->orderByDesc('active')->orderBy('starts_on')->get(),
            'rooms' => Room::with('property:id,name')->where('tenant_id', $tenantId)->orderBy('property_id')->get(['id', 'name', 'property_id']),
        ]);
    }

    public function store(Request $request)
    {
        $season = SeasonalRate::create($this->validated($request) + ['tenant_id' => $request->user()->tenant_id]);
        $this->audit($request, 'season.created', $season);

        return back()->with('success', 'Seasonal rate added.');
    }

    public function update(Request $request, SeasonalRate $season)
    {
        abort_unless($season->tenant_id === $request->user()->tenant_id, 404);
        $season->update($this->validated($request));
        $this->audit($request, 'season.updated', $season);

        return back()->with('success', 'Seasonal rate updated.');
    }

    public function destroy(Request $request, SeasonalRate $season)
    {
        abort_unless($season->tenant_id === $request->user()->tenant_id, 404);
        $this->audit($request, 'season.deleted', $season);
        $season->delete();

        return back()->with('success', 'Seasonal rate deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on|before_or_equal:'.date('Y-m-d', strtotime($request->input('starts_on', 'now').' +366 days')),
            'adjustment_type' => 'required|in:percent,fixed',
            'adjustment_value' => $request->input('adjustment_type') === 'fixed'
                ? 'required|numeric|min:1|max:1000000' : 'required|numeric|min:-90|max:500|not_in:0',
            'min_nights' => 'nullable|integer|min:1|max:365',
            'room_ids' => 'nullable|array',
            'room_ids.*' => ['integer', 'distinct', Rule::exists('rooms', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'active' => 'boolean',
        ], ['ends_on.before_or_equal' => 'A season can span at most one year.', 'adjustment_value.not_in' => 'Use a change other than 0%.']);
        $data['room_ids'] = empty($data['room_ids']) ? null : array_map('intval', $data['room_ids']);

        return $data;
    }

    private function audit(Request $request, string $action, SeasonalRate $season): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $season->tenant_id,
            'action' => $action, 'entity_type' => 'SeasonalRate', 'entity_id' => $season->id,
            'metadata' => $season->only('name', 'starts_on', 'ends_on', 'adjustment_type', 'adjustment_value'), 'ip_address' => $request->ip()]);
    }
}
