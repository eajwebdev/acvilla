<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;

class SuperadminTenantController extends Controller
{
    public function index()
    {
        return Inertia::render('Superadmin/Clients', ['tenants' => Tenant::withCount('properties')->latest()->get(),
            'plans' => SubscriptionPlan::where('active', true)->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['business_name' => 'required|string|max:150',
            'business_email' => 'required|email|max:255', 'business_phone' => 'nullable|string|max:30',
            'admin_name' => 'required|string|max:120', 'admin_email' => 'required|email|unique:users,email',
            'admin_password' => 'required|string|min:12']);
        DB::transaction(function () use ($request, $data) {
            $tenant = Tenant::create(['name' => $data['business_name'], 'slug' => Str::slug($data['business_name']).'-'.Str::lower(Str::random(5)),
                'business_name' => $data['business_name'], 'business_email' => $data['business_email'],
                'business_phone' => $data['business_phone'] ?? null, 'status' => 'ACTIVE',
                'subscription_status' => 'TRIAL', 'trial_ends_at' => now()->addDays(14)]);
            User::create(['name' => $data['admin_name'], 'email' => $data['admin_email'],
                'password' => Hash::make($data['admin_password']), 'role' => 'ADMIN', 'tenant_id' => $tenant->id]);
            AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $tenant->id,
                'action' => 'tenant.created', 'entity_type' => 'Tenant', 'entity_id' => $tenant->id,
                'metadata' => ['business_name' => $tenant->business_name], 'ip_address' => $request->ip()]);
        });

        return back()->with('success', 'Client created with a 14-day trial.');
    }

    public function status(Request $request, Tenant $tenant)
    {
        $data = $request->validate(['status' => 'required|in:ACTIVE,LOCKED,SUSPENDED']);
        $before = $tenant->status;
        $tenant->update(['status' => $data['status'], 'locked_at' => $data['status'] === 'LOCKED' ? now() : null]);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $tenant->id,
            'action' => 'tenant.status_changed', 'entity_type' => 'Tenant', 'entity_id' => $tenant->id,
            'metadata' => ['before' => $before, 'after' => $data['status']], 'ip_address' => $request->ip()]);

        return back()->with('success', 'Client status updated.');
    }

    public function subscribe(Request $request, Tenant $tenant)
    {
        $data = $request->validate(['subscription_plan_id' => 'required|exists:subscription_plans,id',
            'starts_at' => 'required|date']);
        $plan = SubscriptionPlan::where('active', true)->findOrFail($data['subscription_plan_id']);
        $start = CarbonImmutable::parse($data['starts_at']);
        $end = $start->addMonthsNoOverflow($plan->commitment_months);
        $tenant->subscriptions()->create([
            'subscription_plan_id' => $plan->id, 'status' => 'ACTIVE', 'starts_at' => $start->toDateString(),
            'ends_at' => $end->toDateString(), 'renewal_at' => $end->toDateString(),
            'billing_interval' => $plan->billing_interval, 'commitment_months' => $plan->commitment_months,
            'base_price' => $plan->base_monthly_price, 'discount_type' => $plan->discount_type,
            'discount_value' => $plan->discount_value, 'effective_price' => $plan->effective_monthly_price,
            'billing_total' => $plan->billing_price, 'created_by' => $request->user()->id,
        ]);
        $tenant->update(['subscription_status' => 'ACTIVE', 'subscription_started_at' => $start,
            'subscription_ends_at' => $end]);
        AuditLog::create(['actor_user_id' => $request->user()->id, 'tenant_id' => $tenant->id,
            'action' => 'subscription.created', 'entity_type' => 'Tenant', 'entity_id' => $tenant->id,
            'metadata' => ['plan' => $plan->code, 'billing_total' => $plan->billing_price], 'ip_address' => $request->ip()]);

        return back()->with('success', 'Subscription assigned.');
    }
}
