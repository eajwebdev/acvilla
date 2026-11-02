<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Property;
use App\Models\Room;
use App\Rules\ImageSource;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdminPropertyController extends Controller
{
    public function index(Request $request)
    {
        $settings = $request->user()->tenant->settings;

        return Inertia::render('Admin/Properties', ['properties' => Property::with('rooms')
            ->where('tenant_id', $request->user()->tenant_id)->latest()->get(),
            'amenities' => $settings['amenities'], 'bedTypes' => Room::BED_TYPES,
            'stayDefaults' => ['min_nights' => $settings['min_nights'], 'max_nights' => $settings['max_nights']]]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:150', 'city' => 'required|string|max:100',
            'province' => 'required|string|max:100', 'type' => 'required|string|max:50',
            'short_description' => 'nullable|string|max:255', 'description' => 'nullable|string|max:5000']);
        $property = Property::create($data + ['tenant_id' => $request->user()->tenant_id,
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)), 'status' => 'DRAFT']);
        $this->audit($request, 'property.created', $property);

        return back()->with('success', 'Property created.');
    }

    public function update(Request $request, Property $property)
    {
        $this->owned($request, $property);
        $this->admin($request);
        $data = $request->validate(['name' => 'required|string|max:150', 'city' => 'required|string|max:100',
            'province' => 'required|string|max:100', 'type' => 'required|string|max:50',
            'short_description' => 'nullable|string|max:255', 'description' => 'nullable|string|max:5000',
            'status' => 'required|in:DRAFT,PUBLISHED', 'featured' => 'boolean']);
        $property->update($data);
        $this->audit($request, 'property.updated', $property);

        return back()->with('success', 'Property updated.');
    }

    public function storeRoom(Request $request, Property $property)
    {
        $this->owned($request, $property);
        $this->admin($request);
        $data = $this->validateRoom($request);
        $room = Room::create($data + ['tenant_id' => $request->user()->tenant_id, 'property_id' => $property->id,
            'slug' => Str::slug($data['name']).'-'.Str::lower(Str::random(5)), 'active' => true]);
        $this->audit($request, 'room.created', $room);

        return back()->with('success', 'Room created.');
    }

    public function updateRoom(Request $request, Room $room)
    {
        abort_unless($room->tenant_id === $request->user()->tenant_id, 404);
        $this->admin($request);
        $data = $this->validateRoom($request, ['active' => 'boolean']);
        $room->update($data);
        $this->audit($request, 'room.updated', $room);

        return back()->with('success', 'Room updated.');
    }

    private function validateRoom(Request $request, array $extra = []): array
    {
        $settings = $request->user()->tenant->settings;
        $data = $request->validate([
            'name' => 'required|string|max:150', 'description' => 'nullable|string|max:2000',
            'maximum_occupancy' => 'required|integer|min:1|max:200', 'whole_property' => 'sometimes|boolean', 'kind' => ['sometimes', Rule::in(Room::KINDS)], 'quantity' => 'required|integer|min:1|max:100',
            'base_rate' => 'required|numeric|min:1|max:1000000', 'weekend_rate' => 'nullable|numeric|min:1|max:1000000',
            'cleaning_fee' => 'sometimes|numeric|min:0|max:100000',
            'bedrooms' => 'sometimes|integer|min:0|max:50', 'bathrooms' => 'sometimes|numeric|min:0|max:50|multiple_of:0.5',
            'size_sqm' => 'nullable|integer|min:1|max:100000',
            'beds' => 'nullable|array|max:10', 'beds.*.type' => ['required', Rule::in(Room::BED_TYPES)], 'beds.*.count' => 'required|integer|min:1|max:20',
            'base_guests' => 'nullable|integer|min:1|lte:maximum_occupancy', 'extra_guest_fee' => 'sometimes|numeric|min:0|max:100000',
            'min_nights' => 'nullable|integer|min:1|max:365', 'max_nights' => 'nullable|integer|min:1|max:365',
            'amenities' => 'nullable|array', 'amenities.*' => ['string', 'distinct', Rule::in($settings['amenities'])],
            'photos' => 'nullable|array|max:30', 'photos.*' => [new ImageSource],
            ...$extra,
        ]);
        $min = $data['min_nights'] ?? $settings['min_nights'];
        $max = $data['max_nights'] ?? $settings['max_nights'];
        if ($min > $max) {
            throw ValidationException::withMessages(['max_nights' => "Maximum nights ({$max}) must be at least the minimum ({$min}). Blank fields use your booking rules."]);
        }

        return $data;
    }

    private function owned(Request $request, Property $property): void
    {
        abort_unless($property->tenant_id === $request->user()->tenant_id, 404);
    }

    private function admin(Request $request): void
    {
        abort_unless($request->user()->role === 'ADMIN', 403);
    }

    private function audit(Request $request, string $action, Property|Room $entity): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $request->user()->tenant_id,
            'action' => $action, 'entity_type' => class_basename($entity), 'entity_id' => $entity->id,
            'metadata' => ['name' => $entity->name], 'ip_address' => $request->ip()]);
    }
}
