import { usePage } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useEffect, useState, type KeyboardEvent, type ReactNode } from 'react';

export type DateRange = { start: string; end: string };
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const parse = (s: string) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d, 12); };
const add = (d: Date, days: number) => { const next = new Date(d); next.setDate(next.getDate() + days); return next; };
const label = (s: string) => s ? parse(s).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Select dates';
const same = (a: Date, b: Date) => iso(a) === iso(b);

/**
 * `split` shows separate Check-in and Check-out boxes that open the same calendar.
 * `single` picks one day (an event date): the range is that day to the next, so it is priced and held like one night.
 * `unavailable` lists fully booked nights: they are struck out and can't be picked, and a stay can't run across one
 * (its check-out may fall on a booked night, since the guest leaves that morning).
 */
export type Preset = 'today' | 'tomorrow' | 'next7' | 'next30' | 'week' | 'month' | 'lastMonth' | 'year';
const PRESET_LABELS: Record<Preset, string> = { today: 'Today', tomorrow: 'Tomorrow', next7: 'Next 7 days', next30: 'Next 30 days', week: 'This week', month: 'This month', lastMonth: 'Last month', year: 'This year' };

export default function DateRangePicker({ value, onChange, mode = 'booking', label: title = 'Dates', className = '', split = false, single = false, unavailable, trigger, presets = ['today', 'week', 'month', 'lastMonth', 'year'] }: {
  value: DateRange; onChange: (range: DateRange) => void; mode?: 'booking' | 'report'; label?: string; className?: string; split?: boolean; single?: boolean; unavailable?: ReadonlySet<string>;
  /** Replaces the built-in trigger button (report mode). */ trigger?: ReactNode; presets?: Preset[];
}) {
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<DateRange>(value);
  const [month, setMonth] = useState(() => { const date = value.start ? parse(value.start) : new Date(); return new Date(date.getFullYear(), date.getMonth(), 1); });
  useEffect(() => { if (open) { setDraft({ start: value.start, end: value.end }); setMonth(new Date((value.start ? parse(value.start) : new Date()).getFullYear(), (value.start ? parse(value.start) : new Date()).getMonth(), 1)); } }, [open, value.start, value.end]);
  // The resort's today from the server; the phone's own date only as a fallback.
  const today = usePage<{ serverToday?: string }>().props.serverToday ?? iso(new Date());
  const days = Array.from({ length: new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate() }, (_, i) => new Date(month.getFullYear(), month.getMonth(), i + 1, 12));
  const offset = (new Date(month.getFullYear(), month.getMonth(), 1).getDay() + 6) % 7;
  const booked = (date: string) => unavailable?.has(date) ?? false;
  const pickingEnd = !single && !!draft.start && !draft.end;
  // While choosing check-out, the stay can reach at most the first booked night after check-in.
  const limit = pickingEnd && unavailable ? [...unavailable].filter(night => night > draft.start).sort()[0] : undefined;
  const blocked = (date: string) => (mode === 'booking' && date < today) || (pickingEnd && date > draft.start ? (limit !== undefined && date > limit) : booked(date));
  function pick(date: Date) {
    const selected = iso(date);
    if (single) { setDraft({ start: selected, end: iso(add(date, 1)) }); return; }
    setDraft(!draft.start || draft.end || selected < draft.start ? { start: selected, end: '' } : { start: draft.start, end: selected });
  }
  function moveFocus(event: KeyboardEvent<HTMLButtonElement>, day: Date) {
    const delta = {ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7}[event.key as 'ArrowLeft'|'ArrowRight'|'ArrowUp'|'ArrowDown'];
    if (delta === undefined) return;
    event.preventDefault();
    const target = add(day, delta);
    if (mode === 'booking' && iso(target) < today) return;
    if (target.getMonth() !== month.getMonth() || target.getFullYear() !== month.getFullYear()) setMonth(new Date(target.getFullYear(), target.getMonth(), 1));
    requestAnimationFrame(() => document.querySelector<HTMLButtonElement>(`[data-calendar-date="${iso(target)}"]`)?.focus());
  }
  function preset(kind: Preset) {
    const now = new Date();
    const ranges: Record<Preset, DateRange> = {
      today: { start: iso(now), end: iso(now) },
      tomorrow: { start: iso(add(now, 1)), end: iso(add(now, 1)) },
      next7: { start: iso(now), end: iso(add(now, 6)) },
      next30: { start: iso(now), end: iso(add(now, 29)) },
      week: { start: iso(add(now, -((now.getDay() + 6) % 7))), end: iso(now) },
      month: { start: iso(new Date(now.getFullYear(), now.getMonth(), 1)), end: iso(now) },
      lastMonth: { start: iso(new Date(now.getFullYear(), now.getMonth() - 1, 1)), end: iso(new Date(now.getFullYear(), now.getMonth(), 0)) },
      year: { start: iso(new Date(now.getFullYear(), 0, 1)), end: iso(now) },
    };
    setDraft(ranges[kind]); setMonth(parse(ranges[kind].start));
  }
  return <Dialog.Root open={open} onOpenChange={setOpen}>
    {split && (['start', 'end'] as const).map(edge => <button key={edge} type="button" className={`date-trigger ${className}`} onClick={() => setOpen(true)} aria-label={`${edge === 'start' ? 'Check-in' : 'Check-out'}: ${value[edge] ? label(value[edge]) : 'select date'}`}><CalendarDays size={19} aria-hidden="true"/><span className="flex-1"><small>{edge === 'start' ? 'Check-in' : 'Check-out'}</small><strong>{value[edge] ? label(value[edge]) : 'Select date'}</strong></span><ChevronDown size={16} aria-hidden="true" className="shrink-0 opacity-70"/></button>)}
    {single && <Dialog.Trigger asChild><button type="button" className={`date-trigger ${className}`} aria-label={`${title}: ${value.start ? label(value.start) : 'select a date'}`}><CalendarDays size={19} aria-hidden="true"/><span><small>{title}</small><strong>{value.start ? label(value.start) : 'Select a date'}</strong></span></button></Dialog.Trigger>}
    {trigger && <Dialog.Trigger asChild>{trigger}</Dialog.Trigger>}
    {!trigger && !split && !single && <Dialog.Trigger asChild><button type="button" className={`date-trigger ${className}`} aria-label={`${title}: ${value.start ? label(value.start) : 'select start'} to ${value.end ? label(value.end) : 'select end'}`}><CalendarDays size={19} aria-hidden="true"/><span><small>{title}</small><strong>{value.start && value.end ? `${label(value.start)} to ${label(value.end)}` : 'Select date range'}</strong></span></button></Dialog.Trigger>}
    <Dialog.Portal><Dialog.Overlay className="date-overlay"/><Dialog.Content className="date-dialog" aria-describedby={undefined}>
      <div className="flex items-center justify-between gap-4"><div><Dialog.Title className="text-xl font-semibold">{title}</Dialog.Title><p className="mt-1 text-sm text-slate">{single ? 'Choose the day of your event.' : mode === 'booking' ? 'Choose check-in, then check-out.' : 'Choose the dates for this report.'}</p></div><Dialog.Close className="icon-button" aria-label="Close calendar"><X size={20}/></Dialog.Close></div>
      {mode === 'report' && <div className="mt-5 flex flex-wrap gap-2">{presets.map(key => [key, PRESET_LABELS[key]] as const).map(([key, text]) => <button key={key} type="button" className="date-preset" onClick={() => preset(key)}>{text}</button>)}</div>}
      <div className="mt-6 flex items-center justify-between"><button type="button" className="icon-button disabled:opacity-30" aria-label="Previous month" disabled={mode === 'booking' && iso(month) <= today.slice(0, 7) + '-01'} onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() - 1, 1))}><ChevronLeft size={20}/></button><strong>{month.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })}</strong><button type="button" className="icon-button" aria-label="Next month" onClick={() => setMonth(new Date(month.getFullYear(), month.getMonth() + 1, 1))}><ChevronRight size={20}/></button></div>
      <div className="date-grid mt-4">{['M','T','W','T','F','S','S'].map((day, i) => <span key={i} className="date-weekday">{day}</span>)}{Array.from({length: offset}, (_, i) => <span key={`blank-${i}`}/>)}{days.map(day => { const date = iso(day); const selected = date === draft.start || (!single && date === draft.end); const inRange = !single && !!draft.end && date > draft.start && date < draft.end; const disabled = blocked(date); const full = booked(date) && date >= today && !(pickingEnd && date === limit); return <button type="button" key={date} data-calendar-date={date} disabled={disabled} onClick={() => pick(day)} onKeyDown={event => moveFocus(event, day)} aria-label={`${day.toLocaleDateString('en-PH', { dateStyle: 'full' })}${full ? ', fully booked' : ''}`} aria-pressed={selected} title={full ? 'Fully booked' : undefined} className={`date-day ${selected ? 'selected' : ''} ${inRange ? 'in-range' : ''} ${same(day, new Date()) ? 'today' : ''} ${full ? 'booked' : ''}`}>{day.getDate()}</button>; })}</div>
      {unavailable && unavailable.size > 0 && <p className="date-legend mt-3"><span className="date-day booked" aria-hidden="true">8</span>Fully booked{single ? '' : '. Your stay can’t include a booked night.'}</p>}
      <div className="mt-6 flex items-center justify-between gap-3 border-t border-slate-200 pt-5"><span className="text-sm text-slate">{single ? (draft.start ? label(draft.start) : 'No date yet') : <>{draft.start ? label(draft.start) : 'Start'} → {draft.end ? label(draft.end) : 'End'}</>}</span><div className="flex gap-2"><button type="button" className="btn btn-light border border-slate-200" onClick={() => { onChange({start:'',end:''}); setOpen(false); }}>Clear</button><button type="button" className="btn btn-pink" disabled={!draft.start || !draft.end || (mode === 'booking' && draft.end === draft.start)} onClick={() => { onChange(draft); setOpen(false); }}>Apply</button></div></div>
    </Dialog.Content></Dialog.Portal>
  </Dialog.Root>;
}
