import { Head, router, usePage } from '@inertiajs/react';
import { BedDouble, CalendarDays, Check, ChevronLeft, ChevronRight, Clock3, Gem, Images, LoaderCircle, Mail, Minus, PartyPopper, Phone, PhoneCall, Plus, QrCode, ShieldCheck, Users, type LucideIcon } from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState, type PointerEvent, type ReactNode } from 'react';
import DateRangePicker, { type DateRange } from '../../Components/DateRangePicker';
import GoogleButton from '../../Components/GoogleButton';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';
import { RoomDialog, roomPhotos } from '../../Components/RoomShowcase';
import { includedGuests, money, pricingLabel, rateUnit, time12, type AddOn, type Property, type Quote, type Room } from '../../types';

type Kind = 'stay' | 'event' | 'whole';
/** The hours a facility is free on the chosen dates, next to the guests before and after (from /availability/batch). */
type Window = { event: boolean; passed?: boolean; earliest: string; latest: string; default_start: string; default_end: string; after: { room: string | null; time: string } | null; before: { room: string | null; time: string } | null };
type Check = { available: boolean; reason: string | null; total: number | null; times: Window | null };
type Times = { start: string; end: string };
type Account = { name: string; email: string; role: string; phone: string | null; avatar?: string | null };
/** The request in progress, kept while the guest signs in with Google or adds their number, then picked up again. */
type Draft = { at: number; kind: Kind; dates: DateRange; guests: number; picked: Record<number, number>; times: Record<number, Times>; form: { type: string; callTime: string; message: string } };
const DRAFT = 'acv-book-draft';
function takeDraft(): Draft | null {
  try {
    const draft = JSON.parse(sessionStorage.getItem(DRAFT) || 'null') as Draft | null;
    sessionStorage.removeItem(DRAFT);
    return draft && Date.now() - draft.at < 2 * 3600 * 1000 ? draft : null;
  } catch { return null; }
}
type Selection = { check_in?: string; check_out?: string; guests?: number; type?: string; room?: number };

const STEPS = ['When', 'Choose', 'Your details', 'Review'] as const;
const KINDS: Record<Kind, { label: string; icon: LucideIcon; types: string[] }> = {
  stay: { label: 'Overnight stay', icon: BedDouble, types: ['stay', 'day_tour'] },
  event: { label: 'Event or celebration', icon: PartyPopper, types: ['celebration', 'wedding', 'corporate'] },
  whole: { label: 'Whole resort', icon: Gem, types: ['whole_resort', 'wedding'] },
};
const kindOf = (room: Room): Kind => room.whole_property ? 'whole' : room.kind === 'venue' ? 'event' : 'stay';
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const nextDay = (date: string) => { const d = new Date(`${date}T12:00:00`); d.setDate(d.getDate() + 1); return iso(d); };
const nightsBetween = (a: string, b: string) => Math.round((new Date(`${b}T12:00:00`).getTime() - new Date(`${a}T12:00:00`).getTime()) / 86400000);
const day = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
const PHONE = /^[0-9+()\-\s]{7,30}$/;
// Arrival, departure and event times are offered in half hours between these (the server uses the same limits).
const DAY_START = '06:00';
const DAY_END = '22:00';
const MIN_EVENT = 60;
const mins = (t: string) => Number(t.slice(0, 2)) * 60 + Number(t.slice(3, 5));
const clock = (m: number) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
const slots = (from: string, to: string) => { const list: string[] = []; for (let m = Math.ceil(mins(from) / 30) * 30; m <= mins(to); m += 30) list.push(clock(m)); return list; };
/** The choices for a facility's start and end, inside its free window. */
const startSlots = (w: Window) => slots(w.earliest, w.event ? clock(mins(w.latest) - MIN_EVENT) : DAY_END);
const endSlots = (w: Window, start: string) => w.event ? slots(clock(mins(start) + MIN_EVENT), w.latest) : slots(DAY_START, w.latest);
/** Keep the guest's times when they still fit the window, otherwise fall back to the first free standard hours. */
function fitTimes(w: Window, current?: Times): Times {
  const start = current && startSlots(w).includes(current.start) ? current.start : w.default_start;
  const ends = endSlots(w, start);
  const end = current && ends.includes(current.end) ? current.end : ends.includes(w.default_end) ? w.default_end : ends[ends.length - 1] ?? w.default_end;
  return { start, end };
}
/** Which step a server validation error belongs to, so the guest lands where they can fix it. */
const stepFor = (field: string) => ['check_in', 'check_out'].includes(field) ? 0 : field.startsWith('items') || ['room_id', 'guests', 'add_ons', 'promo_code', 'check_in_time', 'check_out_time'].includes(field) ? 1 : 2;

/**
 * The guided reservation request: pick what and when, choose one or more facilities with live availability and
 * prices, leave contact details, review and send. Nothing is paid now: the owner calls, approves the dates, and
 * the guest then pays the downpayment by GCash and uploads the receipt on their booking page.
 */
export default function Book({ property, tenant, bookable, selection = {}, addOns = [], requestTypes = {}, callTimes = {} }: {
  property: Property; tenant: SiteTenant; bookable: boolean; selection?: Selection; addOns?: AddOn[];
  requestTypes?: Record<string, string>; callTimes?: Record<string, string>;
}) {
  const rooms = property.rooms;
  const kinds = (Object.keys(KINDS) as Kind[]).filter(k => rooms.some(room => kindOf(room) === k));
  const preselected = rooms.find(room => room.id === selection.room);
  const [draft] = useState(takeDraft);
  const initialKind: Kind = draft && kinds.includes(draft.kind) ? draft.kind : preselected ? kindOf(preselected)
    : selection.type === 'whole_resort' && kinds.includes('whole') ? 'whole'
    : selection.type && KINDS.event.types.includes(selection.type) && kinds.includes('event') ? 'event' : kinds[0] ?? 'stay';
  const typeFor = (k: Kind) => KINDS[k].types.find(key => key in requestTypes) ?? Object.keys(requestTypes)[0] ?? '';

  const [kind, setKind] = useState<Kind>(initialKind);
  // Dates from an old link that are already past are dropped, so the guest starts by picking new ones.
  const serverToday = usePage<{ serverToday?: string }>().props.serverToday ?? iso(new Date());
  const draftDates = draft && draft.dates.start >= serverToday ? draft.dates : null;
  const linkDates = selection.check_in && selection.check_out && selection.check_in >= serverToday && selection.check_out > selection.check_in;
  const [dates, setDates] = useState<DateRange>(draftDates ?? (linkDates ? { start: selection.check_in!, end: selection.check_out! } : { start: '', end: '' }));
  const [guests, setGuests] = useState(draft?.guests ?? selection.guests ?? 2);
  // The chosen facilities, each with how many guests it is for.
  const [picked, setPicked] = useState<Record<number, number>>(draftDates ? draft!.picked : preselected ? { [preselected.id]: Math.min(selection.guests ?? 2, preselected.maximum_occupancy) } : {});
  const [step, setStep] = useState(draftDates ? 2 : linkDates ? 1 : 0);
  const [checks, setChecks] = useState<Record<number, Check>>({});
  // Arrival and departure (or event start and end) for each chosen facility.
  const [times, setTimes] = useState<Record<number, Times>>(draftDates ? draft!.times : {});
  const [checking, setChecking] = useState(false);
  const [quotes, setQuotes] = useState<Record<number, Quote>>({});
  const [extras, setExtras] = useState<Record<number, number>>({});
  const [promoInput, setPromoInput] = useState('');
  const [promo, setPromo] = useState('');
  const [promoError, setPromoError] = useState('');
  const [form, setForm] = useState({ name: '', phone: '', email: '', type: draft?.form.type ?? (selection.type && selection.type in requestTypes ? selection.type : typeFor(initialKind)), callTime: draft?.form.callTime ?? Object.keys(callTimes)[0] ?? '', message: draft?.form.message ?? '' });
  // With Google sign-in on, guests book from their account: its name, email and mobile number go on the request.
  const { auth, googleLogin, guestLoginRequired } = usePage<{ auth: { user: Account | null }; googleLogin?: boolean; guestLoginRequired?: boolean }>().props;
  const account = auth.user?.role === 'GUEST' ? auth.user : null;
  const accountMode = !!guestLoginRequired && (!auth.user || !!account);
  const saveDraft = () => { try { sessionStorage.setItem(DRAFT, JSON.stringify({ at: Date.now(), kind, dates, guests, picked, times, form: { type: form.type, callTime: form.callTime, message: form.message } } satisfies Draft)); } catch { /* private mode: the guest picks again */ } };
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [sending, setSending] = useState(false);
  const [viewing, setViewing] = useState<Room | null>(null);
  const set = (key: keyof typeof form, value: string) => { setForm(current => ({ ...current, [key]: value })); setErrors(({ [key]: _, ...rest }) => rest); };

  const isEvent = kind === 'event';
  const primary = useMemo(() => rooms.filter(room => kindOf(room) === kind), [rooms, kind]);
  // Rooms and halls can be added to each other; the whole resort already includes everything.
  const more = useMemo(() => kind === 'whole' ? [] : rooms.filter(room => kindOf(room) !== kind && kindOf(room) !== 'whole'), [rooms, kind]);
  const shown = useMemo(() => [...primary, ...more], [primary, more]);
  const shownKey = shown.map(room => room.id).join(',');
  const chosen = rooms.filter(room => picked[room.id] !== undefined);
  const chosenKey = chosen.map(room => `${room.id}:${picked[room.id]}`).join(',');
  // A big overnight group can fill several rooms, so its limit is all rooms together; a hall or the resort is one place.
  const maxGuests = Math.max(1, kind === 'stay' ? primary.reduce((sum, room) => sum + room.maximum_occupancy, 0) : Math.max(0, ...primary.map(room => room.maximum_occupancy)));
  const nights = dates.start && dates.end ? nightsBetween(dates.start, dates.end) : 0;
  const settings = tenant?.settings;
  // Booked nights per facility, so the calendar can strike out dates before the guest picks them.
  const [taken, setTaken] = useState<Record<number, string[]>>({});
  useEffect(() => {
    const ahead = new Date(); ahead.setDate(ahead.getDate() + Math.min(400, (settings?.booking_window_days ?? 365) + 1));
    fetch(`/availability/calendar?${new URLSearchParams({ property: property.slug, from: iso(new Date()), to: iso(ahead) })}`, { headers: { Accept: 'application/json' } })
      .then(response => response.ok ? response.json() : null).then(body => { if (body) setTaken(body.rooms); }).catch(() => {});
  }, [property.slug, settings?.booking_window_days]);
  // A date is fully booked for this kind of booking when every option of that kind is taken that night.
  const fullyBooked = useMemo(() => {
    const lists = primary.map(room => new Set(taken[room.id] ?? []));
    return lists.length ? new Set([...lists[0]].filter(night => lists.every(list => list.has(night)))) : new Set<string>();
  }, [taken, primary]);
  /** A hall is booked for the first day; rooms and the whole resort for the full stay (one night when it's an event). */
  const datesFor = (room: Room) => room.kind === 'venue' ? { start: dates.start, end: dates.start ? nextDay(dates.start) : '' } : { start: dates.start, end: dates.end };
  const lengthFor = (room: Room) => room.kind === 'venue' ? 'Event day' : `${nights} ${nights === 1 ? 'night' : 'nights'}`;
  const defaultGuests = (room: Room, current: Record<number, number>) => {
    if (room.kind === 'venue' || room.whole_property) return Math.min(guests, room.maximum_occupancy);
    // Rooms added to an event are for the few who stay over: start at the room's normal capacity.
    if (kind !== 'stay') return includedGuests(room);
    const placed = rooms.filter(r => current[r.id] !== undefined && r.kind !== 'venue').reduce((sum, r) => sum + current[r.id], 0);
    return Math.max(1, Math.min(room.maximum_occupancy, guests - placed > 0 ? guests - placed : includedGuests(room)));
  };
  const roomExtras = addOns.filter(addOn => chosen.some(room => !addOn.room_ids?.length || addOn.room_ids.includes(room.id)));
  const chosenExtras = Object.fromEntries(Object.entries(extras).filter(([id, qty]) => qty > 0 && roomExtras.some(addOn => addOn.id === Number(id))));
  const extrasKey = JSON.stringify(chosenExtras);

  // An event is one day: keep the range at that single day whenever events are chosen.
  useEffect(() => { if (isEvent && dates.start && dates.end !== nextDay(dates.start)) setDates({ start: dates.start, end: nextDay(dates.start) }); }, [isEvent, dates.start, dates.end]);
  useEffect(() => { if (guests > maxGuests) setGuests(maxGuests); }, [guests, maxGuests]);

  // Live availability and a starting price for every facility on show, in one request, a moment after the last
  // change (so holding + on the guest counter sends one check, not dozens).
  useEffect(() => {
    if (!dates.start || !dates.end) { setChecks({}); return; }
    const controller = new AbortController();
    setChecking(true);
    const timer = window.setTimeout(() => {
      const params = new URLSearchParams();
      shown.forEach((unit, i) => {
        const range = datesFor(unit);
        params.set(`items[${i}][room_id]`, String(unit.id)); params.set(`items[${i}][check_in]`, range.start); params.set(`items[${i}][check_out]`, range.end);
        params.set(`items[${i}][guests]`, String(Math.min(guests, unit.maximum_occupancy)));
      });
      fetch(`/availability/batch?${params}`, { signal: controller.signal, headers: { Accept: 'application/json' } }).then(async response => {
        const body = response.ok ? await response.json() as { room_id: number; available: boolean; message: string | null; total: number | null; times: Window | null }[] : [];
        const next = Object.fromEntries(shown.map(unit => { const row = body.find(r => r.room_id === unit.id);
          return [unit.id, row ? { available: row.available, reason: row.message, total: row.total, times: row.times } : { available: false, reason: response.status === 429 ? 'Too many checks: wait a moment' : 'Could not check right now', total: null, times: null }]; })) as Record<number, Check>;
        setChecks(next);
        setChecking(false);
        // Drop any pick that is no longer free; a single option (the whole resort) is picked for the guest.
        setPicked(current => {
          const kept = Object.fromEntries(Object.entries(current).filter(([id]) => next[Number(id)]?.available)) as Record<number, number>;
          if (!Object.keys(kept).length && primary.length === 1 && next[primary[0].id]?.available) kept[primary[0].id] = Math.min(guests, primary[0].maximum_occupancy);
          return kept;
        });
      }).catch(() => {});
    }, 300);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [shownKey, dates.start, dates.end, guests]); // eslint-disable-line react-hooks/exhaustive-deps

  // Exact prices for the chosen facilities with their guest counts; extras go on the first one that offers them, a promo on the first.
  useEffect(() => {
    if (!chosen.length || !chosen.every(room => checks[room.id]?.available)) { setQuotes({}); return; }
    const controller = new AbortController();
    const placed = new Set<number>();
    const timer = window.setTimeout(() => Promise.all(chosen.map(async (room, i): Promise<[number, Quote | null]> => {
      const range = datesFor(room);
      const params = new URLSearchParams({ room_id: String(room.id), check_in: range.start, check_out: range.end, guests: String(picked[room.id]), ...(promo && i === 0 ? { promo_code: promo } : {}) });
      Object.entries(JSON.parse(extrasKey) as Record<string, number>).forEach(([id, qty]) => {
        const addOn = addOns.find(a => a.id === Number(id));
        if (addOn && !placed.has(addOn.id) && (!addOn.room_ids?.length || addOn.room_ids.includes(room.id))) { placed.add(addOn.id); params.set(`add_ons[${id}]`, String(qty)); }
      });
      const response = await fetch(`/quote?${params}`, { signal: controller.signal, headers: { Accept: 'application/json' } });
      const body = await response.json().catch(() => ({}));
      if (!response.ok && body.errors?.promo_code) { setPromoError(body.errors.promo_code[0]); setPromo(''); }
      return [room.id, response.ok ? body : null];
    })).then(results => { if (!controller.signal.aborted) setQuotes(Object.fromEntries(results.filter(([, q]) => q)) as Record<number, Quote>); }).catch(() => {}), 300);
    return () => { window.clearTimeout(timer); controller.abort(); };
  }, [chosenKey, checks, dates.start, dates.end, promo, extrasKey]); // eslint-disable-line react-hooks/exhaustive-deps

  // Every chosen facility always has times that fit its free hours on the chosen dates.
  useEffect(() => {
    setTimes(current => {
      const next: Record<number, Times> = {};
      // Until the free hours are known (just after coming back from sign-in), the guest's times are kept as they were.
      chosen.forEach(room => { const w = checks[room.id]?.times; if (w) next[room.id] = fitTimes(w, current[room.id]); else if (current[room.id]) next[room.id] = current[room.id]; });
      return JSON.stringify(next) === JSON.stringify(current) ? current : next;
    });
  }, [chosenKey, checks]); // eslint-disable-line react-hooks/exhaustive-deps
  const setUnitTimes = (room: Room, change: Partial<Times>) => setTimes(current => {
    const w = checks[room.id]?.times;
    return w ? { ...current, [room.id]: fitTimes(w, { ...(current[room.id] ?? fitTimes(w)), ...change }) } : current;
  });
  const timeLabel = (room: Room) => { const t = times[room.id]; if (!t) return ''; return room.kind === 'venue' ? `${time12(t.start)} to ${time12(t.end)}` : `arrive ${time12(t.start)}, check out ${time12(t.end)}`; };

  function choose(next: Kind) {
    setKind(next);
    setPicked({});
    setExtras({});
    set('type', typeFor(next));
  }
  function toggle(room: Room) {
    setPicked(current => {
      if (current[room.id] !== undefined) { const { [room.id]: _, ...rest } = current; return rest; }
      // The whole resort can't be combined with single rooms or halls, and the other way round.
      const base = room.whole_property ? {} : Object.fromEntries(Object.entries(current).filter(([id]) => !rooms.find(r => r.id === Number(id))?.whole_property));
      return { ...base, [room.id]: defaultGuests(room, base) };
    });
  }
  const setUnitGuests = (room: Room, change: (n: number) => number) => setPicked(current => current[room.id] === undefined ? current : { ...current, [room.id]: Math.min(room.maximum_occupancy, Math.max(1, change(current[room.id]))) });

  const allFree = chosen.length > 0 && chosen.every(room => checks[room.id]?.available === true && !!times[room.id]);
  const ready = [
    !!dates.start && !!dates.end && nights > 0,
    allFree && !checking,
    accountMode ? !!account?.phone : form.name.trim().length > 1 && PHONE.test(form.phone.trim()) && /^\S+@\S+\.\S+$/.test(form.email.trim()),
    bookable,
  ];
  const hint = [
    isEvent ? 'Pick your event date to continue.' : 'Pick your check-in and check-out dates to continue.',
    checking ? 'Checking availability…' : 'Choose at least one available facility to continue.',
    'Add your name and a mobile number we can call.',
    'Bookings are temporarily unavailable.',
  ][step];

  function next() {
    if (!ready[step]) {
      if (step === 2 && accountMode) { setErrors({ account: account ? 'Add your mobile number to continue.' : googleLogin ? 'Sign in with Google to continue.' : 'Sign in or create an account to continue.' }); return; }
      if (step === 2) setErrors({ ...(!form.name.trim() ? { guest_name: 'Please enter your name.' } : {}), ...(!PHONE.test(form.phone.trim()) ? { guest_phone: 'Enter a mobile number we can call, e.g. 0917 123 4567.' } : {}), ...(!/^\S+@\S+\.\S+$/.test(form.email.trim()) ? { guest_email: form.email.trim() ? 'Check your email address.' : 'Enter your email: we send your booking updates there.' } : {}) });
      return;
    }
    if (step < 3) { setStep(step + 1); window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    setSending(true);
    router.post('/bookings', { items: chosen.map(room => ({ room_id: room.id, check_in: datesFor(room).start, check_out: datesFor(room).end, guests: picked[room.id],
        check_in_time: times[room.id]?.start, check_out_time: times[room.id]?.end })),
      guest_name: account?.name ?? form.name.trim(), guest_phone: account?.phone ?? form.phone.trim(), guest_email: account?.email ?? (form.email.trim() || null),
      request_type: form.type, call_time: form.callTime, message: form.message.trim() || null, promo_code: promo || null, add_ons: chosenExtras }, {
      onError: errs => { setErrors(errs as Record<string, string>); setStep(Math.min(...Object.keys(errs).map(stepFor), 3)); },
      onFinish: () => setSending(false),
    });
  }
  const back = () => { setStep(Math.max(0, step - 1)); window.scrollTo({ top: 0, behavior: 'smooth' }); };
  const priced = chosen.length > 0 && chosen.every(room => quotes[room.id] || checks[room.id]?.total);
  const total = priced ? chosen.reduce((sum, room) => sum + Number(quotes[room.id]?.total ?? checks[room.id]?.total ?? 0), 0) : null;
  const percent = settings?.deposit_percent ?? 100;
  const deposit = total !== null ? Math.round(total * percent) / 100 : null;
  const when = !dates.start ? null : isEvent ? day(dates.start) : dates.end ? `${day(dates.start)} to ${day(dates.end)}` : day(dates.start);
  const fromPrice = (k: Kind) => { const list = rooms.filter(r => kindOf(r) === k); const low = list.reduce((min, r) => Number(r.base_rate) < Number(min.base_rate) ? r : min, list[0]); return low ? `${k === 'whole' ? '' : 'From '}${money(low.base_rate)} ${rateUnit(low)}` : ''; };
  const summaryPhoto = chosen[0] ? roomPhotos(chosen[0], property.cover_image)[0] : property.cover_image;
  const errorList = Object.values(errors);

  const unitCard = (unit: Room) => {
    const check = checks[unit.id]; const free = check?.available === true; const on = picked[unit.id] !== undefined; const photos = roomPhotos(unit, property.cover_image);
    return <div key={unit.id} className={`ac-unit ${on ? 'selected' : ''} ${check && !free ? 'unavailable' : ''}`}>
      <button type="button" className="ac-unit-photo" onClick={() => setViewing(unit)} aria-label={`View ${photos.length} photos of ${unit.name}`}>{photos[0] && <img src={photos[0]} alt="" loading="lazy"/>}<span><Images size={13}/>{photos.length}</span></button>
      <div className="ac-unit-body">
        <button type="button" className="block w-full text-left disabled:cursor-not-allowed" disabled={!free} aria-pressed={on} onClick={() => toggle(unit)}>
          <span className="flex items-start justify-between gap-3"><strong className="ac-unit-name">{unit.name}</strong><span className={`ac-check ${on ? 'on' : ''}`} aria-hidden="true">{on && <Check size={14}/>}</span></span>
          <small className="block text-slate">{unit.kind === 'venue' ? `Up to ${unit.maximum_occupancy} guests · ${dates.start ? day(dates.start) : 'event day'}` : `Good for ${includedGuests(unit)} pax${unit.maximum_occupancy > includedGuests(unit) ? ` · up to ${unit.maximum_occupancy}` : ''}`}</small>
          {unit.description && <span className="mt-2 line-clamp-2 block text-sm text-[#56635C]">{unit.description}</span>}
          <span className="mt-3 flex flex-wrap items-end justify-between gap-2">
            {!check || checking ? <span className="ac-badge muted"><LoaderCircle size={13} className="animate-spin"/> Checking</span> : free ? <span className="ac-badge ok"><Check size={13}/> Available</span> : <span className="ac-badge no">{check.reason || 'Not available'}</span>}
            <span className="text-right">{free && (quotes[unit.id]?.total ?? check?.total) ? <><strong className="ac-unit-price">{money(quotes[unit.id]?.total ?? check!.total!)}</strong><small className="block text-slate">{lengthFor(unit)}</small></> : <><strong className="ac-unit-price">{money(unit.base_rate)}</strong> <small className="text-slate">{rateUnit(unit)}</small></>}</span>
          </span>
        </button>
        {on && check?.times && times[unit.id] && <UnitTimes room={unit} window={check.times} value={times[unit.id]} start={datesFor(unit).start} end={datesFor(unit).end} standardEnd={unit.kind === 'venue' ? settings?.event_end_time : undefined} onChange={change => setUnitTimes(unit, change)}/>}
        {on && <div className="ac-unit-guests"><span>Guests here</span><div className="ac-counter small"><HoldButton label={`Fewer guests in ${unit.name}`} disabled={picked[unit.id] <= 1} onStep={() => setUnitGuests(unit, n => n - 1)}><Minus size={14}/></HoldButton><output>{picked[unit.id]}</output><HoldButton label={`More guests in ${unit.name}`} disabled={picked[unit.id] >= unit.maximum_occupancy} onStep={() => setUnitGuests(unit, n => n + 1)}><Plus size={14}/></HoldButton></div></div>}
      </div>
    </div>;
  };

  return <GuestShell tenant={tenant}>
    <Head title={`Book | ${tenant?.business_name || property.name}`}/>
    <div className="ac-book container">
      <header className="ac-book-head">
        <p className="ac-eyebrow">Reservation request</p>
        <h1 className="ac-title mt-3">Book your visit</h1>
        <p className="mt-2 text-slate">Send your request now; pay the downpayment only after we confirm your dates.</p>
        <ol className="ac-stepper" aria-label="Booking steps">{STEPS.map((label, i) => <li key={label} aria-current={i === step ? 'step' : undefined} className={i < step ? 'done' : i === step ? 'current' : ''}>
          <button type="button" disabled={i > step} onClick={() => setStep(i)}><span>{i < step ? <Check size={14}/> : i + 1}</span>{label}</button>
        </li>)}
          <li className="later"><button type="button" disabled title="After we confirm your dates"><span><QrCode size={13}/></span>Downpayment</button></li>
        </ol>
      </header>

      <div className="ac-book-grid">
        <section className="ac-book-main" aria-live="polite">
          {step === 0 && <>
            <Panel title="What are you planning?">
              <div className="ac-kinds" role="radiogroup" aria-label="Type of booking">{kinds.map(k => { const { label, icon: Icon } = KINDS[k]; return <button key={k} type="button" role="radio" aria-checked={kind === k} onClick={() => choose(k)} className="ac-kind">
                <Icon size={24} strokeWidth={1.6}/><strong>{label}</strong><small>{fromPrice(k)}</small>{kind === k && <Check size={16} className="ac-kind-check"/>}
              </button>; })}</div>
            </Panel>
            <Panel title={isEvent ? 'When is your event?' : 'When are you staying?'} note={settings ? (isEvent ? `Standard hours ${time12(settings.event_start_time ?? '08:00')} to ${time12(settings.event_end_time ?? '17:00')}` : `Standard check-in ${time12(settings.check_in_time)} · check-out ${time12(settings.check_out_time)}`) + ' · pick your own times in the next step' : undefined}>
              <DateRangePicker single={isEvent} value={dates} onChange={setDates} label={isEvent ? 'Event date' : 'Check-in to check-out'} className="ac-book-date" unavailable={fullyBooked}/>
              {fullyBooked.size > 0 && <p className="mt-2 text-xs text-slate">Struck-out dates in the calendar are fully booked.</p>}
              {errors.check_in && <p className="field-error mt-2">{errors.check_in}</p>}
              {errors.check_out && <p className="field-error mt-2">{errors.check_out}</p>}
            </Panel>
            <Panel title="How many guests?" note={`Up to ${maxGuests} for ${KINDS[kind].label.toLowerCase()}`}>
              <div className="ac-counter"><HoldButton label="Fewer guests" disabled={guests <= 1} onStep={() => setGuests(g => Math.max(1, g - 1))}><Minus size={18}/></HoldButton>
                <label className="ac-counter-value"><Users size={18}/><input type="number" inputMode="numeric" min={1} max={maxGuests} aria-label="Number of guests" value={guests} onFocus={e => e.target.select()} onChange={e => { const n = parseInt(e.target.value, 10); if (!Number.isNaN(n)) setGuests(Math.min(maxGuests, Math.max(1, n))); }}/>{guests === 1 ? 'guest' : 'guests'}</label>
                <HoldButton label="More guests" disabled={guests >= maxGuests} onStep={() => setGuests(g => Math.min(maxGuests, g + 1))}><Plus size={18}/></HoldButton></div>
              <p className="mt-2 text-xs text-slate">Tip: hold − or + to count faster, or tap the number to type it.</p>
            </Panel>
          </>}

          {step === 1 && <>
            <Panel title={isEvent ? 'Choose your venue' : kind === 'whole' ? 'Your exclusive package' : 'Choose your rooms'} note={`${when} · ${guests} ${guests === 1 ? 'guest' : 'guests'}`}>
              {kind === 'stay' && primary.length > 1 && <p className="-mt-2 mb-3 text-sm text-slate">Big group? Tick as many rooms as you need.</p>}
              <div className="grid gap-3">{primary.map(unitCard)}</div>
            </Panel>
            {more.length > 0 && <Panel title={isEvent ? 'Add rooms for the night' : 'Add a hall for your celebration'} note="Optional">
              <div className="grid gap-3">{more.map(unitCard)}</div>
            </Panel>}
            {!checking && shown.length > 0 && primary.every(unit => checks[unit.id] && !checks[unit.id].available) && <div className="ac-book-alert">
              <p><strong>Nothing is free for {when}.</strong> Try other dates{kinds.length > 1 ? ' or another type of booking' : ''}, or call us and we will find something.</p>
              <button type="button" className="ac-btn ac-btn-outline mt-3 !min-h-11" onClick={() => setStep(0)}><CalendarDays size={17}/> Change dates</button>
            </div>}
            {(errors.items || errors.guests || errors.room_id) && <p className="field-error mt-3">{errors.items || errors.guests || errors.room_id}</p>}
          </>}

          {step === 2 && <>
            {accountMode && !account && <Panel title="Sign in to send your request">
              <p className="text-sm text-slate">{googleLogin ? 'Use your Google account' : 'Create a free account or sign in'}: your bookings are saved there, so you can check them on any phone without a booking number. You add your mobile number once.</p>
              {googleLogin ? <GoogleButton returnTo="/book" onLeave={saveDraft} className="mt-5 w-full sm:w-auto"/>
                : <div className="mt-5 flex flex-wrap gap-3"><a href="/register?return=/book" onClick={saveDraft} className="ac-btn ac-btn-primary !min-h-11">Create account</a><a href="/login?return=/book" onClick={saveDraft} className="ac-btn ac-btn-outline !min-h-11">Sign in</a></div>}
              <p className="mt-3 text-xs text-slate">Your dates and choices are kept while you sign in.</p>
              {errors.account && <p className="field-error mt-3">{errors.account}</p>}
            </Panel>}
            {account && !account.phone && <Panel title="Add your mobile number">
              <p className="text-sm text-slate">The owner calls this number to confirm your dates.</p>
              <a href="/account/complete?return=/book" onClick={saveDraft} className="ac-btn ac-btn-primary mt-4 !min-h-11"><Phone size={17}/> Add mobile number</a>
              {errors.account && <p className="field-error mt-3">{errors.account}</p>}
            </Panel>}
            {account?.phone && <Panel title="Booking as" note="The owner calls this number to confirm your dates.">
              <div className="ac-account-card">
                {account.avatar ? <img src={account.avatar} alt="" referrerPolicy="no-referrer"/> : <span>{account.name.charAt(0).toUpperCase()}</span>}
                <div className="min-w-0"><strong>{account.name}</strong><small><Mail size={13}/>{account.email}</small><small><Phone size={13}/>{account.phone}</small></div>
                <a href="/account/complete?return=/book" onClick={saveDraft}>Change</a>
              </div>
            </Panel>}
            <Panel title={accountMode ? 'About your visit' : 'How can we reach you?'} note={accountMode ? undefined : 'The owner calls this number to confirm your dates.'}>
              <div className="grid gap-4 sm:grid-cols-2">
                {!accountMode && <>
                <Field label="Full name" error={errors.guest_name}><input required autoComplete="name" value={form.name} onChange={e => set('name', e.target.value)}/></Field>
                <Field label="Mobile number" error={errors.guest_phone}><input type="tel" required autoComplete="tel" inputMode="tel" placeholder="0917 123 4567" value={form.phone} onChange={e => set('phone', e.target.value)}/></Field>
                <Field label="Email" error={errors.guest_email}><input type="email" autoComplete="email" required placeholder="you@example.com" value={form.email} onChange={e => set('email', e.target.value)}/><span className="mt-1 block text-xs text-slate">We email your booking updates here: approval, downpayment, confirmation.</span></Field>
                </>}
                {Object.keys(callTimes).length > 0 && <Field label="When should we call you?"><select value={form.callTime} onChange={e => set('callTime', e.target.value)}>{Object.entries(callTimes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select><span className="mt-1 block text-xs text-slate">For our call to confirm your dates. Your arrival and event times are set in step 2.</span></Field>}
                {Object.keys(requestTypes).length > 0 && <Field label="Occasion" error={errors.request_type}><select value={form.type} onChange={e => set('type', e.target.value)}>{Object.entries(requestTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>}
                <Field label={<>Message <span className="font-normal text-slate">(optional)</span></>} className="sm:col-span-2"><textarea maxLength={1000} rows={3} placeholder="Celebration details or special requests" value={form.message} onChange={e => set('message', e.target.value)}/></Field>
              </div>
            </Panel>
            {roomExtras.length > 0 && <Panel title="Add extras" note="Optional">
              <div className="grid gap-2">{roomExtras.map(addOn => { const qty = extras[addOn.id] || 0; return <div key={addOn.id} className={`ac-extra ${qty > 0 ? 'on' : ''}`}>
                <label className="flex min-w-0 flex-1 cursor-pointer items-start gap-3">{addOn.max_quantity === 1 && <input type="checkbox" className="mt-1 size-4 accent-[var(--ac-green)]" checked={qty > 0} onChange={e => setExtras({ ...extras, [addOn.id]: e.target.checked ? 1 : 0 })}/>}<span className="min-w-0"><strong className="block">{addOn.name}</strong><small className="block text-slate">{money(addOn.price)} {pricingLabel[addOn.pricing]}{addOn.description ? ` · ${addOn.description}` : ''}</small></span></label>
                {addOn.max_quantity > 1 && <div className="ac-counter small"><HoldButton label={`Fewer ${addOn.name}`} disabled={qty === 0} onStep={() => setExtras(x => ({ ...x, [addOn.id]: Math.max(0, (x[addOn.id] || 0) - 1) }))}><Minus size={14}/></HoldButton><output>{qty}</output><HoldButton label={`More ${addOn.name}`} disabled={qty >= addOn.max_quantity} onStep={() => setExtras(x => ({ ...x, [addOn.id]: Math.min(addOn.max_quantity, (x[addOn.id] || 0) + 1) }))}><Plus size={14}/></HoldButton></div>}
              </div>; })}</div>
            </Panel>}
            <details className="ac-promo"><summary>Have a promo code?</summary><div className="mt-3">{promo ? <p className="flex items-center justify-between gap-2 text-sm">Code <strong className="font-mono">{promo}</strong> applied<button type="button" onClick={() => { setPromo(''); setPromoInput(''); }} className="text-xs font-semibold text-slate underline">Remove</button></p>
              : <div className="flex gap-2"><input aria-label="Promo code" placeholder="Promo code" maxLength={20} className="ac-input flex-1 font-mono uppercase" value={promoInput} onChange={e => { setPromoInput(e.target.value.toUpperCase()); setPromoError(''); }}/><button type="button" disabled={!promoInput.trim()} onClick={() => setPromo(promoInput.trim())} className="ac-btn ac-btn-outline !min-h-11 !px-5">Apply</button></div>}
              {promoError && <p className="field-error mt-1.5">{promoError}</p>}</div></details>
          </>}

          {step === 3 && <>
            <Panel title="Check your request">
              <dl className="ac-review">
                <Row label={chosen.length > 1 ? 'Facilities' : chosen[0]?.kind === 'venue' ? 'Venue' : kind === 'whole' ? 'Package' : 'Room'} edit={() => setStep(1)}>{chosen.map(room => <span key={room.id} className="block">{room.name} <small className="!inline !text-slate">· {picked[room.id]} {picked[room.id] === 1 ? 'guest' : 'guests'} · {lengthFor(room)}</small><small className="!mt-0">{timeLabel(room)}</small></span>)}</Row>
                <Row label={isEvent ? 'Event date' : 'Dates'} edit={() => setStep(0)}>{when}<small>{isEvent ? 'Event day' : `${nights} ${nights === 1 ? 'night' : 'nights'}`}</small></Row>
                <Row label="Contact" edit={() => setStep(2)}>{account?.name ?? form.name}<small>{account ? `${account.phone} · ${account.email}` : `${form.phone}${form.email ? ` · ${form.email}` : ''}`}</small></Row>
                {(requestTypes[form.type] || callTimes[form.callTime]) && <Row label="Occasion" edit={() => setStep(2)}>{requestTypes[form.type] ?? 'Stay'}<small>{callTimes[form.callTime] ? `Call me: ${callTimes[form.callTime]}` : ''}</small></Row>}
                {form.message.trim() && <Row label="Message" edit={() => setStep(2)}><span className="whitespace-pre-line font-normal">{form.message.trim()}</span></Row>}
              </dl>
            </Panel>
            {total !== null && <Panel title="Price">
              <dl className="ac-price">
                {chosen.map(room => { const q = quotes[room.id]; return <Fragment key={room.id}>
                  <dt>{room.name} · {lengthFor(room)}</dt><dd>{money(q ? q.subtotal + q.extra_guest_fee : checks[room.id]?.total ?? 0)}</dd>
                  {q?.add_ons.map(line => <Fragment key={line.id}><dt className="pl-3">{line.name}{line.quantity > 1 ? ` ×${line.quantity}` : ''}</dt><dd>{money(line.amount)}</dd></Fragment>)}
                  {q?.discounts.map(line => <Fragment key={line.label}><dt className="pl-3 text-green-700">{line.label}</dt><dd className="text-green-700">−{money(line.amount)}</dd></Fragment>)}
                  {q && q.cleaning_fee + q.service_fee + q.tax > 0 && <><dt className="pl-3">Fees & taxes</dt><dd>{money(q.cleaning_fee + q.service_fee + q.tax)}</dd></>}
                </Fragment>; })}
                <dt className="total">Total</dt><dd className="total">{money(total)}</dd>
              </dl>
            </Panel>}
            {deposit !== null && <div className="ac-downpay-plan">
              <QrCode size={22}/>
              <div><strong>{percent < 100 ? `${percent}% downpayment: ${money(deposit)}` : `Payment: ${money(total!)}`}</strong>
                <p>Nothing to pay today. After we call and confirm your dates, open your booking page, pay by GCash (scan our QR) and upload or snap a photo of the receipt. We check it and your booking is confirmed.{percent < 100 ? ` The remaining ${money(total! - deposit)} is paid on arrival.` : ''}</p></div>
            </div>}
            {(settings?.house_rules || settings?.cancellation_policy) && <details className="ac-promo"><summary>House rules & cancellation</summary><div className="mt-3 grid gap-4 text-sm leading-6 text-slate sm:grid-cols-2">{settings.house_rules && <p className="whitespace-pre-line">{settings.house_rules}</p>}{settings.cancellation_policy && <p className="whitespace-pre-line">{settings.cancellation_policy}</p>}</div></details>}
            {errorList.length > 0 && <p role="alert" className="field-error mt-4">{errorList[0]}</p>}
          </>}

          <div className="ac-book-nav">
            {step > 0 ? <button type="button" className="ac-btn ac-btn-outline" onClick={back}><ChevronLeft size={18}/> Back</button> : <span/>}
            <div className="text-right">
              <button type="button" className="ac-btn ac-btn-primary" disabled={sending || (step < 2 && !ready[step]) || (step === 3 && !ready[3])} onClick={next}>{sending ? 'Sending…' : step === 3 ? <>Send reservation request <ChevronRight size={18}/></> : <>Continue <ChevronRight size={18}/></>}</button>
              {step < 2 && !ready[step] && <p className="mt-2 text-xs text-slate">{hint}</p>}
            </div>
          </div>
        </section>

        <aside className="ac-book-summary" aria-label="Your booking">
          {summaryPhoto && <img src={summaryPhoto} alt="" className="ac-summary-photo"/>}
          <p className="ac-eyebrow mt-4">{chosen.length ? KINDS[kind].label : 'Your booking'}</p>
          <h2 className="mt-1 font-serif text-xl font-semibold text-[var(--ac-green)]">{chosen.length ? chosen.map(room => room.name).join(' + ') : property.name}</h2>
          <ul className="ac-summary-list">
            <li><CalendarDays size={16}/>{when ?? 'Dates not chosen yet'}</li>
            <li><Users size={16}/>{guests} {guests === 1 ? 'guest' : 'guests'}</li>
            {chosen.filter(room => times[room.id]).map(room => <li key={room.id}><Clock3 size={16}/><span>{chosen.length > 1 ? `${room.name}: ` : ''}{timeLabel(room)}</span></li>)}
          </ul>
          {total !== null && <div className="ac-summary-total"><span>Total</span><strong>{money(total)}</strong></div>}
          {deposit !== null && percent < 100 && <p className="mt-2 text-xs text-slate">{percent}% downpayment ({money(deposit)}) after we confirm your dates; the rest on arrival.</p>}
          <p className="ac-summary-note"><ShieldCheck size={16}/><span><strong>Nothing to pay today.</strong> We call to confirm your dates, then you pay the downpayment by GCash.</span></p>
          {tenant?.business_phone && <a href={`tel:${tenant.business_phone.replace(/[^\d+]/g, '')}`} className="ac-summary-note link"><PhoneCall size={16}/><span>Prefer to talk? Call <strong>{tenant.business_phone}</strong></span></a>}
        </aside>
      </div>
    </div>

    <div className="ac-book-bar lg:hidden">
      <div className="min-w-0">{total !== null ? <><strong>{money(total)}</strong><small>{chosen.map(room => room.name).join(' + ')}</small></> : <><small>Step {step + 1} of 4</small><strong className="!text-base">{STEPS[step]}</strong></>}</div>
      <div className="flex gap-2">{step > 0 && <button type="button" className="ac-icon-btn inline-grid" aria-label="Back" onClick={back}><ChevronLeft size={20}/></button>}
        <button type="button" className="ac-btn ac-btn-primary !min-h-11 !px-5" disabled={sending || (step < 2 && !ready[step]) || (step === 3 && !ready[3])} onClick={next}>{sending ? 'Sending…' : step === 3 ? 'Send request' : 'Continue'}</button></div>
    </div>
    <RoomDialog room={viewing} fallbackPhoto={property.cover_image} open={!!viewing} onOpenChange={open => { if (!open) setViewing(null); }}/>
  </GuestShell>;
}

/**
 * A − / + button that keeps counting while it is held: one step on press, then repeats after a short
 * pause and speeds up, so going from 2 to 40 guests is one long press. Keyboard Enter/Space still steps once.
 */
export function HoldButton({ label, disabled, onStep, children }: { label: string; disabled: boolean; onStep: () => void; children: ReactNode }) {
  const timer = useRef<number | undefined>(undefined);
  const step = useRef(onStep);
  step.current = onStep;
  const stop = () => { window.clearTimeout(timer.current); timer.current = undefined; };
  useEffect(() => stop, []);
  useEffect(() => { if (disabled) stop(); }, [disabled]);
  const start = (event: PointerEvent<HTMLButtonElement>) => {
    if (event.button !== 0 || disabled) return;
    event.preventDefault(); // no text selection or long-press menu while holding
    stop();
    step.current();
    let delay = 140;
    const repeat = () => { step.current(); delay = Math.max(35, delay * 0.88); timer.current = window.setTimeout(repeat, delay); };
    timer.current = window.setTimeout(repeat, 420);
  };
  return <button type="button" aria-label={label} disabled={disabled} onPointerDown={start} onPointerUp={stop} onPointerLeave={stop} onPointerCancel={stop}
    onContextMenu={event => event.preventDefault()} onClick={event => { if (event.detail === 0) step.current(); }}>{children}</button>;
}

/** Arrival and check-out (or event start and end) for one chosen facility, limited to its free hours. */
function UnitTimes({ room, window: w, value, start, end, standardEnd, onChange }: { room: Room; window: Window; value: Times; start: string; end: string; standardEnd?: string; onChange: (change: Partial<Times>) => void }) {
  const short = (date: string) => new Date(`${date}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
  const notes = [
    w.after && `Free from ${time12(w.earliest)}: ${w.after.room ?? 'other'} guests leave at ${time12(w.after.time)} that day.`,
    !w.after && w.passed && `Today: times from ${time12(w.earliest)} on.`,
    w.before && `Must be free by ${time12(w.latest)}: ${w.before.room ?? 'other'} guests arrive at ${time12(w.before.time)} that day.`,
    w.event && standardEnd && value.end > standardEnd.slice(0, 5) && `Ending after ${time12(standardEnd)} may cost extra (day-to-night use).`,
  ].filter(Boolean) as string[];
  return <div className="ac-unit-times">
    <span className="ac-unit-times-label"><Clock3 size={15}/> {w.event ? 'Event time' : 'Times'}</span>
    <div className="ac-unit-times-fields">
      <label><small>{w.event ? 'Starts' : `Arrive ${short(start)}`}</small><select aria-label={`${w.event ? 'Start time' : 'Arrival time'} for ${room.name}`} value={value.start} onChange={e => onChange({ start: e.target.value })}>{startSlots(w).map(t => <option key={t} value={t}>{time12(t)}</option>)}</select></label>
      <label><small>{w.event ? 'Ends' : `Check out ${short(end)}`}</small><select aria-label={`${w.event ? 'End time' : 'Check-out time'} for ${room.name}`} value={value.end} onChange={e => onChange({ end: e.target.value })}>{endSlots(w, value.start).map(t => <option key={t} value={t}>{time12(t)}</option>)}</select></label>
    </div>
    {notes.map(note => <p key={note} className="ac-unit-times-note">{note}</p>)}
  </div>;
}

function Panel({ title, note, children }: { title: string; note?: string; children: ReactNode }) {
  return <div className="ac-panel"><div className="mb-4 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1"><h2>{title}</h2>{note && <p className="text-sm text-slate">{note}</p>}</div>{children}</div>;
}

function Field({ label, error, className = '', children }: { label: ReactNode; error?: string; className?: string; children: ReactNode }) {
  return <label className={`field ${className}`}><span>{label}</span>{children}{error && <span className="field-error">{error}</span>}</label>;
}

function Row({ label, edit, children }: { label: string; edit: () => void; children: ReactNode }) {
  return <div className="ac-review-row"><dt>{label}</dt><dd>{children}</dd><button type="button" onClick={edit}>Edit</button></div>;
}
