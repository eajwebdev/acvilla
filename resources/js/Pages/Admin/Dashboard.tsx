import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRightFromLine, ArrowRightToLine, BedDouble, CalendarDays, Check, ChevronDown, ChevronLeft, ChevronRight, CircleAlert, Download, Inbox, LogIn, LogOut, Plus, SprayCan, TrendingUp, TriangleAlert, Wallet } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { CartesianGrid, Line, LineChart, ReferenceLine, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import BookingDrawer from '../../Components/BookingDrawer';
import DashboardShell from '../../Components/DashboardShell';
import DateRangePicker from '../../Components/DateRangePicker';
import NewBookingDialog from '../../Components/NewBookingDialog';
import { addDays, Avatar, axisMoney, Card, DIRECT_SOURCES, Empty, fmtDay, fmtTime, kMoney, parseDay, SourceBadge, Status } from '../../Components/ops';
import { money } from '../../types';

type Row = { id: number; reference: string; check_in: string; check_out: string; guest_name: string; guest_phone: string | null; unit: string; guests: number; time: string; nights: number; status: string; payment_status: string; source: string; total: number; paid: number; open_charges: number; balance: number };
type Day = { date: string; to: string; days: number; occupiedNights: number; inventory: number; occupied: number; occupancy: number; arrivals: Row[]; departures: Row[]; inHouse: number; revenueToday: number; revenueYesterday: number; revenueWeek: number[]; balanceDue: number; balanceAtCheckIn: number };
type Task = { id: number; date: string; unit: string; status: string; risk: boolean; cleaner: string | null; ready_by: string; check_out_time: string; next_guest: string | null; checklist: string[]; checklist_total: number };
type Attention = { key: string; tone: 'danger' | 'warn' | 'info'; title: string; detail: string; booking_id?: number; href?: string };
type Props = { tenant: { business_name: string }; today: string; day: Day; properties: number; units: number; trend: { month: string; label: string; booked: number }[]; months: number; target: number; attention: Attention[]; cleaning: Task[]; cleaningLimited: boolean };

function Spark({ values }: { values: number[] }) {
  const max = Math.max(...values, 1);
  const pts = values.map((v, i) => `${(i / Math.max(values.length - 1, 1)) * 60},${18 - (v / max) * 16}`).join(' ');
  return <svg viewBox="0 0 60 20" className="h-5 w-16" aria-hidden="true"><polyline points={pts} fill="none" stroke="#0070C0" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"/></svg>;
}
function Ring({ percent }: { percent: number }) {
  const r = 14, c = 2 * Math.PI * r;
  return <svg viewBox="0 0 36 36" className="size-9 -rotate-90" aria-hidden="true"><circle cx="18" cy="18" r={r} fill="none" stroke="#E3EAE1" strokeWidth="4"/>{percent > 0 && <circle cx="18" cy="18" r={r} fill="none" stroke="#0070C0" strokeWidth="4" strokeDasharray={`${(percent / 100) * c} ${c}`} strokeLinecap="round"/>}</svg>;
}

export default function Dashboard({ today, day, properties, units, trend, months, target, attention, cleaning, cleaningLimited }: Props) {
  const { auth } = usePage<{ auth: { user: { role: string } } }>().props;
  const admin = auth.user.role === 'ADMIN';
  const [drawer, setDrawer] = useState<number | null>(null);
  const [tab, setTab] = useState<'arrivals' | 'departures'>('arrivals');
  const [filter, setFilter] = useState<'all' | 'unpaid' | 'direct' | 'airbnb'>('all');
  const [dismissed, setDismissed] = useState<string[]>([]);
  const storeKey = `tapp.dismissed.${day.date}`;
  useEffect(() => { try { setDismissed(JSON.parse(localStorage.getItem(storeKey) || '[]')); } catch { setDismissed([]); } }, [storeKey]);
  const dismiss = (keys: string[]) => { const next = [...new Set([...dismissed, ...keys])]; setDismissed(next); try { localStorage.setItem(storeKey, JSON.stringify(next)); } catch { /* private mode */ } };
  const visibleAttention = attention.filter(a => !dismissed.includes(a.key));

  const isRange = day.to !== day.date;
  const isToday = !isRange && day.date === today;
  const span = (from: string, to: string): Record<string, string> => from === to ? (from === today ? {} : { date: from }) : { from, to };
  const go = (from: string, to = from) => router.get('/admin', { ...span(from, to), ...(months !== 12 ? { months } : {}) }, { preserveScroll: true, preserveState: true });
  // The arrows move by the length of the period: a day, a week, a month...
  const shift = (direction: number) => go(addDays(day.date, direction * day.days), addDays(day.to, direction * day.days));
  const short = (d: string) => fmtDay(d, { month: 'short', day: 'numeric' });
  const rangeLabel = isRange ? `${short(day.date)} to ${short(day.to)}` : fmtDay(day.date, { weekday: 'short', month: 'short', day: 'numeric' });
  const when = isToday ? 'today' : isRange ? 'in this period' : 'this day';
  const query = new URLSearchParams(span(day.date, day.to)).toString();
  const turnovers = cleaning.length;
  const list = (tab === 'arrivals' ? day.arrivals : day.departures).filter(r => filter === 'all' || (filter === 'unpaid' ? r.balance > 0 : filter === 'direct' ? DIRECT_SOURCES.includes(r.source) : r.source === filter));
  const act = (row: Row, status: string) => router.put(`/admin/bookings/${row.id}/status`, { status }, { preserveScroll: true });

  const current = trend[trend.length - 1];
  const bookedTotal = trend.reduce((s, t) => s + t.booked, 0);
  const monthEnd = useMemo(() => { const d = parseDay(today); return new Date(d.getFullYear(), d.getMonth() + 1, 0); }, [today]);
  const daysLeft = monthEnd.getDate() - parseDay(today).getDate();
  const monthName = parseDay(today).toLocaleDateString('en-PH', { month: 'long' });
  const chartMax = Math.max(target, ...trend.map(t => t.booked), 1000);
  const yTicks = [0, chartMax / 2, chartMax].map(v => Math.ceil(v / 1000) * 1000);
  const data = trend.map((t, i) => ({ ...t, solid: i < trend.length - 1 ? t.booked : null, dashed: i >= trend.length - 2 ? t.booked : null }));
  const prior = isRange ? `vs previous ${day.days} days` : 'vs yesterday';
  const vsYesterday = day.revenueYesterday > 0 ? Math.round(100 * (day.revenueToday - day.revenueYesterday) / day.revenueYesterday) : null;

  const kpis = [
    { icon: ArrowRightToLine, label: 'Arrivals', value: String(day.arrivals.length), sub: day.arrivals.length ? `${day.arrivals.filter(r => r.status === 'CHECKED_IN').length} checked in` : 'none scheduled' },
    { icon: ArrowRightFromLine, label: 'Departures', value: String(day.departures.length), sub: day.departures.length ? `${day.departures.filter(r => ['CHECKED_OUT', 'COMPLETED'].includes(r.status)).length} checked out` : 'none scheduled' },
    { icon: BedDouble, label: 'Occupancy', value: `${day.occupancy}%`, sub: isRange ? `${day.occupiedNights} of ${day.inventory * day.days} unit-nights` : `${day.occupied} of ${day.inventory} units tonight`, extra: <Ring percent={day.occupancy}/> },
    { icon: Wallet, label: isRange ? 'Revenue received' : isToday ? 'Revenue today' : 'Revenue', value: kMoney(day.revenueToday), sub: vsYesterday === null ? 'no prior baseline' : `${vsYesterday >= 0 ? '+' : ''}${vsYesterday}% ${prior}`, extra: <Spark values={day.revenueWeek}/> },
    { icon: TriangleAlert, label: 'Balance due', value: kMoney(day.balanceDue), sub: `${kMoney(day.balanceAtCheckIn)} due at check-in` },
  ];

  return <DashboardShell title={isToday ? 'Today' : isRange ? rangeLabel : fmtDay(day.date, { weekday: 'long', month: 'short', day: 'numeric' })}
    subtitle={`${isRange ? `${day.days} days · ` : ''}${properties} ${properties === 1 ? 'property' : 'properties'} · ${units} ${units === 1 ? 'unit' : 'units'} · ${turnovers} ${turnovers === 1 ? 'turnover' : 'turnovers'}`}
    actions={<>
      <div className="flex items-center rounded-xl border border-[var(--line)] bg-white">
        <button type="button" aria-label={isRange ? 'Previous period' : 'Previous day'} onClick={() => shift(-1)} className="grid size-9 place-items-center text-[var(--muted)] transition-colors hover:text-[var(--ink)]"><ChevronLeft size={16}/></button>
        <DateRangePicker mode="report" label="Dashboard dates" presets={['today', 'tomorrow', 'next7', 'next30', 'week', 'month', 'lastMonth', 'year']}
          value={{ start: day.date, end: day.to }} onChange={range => range.start && range.end ? go(range.start, range.end) : go(today)}
          trigger={<button type="button" aria-label={`Dashboard dates: ${rangeLabel}. Change`} className="flex h-9 items-center gap-1.5 whitespace-nowrap rounded-lg px-2 text-sm font-bold transition-colors hover:bg-[#F4F7F3]"><CalendarDays size={15} className="text-[var(--muted)]"/>{rangeLabel}<ChevronDown size={14} className="text-[var(--muted)]"/></button>}/>
        <button type="button" aria-label={isRange ? 'Next period' : 'Next day'} onClick={() => shift(1)} className="grid size-9 place-items-center text-[var(--muted)] transition-colors hover:text-[var(--ink)]"><ChevronRight size={16}/></button>
      </div>
      {!isToday && <button type="button" onClick={() => go(today)} className="btn btn-light">Today</button>}
      <a href={`/admin/today/export${query ? `?${query}` : ''}`} className="btn btn-light"><Download size={15}/> Export</a>
      <NewBookingDialog defaultDate={day.date} trigger={<button type="button" className="btn btn-pink"><Plus size={16}/> New booking</button>}/>
    </>}>
    <Head title="Today"/>
    <div className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--line)] sm:grid-cols-3 xl:grid-cols-5">
      {kpis.map(({ icon: Icon, label, value, sub, extra }) => <div key={label} className="flex min-w-0 items-start justify-between gap-2 bg-[#FAFBF8] p-4">
        <div className="min-w-0"><p className="label flex items-center gap-1.5"><Icon size={13}/>{label}</p><p className="num mt-2 text-[26px] font-semibold leading-none">{value}</p><p className="mt-2 truncate text-[11.5px] font-semibold text-[var(--muted)]">{sub}</p></div>
        {extra}
      </div>)}
    </div>

    <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <Card title="Revenue vs target" subtitle={`${kMoney(bookedTotal)} booked · trailing ${months} months · ${target > 0 ? `target ${kMoney(target)}/mo` : 'no target set'}`}
        action={<div className="flex items-center gap-3"><span className="flex items-center gap-1.5 text-xs font-semibold"><span className="h-0.5 w-4 bg-sea"/>Booked</span>{target > 0 && <span className="flex items-center gap-1.5 text-xs font-semibold"><span className="h-0 w-4 border-t-2 border-dashed border-amber-500"/>Target</span>}
          <select aria-label="Months shown" value={months} onChange={e => router.get('/admin', { ...(isToday ? {} : { date: day.date }), months: e.target.value }, { preserveScroll: true, preserveState: true })} className="rounded-lg border border-[var(--line)] bg-white px-2 py-1.5 text-xs font-bold">{[3, 6, 12].map(m => <option key={m} value={m}>{m} months</option>)}</select></div>}>
        <div className="grid grid-cols-2 border-b border-[var(--line)]">
          <div className="border-r border-[var(--line)] px-5 py-3"><p className="label">Booked</p><p className="num mt-1 text-xl font-semibold">{kMoney(current?.booked ?? 0)}</p><p className="text-[11px] font-semibold text-[var(--muted)]">{monthName} so far</p></div>
          <div className="px-5 py-3"><p className="label">{target > 0 ? 'To target' : 'Days left'}</p><p className="num mt-1 text-xl font-semibold">{target > 0 ? kMoney(Math.max(0, target - (current?.booked ?? 0))) : daysLeft}</p><p className="text-[11px] font-semibold text-[var(--muted)]">{target > 0 ? `${daysLeft} days left in ${monthName}` : `in ${monthName}`}</p></div>
        </div>
        <div className="h-60 px-2 pt-4">
          <ResponsiveContainer width="100%" height="100%">
            <LineChart data={data} margin={{ top: 6, right: 16, left: 4, bottom: 0 }}>
              <CartesianGrid vertical={false} stroke="#EDF2EB"/>
              <XAxis dataKey="label" tick={{ fontSize: 10, fontFamily: 'Jost', fill: '#64736B' }} axisLine={false} tickLine={false}/>
              <YAxis ticks={yTicks} domain={[0, yTicks[2]]} tickFormatter={axisMoney} tick={{ fontSize: 10, fontFamily: 'Jost', fill: '#64736B' }} axisLine={false} tickLine={false} width={46}/>
              <Tooltip formatter={v => money(Number(v))} labelFormatter={(l, p) => (p?.[0]?.payload as { month?: string })?.month ?? String(l)}/>
              {target > 0 && <ReferenceLine y={target} stroke="#A07716" strokeDasharray="5 4"/>}
              <Line type="monotone" dataKey="solid" name="Booked" stroke="#0070C0" strokeWidth={2} dot={false} connectNulls={false} isAnimationActive={false}/>
              <Line type="monotone" dataKey="dashed" name="Booked" stroke="#0070C0" strokeWidth={2} strokeDasharray="4 4" dot={(p: { cx?: number; cy?: number; index?: number }) => p.index === data.length - 1 ? <circle key="last" cx={p.cx} cy={p.cy} r={4} fill="#fff" stroke="#0070C0" strokeWidth={2}/> : <g key={p.index}/>} isAnimationActive={false}/>
            </LineChart>
          </ResponsiveContainer>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-[var(--line)] px-5 py-3 text-xs font-semibold">
          <p className="flex items-center gap-2"><TrendingUp size={14} className="text-ocean"/><span><strong>{monthName} has booked {kMoney(current?.booked ?? 0)} so far.</strong> <span className="text-[var(--muted)]">{daysLeft} days left in {monthName}. {target > 0 ? `${Math.round(100 * (current?.booked ?? 0) / target)}% of target${daysLeft > 0 && target > (current?.booked ?? 0) ? ` · ${kMoney((target - (current?.booked ?? 0)) / daysLeft)}/day to close the gap` : ''}.` : 'Set a target to track variance, forecast and pacing.'}</span></span></p>
          {admin && <Link href="/admin/settings/targets" className="btn btn-soft btn-sm">{target > 0 ? 'Edit target' : 'Set monthly revenue target'}</Link>}
        </div>
      </Card>

      <Card title="Attention required" action={visibleAttention.length > 0 ? <button type="button" onClick={() => dismiss(visibleAttention.map(a => a.key))} className="text-xs font-bold text-[var(--muted)] hover:text-[var(--ink)]">Dismiss all</button> : <span className="text-xs font-bold text-[#B3BDB6]">Dismiss all</span>} bodyClass="max-h-[420px] overflow-y-auto">
        {visibleAttention.length === 0 ? <div className="py-14"><Empty icon={<Check size={18}/>} title="Nothing needs you right now" body="Every room is assigned, on track and paid up for today."/></div>
          : <ul>{visibleAttention.map(a => {
            const tone = a.tone === 'danger' ? 'text-[var(--danger)] bg-rose-50' : a.tone === 'warn' ? 'text-amber-700 bg-amber-50' : 'text-sky-700 bg-sky-50';
            const body = <><span className={`grid size-8 shrink-0 place-items-center rounded-lg ${tone}`}>{a.tone === 'info' ? <CircleAlert size={15}/> : <TriangleAlert size={15}/>}</span><span className="min-w-0 flex-1"><span className="block text-sm font-bold">{a.title}</span><span className="block truncate text-xs font-semibold text-[var(--muted)]">{a.detail}</span></span></>;
            return <li key={a.key} className="group flex items-center gap-2 border-b border-[var(--line)] px-4 py-3 last:border-0">
              {a.booking_id ? <button type="button" onClick={() => setDrawer(a.booking_id!)} className="flex min-w-0 flex-1 items-center gap-3 text-left">{body}</button> : <Link href={a.href || '#'} className="flex min-w-0 flex-1 items-center gap-3">{body}</Link>}
              <button type="button" onClick={() => dismiss([a.key])} aria-label="Dismiss" className="text-xs font-bold text-[var(--muted)] opacity-60 hover:opacity-100">✕</button>
            </li>;
          })}</ul>}
      </Card>
    </div>

    <div className="mt-5 grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <Card title={isToday ? "Today's guests" : isRange ? 'Arrivals and departures' : 'Guests'} subtitle="Front-desk queue, ordered by scheduled time"
        action={<div className="seg">{(['arrivals', 'departures'] as const).map(t => <button key={t} type="button" aria-pressed={tab === t} onClick={() => setTab(t)}>{t === 'arrivals' ? 'Arrivals' : 'Departures'}<span className="num rounded-md bg-white/70 px-1.5 text-[10px]">{(t === 'arrivals' ? day.arrivals : day.departures).length}</span></button>)}</div>}>
        <div className="flex flex-wrap gap-1.5 px-5 pt-4">{(['all', 'unpaid', 'direct', 'airbnb'] as const).map(f => <button key={f} type="button" className="chip" aria-pressed={filter === f} onClick={() => setFilter(f)}>{f === 'all' ? 'All' : f[0].toUpperCase() + f.slice(1)}</button>)}</div>
        {list.length === 0 ? <Empty icon={<Inbox size={18}/>} title={`No ${tab} ${when}`} body={<>Nothing on the books from this channel for {isRange ? rangeLabel : fmtDay(day.date, { weekday: 'long', month: 'short', day: 'numeric' })}. Clear the filter or search other dates in <Link href="/admin/bookings" className="font-bold text-ocean">Bookings</Link>.</>}/>
          : <ul className="mt-3">{list.map(row => {
            const canIn = tab === 'arrivals' && row.status === 'CONFIRMED';
            const canOut = tab === 'departures' && row.status === 'CHECKED_IN';
            return <li key={row.id} className="flex items-center gap-3 border-t border-[var(--line)] px-5 py-3 hover:bg-[#F8FAF7]">
              <span className="num w-16 shrink-0 text-xs font-semibold text-[var(--muted)]">{isRange && <span className="block text-[var(--ink)]">{short(tab === 'arrivals' ? row.check_in : row.check_out)}</span>}{fmtTime(row.time)}</span>
              <button type="button" onClick={() => setDrawer(row.id)} className="flex min-w-0 flex-1 items-center gap-3 text-left">
                <Avatar name={row.guest_name} size={32}/>
                <span className="min-w-0"><span className="block truncate text-sm font-bold">{row.guest_name}</span><span className="block truncate text-xs font-semibold text-[var(--muted)]">{row.unit} · {row.nights}n · {row.guests} pax</span></span>
              </button>
              <span className="hidden sm:inline"><SourceBadge source={row.source}/></span>
              <span className={`num hidden w-20 text-right text-sm font-semibold md:block ${row.balance > 0 ? 'text-[var(--danger)]' : 'text-emerald-700'}`}>{row.balance > 0 ? money(row.balance) : 'Paid'}</span>
              {canIn ? <button type="button" onClick={() => act(row, 'CHECKED_IN')} className="btn btn-navy btn-sm"><LogIn size={13}/>Check in</button>
                : canOut ? <button type="button" disabled={row.open_charges > 0} title={row.open_charges > 0 ? 'Settle room charges first' : undefined} onClick={() => act(row, 'CHECKED_OUT')} className="btn btn-navy btn-sm"><LogOut size={13}/>Check out</button>
                  : <Status value={row.status}/>}
            </li>;
          })}</ul>}
      </Card>

      <Card title="Cleaning" action={<Link href={`/admin/cleaning${day.date === today ? '' : `?date=${day.date}`}`} className="flex items-center gap-1 text-xs font-bold text-ocean">Turnaround timeline <ChevronRight size={14}/></Link>}>
        {cleaningLimited ? <div className="py-10"><Empty icon={<SprayCan size={18}/>} title="Choose a shorter period" body="Turnovers are listed for periods of up to 31 days. Open the turnaround timeline for any day."/></div>
          : cleaning.length === 0 ? <div className="py-10"><Empty icon={<SprayCan size={18}/>} title="No turnovers scheduled" body={`No cleaning is on the board ${when === 'this day' ? 'for this day' : when}.`}/></div>
          : <ul>{cleaning.map(t => <li key={t.id} className="flex items-center gap-3 border-b border-[var(--line)] px-5 py-3 last:border-0">
            <span className={`size-2.5 shrink-0 rounded-full ${t.status === 'DONE' ? 'bg-sea' : t.risk ? 'bg-[var(--danger)]' : t.status === 'IN_PROGRESS' ? 'bg-blue-600' : 'border-2 border-dashed border-amber-500'}`}/>
            <span className="min-w-0 flex-1"><span className="block truncate text-sm font-bold">{t.unit}</span><span className="block truncate text-xs font-semibold text-[var(--muted)]">{isRange && `${short(t.date)} · `}{t.cleaner ?? 'Unassigned'} · {t.checklist.length}/{t.checklist_total}</span></span>
            <span className="text-right text-xs font-semibold"><span className="block text-[var(--muted)]">ready by</span><span className="num">{fmtTime(t.ready_by)}</span></span>
          </li>)}</ul>}
      </Card>
    </div>
    <BookingDrawer id={drawer} onClose={() => setDrawer(null)}/>
  </DashboardShell>;
}
