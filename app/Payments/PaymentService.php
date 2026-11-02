<?php

namespace App\Payments;

use App\Models\Tenant;
use App\Services\TenantSettings;

class PaymentService
{
    /** Options the checkout page offers, in display order. */
    public function options(Tenant $tenant): array
    {
        $settings = $tenant->settings;
        $options = collect(TenantSettings::PAYMENT_METHODS)
            ->filter(fn ($label, $method) => $settings['payment_methods'][$method]['enabled'])
            ->map(fn ($label, $method) => ['method' => $method, 'label' => $label, ...$settings['payment_methods'][$method]])
            ->values()->all();
        if (! $options) {
            $options[] = ['method' => 'manual', 'label' => 'Request manual payment', 'account_name' => '', 'account_number' => '',
                'instructions' => 'We will contact you with payment details.', 'qr_image' => '', 'enabled' => true];
        }
        if ($settings['test_payments']) {
            array_unshift($options, ['method' => 'mock', 'label' => 'Pay now (test mode)', 'account_name' => '', 'account_number' => '',
                'instructions' => 'Confirms instantly. No real money moves.', 'qr_image' => '', 'enabled' => true]);
        }

        return $options;
    }

    public function gateway(Tenant $tenant, string $method): ?PaymentGateway
    {
        if (! in_array($method, array_column($this->options($tenant), 'method'), true)) {
            return null;
        }

        return $method === 'mock' ? new MockPaymentGateway : new ManualPaymentGateway($method);
    }
}
