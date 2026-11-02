<?php

namespace App\Http\Controllers;

use App\Models\AddOn;
use App\Models\AuditLog;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminAddOnController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return Inertia::render('Admin/AddOns', [
            'addOns' => AddOn::where('tenant_id', $tenantId)->orderBy('sort_order')->orderBy('id')->get(),
            'rooms' => Room::with('property:id,name')->where('tenant_id', $tenantId)->orderBy('property_id')->get(['id', 'name', 'property_id']),
            'pricingOptions' => AddOn::PRICING,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        $addOn = AddOn::create($this->validated($request) + ['tenant_id' => $tenantId,
            'sort_order' => (int) AddOn::where('tenant_id', $tenantId)->max('sort_order') + 1]);
        $this->audit($request, 'add_on.created', $addOn);

        return back()->with('success', 'Add-on created.');
    }

    public function update(Request $request, AddOn $addOn)
    {
        abort_unless($addOn->tenant_id === $request->user()->tenant_id, 404);
        $addOn->update($this->validated($request));
        $this->audit($request, 'add_on.updated', $addOn);

        return back()->with('success', 'Add-on updated.');
    }

    public function destroy(Request $request, AddOn $addOn)
    {
        abort_unless($addOn->tenant_id === $request->user()->tenant_id, 404);
        $this->audit($request, 'add_on.deleted', $addOn);
        $addOn->delete();

        return back()->with('success', 'Add-on deleted. Past bookings keep what they ordered.');
    }

    /** Move one add-on up or down in the guest-facing list. */
    public function move(Request $request, AddOn $addOn)
    {
        abort_unless($addOn->tenant_id === $request->user()->tenant_id, 404);
        $direction = $request->validate(['direction' => 'required|in:up,down'])['direction'];
        $list = AddOn::where('tenant_id', $addOn->tenant_id)->orderBy('sort_order')->orderBy('id')->get()->values();
        $index = $list->search(fn (AddOn $item) => $item->id === $addOn->id);
        $swap = $direction === 'up' ? $index - 1 : $index + 1;
        if ($swap >= 0 && $swap < $list->count()) {
            $items = $list->all();
            [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
            foreach ($items as $position => $item) {
                $item->update(['sort_order' => $position]);
            }
        }

        return back();
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'description' => 'nullable|string|max:300',
            'price' => 'required|numeric|min:0|max:1000000',
            'pricing' => ['required', Rule::in(array_keys(AddOn::PRICING))],
            'max_quantity' => 'required|integer|min:1|max:20',
            'room_ids' => 'nullable|array',
            'room_ids.*' => ['integer', 'distinct', Rule::exists('rooms', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'active' => 'boolean',
        ]);
        $data['room_ids'] = empty($data['room_ids']) ? null : array_map('intval', $data['room_ids']);

        return $data;
    }

    private function audit(Request $request, string $action, AddOn $addOn): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $addOn->tenant_id,
            'action' => $action, 'entity_type' => 'AddOn', 'entity_id' => $addOn->id,
            'metadata' => $addOn->only('name', 'price', 'pricing'), 'ip_address' => $request->ip()]);
    }
}
