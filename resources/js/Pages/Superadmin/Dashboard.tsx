import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowRight, ArrowUpRight, Building2, CalendarCheck2, CircleDollarSign, Users } from 'lucide-react';
import { useState } from 'react';
import DashboardChart, { type ChartPoint } from '../../Components/DashboardChart';
import DashboardShell from '../../Components/DashboardShell';
import DateRangePicker, { type DateRange } from '../../Components/DateRangePicker';
import { Card, Empty, Status } from '../../Components/ops';
import { money } from '../../types';

type Tenant = { id: number; business_name: string; status: string; subscription_status: string; properties_count: number };
type Metrics = { active: number; trial: number; locked: number; suspended: number; newClients: number; subscriptions: number; bookings: number; bookingValue: number; mrr: number; arr: number };

/** "+12%" against the previous period of the same length; null when there is nothing to compare. */
function Delta({ now, before }: { now: number; before: number }) {
  if (!before) return null;
  const change = Math.round(((now - before) / before) * 100);
  const up = change >= 0;
  return <span className={`inline-flex items-center gap-0.5 rounded-full px-1.5 py-0.5 text-[11px] font-bold ${up ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>{up ? <ArrowUpRight size={12}/> : <ArrowDownRight size={12}/>}{Math.abs(change)}%</span>;
}

export default function Dashboard({ tenants, period, metrics, previous, series }: { tenants: Tenant[]; period: { from: string; to: string }; metrics: Metrics; previous: { newClients: number; subscriptions: number; bookings: number }; series: ChartPoint[] }) {
  const [range, setRange] = useState<DateRange>({ start: period.from, end: period.to });
  const cards = [
    [Building2, 'Active clients', String(metrics.active), null],
    [Users, 'New clients', String(metrics.newClients), [metrics.newClients, previous.newClients]],
    [CircleDollarSign, 'Subscription sales', money(metrics.subscriptions), [metrics.subscriptions, previous.subscriptions]],
    [CalendarCheck2, 'Bookings', String(metrics.bookings), [metrics.bookings, previous.bookings]],
  ] as const;
  return <DashboardShell platform title="Platform overview" subtitle="Your clients, subscriptions and booking activity in one view."
    actions={<DateRangePicker mode="report" label="Reporting period" value={range} onChange={next => { setRange(next); if (next.start && next.end) router.get('/superadmin', { from: next.start, to: next.end }, { preserveState: true }); else router.get('/superadmin'); }} className="w-full sm:w-64"/>}>
    <Head title="Platform overview"/>
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {cards.map(([Icon, label, value, compare]) => <div className="metric-card" key={label}>
        <div className="flex items-center justify-between"><small>{label}</small><span className="grid size-9 place-items-center rounded-xl bg-[var(--mint)] text-pink"><Icon size={18}/></span></div>
        <strong className="num">{value}</strong>
        <p className="mt-1 flex items-center gap-2 text-xs font-semibold text-[var(--muted)]">{compare ? <><Delta now={compare[0]} before={compare[1]}/>vs previous period</> : 'Live'}</p>
      </div>)}
    </div>
    <div className="mt-6 grid gap-6 xl:grid-cols-[2fr_1fr]">
      <DashboardChart data={series} lines={[{ key: 'subscriptions', name: 'Subscription sales', color: '#0070C0', money: true }, { key: 'bookings', name: 'Bookings', color: '#005A1C' }]}/>
      <Card title="Business health" subtitle="Live client state and recurring revenue">
        <dl className="grid px-5 py-2">{[['Monthly recurring revenue', money(metrics.mrr)], ['Annual run rate', money(metrics.arr)], ['Booking value', money(metrics.bookingValue)], ['Trial clients', metrics.trial], ['Locked clients', metrics.locked], ['Suspended clients', metrics.suspended]].map(([label, value]) => <div key={label} className="flex justify-between gap-3 border-b border-[var(--line)] py-3 text-sm last:border-0"><dt className="text-[var(--muted)]">{label}</dt><dd className="num font-bold">{value}</dd></div>)}</dl>
      </Card>
    </div>
    <Card className="mt-6" title="Recent clients" action={<Link href="/superadmin/clients" className="btn btn-light btn-sm">Manage clients <ArrowRight size={14}/></Link>}>
      {tenants.length ? <div className="overflow-x-auto"><table className="table w-full min-w-[600px]"><thead><tr><th>Business</th><th className="text-right">Properties</th><th>Subscription</th><th>Status</th></tr></thead>
        <tbody>{tenants.map(t => <tr key={t.id}><td className="font-bold">{t.business_name}</td><td className="num text-right">{t.properties_count}</td><td><Status value={t.subscription_status}/></td><td><Status value={t.status}/></td></tr>)}</tbody></table></div>
        : <Empty icon={<Building2 size={20}/>} title="No clients yet" body="Onboard your first business to start a 14-day trial." action={<Link href="/superadmin/clients" className="btn btn-pink">Onboard a client</Link>}/>}
    </Card>
  </DashboardShell>;
}
