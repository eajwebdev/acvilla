import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChartLine, FileText, Receipt, Trash2, Wallet } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Area, Bar, BarChart, CartesianGrid, ComposedChart, Legend, Line, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import BookingDrawer from '../../Components/BookingDrawer';
import DashboardShell from '../../Components/DashboardShell';
import { axisMoney, Card, Empty, exactMoney, fmtDay, iso, SOURCE_COLORS } from '../../Components/ops';
import { money } from '../../types';

type Unit = { id: number; name: string; revenue: number; occupancy: number; nights: number; bookings: number; avg_stay: number | null; adr: number; signal: 'top' | 'low' | 'below' | 'healthy' };
type Analytics = { metrics: { revenue: number; previousRevenue: number; change: number | null; occupancy: number; occupiedNights: number; availableNights: number; adr: number; turnovers: number; turnoversPerDay: number; direct: number; directShare: number; days: number }; series: { date: string; revenue: number; occupancy: number; arrivals: number; departures: number; blocked: number }[]; units: Unit[]; weakest: (Unit & { gap: number }) | null; sources: { source: string; label: string; bookings: number; nights: number; value: number; share: number; direct: boolean }[] };
type Finance = { totals: { stay: number; pos: number; collected: number; expenses: number; net: number; outstanding: number; openRoomCharges: number }; byMethod: { method: string; label: string; total: number }[]; series: { date: string; income: number; expenses: number }[]; transactions: { type: 'payment' | 'pos' | 'expense'; date: string; label: string; amount: number; booking_id: number | null }[] };
type Expense = { id: number; spent_on: string; category: string; amount: string; description: string | null; room: { name: string } | null };
type Props = { tab: 'analytics' | 'finance' | 'expenses'; range: string; period: { from: string; to: string }; analytics: Analytics | null; finance: Finance | null; expenses: Expense[] | null; categories: string[]; rooms: { id: number; name: string }[] };

const ranges = [['month', 'This month'], ['7d', 'Last 7D'], ['30d', '30D'], ['90d', '90D']] as const;
const signal: Record<Unit['signal'], [string, string]> = { top: ['Top performer', 'bg-emerald-500'], healthy: ['Healthy', 'bg-sky-500'], below: ['Below portfolio', 'bg-amber-400'], low: ['Low occupancy', 'bg-amber-400'] };
const tick = { fontSize: 10, fontFamily: 'Jost', fill: '#64736B' };

export default function Insights({ tab, range, period, analytics, finance, expenses, categories, rooms }: Props) {
  const visit = (params: Record<string, string>) => router.get('/admin/insights', { tab, range, ...params }, { preserveScroll: true });
  const label = `${fmtDay(period.from, { month: 'long', day: 'numeric' })} to ${period.to === iso(new Date()) ? 'Today' : fmtDay(period.to, { month: 'long', day: 'numeric' })}`;
  return <DashboardShell title="Insights" subtitle={label}
    actions={<div className="seg dark">{([['analytics', 'Analytics', ChartLine], ['finance', 'Finance', Wallet], ['expenses', 'Expenses', Receipt]] as const).map(([key, text, Icon]) => <button key={key} type="button" aria-pressed={tab === key} onClick={() => visit({ tab: key })}><Icon size={15}/>{text}</button>)}</div>}>
    <Head title="Insights"/>
    <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div className="seg">{ranges.map(([key, text]) => <button key={key} type="button" aria-pressed={range === key} onClick={() => visit({ range: key })}>{text}</button>)}</div>
      <a href={`/admin/reports/export?from=${period.from}&to=${period.to}`} className="btn btn-light"><FileText size={15}/>Report (CSV)</a>
    </div>
    {analytics && <AnalyticsView data={analytics} label={label}/>}
    {finance && tab === 'finance' && <FinanceView data={finance}/>}
    {finance && tab === 'expenses' && expenses && <ExpensesView data={finance} expenses={expenses} categories={categories} rooms={rooms}/>}
  </DashboardShell>;
}

function AnalyticsView({ data, label }: { data: Analytics; label: string }) {
  const m = data.metrics;
  const maxRevenue = Math.max(...data.units.map(u => u.revenue), 1);
  return <>
    <div className="grid gap-px overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--line)] sm:grid-cols-2 lg:grid-cols-[1.6fr_repeat(4,1fr)]">
      <div className="bg-[#FAFBF8] p-5"><p className="num text-4xl font-semibold text-forest">{money(m.revenue)}</p><p className="mt-2 flex flex-wrap items-center gap-2 text-sm font-semibold">Revenue · {label}{m.change !== null && <span className={`num flex items-center text-xs ${m.change >= 0 ? 'text-emerald-700' : 'text-[var(--danger)]'}`}>{m.change >= 0 ? <ArrowUp size={12}/> : <ArrowDown size={12}/>}{Math.abs(m.change)}% vs prev {m.days}d</span>}</p></div>
      {[[`${m.occupancy}%`, 'Occupancy', `${m.occupiedNights} of ${m.availableNights} nights`], [money(m.adr), 'Avg / night', 'per booked night'], [String(m.turnovers), 'Turnovers', `${m.turnoversPerDay} per day`], [`${m.directShare}%`, 'Direct', `${money(m.direct)} fee-free`]].map(([v, l, s]) => <div key={l} className="bg-[#FAFBF8] p-5"><p className="num text-2xl font-semibold">{v}</p><p className="mt-1 text-sm font-semibold">{l}</p><p className="num text-[11px] text-[var(--muted)]">{s}</p></div>)}
    </div>
    <Card className="mt-5" title="Portfolio performance" subtitle={label} bodyClass="grid gap-5 p-5 lg:grid-cols-[minmax(0,1fr)_300px]">
      <div className="h-72">{m.revenue === 0 && data.series.every(p => p.occupancy === 0) ? <div className="grid h-full place-items-center rounded-xl bg-[#F8FAF7] text-sm font-semibold text-[var(--muted)]">No stays in this period yet.</div>
        : <ResponsiveContainer width="100%" height="100%"><ComposedChart data={data.series} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
          <defs><linearGradient id="rev" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#0070C0" stopOpacity={.25}/><stop offset="100%" stopColor="#0070C0" stopOpacity={0}/></linearGradient></defs>
          <CartesianGrid vertical={false} stroke="#EDF2EB"/>
          <XAxis dataKey="date" tick={tick} tickFormatter={d => fmtDay(d)} minTickGap={24} axisLine={false} tickLine={false}/>
          <YAxis yAxisId="r" tick={tick} tickFormatter={axisMoney} width={50} axisLine={false} tickLine={false}/>
          <YAxis yAxisId="o" orientation="right" domain={[0, 100]} tick={tick} tickFormatter={v => `${v}%`} width={38} axisLine={false} tickLine={false}/>
          <Tooltip labelFormatter={d => fmtDay(String(d), { weekday: 'short', month: 'short', day: 'numeric' })} formatter={(v, n) => n === 'Occupancy' ? `${v}%` : n === 'Revenue' ? money(Number(v)) : String(v)}/>
          <Legend iconType="plainline" wrapperStyle={{ fontSize: 11, fontWeight: 600 }}/>
          <Area yAxisId="r" type="monotone" dataKey="revenue" name="Revenue" stroke="#0070C0" strokeWidth={2} fill="url(#rev)"/>
          <Line yAxisId="o" type="monotone" dataKey="occupancy" name="Occupancy" stroke="#005A1C" strokeWidth={1.5} dot={false}/>
          <Bar yAxisId="o" dataKey="blocked" name="Owner block" fill="#C9D6C6" barSize={4}/>
        </ComposedChart></ResponsiveContainer>}</div>
      <div className="grid content-start gap-5 lg:border-l lg:border-[var(--line)] lg:pl-5">
        {data.weakest ? <div><p className="text-sm"><span className="mr-1.5 inline-block size-2 rounded-full bg-[var(--danger)]"/><strong className="num">{data.weakest.name}</strong> needs attention</p>
          <div className="mt-2 grid grid-cols-3 gap-2 text-xs"><div><p className="num text-base font-semibold">{data.weakest.occupancy}%</p><p className="text-[var(--muted)]">occupancy</p></div><div><p className="num text-base font-semibold">{Math.max(0, data.weakest.gap)} pts</p><p className="text-[var(--muted)]">below portfolio</p></div><div><p className="num text-base font-semibold">{money(data.weakest.revenue)}</p><p className="text-[var(--muted)]">revenue</p></div></div></div> : <p className="text-sm text-[var(--muted)]">Add units to compare performance.</p>}
        <div><p className="text-sm"><span className="mr-1.5 inline-block size-2 rounded-full bg-emerald-500"/><strong>Direct</strong> share of revenue</p>
          <div className="mt-2 grid grid-cols-3 gap-2 text-xs"><div><p className="num text-base font-semibold">{m.directShare}%</p><p className="text-[var(--muted)]">of revenue</p></div><div><p className="num text-base font-semibold">{money(m.direct)}</p><p className="text-[var(--muted)]">no channel fee</p></div><div><p className="num text-base font-semibold">{money(m.revenue - m.direct)}</p><p className="text-[var(--muted)]">via OTAs</p></div></div></div>
      </div>
    </Card>
    <Card className="mt-5" title="Where bookings come from" subtitle="Bookings made in this period, by channel" bodyClass="p-5">
      {data.sources.length === 0 ? <p className="text-sm font-semibold text-[var(--muted)]">No bookings made in this period yet.</p> : <>
        <div className="flex h-3 overflow-hidden rounded-full bg-[#EDF2EB]">{data.sources.map(s => <span key={s.source} title={`${s.label} ${s.share}%`} style={{ width: `${s.share}%`, background: SOURCE_COLORS[s.source] ?? '#B3BDB6' }}/>)}</div>
        <div className="mt-4 overflow-x-auto"><table className="table w-full min-w-[560px]"><thead><tr><th>Channel</th><th className="text-right">Bookings</th><th className="text-right">Share</th><th className="text-right">Nights</th><th className="text-right">Booked value</th></tr></thead><tbody>
          {data.sources.map(s => <tr key={s.source}><td><span className="flex items-center gap-2 font-semibold"><span className="size-2.5 rounded-full" style={{ background: SOURCE_COLORS[s.source] ?? '#B3BDB6' }}/>{s.label}{!s.direct && <span className="pill">OTA</span>}</span></td><td className="num text-right">{s.bookings}</td><td className="num text-right">{s.share}%</td><td className="num text-right">{s.nights}</td><td className="num text-right font-semibold">{money(s.value)}</td></tr>)}
        </tbody></table></div>
      </>}
    </Card>
    <Card className="mt-5" title="Unit performance" subtitle={`${data.units.length} units`}>
      <div className="overflow-x-auto"><table className="table w-full min-w-[760px]"><thead><tr><th>Unit</th><th>Revenue</th><th>Occupancy</th><th>ADR</th><th className="text-right">Avg stay</th><th>Signal</th></tr></thead><tbody>
        {data.units.length === 0 && <tr><td colSpan={6}><Empty icon={<ChartLine size={18}/>} title="No units yet"/></td></tr>}
        {data.units.map(u => <tr key={u.id}>
          <td className="num font-semibold">{u.name}<span className="block font-sans text-[11px] text-[var(--muted)]">{u.bookings} stays · {u.nights} nights</span></td>
          <td><span className="num text-sm">{money(u.revenue)}</span><span className="mt-1 block h-1 w-36 rounded-full bg-[#EDF2EB]"><span className="block h-full rounded-full bg-sea" style={{ width: `${100 * u.revenue / maxRevenue}%` }}/></span></td>
          <td><span className="num text-sm">{u.occupancy}%</span><span className="mt-1 block h-1 w-36 rounded-full bg-[#EDF2EB]"><span className="block h-full rounded-full bg-forest" style={{ width: `${u.occupancy}%` }}/></span></td>
          <td className="num text-sm">{money(u.adr)}</td>
          <td className="num text-right text-sm">{u.avg_stay ? `${u.avg_stay}n` : '-'}</td>
          <td><span className="flex items-center gap-2 text-xs font-semibold"><span className={`size-2 rounded-full ${signal[u.signal][1]}`}/>{signal[u.signal][0]}</span></td>
        </tr>)}
      </tbody></table></div>
    </Card>
  </>;
}

function Totals({ t }: { t: Finance['totals'] }) {
  return <div className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--line)] lg:grid-cols-5">
    {[['Collected', t.collected, `${money(t.stay)} stays · ${money(t.pos)} POS`], ['Expenses', -t.expenses, 'recorded this period'], ['Net', t.net, 'collected − expenses'], ['Outstanding', t.outstanding, 'open stay balances'], ['Room charges', t.openRoomCharges, 'POS on folios, unsettled']].map(([l, v, s]) => <div key={l as string} className="min-w-0 bg-[#FAFBF8] p-4"><p className="label">{l}</p><p className={`num mt-2 text-2xl font-semibold ${(v as number) < 0 ? 'text-[var(--danger)]' : ''}`}>{money(v as number)}</p><p className="mt-1 truncate text-[11.5px] font-semibold text-[var(--muted)]">{s}</p></div>)}
  </div>;
}

function FinanceView({ data }: { data: Finance }) {
  const [drawer, setDrawer] = useState<number | null>(null);
  const max = Math.max(...data.byMethod.map(m => m.total), 1);
  return <>
    <Totals t={data.totals}/>
    <div className="mt-5 grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <Card title="Cash flow" subtitle="Money in (stay payments + POS) against expenses, per day" bodyClass="h-72 p-4">
        <ResponsiveContainer width="100%" height="100%"><BarChart data={data.series} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}><CartesianGrid vertical={false} stroke="#EDF2EB"/><XAxis dataKey="date" tick={tick} tickFormatter={d => fmtDay(d)} minTickGap={24} axisLine={false} tickLine={false}/><YAxis tick={tick} tickFormatter={axisMoney} width={50} axisLine={false} tickLine={false}/><Tooltip labelFormatter={d => fmtDay(String(d))} formatter={v => money(Number(v))}/><Legend wrapperStyle={{ fontSize: 11, fontWeight: 600 }}/><Bar dataKey="income" name="Money in" fill="#0070C0" radius={[3, 3, 0, 0]}/><Bar dataKey="expenses" name="Expenses" fill="#005A1C" radius={[3, 3, 0, 0]}/></BarChart></ResponsiveContainer>
      </Card>
      <Card title="By payment method" bodyClass="p-5">
        {data.byMethod.length === 0 ? <p className="text-sm font-semibold text-[var(--muted)]">Nothing collected in this period.</p> : <ul className="grid gap-3">{data.byMethod.map(m => <li key={m.method}><div className="flex justify-between text-sm font-semibold"><span>{m.label}</span><span className="num">{money(m.total)}</span></div><div className="mt-1 h-1.5 rounded-full bg-[#EDF2EB]"><div className="h-full rounded-full bg-sea" style={{ width: `${100 * m.total / max}%` }}/></div></li>)}</ul>}
      </Card>
    </div>
    <Card className="mt-5" title="Transactions" subtitle="Latest 60 in this period">
      <div className="overflow-x-auto"><table className="table w-full min-w-[620px]"><thead><tr><th>Date</th><th>Type</th><th>Detail</th><th className="text-right">Amount</th></tr></thead><tbody>
        {data.transactions.length === 0 && <tr><td colSpan={4}><Empty icon={<Wallet size={18}/>} title="No transactions in this period"/></td></tr>}
        {data.transactions.map((t, i) => <tr key={i} className={t.booking_id ? 'clickable' : ''} onClick={() => t.booking_id && setDrawer(t.booking_id)}><td className="num text-xs">{new Date(t.date).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })}</td><td><span className="pill">{t.type === 'pos' ? 'POS' : t.type}</span></td><td className="font-semibold">{t.label}</td><td className={`num text-right font-semibold ${t.amount < 0 ? 'text-[var(--danger)]' : 'text-emerald-700'}`}>{t.amount < 0 ? '−' : '+'}{exactMoney(Math.abs(t.amount))}</td></tr>)}
      </tbody></table></div>
    </Card>
    <BookingDrawer id={drawer} onClose={() => setDrawer(null)}/>
  </>;
}

function ExpensesView({ data, expenses, categories, rooms }: { data: Finance; expenses: Expense[]; categories: string[]; rooms: { id: number; name: string }[] }) {
  const { auth } = usePage<{ auth: { user: { role: string } } }>().props;
  const admin = auth.user.role === 'ADMIN';
  const form = useForm({ spent_on: iso(new Date()), category: categories[0], amount: '', description: '', room_id: '' as number | '' });
  const submit = (e: FormEvent) => { e.preventDefault(); form.transform(d => ({ ...d, room_id: d.room_id || null, description: d.description || null })); form.post('/admin/insights/expenses', { preserveScroll: true, onSuccess: () => form.reset('amount', 'description') }); };
  const byCategory = categories.map(c => [c, expenses.filter(x => x.category === c).reduce((s, x) => s + Number(x.amount), 0)] as const).filter(([, v]) => v > 0).sort((a, b) => b[1] - a[1]);
  return <>
    <Totals t={data.totals}/>
    <div className="mt-5 grid gap-5 lg:grid-cols-[340px_minmax(0,1fr)]">
      <div className="grid content-start gap-5">
        {admin ? <form onSubmit={submit} className="card grid gap-3 p-5">
          <h2 className="font-bold">Record expense</h2>
          <div className="grid grid-cols-2 gap-2"><label className="field">Date<input type="date" required max={iso(new Date())} value={form.data.spent_on} onChange={e => form.setData('spent_on', e.target.value)}/></label><label className="field">Amount<input type="number" required min="0.01" step="0.01" value={form.data.amount} onChange={e => form.setData('amount', e.target.value)}/></label></div>
          <label className="field">Category<select value={form.data.category} onChange={e => form.setData('category', e.target.value)}>{categories.map(c => <option key={c}>{c}</option>)}</select></label>
          <label className="field">Unit (optional)<select value={form.data.room_id} onChange={e => form.setData('room_id', e.target.value ? Number(e.target.value) : '')}><option value="">Whole property</option>{rooms.map(r => <option key={r.id} value={r.id}>{r.name}</option>)}</select></label>
          <label className="field">Description<input maxLength={200} value={form.data.description} onChange={e => form.setData('description', e.target.value)} placeholder="e.g. Meralco bill, detergent"/></label>
          {Object.values(form.errors).map((m, i) => <p key={i} role="alert" className="text-xs font-semibold text-[var(--danger)]">{m}</p>)}
          <button disabled={form.processing} className="btn btn-pink">Save expense</button>
        </form> : <p className="card p-5 text-sm font-semibold text-[var(--muted)]">Only admins can record expenses.</p>}
        <Card title="By category" bodyClass="p-5">{byCategory.length === 0 ? <p className="text-sm text-[var(--muted)]">No expenses yet.</p> : <ul className="grid gap-2 text-sm">{byCategory.map(([c, v]) => <li key={c} className="flex justify-between font-semibold"><span>{c}</span><span className="num">{money(v)}</span></li>)}</ul>}</Card>
      </div>
      <Card title="Expenses" subtitle={`${expenses.length} in this period`}>
        <div className="overflow-x-auto"><table className="table w-full min-w-[560px]"><thead><tr><th>Date</th><th>Category</th><th>Detail</th><th className="text-right">Amount</th>{admin && <th/>}</tr></thead><tbody>
          {expenses.length === 0 && <tr><td colSpan={5}><Empty icon={<Receipt size={18}/>} title="No expenses recorded" body="Track utilities, supplies and repairs to see your real net."/></td></tr>}
          {expenses.map(x => <tr key={x.id}><td className="num text-xs">{fmtDay(x.spent_on)}</td><td className="font-semibold">{x.category}</td><td className="text-sm">{x.description || '-'}{x.room && <span className="block text-[11px] text-[var(--muted)]">{x.room.name}</span>}</td><td className="num text-right font-semibold">{exactMoney(x.amount)}</td>{admin && <td className="text-right"><button type="button" aria-label="Delete expense" onClick={() => confirm('Delete this expense?') && router.delete(`/admin/insights/expenses/${x.id}`, { preserveScroll: true })} className="text-[var(--muted)] hover:text-[var(--danger)]"><Trash2 size={15}/></button></td>}</tr>)}
        </tbody></table></div>
      </Card>
    </div>
  </>;
}
