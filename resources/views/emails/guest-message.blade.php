<?php
    $brand = $tenant->primary_color ?: '#0A2266';
    $mark = public_path('branding/ac-villa-logo-email.png');
    $logo = $tenant->logo ?: (isset($message) && is_file($mark) ? $message->embed($mark) : null);
    $serif = "Georgia,'Times New Roman',serif";
    $sans = 'Arial,Helvetica,sans-serif';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#F4F1EB;font-family:{{ $sans }};color:#141C33;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $subjectLine }} · {{ $tenant->business_name }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F1EB;padding:28px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #E7E3D9;">
            <tr><td align="center" style="background:#ffffff;padding:26px 28px 18px;">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $tenant->business_name }}" width="190" style="display:block;width:190px;max-width:190px;height:auto;border:0;">
                @else
                    <span style="font-family:{!! $serif !!};font-size:24px;font-weight:bold;color:{{ $brand }};">{{ $tenant->business_name }}</span>
                @endif
            </td></tr>
            <tr><td style="background:{{ $brand }};padding:26px 32px;border-bottom:4px solid #FFC800;">
                <div style="font-size:11px;line-height:16px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;color:#FFE27A;">{{ $tenant->business_name }}</div>
                <div style="margin-top:6px;font-family:{!! $serif !!};font-size:26px;line-height:32px;color:#ffffff;">{{ $subjectLine }}</div>
            </td></tr>
            <tr><td style="padding:30px 32px 10px;font-size:15px;line-height:24px;color:#141C33;">
                @foreach ($blocks as $block)
                    @if ($block['type'] === 'greeting')
                        <p style="margin:0 0 16px;font-family:{!! $serif !!};font-size:21px;line-height:28px;color:{{ $brand }};">{{ $block['text'] }}</p>
                    @elseif ($block['type'] === 'details')
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#F6F5F1;border-radius:12px;">
                            <tr><td style="padding:8px 18px;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                    @foreach ($block['rows'] as $row)
                                        <tr>
                                            @if ($row['label'] !== null)
                                                <td valign="top" style="padding:7px 12px 7px 0;font-size:13px;line-height:20px;color:#596174;white-space:nowrap;">{{ $row['label'] }}</td>
                                                <td valign="top" align="right" style="padding:7px 0;font-size:14px;line-height:20px;font-weight:bold;color:#141C33;">{!! $row['value'] !!}</td>
                                            @else
                                                <td colspan="2" style="padding:7px 0;font-size:14px;line-height:20px;font-weight:bold;color:{{ $brand }};">{!! $row['value'] !!}</td>
                                            @endif
                                        </tr>
                                    @endforeach
                                </table>
                            </td></tr>
                        </table>
                    @else
                        <p style="margin:0 0 16px;">{!! $block['html'] !!}</p>
                    @endif
                @endforeach
            </td></tr>
            @if ($button)
                <tr><td align="center" style="padding:6px 32px 30px;">
                    <a href="{{ $button }}" style="display:inline-block;padding:14px 32px;border-radius:999px;background:{{ $brand }};color:#ffffff;font-size:15px;font-weight:bold;text-decoration:none;">Open my booking</a>
                </td></tr>
            @endif
            <tr><td align="center" style="padding:22px 32px 26px;background:#F9F8F5;border-top:1px solid #EEEAE2;font-size:12px;line-height:19px;color:#596174;">
                <div style="font-family:{!! $serif !!};font-size:16px;line-height:22px;color:{{ $brand }};">{{ $tenant->business_name }}</div>
                @if ($tenant->business_phone || $tenant->business_email)
                    <div style="margin-top:4px;">{{ collect([$tenant->business_phone, $tenant->business_email])->filter()->implode(' · ') }}</div>
                @endif
                @if ($tenant->business_address)
                    <div>{{ $tenant->business_address }}</div>
                @endif
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
