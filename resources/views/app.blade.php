<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0A2266">
    <meta name="color-scheme" content="light">
    <meta name="format-detection" content="telephone=no">
    @php
        // Messenger, Facebook and the like read this HTML without running the page's JavaScript, so link previews are set here.
        // Only the public home and stay pages add their own words: a booking link never shows a guest's details.
        $component = $page['component'] ?? null;
        $name = data_get($page, 'props.tenant.business_name') ?: config('app.name');
        $property = $component === 'Public/Stay' ? data_get($page, 'props.property') : null;
        $headline = $component === 'Public/Home' ? data_get($page, 'props.tenant.hero_headline') : null;
        $shareTitle = $property ? data_get($property, 'name')." | {$name}" : ($headline ? "{$name} | {$headline}" : $name);
        $shareText = ($property ? data_get($property, 'short_description') ?: data_get($property, 'description') : null)
            ?: ($component === 'Public/Home' ? data_get($page, 'props.tenant.hero_description') : null)
            ?: 'A-frame villas, private pool, weddings and events in North Poblacion, Mabinay, Negros Oriental. Send a reservation request and we will call you to confirm.';
        $shareText = Str::limit(Str::squish(strip_tags($shareText)), 200);
        $cover = $property ? data_get($property, 'cover_image') : null;
    @endphp
    <title inertia>{{ $shareTitle }}</title>
    <meta name="description" content="{{ $shareText }}">
    <meta property="og:site_name" content="{{ $name }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $shareTitle }}">
    <meta property="og:description" content="{{ $shareText }}">
    <meta property="og:image" content="{{ url($cover ?: '/branding/acvilla-share.jpg') }}">
    @unless ($cover)
        <meta property="og:image:type" content="image/jpeg">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
    @endunless
    <meta property="og:image:alt" content="{{ $name }}">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="icon" type="image/png" sizes="48x48" href="/branding/ac-villa-favicon-48.png">
    <link rel="apple-touch-icon" href="/branding/ac-villa-touch.png">
    <link rel="manifest" href="/site.webmanifest">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;0,700;1,500;1,600;1,700&family=Jost:wght@400;500;600;700&family=Hind:wght@500;600;700&family=Merriweather:ital,wght@1,900&family=Oleo+Script:wght@700&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
    @viteReactRefresh
    @vite(['resources/js/app.tsx', 'resources/css/app.css'])
    @inertiaHead
</head>
<body>@inertia</body>
</html>
