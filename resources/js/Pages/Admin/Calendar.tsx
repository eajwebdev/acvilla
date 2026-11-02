import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, LayoutGrid, Lock, PanelRightClose, PanelRightOpen, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import BookingDrawer from '../../Components/BookingDrawer';
import DashboardShell from '../../Components/DashboardShell';
import NewBookingDialog from '../../Components/NewBookingDialog';
import { addDays, fmtDay, fmtTime, iso, parseDay, Status } from '../../Components/ops';

type Row = { id: number; guest_name: string; unit: string; room_id: number; check_in: string; check_out: string; check_in_time: string; check_out_time: string; status: string; balance: number; guests: number };
type Block = { id: number; room_id: number; unit: string; reason: string | null; starts_on: string; ends_on: string };
type Unit = { id: number; name: string; property: string; quantity: number; active: boolean };
type Props = { month: string; start: string; today: string; filters: { room_id?: number; date?: string }; bookings: Row[]; blocked: Block[]; rooms: Unit[]; inventory: number };

const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

export default function Calendar({ month, start, today, filters, bookings, blocked, rooms, inventory }: Props) {
  const { auth } = usePage<{ auth: { user: { role: string } } }>().props;
  const admin = auth.user.role === 'ADMIN';
  const [mode, setMode] = useState<'timeline' | 'occupancy'>('timeline');
  const [selected, setSelected] = useState(filters.date ?? (today.startsWith(month) ? today : `${month}-01`));
  const [panel, setPanel] = useState(true);
  const [drawer, setDrawer] = useState<number | null>(null);
  const [booking, setBooking] = useState(false);
  const days = useMemo(() => Array.from({ length: 42 }, (_, i) => addDays(start, i)), [start]);
  const monthDate = parseDay(`${month}-01`);
  const shift = (n: number) => { const d = new Date(monthDate.getFullYear(), monthDate.getMonth() + n, 1); visit({ month: iso(d).slice(0, 7) }); };
  const visit = (params: Record<string, string | number | undefined>) => router.get('/admin/calendar', { month, room_id: filters.room_id, ...params }, { preserveState: true, preserveScroll: true, replace: true });

  const stats = useMemo(() => Object.fromEntries(days.map(d => {
    const inHouse = bookings.filter(b => b.check_in <= d && b.check_out > d);
    const blocks = blocked.filter(b => b.starts_on <= d && b.ends_on > d);
    const vacant = Math.max(0, inventory - inHouse.length - blocks.length);
    return [d, { inHouse, arriving: bookings.filter(b => b.check_in === d), leaving: bookings.filter(b => b.check_out === d), blocks, vacant, occupancy: inventory ? Math.min(100, Math.round(100 * inHouse.length / inventory)) : 0 }];
  })), [days, bookings, blocked, inventory]);
  const sel = stats[selected] ?? { inHouse: [], arriving: [], leaving: [], blocks: [], vacant: inventory, occupancy: 0 };
  // Occupancy runs from pale to the logo's forest green: one hue, so "more" always reads as darker.
  const heat = (p: number) => p === 0 ? '#EDF2EB' : p < 34 ? '#DCEFD3' : p < 67 ? '#96CB80' : p < 100 ? '#2F7A16' : '#005A1C';

  return <DashboardShell bare>
    <Head title="Calendar"/>
    <div className={`grid min-h-[calc(100vh-3.5rem)] ${panel ? 'xl:grid-cols-[minmax(0,1fr)_300px]' : ''}`}>
      <div className="min-w-0 px-4 pb-6 md:px-5">
        <div className="page-head -mx-4 flex flex-wrap items-center gap-3 px-4 py-4 md:-mx-5 md:px-5">
          <div className="flex items-center gap-1">
            <button type="button" aria-label="Previous month" onClick={() => shift(-1)} className="grid size-8 place-items-center rounded-lg hover:bg-white"><ChevronLeft size={17}/></button>
            <h1 className="min-w-[150px] text-center font-serif text-[24px] font-semibold leading-[1.15] text-forest">{monthDate.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })}</h1>
            <button type="button" aria-label="Next month" onClick={() => shift(1)} className="grid size-8 place-items-center rounded-lg hover:bg-white"><ChevronRight size={17}/></button>
          </div>
          <button type="button" onClick={() => { setSelected(today); visit({ month: today.slice(0, 7) }); }} className="text-sm font-bold text-[var(--muted)] hover:text-[var(--ink)]">Today</button>
          <div className="seg"><button type="button" aria-pressed={mode === 'timeline'} onClick={() => setMode('timeline')}><CalendarDays size={15}/>Timeline</button><button type="button" aria-pressed={mode === 'occupancy'} onClick={() => setMode('occupancy')}><LayoutGrid size={15}/>Occupancy</button></div>
          <div className="flex w-full min-w-0 items-center gap-2 sm:ml-auto sm:w-auto">
            <select aria-label="Filter units" value={filters.room_id ?? ''} onChange={e => visit({ room_id: e.target.value || undefined })} className="min-h-[38px] min-w-0 flex-1 rounded-full border border-[var(--line)] bg-white px-3 text-sm font-semibold sm:w-40 sm:flex-none"><option value="">● All units</option>{rooms.map(r => <option key={r.id} value={r.id}>{r.name}{r.quantity > 1 ? ` (${r.quantity})` : ''}</option>)}</select>
            <button type="button" onClick={() => setBooking(true)} className="btn btn-pink shrink-0"><Plus size={16}/> New booking</button>
            <button type="button" onClick={() => setPanel(!panel)} aria-label={panel ? 'Hide day panel' : 'Show day panel'} className="hidden size-9 place-items-center rounded-lg border border-[var(--line)] bg-white xl:grid">{panel ? <PanelRightClose size={16}/> : <PanelRightOpen size={16}/>}</button>
          </div>
        </div>
        {mode === 'occupancy' && <p className="mb-2 flex items-center justify-end gap-2 text-xs font-semibold text-[var(--muted)]">Empty<span className="flex h-2.5 w-20 overflow-hidden rounded-full">{[0, 20, 50, 80, 100].map(p => <span key={p} className="flex-1" style={{ background: heat(p) }}/>)}</span>Full</p>}
        <div className="grid grid-cols-7 gap-1.5 sm:gap-2">
          {weekdays.map(w => <div key={w} className="py-1 text-center text-[11px] font-bold text-[var(--muted)]">{w}</div>)}
          {days.map(d => {
            const s = stats[d]; const inMonth = d.startsWith(month); const isToday = d === today; const isSel = d === selected;
            const weekend = [0, 6].includes(parseDay(d).getDay());
            return <button type="button" key={d} onClick={() => { setSelected(d); setPanel(true); }} onDoubleClick={() => { setSelected(d); setBooking(true); }}
              className={`relative flex min-h-[78px] flex-col overflow-hidden rounded-xl border p-1.5 text-left transition sm:min-h-[100px] sm:p-2.5 ${isSel ? 'border-forest shadow-[0_0_0_1px_var(--forest)]' : 'border-[var(--line)] hover:border-[#C9D6C6]'} ${inMonth ? '' : 'opacity-50'}`}
              style={{ background: mode === 'occupancy' && inMonth ? heat(s.occupancy) : inMonth ? '#fff' : '#F6F8F4' }}>
              <span className="flex items-center gap-2">{isToday ? <span className="grid size-7 place-items-center rounded-full bg-forest text-sm font-bold text-white">{Number(d.slice(8))}</span> : <span className={`text-sm font-bold ${!inMonth ? 'text-[#9AA59E]' : weekend && mode === 'timeline' ? 'text-[var(--muted)]' : ''} ${mode === 'occupancy' && s.occupancy >= 67 ? 'text-white' : ''}`}>{Number(d.slice(8))}</span>}{isToday && <span className="hidden text-[9px] font-bold uppercase tracking-wider sm:inline">Today</span>}</span>
              {mode === 'timeline' ? <span className="mt-1 grid gap-0.5">
                {s.arriving.slice(0, 2).map(b => <span key={`a${b.id}`} className="hidden truncate rounded-md bg-forest px-1.5 py-0.5 text-[10px] font-bold text-white sm:block">→ {b.guest_name}</span>)}
                {s.leaving.slice(0, 1).map(b => <span key={`l${b.id}`} className="hidden truncate rounded-md bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold text-rose-700 sm:block">← {b.guest_name}</span>)}
                {s.arriving.length + s.leaving.length > 3 && <span className="hidden text-[10px] font-bold text-[var(--muted)] sm:block">+{s.arriving.length + s.leaving.length - 3} more</span>}
                {s.blocks.length > 0 && <span className="hidden items-center gap-1 text-[10px] font-bold text-[var(--muted)] sm:flex"><Lock size={10}/>{s.blocks.length} blocked</span>}
              </span> : <span className={`num mt-auto text-xs font-semibold ${s.occupancy >= 67 ? 'text-white' : 'text-forest'}`}>{s.occupancy}%</span>}
              {mode === 'timeline' && inMonth && <span className="mt-auto pb-1 pt-1 text-[10px] font-bold text-[#3D4A43] sm:pb-0 sm:text-[11px]" title={`${s.vacant} vacant`}>{s.vacant}<span className="hidden sm:inline"> vacant</span></span>}
              {mode === 'timeline' && <span className="absolute inset-x-2 bottom-1 h-1 overflow-hidden rounded-full bg-[#E3EAE1]"><span className="block h-full rounded-full bg-sea" style={{ width: `${s.occupancy}%` }}/></span>}
            </button>;
          })}
        </div>
      </div>
      {panel && <aside className="border-t border-[var(--line)] bg-white p-4 xl:sticky xl:top-14 xl:h-[calc(100vh-3.5rem)] xl:overflow-y-auto xl:border-l xl:border-t-0">
        <div className="flex items-start justify-between"><div><p className="label">{parseDay(selected).toLocaleDateString('en-PH', { weekday: 'long' })}</p><h2 className="text-2xl font-bold">{fmtDay(selected)}</h2></div><span className="mt-6 text-xs font-bold">{sel.vacant} vacant</span></div>
        <div className="mt-3 grid grid-cols-3 gap-2 text-center">{([['in-house', sel.inHouse.length, ''], ['arriving', sel.arriving.length, ''], ['leaving', sel.leaving.length, 'text-[var(--danger)]']] as const).map(([label, n, tone]) => <div key={label} className="rounded-xl bg-[#F1F5F0] py-3"><p className={`num text-lg font-semibold ${tone}`}>{n}</p><p className="text-[10.5px] font-semibold text-[var(--muted)]">{label}</p></div>)}</div>
        <button type="button" onClick={() => setBooking(true)} disabled={selected < today} className="btn btn-soft mt-3 w-full"><Plus size={15}/>Book {fmtDay(selected)}</button>
        {[['Arriving', sel.arriving, 'check_in_time'], ['Leaving', sel.leaving, 'check_out_time'], ['In-house', sel.inHouse.filter(b => b.check_in !== selected), null]].map(([label, list, timeKey]) => (list as Row[]).length > 0 && <div key={label as string} className="mt-4"><p className="label mb-1.5">{label as string}</p><ul className="grid gap-1.5">{(list as Row[]).map(b => <li key={b.id}><button type="button" onClick={() => setDrawer(b.id)} className="flex w-full items-center justify-between gap-2 rounded-xl border border-[var(--line)] px-3 py-2 text-left hover:border-[#7FB08A]"><span className="min-w-0"><span className="block truncate text-sm font-bold">{b.guest_name}</span><span className="block truncate text-[11px] font-semibold text-[var(--muted)]">{b.unit}{timeKey ? ` · ${fmtTime(b[timeKey as 'check_in_time'])}` : ` · until ${fmtDay(b.check_out)}`}</span></span><Status value={b.status}/></button></li>)}</ul></div>)}
        {!sel.arriving.length && !sel.leaving.length && !sel.inHouse.length && <p className="mt-6 text-center text-sm font-semibold text-[var(--muted)]">No bookings on this day.</p>}
        {sel.blocks.length > 0 && <div className="mt-4"><p className="label mb-1.5">Blocked</p><ul className="grid gap-1.5">{sel.blocks.map(b => <li key={b.id} className="flex items-center justify-between gap-2 rounded-xl bg-[#F4F7F3] px-3 py-2 text-xs font-semibold"><span className="min-w-0"><span className="block truncate font-bold">{b.unit}</span><span className="text-[var(--muted)]">{b.reason} · {fmtDay(b.starts_on)} to {fmtDay(b.ends_on)}</span></span>{admin && <button type="button" aria-label="Remove block" onClick={() => confirm('Remove this block?') && router.delete(`/admin/calendar/blocks/${b.id}`, { preserveScroll: true, preserveState: true })} className="text-[var(--muted)] hover:text-[var(--danger)]"><Trash2 size={14}/></button>}</li>)}</ul></div>}
        {admin && rooms.length > 0 && <BlockForm rooms={rooms} date={selected < today ? today : selected}/>}
      </aside>}
    </div>
    <NewBookingDialog open={booking} onOpenChange={setBooking} defaultDate={selected} defaultUnit={filters.room_id ? Number(filters.room_id) : undefined}/>
    <BookingDrawer id={drawer} onClose={() => setDrawer(null)}/>
  </DashboardShell>;
}

function BlockForm({ rooms, date }: { rooms: Unit[]; date: string }) {
  const [open, setOpen] = useState(false);
  const [f, setF] = useState({ room_id: rooms[0].id, starts_on: date, ends_on: addDays(date, 1), reason: 'Owner block' });
  const [busy, setBusy] = useState(false);
  const submit = (e: FormEvent) => { e.preventDefault(); setBusy(true); router.post('/admin/calendar/blocks', f, { preserveScroll: true, preserveState: true, onSuccess: () => setOpen(false), onFinish: () => setBusy(false) }); };
  if (!open) return <button type="button" onClick={() => { setF(x => ({ ...x, starts_on: date, ends_on: addDays(date, 1) })); setOpen(true); }} className="mt-5 flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-[#C9D6C6] py-2.5 text-xs font-bold text-[var(--muted)] hover:text-[var(--ink)]"><Lock size={13}/>Block dates</button>;
  return <form onSubmit={submit} className="mt-5 grid gap-2 rounded-xl border border-[var(--line)] p-3">
    <p className="label">Block dates</p>
    <label className="field">Unit<select value={f.room_id} onChange={e => setF({ ...f, room_id: Number(e.target.value) })}>{rooms.filter(r => r.active).map(r => <option key={r.id} value={r.id}>{r.name}</option>)}</select></label>
    <div className="grid grid-cols-2 gap-2"><label className="field">From<input type="date" required value={f.starts_on} onChange={e => setF({ ...f, starts_on: e.target.value, ends_on: f.ends_on <= e.target.value ? addDays(e.target.value, 1) : f.ends_on })}/></label><label className="field">Until<input type="date" required min={addDays(f.starts_on, 1)} value={f.ends_on} onChange={e => setF({ ...f, ends_on: e.target.value })}/></label></div>
    <label className="field">Reason<input required maxLength={120} value={f.reason} onChange={e => setF({ ...f, reason: e.target.value })}/></label>
    <div className="flex gap-2"><button type="button" onClick={() => setOpen(false)} className="btn btn-light flex-1">Cancel</button><button disabled={busy} className="btn btn-navy flex-1">Block</button></div>
  </form>;
}
