import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, BookOpen, Plus, Search } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import BookingDrawer from '../../Components/BookingDrawer';
import DashboardShell from '../../Components/DashboardShell';
import NewBookingDialog from '../../Components/NewBookingDialog';
import { Empty, fmtDay, SOURCE_GROUPS, SOURCES, SourceBadge, Status } from '../../Components/ops';
import { money } from '../../types';

type Row = { id: number; reference: string; guest_name: string; guest_phone: string | null; check_in: string; check_out: string; guests: number; status: string; payment_status: string; total: string; paid_amount: string | null; open_charges: string | null; source: string | null; group_code: string | null; room: { name: string } | null; property: { name: string } | null; payments: { id: number }[] };
type Filters = { tab?: string; source?: string; search?: string; sort?: string; dir?: 'asc' | 'desc'; status?: string; from?: string; to?: string; open?: number };
type Props = { bookings: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number }; filters: Filters; active: number };

const tabs = [['all', 'All'], ['requests', 'Requests to call'], ['unpaid', 'Unpaid'], ['paid', 'Paid'], ['direct', 'Direct'], ['airbnb', 'Airbnb'], ['cancelled', 'Cancelled']] as const;

export default function Bookings({ bookings, filters, active }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [drawer, setDrawer] = useState<number | null>(filters.open ? Number(filters.open) : null);
  const tab = filters.tab ?? 'all';
  const { requestCount = 0 } = usePage<{ requestCount?: number }>().props;
  const visit = (next: Partial<Filters>) => router.get('/admin/bookings', { ...filters, open: undefined, ...next }, { preserveState: true, preserveScroll: true, replace: true });
  useEffect(() => { if (filters.open) setDrawer(Number(filters.open)); }, [filters.open]);
  const submit = (e: FormEvent) => { e.preventDefault(); visit({ search: search || undefined }); };
  const sortBy = (key: string) => visit({ sort: key, dir: filters.sort === key && filters.dir !== 'desc' ? 'desc' : 'asc' });
  const SortHead = ({ k, label, right = false }: { k: string; label: string; right?: boolean }) => {
    const on = filters.sort === k; const Icon = !on ? ArrowUpDown : filters.dir === 'desc' ? ArrowDown : ArrowUp;
    return <th className={right ? 'text-right' : ''}><button type="button" onClick={() => sortBy(k)} className={`inline-flex items-center gap-1 ${on ? 'text-[var(--ink)]' : ''}`}>{label}<Icon size={11}/></button></th>;
  };
  const balance = (b: Row) => Math.max(0, Number(b.total) - Number(b.paid_amount || 0)) + Number(b.open_charges || 0);

  return <DashboardShell title="Bookings" subtitle={<span>{active} active · {bookings.total} shown</span>}
    actions={<>
      <form onSubmit={submit} className="flex items-center gap-2 rounded-xl border border-[var(--line)] bg-white px-3"><Search size={15} className="text-[var(--muted)]"/><input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search guest, unit or ref" aria-label="Search bookings" className="w-48 bg-transparent py-2 text-sm font-semibold outline-none"/></form>
      <NewBookingDialog trigger={<button type="button" className="btn btn-pink"><Plus size={16}/> New booking</button>}/>
    </>}>
    <Head title="Bookings"/>
    <div className="mb-4 flex flex-wrap gap-1.5">{tabs.map(([key, label]) => <button key={key} type="button" className="chip" aria-pressed={tab === key && !filters.status} onClick={() => visit({ tab: key === 'all' ? undefined : key, status: undefined })}>{label}{key === 'requests' && requestCount > 0 && <span className="num ml-1.5 rounded-full bg-amber-400 px-1.5 text-[10px] text-[#3a2a00]">{requestCount}</span>}</button>)}
      <select aria-label="Filter by source" value={filters.source ?? ''} onChange={e => visit({ source: e.target.value || undefined })} className={`rounded-full border px-3 py-1.5 text-xs font-bold ${filters.source ? 'border-forest bg-forest text-white' : 'border-[var(--line)] bg-white text-[#46544C]'}`}><option value="">All sources</option>{SOURCE_GROUPS.map(([group, keys]) => <optgroup key={group} label={group}>{keys.map(k => <option key={k} value={k}>{SOURCES[k]}</option>)}</optgroup>)}</select>
      {(filters.status || filters.from || filters.search || filters.source) && <button type="button" onClick={() => { setSearch(''); router.get('/admin/bookings'); }} className="px-2 text-xs font-bold text-ocean">Clear filters</button>}</div>
    <div className="card overflow-x-auto">
      <table className="table w-full min-w-[860px]">
        <thead><tr><SortHead k="guest" label="Guest"/><SortHead k="unit" label="Unit"/><SortHead k="stay" label="Stay"/><th>Source</th><th>Status</th><SortHead k="balance" label="Balance" right/></tr></thead>
        <tbody>
          {bookings.data.length === 0 && <tr><td colSpan={6}><Empty icon={<BookOpen size={18}/>} title="No bookings match" body="Try another tab or search, or add a booking for a walk-in guest."/></td></tr>}
          {bookings.data.map(b => { const due = balance(b); const cancelled = ['CANCELLED', 'EXPIRED', 'NO_SHOW'].includes(b.status); return <tr key={b.id} className="clickable" onClick={() => setDrawer(b.id)}>
            <td><span className="block font-bold">{b.guest_name}</span><span className="num block text-[11px] text-[var(--muted)]">{b.reference}{b.group_code ? ` · ${b.group_code}` : ''}</span></td>
            <td className="font-semibold">{b.room?.name}<span className="block text-[11px] font-semibold text-[var(--muted)]">{b.property?.name}</span></td>
            <td className="font-semibold text-[#3D4A43]">{fmtDay(b.check_in)} to {fmtDay(b.check_out)}<span className="block text-[11px] text-[var(--muted)]">{b.guests} {b.guests === 1 ? 'guest' : 'guests'}</span></td>
            <td><SourceBadge source={b.source}/></td>
            <td><span className="flex flex-wrap items-center gap-1"><Status value={b.status}/>{b.payments.length > 0 && <span className="pill border-amber-200 bg-amber-50 text-amber-800">verify payment</span>}</span></td>
            <td className={`num text-right font-semibold ${cancelled ? 'text-[var(--muted)]' : due > 0 ? 'text-[var(--danger)]' : 'text-emerald-700'}`}>{cancelled ? '-' : due > 0 ? money(due) : 'Paid'}</td>
          </tr>; })}
        </tbody>
      </table>
    </div>
    {bookings.links.length > 3 && <nav aria-label="Pagination" className="mt-4 flex flex-wrap justify-center gap-1.5">{bookings.links.map((link, i) => link.url ? <Link key={i} href={link.url} preserveScroll className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${link.active ? 'bg-forest text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }}/> : <span key={i} className="px-3 py-1.5 text-sm text-[var(--muted)]" dangerouslySetInnerHTML={{ __html: link.label }}/>)}</nav>}
    <BookingDrawer id={drawer} onClose={() => setDrawer(null)}/>
  </DashboardShell>;
}
