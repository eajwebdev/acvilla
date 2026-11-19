<?php

/*
 * The one all-inclusive package on /quotation: a one-time setup fee and a monthly price, every module included.
 * /quotation?package=ac-villa shows it as a formal quotation for the client below. The platform owner can set a
 * discount on the monthly price on that page; customers get a signed link they can't change, never below min_price.
 */
return [
    'package' => [
        'id' => 'ac-villa',
        'client' => 'Ech Carido Che',
        'business' => 'AC Villa & Private Resort',
        'setup_fee' => 12000, // paid once, in full, on signing
        'price' => 6000,
        'min_price' => (int) env('QUOTATION_MIN_PRICE', 4500),
    ],

    // The quotation document itself. Give each new client quotation the next number.
    'document' => [
        'number' => 'EAJ-2026-011',
        'issued' => '2026-11-20',
        'valid_until' => '2026-12-20',
        'prepared_by' => 'Edwin Abril Jr',
        'prepared_by_title' => 'Lead Developer, EAJ Web Development Services',
    ],
];
