<?php

return [
    // The one-click demo logins can never be on in production, even if DEMO=true is left in the .env by mistake.
    'demo_mode' => (bool) env('DEMO', false) && env('APP_ENV') !== 'production',
    'site_tenant' => env('SITE_TENANT', 'ac-villa'),
    'timezone' => 'Asia/Manila',

    // Nightly database backups (php artisan platform:backup). Copy this folder off the server as well.
    'backups' => [
        'path' => env('BACKUP_PATH', storage_path('app/backups')),
        'keep' => (int) env('BACKUP_KEEP', 14),
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    ],

    // Defaults for every tenant setting; tenants override them from Admin → Settings.
    // Guests need an account (Google, or email until Google sign-in is set up) with a mobile number to book.
    'require_guest_login' => (bool) env('REQUIRE_GUEST_LOGIN', true),

    'tenant_defaults' => [
        'currency' => 'PHP',
        'reference_prefix' => 'ACV',
        'check_in_time' => '14:00',
        'check_out_time' => '12:00',
        // Standard hours for an event in a hall, and the cleaning gap kept between one guest leaving and the next arriving.
        'event_start_time' => '08:00',
        'event_end_time' => '17:00',
        'turnover_minutes' => 120,
        'min_nights' => 1,
        'max_nights' => 30,
        'advance_notice_days' => 0,
        'booking_window_days' => 365,
        'hold_minutes' => (int) env('BOOKING_HOLD_MINUTES', 15),
        'manual_payment_hours' => 24,
        // A website reservation request keeps its dates this long while the owner calls the guest to confirm.
        'request_hold_hours' => 48,
        'service_fee_percent' => 5,
        'tax_label' => 'Tax',
        'tax_percent' => 0,
        'weekly_discount_percent' => 0,
        'monthly_discount_percent' => 0,
        'weekend_days' => [0, 6],
        'house_rules' => '',
        'cancellation_policy' => '',
        'privacy_policy' => '',
        'terms_of_service' => '',
        'test_payments' => true,
        'deposit_percent' => 100,
        'balance_due_days' => 7,
        'payment_methods' => [
            'gcash' => ['enabled' => false, 'account_name' => '', 'account_number' => '', 'instructions' => '', 'qr_image' => ''],
            'maya' => ['enabled' => false, 'account_name' => '', 'account_number' => '', 'instructions' => '', 'qr_image' => ''],
            'bank_transfer' => ['enabled' => false, 'account_name' => '', 'account_number' => '', 'instructions' => '', 'qr_image' => ''],
            'cash' => ['enabled' => false, 'account_name' => '', 'account_number' => '', 'instructions' => 'Pay in cash at the front desk when you arrive.', 'qr_image' => ''],
        ],
        'amenities' => ['Air conditioning', 'Wi-Fi', 'Hot shower', 'Private bathroom', 'Smart TV', 'Kitchen',
            'Refrigerator', 'Coffee maker', 'Balcony', 'Sea view', 'Pool access', 'Free parking', 'Workspace',
            'Towels & linens', 'Toiletries', 'Hair dryer', 'Breakfast available', 'Pet friendly'],
        'monthly_revenue_target' => 0,
        'cleaning_checklist' => ['Strip and replace bed linens', 'Replace towels', 'Clean and sanitize bathroom',
            'Restock toiletries', 'Vacuum and mop floors', 'Empty trash bins', 'Wipe kitchen and appliances',
            'Check remotes, lights and aircon', 'Report damage or missing items'],
        'cleaning_photo_required' => true,
    ],
    // Guest emails. Placeholders in {braces} are filled per booking; tenants edit these in Settings → Guest messages.
    'message_defaults' => [
        'booking_requested' => ['enabled' => true, 'send_days_before' => null,
            'subject' => 'We received your reservation request {booking_reference}',
            'body' => "Hi {guest_name},

Thank you for choosing {business_name}! We received your reservation request:

{room_name} at {property_name}
{check_in} to {check_out} ({nights} nights) · {guests} guests
Estimated total: {total}

No payment is needed yet. Our owner will call you at {guest_phone} to confirm your booking and arrange payment. We'll hold your dates until {payment_deadline}.

See your booking any time at {booking_link} with your reference {booking_reference} and this mobile number.

Need us sooner? Call {business_phone}.

{business_name}"],
        'booking_received' => ['enabled' => true, 'send_days_before' => null,
            'subject' => 'Booking {booking_reference}: downpayment needed',
            'body' => "Hi {guest_name},

Good news: your dates at {property_name} ({room_name}, {check_in} to {check_out}) are approved.

To confirm your booking, please send {amount_due} by {payment_deadline} as your downpayment.

{payment_instructions}

Then open {booking_link}, enter your reference {booking_reference} and your mobile number, and upload a photo or screenshot of the receipt. We'll confirm your booking as soon as we've checked it.

{business_name}"],
        'booking_confirmed' => ['enabled' => true, 'send_days_before' => null,
            'subject' => 'Your stay at {property_name} is confirmed',
            'body' => "Hi {guest_name},

Great news, booking {booking_reference} is confirmed.

{room_name} at {property_name}
Check-in: {check_in} from {check_in_time}
Check-out: {check_out} by {check_out_time}
Guests: {guests}

Total: {total}
Paid: {amount_paid}
Balance: {balance_due} {balance_due_note}

Questions? Reply to this email or call {business_phone}.

{business_name}"],
        'receipt_received' => ['enabled' => true, 'send_days_before' => null,
            'subject' => 'We got your downpayment receipt for {booking_reference}',
            'body' => "Hi {guest_name},

Thank you! We received your downpayment receipt of {amount_due} for {room_name} ({check_in} to {check_out}).

We're checking it now and will email you as soon as your booking is confirmed. Your dates stay reserved while we check.

See your booking any time at {booking_link} with your reference {booking_reference} and your mobile number.

{business_name}"],
        'receipt_rejected' => ['enabled' => true, 'send_days_before' => null,
            'subject' => 'Please send your receipt again for {booking_reference}',
            'body' => "Hi {guest_name},

We couldn't verify the downpayment receipt you sent for booking {booking_reference}. It may be unclear, or we couldn't find the payment yet.

Please open {booking_link}, enter your reference {booking_reference} and your mobile number, and upload a clear photo or screenshot of the receipt by {payment_deadline}.

Amount to send: {amount_due}
{payment_instructions}

Questions? Call {business_phone}.

{business_name}"],
        'payment_received' => ['enabled' => true, 'send_days_before' => null,
            'subject' => 'Payment received for {booking_reference}',
            'body' => "Hi {guest_name},

We've received your payment for booking {booking_reference}. Thank you!

Paid so far: {amount_paid} of {total}
Balance: {balance_due} {balance_due_note}

{business_name}"],
        'booking_cancelled' => ['enabled' => true, 'send_days_before' => null,
            'subject' => 'Booking {booking_reference} has been cancelled',
            'body' => "Hi {guest_name},

Your booking {booking_reference} for {property_name} ({check_in} to {check_out}) has been cancelled.

If this is unexpected, please contact us at {business_phone} or {business_email}.

{business_name}"],
        'pre_arrival' => ['enabled' => true, 'send_days_before' => 2,
            'subject' => 'See you soon at {property_name}',
            'body' => "Hi {guest_name},

We're looking forward to welcoming you on {check_in}. Check-in starts at {check_in_time}.

Address: {property_address}
Balance to settle: {balance_due} {balance_due_note}

Safe travels!
{business_name}"],
        'thank_you' => ['enabled' => true, 'send_days_before' => null,
            'subject' => 'Thank you for staying at {property_name}',
            'body' => "Hi {guest_name},

Thank you for staying with us. We hope you had a wonderful time and would love to host you again.

{business_name}"],
    ],
];
