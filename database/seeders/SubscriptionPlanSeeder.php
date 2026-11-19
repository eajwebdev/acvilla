<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        // Prices follow quotation EAJ-2026-011: ₱6,000/month, and the annual plan at its ₱4,500/month floor.
        // firstOrCreate: re-seeding never overwrites prices the superadmin has since edited in the app.
        SubscriptionPlan::firstOrCreate(['code' => 'MONTHLY'], [
            'name' => 'Monthly', 'description' => 'Flexible monthly billing for accommodation businesses.',
            'billing_interval' => 'MONTHLY', 'base_monthly_price' => 6000,
            'effective_monthly_price' => 6000, 'billing_price' => 6000,
            'commitment_months' => 1, 'discount_type' => 'NONE', 'discount_value' => 0,
            'active' => true, 'featured' => false,
        ]);

        SubscriptionPlan::firstOrCreate(['code' => 'ANNUAL'], [
            'name' => 'Annual', 'description' => 'Twelve-month commitment, billed once per year.',
            'billing_interval' => 'ANNUAL', 'base_monthly_price' => 6000,
            'effective_monthly_price' => 4500, 'billing_price' => 54000,
            'commitment_months' => 12, 'discount_type' => 'FIXED', 'discount_value' => 18000,
            'active' => true, 'featured' => true,
        ]);
    }
}
