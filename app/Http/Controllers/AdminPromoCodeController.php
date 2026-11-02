<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PromoCode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminPromoCodeController extends Controller
{
    public function index(Request $request)
    {
        $promos = PromoCode::where('tenant_id', $request->user()->tenant_id)->orderByDesc('active')->latest()->get()
            ->map(fn (PromoCode $promo) => [...$promo->toArray(), 'uses' => $promo->usesCount()]);

        return Inertia::render('Admin/PromoCodes', ['promos' => $promos]);
    }

    public function store(Request $request)
    {
        $promo = PromoCode::create($this->validated($request) + ['tenant_id' => $request->user()->tenant_id]);
        $this->audit($request, 'promo.created', $promo);

        return back()->with('success', 'Promo code created.');
    }

    public function update(Request $request, PromoCode $promo)
    {
        abort_unless($promo->tenant_id === $request->user()->tenant_id, 404);
        $promo->update($this->validated($request, $promo));
        $this->audit($request, 'promo.updated', $promo);

        return back()->with('success', 'Promo code updated.');
    }

    public function destroy(Request $request, PromoCode $promo)
    {
        abort_unless($promo->tenant_id === $request->user()->tenant_id, 404);
        $this->audit($request, 'promo.deleted', $promo);
        $promo->delete();

        return back()->with('success', 'Promo code deleted. Past bookings keep their discount.');
    }

    private function validated(Request $request, ?PromoCode $promo = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code' => ['required', 'regex:/^[A-Z0-9_-]{3,20}$/',
                Rule::unique('promo_codes')->where('tenant_id', $request->user()->tenant_id)->ignore($promo?->id)],
            'discount_type' => 'required|in:percent,amount',
            'discount_value' => $request->input('discount_type') === 'percent' ? 'required|numeric|min:1|max:100' : 'required|numeric|min:1|max:1000000',
            'starts_on' => 'nullable|date_format:Y-m-d',
            'ends_on' => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
            'min_nights' => 'nullable|integer|min:1|max:365',
            'max_uses' => 'nullable|integer|min:1|max:100000',
            'active' => 'boolean',
        ], ['code.regex' => 'Use 3-20 letters, numbers, dashes or underscores.', 'code.unique' => 'You already have a promo code with this name.']);
    }

    private function audit(Request $request, string $action, PromoCode $promo): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $promo->tenant_id,
            'action' => $action, 'entity_type' => 'PromoCode', 'entity_id' => $promo->id,
            'metadata' => $promo->only('code', 'discount_type', 'discount_value'), 'ip_address' => $request->ip()]);
    }
}
