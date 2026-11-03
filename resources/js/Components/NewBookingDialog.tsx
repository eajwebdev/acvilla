import * as Dialog from '@radix-ui/react-dialog';
import { router } from '@inertiajs/react';
import { CalendarDays, Check, Clock, LoaderCircle, Mail, Phone, UserRound, X } from 'lucide-react';
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { money } from '../types';
import { addDays, getJson, iso, nightsBetween, SOURCE_COLORS, SOURCE_GROUPS, SOURCES } from './ops';

type Unit = { id: number; name: string; property: string; max_guests: number; available: boolean; reason: string | null; total: number | null };
type Availability = { nights: number; units: Unit[]; check_in_time: string; check_out_time: string };

export default function NewBookingDialog({ trigger, defaultDate, defaultUnit, open: controlledOpen, onOpenChange }: { trigger?: ReactNode; defaultDate?: string; defaultUnit?: number; open?: boolean; onOpenChange?: (open: boolean) => void }) {
  const [innerOpen, setInnerOpen] = useState(false);
  const open = controlledOpen ?? innerOpen;
  const setOpen = (next: boolean) => { onOpenChange ? onOpenChange(next) : setInnerOpen(next); };
  const today = iso(new Date());
  const start = defaultDate && defaultDate >= today ? defaultDate : today;
  const blank = () => ({ group: false, guest_name: '', guest_phone: '', guest_email: '', check_in: start, check_out: addDays(start, 1), check_in_time: '', check_out_time: '', guests: 2, source: '', notes: '', confirm: true, units: defaultUnit ? [defaultUnit] : [] as number[] });
  const [form, setForm] = useState(blank);
  const [data, setData] = useState<Availability | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [showEmail, setShowEmail] = useState(false);
  const set = <K extends keyof ReturnType<typeof blank>>(key: K, value: ReturnType<typeof blank>[K]) => setForm(f => ({ ...f, [key]: value }));
  const nights = nightsBetween(form.check_in, form.check_out);

  useEffect(() => { if (open) { setForm(blank()); setErrors({}); setError(''); setShowEmail(false); } }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => {
    if (!open || nights < 1) { setData(null); return; }
    let live = true;
    setLoading(true); setError('');
    const timer = window.setTimeout(() => {
      getJson<Availability>(`/admin/bookings/availability?check_in=${form.check_in}&check_out=${form.check_out}&guests=${form.guests || 1}`)
        .then(res => { if (!live) return; setData(res); setForm(f => ({ ...f, check_in_time: f.check_in_time || res.check_in_time, check_out_time: f.check_out_time || res.check_out_time, units: f.units.filter(id => res.units.find(u => u.id === id)?.available) })); })
        .catch(e => live && setError(e.message)).finally(() => live && setLoading(false));
    }, 250);
    return () => { live = false; window.clearTimeout(timer); };
  }, [open, form.check_in, form.check_out, form.guests, nights]);

  const selected = useMemo(() => (data?.units ?? []).filter(u => form.units.includes(u.id)), [data, form.units]);
  const total = selected.reduce((sum, u) => sum + (u.total ?? 0), 0);
  const toggleUnit = (unit: Unit) => {
    if (!unit.available) return;
    set('units', form.group ? (form.units.includes(unit.id) ? form.units.filter(id => id !== unit.id) : [...form.units, unit.id]) : [unit.id]);
  };

  function submit(e: FormEvent) {
    e.preventDefault(); setErrors({});
    const problems: Record<string, string> = {};
    if (!form.guest_name.trim()) problems.guest_name = 'Enter the guest name.';
    if (nights < 1) problems.check_out = 'Check-out must be after check-in.';
    if (!form.units.length) problems.units = form.group ? 'Pick at least one unit.' : 'Pick a unit.';
    if (!form.source) problems.source = 'Pick where this booking came from.';
    if (Object.keys(problems).length) { setErrors(problems); return; }
    setBusy(true);
    router.post('/admin/bookings', {
      ...(form.units.length > 1 ? { room_ids: form.units } : { room_id: form.units[0] }),
      guest_name: form.guest_name.trim(), guest_phone: form.guest_phone.trim() || null, guest_email: form.guest_email.trim() || null,
      check_in: form.check_in, check_out: form.check_out, check_in_time: form.check_in_time || null, check_out_time: form.check_out_time || null,
      guests: form.guests, source: form.source, notes: form.notes.trim() || null, confirm: form.confirm,
    }, { preserveScroll: true, onSuccess: () => setOpen(false), onError: errs => setErrors(errs as Record<string, string>), onFinish: () => setBusy(false) });
  }

  const err = (key: string) => errors[key] && <p role="alert" className="mt-1 text-xs font-semibold text-[var(--danger)]">{errors[key]}</p>;
  const iconInput = (icon: ReactNode, input: ReactNode) => <div className="flex items-center gap-3 rounded-xl border border-[var(--line)] bg-white p-2 focus-within:border-[#5480F5]"><span className="grid size-9 shrink-0 place-items-center rounded-lg bg-[var(--mint)] text-ocean">{icon}</span>{input}</div>;

  return <Dialog.Root open={open} onOpenChange={setOpen}>
    {trigger && <Dialog.Trigger asChild>{trigger}</Dialog.Trigger>}
    <Dialog.Portal>
      <Dialog.Overlay className="tapp-overlay"/>
      <Dialog.Content className="tapp-dialog wide" aria-describedby={undefined}>
        <form onSubmit={submit}>
          <div className="flex items-center justify-between border-b border-[var(--line)] px-5 py-4"><Dialog.Title className="text-lg font-bold">New booking</Dialog.Title><Dialog.Close className="grid size-8 place-items-center rounded-lg border border-[var(--line)]" aria-label="Close"><X size={16}/></Dialog.Close></div>
          <div className="grid gap-4 px-5 py-4">
            <div className="grid grid-cols-2 gap-2">{[[false, 'Single guest'], [true, 'Group booking']].map(([value, label]) => <button key={String(value)} type="button" aria-pressed={form.group === value} onClick={() => setForm(f => ({ ...f, group: value as boolean, units: value ? f.units : f.units.slice(0, 1) }))} className={`rounded-xl border py-2.5 text-sm font-bold ${form.group === value ? 'border-forest bg-forest text-white' : 'border-[var(--line)] bg-white'}`}>{label as string}</button>)}</div>
            <div><p className="label mb-1.5">{form.group ? 'Group contact name' : 'Guest name'}</p>{iconInput(<UserRound size={17}/>, <input autoFocus value={form.guest_name} onChange={e => set('guest_name', e.target.value)} placeholder="Guest full name" maxLength={120} className="min-w-0 flex-1 bg-transparent py-1.5 text-[15px] font-semibold outline-none"/>)}{err('guest_name')}</div>
            <div><p className="label mb-1.5">Contact (optional)</p>{iconInput(<Phone size={17}/>, <input value={form.guest_phone} onChange={e => set('guest_phone', e.target.value)} placeholder="+63 9XX XXX XXXX or FB name" maxLength={120} className="min-w-0 flex-1 bg-transparent py-1.5 text-[15px] font-semibold outline-none"/>)}
              {showEmail ? <div className="mt-2">{iconInput(<Mail size={17}/>, <input type="email" value={form.guest_email} onChange={e => set('guest_email', e.target.value)} placeholder="guest@email.com (sends confirmation)" maxLength={255} className="min-w-0 flex-1 bg-transparent py-1.5 text-[15px] font-semibold outline-none"/>)}{err('guest_email')}</div>
                : <button type="button" onClick={() => setShowEmail(true)} className="mt-1.5 text-xs font-bold text-ocean">+ Add email for confirmation</button>}
            </div>
            <div>
              <p className="label mb-1.5">Stay</p>
              <div className="rounded-xl border border-[var(--line)]">
                <div className="grid grid-cols-2 gap-3 p-3">
                  <label className="text-xs font-semibold text-[var(--muted)]">Check-in date<span className="mt-1 flex items-center gap-2 rounded-lg border border-[var(--line)] bg-[#F7F6F3] px-2.5"><CalendarDays size={15}/><input type="date" min={today} value={form.check_in} onChange={e => { const v = e.target.value; setForm(f => ({ ...f, check_in: v, check_out: f.check_out <= v ? addDays(v, 1) : f.check_out })); }} className="min-w-0 flex-1 bg-transparent py-2 text-sm font-bold text-[var(--ink)] outline-none"/></span></label>
                  <label className="text-xs font-semibold text-[var(--muted)]">Check-out date<span className="mt-1 flex items-center gap-2 rounded-lg border border-[var(--line)] bg-[#F7F6F3] px-2.5"><CalendarDays size={15}/><input type="date" min={addDays(form.check_in, 1)} value={form.check_out} onChange={e => set('check_out', e.target.value)} className="min-w-0 flex-1 bg-transparent py-2 text-sm font-bold text-[var(--ink)] outline-none"/></span></label>
                  <label className="text-xs font-semibold text-[var(--muted)]">Check-in time<span className="mt-1 flex items-center gap-2 rounded-lg border border-[var(--line)] bg-[#F7F6F3] px-2.5"><Clock size={15}/><input type="time" value={form.check_in_time} onChange={e => set('check_in_time', e.target.value)} className="min-w-0 flex-1 bg-transparent py-2 text-sm font-bold text-[var(--ink)] outline-none"/></span></label>
                  <label className="text-xs font-semibold text-[var(--muted)]">Check-out time<span className="mt-1 flex items-center gap-2 rounded-lg border border-[var(--line)] bg-[#F7F6F3] px-2.5"><Clock size={15}/><input type="time" value={form.check_out_time} onChange={e => set('check_out_time', e.target.value)} className="min-w-0 flex-1 bg-transparent py-2 text-sm font-bold text-[var(--ink)] outline-none"/></span></label>
                </div>
                <div className="flex items-center justify-between border-t border-[var(--line)] px-3 py-2.5 text-sm font-bold"><span>Nights</span><span className="num">{nights}</span></div>
                <div className="flex items-center justify-between border-t border-[var(--line)] px-3 py-2 text-sm font-bold"><span>Guests {form.group && <small className="font-semibold text-[var(--muted)]">per unit</small>}</span><span className="flex items-center gap-2"><button type="button" aria-label="Fewer guests" onClick={() => set('guests', Math.max(1, form.guests - 1))} className="grid size-7 place-items-center rounded-lg border border-[var(--line)]">−</button><span className="num w-6 text-center">{form.guests}</span><button type="button" aria-label="More guests" onClick={() => set('guests', Math.min(100, form.guests + 1))} className="grid size-7 place-items-center rounded-lg border border-[var(--line)]">+</button></span></div>
              </div>
              {err('check_out')}{err('check_in')}{err('guests')}
            </div>
            <div>
              <div className="mb-1.5 flex items-center justify-between"><p className="label">{form.group ? 'Units' : 'Unit'}</p>{loading && <LoaderCircle size={14} className="animate-spin text-[var(--muted)]"/>}</div>
              {!data && !loading && <p className="text-xs font-semibold text-[var(--muted)]">{error || 'Select dates to check availability.'}</p>}
              {data && <div className="grid max-h-56 gap-1.5 overflow-y-auto">{data.units.map(unit => {
                const on = form.units.includes(unit.id); const fits = form.guests <= unit.max_guests;
                return <button type="button" key={unit.id} disabled={!unit.available} onClick={() => toggleUnit(unit)} aria-pressed={on} className={`flex items-center justify-between gap-3 rounded-xl border px-3 py-2.5 text-left ${on ? 'border-forest bg-[var(--mint)]' : 'border-[var(--line)] bg-white'} ${unit.available ? 'hover:border-[#5480F5]' : 'cursor-not-allowed opacity-50'}`}>
                  <span className="flex min-w-0 items-center gap-2.5"><span className={`grid size-5 shrink-0 place-items-center ${form.group ? 'rounded-md' : 'rounded-full'} border ${on ? 'border-forest bg-forest text-white' : 'border-[#D8D2C4]'}`}>{on && <Check size={12}/>}</span><span className="min-w-0"><span className="block truncate text-sm font-bold">{unit.name}</span><span className="block truncate text-[11px] font-semibold text-[var(--muted)]">{unit.available ? `${unit.property} · up to ${unit.max_guests}${fits ? '' : ' (too many guests)'}` : unit.reason}</span></span></span>
                  {unit.total !== null && <span className="num shrink-0 text-sm font-semibold">{money(unit.total)}</span>}
                </button>;
              })}{!data.units.length && <p className="text-xs text-[var(--muted)]">No units yet. Add one under Settings → Units.</p>}</div>}
              {err('units')}{err('room_id')}
            </div>
            <div><p className="label mb-1.5">Where did this booking come from?</p>
              <div className="grid gap-2">{SOURCE_GROUPS.map(([group, keys]) => <div key={group}><p className="mb-1 text-[11px] font-semibold text-[var(--muted)]">{group}</p><div className="flex flex-wrap gap-1.5">{keys.map(key => <button type="button" key={key} className="chip" aria-pressed={form.source === key} onClick={() => set('source', key)}><span className="size-2 rounded-full" style={{ background: SOURCE_COLORS[key] }}/>{SOURCES[key]}</button>)}</div></div>)}</div>
              {err('source')}</div>
            <label className="field">Notes (optional)<textarea value={form.notes} onChange={e => set('notes', e.target.value)} maxLength={1000} placeholder="Arrival details, requests, deposit agreement…" className="!min-h-[70px]"/></label>
            <label className="flex items-start gap-2.5 text-sm font-semibold"><input type="checkbox" checked={form.confirm} onChange={e => set('confirm', e.target.checked)} className="mt-0.5 size-4 accent-forest"/><span>Confirm now<small className="block text-xs font-semibold text-[var(--muted)]">Holds the unit right away; the balance can be collected later. Untick to keep it as an unpaid hold.</small></span></label>
          </div>
          <div className="sticky bottom-0 border-t border-[var(--line)] bg-[#FAF9F7] px-5 py-4">
            <div className="flex items-baseline justify-between"><span className="label">Total</span><span className="num text-2xl font-semibold text-forest">{money(total)}</span></div>
            <div className="mt-3 grid grid-cols-[1fr_2fr] gap-2"><Dialog.Close asChild><button type="button" className="btn btn-light">Cancel</button></Dialog.Close><button disabled={busy} className="btn btn-pink">{busy ? <LoaderCircle size={16} className="animate-spin"/> : <Check size={16}/>} Create booking</button></div>
          </div>
        </form>
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>;
}
