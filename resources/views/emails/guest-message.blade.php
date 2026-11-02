<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{{ $subjectLine }}</title></head>
<body style="margin:0;padding:0;background:#f4f5f8;font-family:Arial,Helvetica,sans-serif;color:#1f2433;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f8;padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;">
            <tr><td style="background:{{ $tenant->primary_color }};padding:22px 28px;">
                @if ($tenant->logo)
                    <img src="{{ $tenant->logo }}" alt="{{ $tenant->business_name }}" height="36" style="display:block;max-height:36px;">
                @else
                    <span style="color:#ffffff;font-size:18px;font-weight:bold;">{{ $tenant->business_name }}</span>
                @endif
            </td></tr>
            <tr><td style="padding:28px;font-size:15px;line-height:1.6;">{!! nl2br(e($body)) !!}</td></tr>
            <tr><td style="padding:18px 28px;border-top:1px solid #eceef3;font-size:12px;line-height:1.5;color:#6b7385;">
                {{ $tenant->business_name }}@if ($tenant->business_phone) · {{ $tenant->business_phone }}@endif @if ($tenant->business_email) · {{ $tenant->business_email }}@endif
                @if ($tenant->business_address)<br>{{ $tenant->business_address }}@endif
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
