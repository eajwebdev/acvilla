<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        SubscriptionPlan::firstOrCreate(['code' => 'MONTHLY'], [
            'name' => 'Monthly', 'description' => 'Flexible monthly billing for accommodation businesses.',
            'billing_interval' => 'MONTHLY', 'base_monthly_price' => 3499,
            'effective_monthly_price' => 3499, 'billing_price' => 3499,
            'commitment_months' => 1, 'discount_type' => 'NONE', 'discount_value' => 0,
            'active' => true, 'featured' => false,
        ]);

        SubscriptionPlan::firstOrCreate(['code' => 'ANNUAL'], [
            'name' => 'Annual', 'description' => 'Twelve-month commitment, billed once per year.',
            'billing_interval' => 'ANNUAL', 'base_monthly_price' => 3499,
            'effective_monthly_price' => 2499, 'billing_price' => 29988,
            'commitment_months' => 12, 'discount_type' => 'FIXED', 'discount_value' => 12000,
            'active' => true, 'featured' => true,
        ]);
    }
}
