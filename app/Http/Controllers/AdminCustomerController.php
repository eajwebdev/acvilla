<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminCustomerController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['ACTIVE', 'VIP', 'BLOCKED'])],
        ]);
        $tenantId = $request->user()->tenant_id;
        $customers = Customer::where('tenant_id', $tenantId)
            ->when($filters['search'] ?? null, function ($query, string $search): void {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($search)).'%';
                $query->where(fn ($q) => $q->where('name', 'like', $term)->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->withCount('bookings')
            ->withMax('bookings', 'check_out')
            ->withSum(['payments as total_spend' => fn ($query) => $query->where('payments.status', 'PAID')], 'amount')
            ->orderByDesc('bookings_max_check_out')->orderBy('name')
            ->paginate(30)->withQueryString();

        return Inertia::render('Admin/Customers', ['customers' => $customers, 'filters' => $filters]);
    }

    public function show(Request $request, Customer $customer)
    {
        $this->owned($request, $customer);
        $customer->load(['bookings' => fn ($query) => $query->with('property:id,name', 'room:id,name')
            ->withSum(['payments as paid' => fn ($payments) => $payments->where('status', 'PAID')], 'amount')
            ->latest('check_in')]);

        return Inertia::render('Admin/Customer', [
            'customer' => $customer,
            'summary' => [
                'bookings' => $customer->bookings->count(),
                'spent' => round((float) $customer->payments()->where('payments.status', 'PAID')->sum('amount'), 2),
                'last_stay' => $customer->bookings->max(fn ($booking) => $booking->check_out?->toDateString()),
            ],
        ]);
    }

    public function update(Request $request, Customer $customer)
    {
        $this->owned($request, $customer);
        $data = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'VIP', 'BLOCKED'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $customer->update($data);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $request->user()->tenant_id,
            'action' => 'customer.updated', 'entity_type' => 'Customer', 'entity_id' => $customer->id,
            'metadata' => ['status' => $customer->status], 'ip_address' => $request->ip()]);

        return back()->with('success', 'Customer details saved.');
    }

    private function owned(Request $request, Customer $customer): void
    {
        abort_unless($customer->tenant_id === $request->user()->tenant_id, 404);
    }
}
