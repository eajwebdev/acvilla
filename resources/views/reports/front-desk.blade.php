@php
    /** The front-desk report: summary, arrivals, departures and the day's tasks, on A4 landscape. */
    $single = $from->equalTo($to);
    $period = $single ? $from->format('l, F j, Y') : $from->format('M j, Y').' – '.$to->format('M j, Y');
    $sym = $currency === 'PHP' ? '₱' : $currency.' ';
    $money = fn ($v) => $sym.number_format((float) $v, 2);
    $time = fn ($t) => $t ? \Carbon\Carbon::createFromFormat('H:i', substr($t, 0, 5))->format('g:i A') : '';
    $label = fn ($s) => ucwords(strtolower(str_replace('_', ' ', (string) $s)));
    $tone = fn ($s) => match ($s) {
        'CONFIRMED', 'CHECKED_IN', 'COMPLETED', 'CHECKED_OUT', 'DONE', 'PAID' => 'ok',
        'PENDING', 'AWAITING_PAYMENT', 'IN_PROGRESS', 'PARTIALLY_PAID' => 'warn',
        'CANCELLED', 'NO_SHOW', 'UNPAID' => 'bad',
        default => 'muted',
    };
    $sections = [
        ['Arrivals', $day['arrivals'], 'check_in', 'Check-in'],
        ['Departures', $day['departures'], 'check_out', 'Check-out'],
    ];
    $address = trim(implode(' · ', array_filter([$tenant->business_address, $tenant->business_phone])));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Front desk report · {{ $period }}</title>
<style>
    @page { margin: 26px 30px 40px 30px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 8.5px; color: #1d2433; margin: 0; }
    .num { text-align: right; white-space: nowrap; }
    .muted { color: #6b7385; }
    .head { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #0A2266; }
    .head td { vertical-align: middle; padding: 0 0 10px 0; }
    .logo { height: 58px; }
    .title { font-size: 19px; font-weight: bold; color: #0A2266; margin: 0; }
    .biz { font-size: 10px; font-weight: bold; margin: 3px 0 1px; }
    .meta { text-align: right; font-size: 8.5px; line-height: 1.55; }
    .meta strong { font-size: 11px; color: #0A2266; }
    .stats { width: 100%; border-collapse: separate; border-spacing: 6px 0; margin: 12px -6px 4px; }
    .stat { background: #f2f5fb; border: 1px solid #dde3ef; border-radius: 6px; padding: 7px 9px; width: 16.6%; }
    .stat .v { font-size: 14px; font-weight: bold; color: #0A2266; display: block; margin-top: 2px; }
    .stat .k { font-size: 7px; text-transform: uppercase; letter-spacing: .6px; color: #6b7385; }
    h2 { font-size: 11.5px; color: #0A2266; margin: 16px 0 5px; }
    h2 .count { font-size: 8.5px; color: #6b7385; font-weight: normal; }
    table.list { width: 100%; border-collapse: collapse; }
    table.list th { background: #0A2266; color: #fff; font-size: 7.5px; text-transform: uppercase; letter-spacing: .4px; text-align: left; padding: 5px 6px; }
    table.list th.num { text-align: right; }
    table.list td { padding: 5px 6px; border-bottom: 1px solid #e4e8f0; vertical-align: top; }
    table.list tr:nth-child(even) td { background: #f8f9fc; }
    table.list tr.total td { background: #eef2fa; font-weight: bold; border-top: 1.5px solid #0A2266; border-bottom: none; }
    table.list tr { page-break-inside: avoid; }
    .guest { font-weight: bold; }
    .sub { display: block; color: #6b7385; font-size: 7.5px; margin-top: 1px; }
    .pill { display: inline-block; padding: 1.5px 6px; border-radius: 8px; font-size: 7px; font-weight: bold; white-space: nowrap; }
    .pill.ok { background: #dcf3e6; color: #156b3a; }
    .pill.warn { background: #fdf0d2; color: #8a5a00; }
    .pill.bad { background: #fbe0e0; color: #a12424; }
    .pill.muted { background: #e8ebf1; color: #4a5263; }
    .due { color: #a12424; font-weight: bold; }
    .empty { border: 1px dashed #cfd6e4; border-radius: 6px; padding: 10px; text-align: center; color: #6b7385; }
    .foot { position: fixed; bottom: -26px; left: 0; right: 0; font-size: 7.5px; color: #6b7385; }
</style>
</head>
<body>
<div class="foot">{{ $tenant->business_name }} · Front desk report · {{ $period }}</div>

<table class="head">
    <tr>
        @if ($logo)
            <td style="width: 82px;"><img src="{{ $logo }}" class="logo" alt=""></td>
        @endif
        <td>
            <p class="title">Front Desk Report</p>
            <p class="biz">{{ $tenant->business_name }}</p>
            @if ($address)<span class="muted">{{ $address }}</span>@endif
        </td>
        <td class="meta">
            <strong>{{ $period }}</strong><br>
            @unless ($single){{ $day['days'] }} days · @endunless{{ $day['inventory'] }} units<br>
            <span class="muted">Prepared by {{ $by }} · {{ now(config('platform.timezone'))->format('M j, Y g:i A') }}</span>
        </td>
    </tr>
</table>

<table class="stats">
    <tr>
        <td class="stat"><span class="k">Arrivals</span><span class="v">{{ count($day['arrivals']) }}</span></td>
        <td class="stat"><span class="k">Departures</span><span class="v">{{ count($day['departures']) }}</span></td>
        <td class="stat"><span class="k">{{ $single ? 'In house tonight' : 'Avg. units occupied' }}</span><span class="v">{{ $single ? $day['inHouse'] : $day['occupied'] }}</span></td>
        <td class="stat"><span class="k">Occupancy</span><span class="v">{{ $day['occupancy'] }}%</span></td>
        <td class="stat"><span class="k">Revenue{{ $single ? ' today' : '' }}</span><span class="v">{{ $money($day['revenueToday']) }}</span></td>
        <td class="stat"><span class="k">Balance due</span><span class="v">{{ $money($day['balanceDue']) }}</span></td>
    </tr>
</table>

@foreach ($sections as [$title, $rows, $dateKey, $timeLabel])
    <h2>{{ $title }} <span class="count">· {{ count($rows) }} {{ count($rows) === 1 ? 'booking' : 'bookings' }}</span></h2>
    @if (count($rows) === 0)
        <div class="empty">No {{ strtolower($title) }} {{ $single ? 'this day' : 'in this period' }}.</div>
    @else
        <table class="list">
            <thead>
                <tr>
                    <th style="width: 8%;">{{ $single ? $timeLabel : $timeLabel.' date' }}</th>
                    <th style="width: 16%;">Guest</th>
                    <th style="width: 13%;">Unit</th>
                    <th class="num" style="width: 4%;">Pax</th>
                    <th class="num" style="width: 5%;">Nights</th>
                    <th style="width: 7%;">Source</th>
                    <th style="width: 10%;">Status</th>
                    <th class="num" style="width: 9%;">Total</th>
                    <th class="num" style="width: 9%;">Paid</th>
                    <th class="num" style="width: 9%;">Balance</th>
                    <th style="width: 10%;">Reference</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    <tr>
                        <td>
                            @if ($single)
                                {{ $time($r['time']) }}
                            @else
                                <span class="guest">{{ \Carbon\Carbon::parse($r[$dateKey])->format('M j') }}</span><span class="sub">{{ $time($r['time']) }}</span>
                            @endif
                        </td>
                        <td><span class="guest">{{ $r['guest_name'] }}</span>@if ($r['guest_phone'])<span class="sub">{{ $r['guest_phone'] }}</span>@endif</td>
                        <td>{{ $r['unit'] }}</td>
                        <td class="num">{{ $r['guests'] }}</td>
                        <td class="num">{{ $r['nights'] }}</td>
                        <td>{{ $label($r['source']) }}</td>
                        <td><span class="pill {{ $tone($r['status']) }}">{{ $label($r['status']) }}</span></td>
                        <td class="num">{{ $money($r['total']) }}</td>
                        <td class="num">{{ $money($r['paid']) }}</td>
                        <td class="num {{ $r['balance'] > 0 ? 'due' : '' }}">{{ $money($r['balance']) }}</td>
                        <td style="white-space: nowrap; font-size: 7.5px;">{{ $r['reference'] }}</td>
                    </tr>
                @endforeach
                <tr class="total">
                    <td colspan="3">Total · {{ count($rows) }} {{ count($rows) === 1 ? 'booking' : 'bookings' }}</td>
                    <td class="num">{{ collect($rows)->sum('guests') }}</td>
                    <td class="num">{{ collect($rows)->sum('nights') }}</td>
                    <td colspan="2"></td>
                    <td class="num">{{ $money(collect($rows)->sum('total')) }}</td>
                    <td class="num">{{ $money(collect($rows)->sum('paid')) }}</td>
                    <td class="num">{{ $money(collect($rows)->sum('balance')) }}</td>
                    <td></td>
                </tr>
            </tbody>
        </table>
    @endif
@endforeach

<h2>Cleaning &amp; tasks @if ($tasks !== null)<span class="count">· {{ $tasks->where('status', 'DONE')->count() }} of {{ $tasks->count() }} done</span>@endif</h2>
@if ($tasks === null)
    <div class="empty">Tasks are listed for periods of up to a month.</div>
@elseif ($tasks->isEmpty())
    <div class="empty">No cleaning or tasks {{ $single ? 'this day' : 'in this period' }}.</div>
@else
    <table class="list">
        <thead>
            <tr>
                @unless ($single)<th style="width: 9%;">Date</th>@endunless
                <th style="width: 24%;">Task</th>
                <th style="width: 14%;">Type</th>
                <th style="width: 15%;">Assigned to</th>
                <th style="width: 10%;">Ready by</th>
                <th style="width: 10%;">Status</th>
                <th>Notes</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($tasks as $t)
                <tr>
                    @unless ($single)<td>{{ \Carbon\Carbon::parse($t['date'])->format('M j') }}</td>@endunless
                    <td>
                        <span class="guest">{{ $t['unit'] }}</span>
                        @if ($t['kind'] === 'manual' && $t['room'])<span class="sub">{{ $t['room'] }}</span>@endif
                        @if ($t['kind'] === 'turnover' && $t['next_guest'])<span class="sub">Next: {{ $t['next_guest'] }}</span>@endif
                    </td>
                    <td>{{ $t['kind'] === 'manual' ? 'Added by hand' : 'Check-out cleaning' }}@if ($t['checklist_total'] > 0)<span class="sub">{{ count($t['checklist']) }}/{{ $t['checklist_total'] }} steps</span>@endif</td>
                    <td>{!! $t['cleaner'] ? e($t['cleaner']) : '<span class="muted">Unassigned</span>' !!}</td>
                    <td>{{ $t['ready_by'] ? $time($t['ready_by']) : 'Any time' }}</td>
                    <td><span class="pill {{ $t['status'] !== 'DONE' && $t['risk'] ? 'bad' : $tone($t['status']) }}">{{ $t['status'] !== 'DONE' && $t['risk'] ? 'At risk' : $label($t['status']) }}</span></td>
                    <td>{{ $t['notes'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
</body>
</html>
