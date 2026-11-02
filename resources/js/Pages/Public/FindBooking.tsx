import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, CalendarCheck2, CalendarDays, CalendarPlus, Check, History, LoaderCircle, Mail, Pencil, Phone, Search, Users, X } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import GoogleButton from '../../Components/GoogleButton';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';
import { forgetBooking, saveBooking, savedBookings, type SavedBooking } from '../../Components/myBookings';
import { money } from '../../types';

type Summary = { reference: string; property: string | null; facilities: string[]; event: boolean; check_in: string; check_out: string; guests: number; total: number; paid: number; stage: string; created_at: string };
type Guest = { name: string; email: string; role: string; phone: string | null; avatar?: string | null };

const STAGES: Record<string, [string, string]> = {
  requested: ['Waiting for our call', 'sky'],
  due: ['Pay your downpayment', 'amber'],
  rejected: ['Send a clearer receipt', 'rose'],
  reviewing: ['Checking your receipt', 'amber'],
  confirmed: ['Confirmed', 'green'],
  completed: ['Completed', 'slate'],
  closed: ['Cancelled or expired', 'slate'],
};
/** Where an upcoming booking is on the way to confirmed. */
const STEPS = ['Requested', 'Dates approved', 'Downpayment', 'Confirmed'];
const STEP_AT: Record<string, number> = { requested: 0, due: 1, rejected: 1, reviewing: 2, confirmed: 3 };
const day = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

async function lookup(items: SavedBooking[]): Promise<Summary[]> {
  const response = await fetch('/booking/summaries', { method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() }, body: JSON.stringify({ items }) });
  if (!response.ok) throw new Error(response.status === 429 ? 'Too many tries. Please wait a minute.' : 'Could not load your bookings. Please try again.');
  return (await response.json()).bookings as Summary[];
}

/**
 * My bookings. Signed in: the guest's details and every booking on their account, upcoming first and past stays
 * under History, each with its status. Not signed in: sign in to see them; a booking can still be opened with its
 * reference and mobile number.
 */
export default function FindBooking({ tenant, account = null }: { tenant: SiteTenant; account?: Summary[] | null }) {
  const { auth, googleLogin, guestLoginRequired, serverToday } = usePage<{ auth: { user: Guest | null }; googleLogin?: boolean; guestLoginRequired?: boolean; serverToday?: string }>().props;
  const guest = auth.user?.role === 'GUEST' ? auth.user : null;
  const today = serverToday ?? new Date().toISOString().slice(0, 10);
  const [saved, setSaved] = useState<Summary[] | null>(null);
  // Signed in: every booking on the account, plus any others opened on this phone.
  const onAccount = new Set((account ?? []).map(item => item.reference));
  const list = account ? [...account, ...(saved ?? []).filter(item => !onAccount.has(item.reference))] : saved;
  const past = (item: Summary) => ['completed', 'closed'].includes(item.stage) || item.check_out < today;
  const upcoming = (list ?? []).filter(item => !past(item)).sort((a, b) => a.check_in.localeCompare(b.check_in));
  const history = (list ?? []).filter(past).sort((a, b) => b.check_in.localeCompare(a.check_in));
  const [tab, setTab] = useState<'upcoming' | 'history'>('upcoming');
  const shown = tab === 'upcoming' ? upcoming : history;
  const [error, setError] = useState('');
  const [reference, setReference] = useState('');
  const [phone, setPhone] = useState('');
  const [finding, setFinding] = useState(false);
  const [findError, setFindError] = useState('');

  useEffect(() => {
    const remembered = savedBookings();
    if (remembered.length) lookup(remembered).then(setSaved).catch(e => { setError((e as Error).message); setSaved([]); });
    else setSaved([]);
    // Statuses follow along while the page is open (every 20 seconds, and straight away on coming back to it).
    const refresh = () => {
      if (document.hidden) return;
      if (account) router.reload({ only: ['account'] });
      if (savedBookings().length) lookup(savedBookings()).then(setSaved).catch(() => {});
    };
    const timer = window.setInterval(refresh, 20000);
    document.addEventListener('visibilitychange', refresh);
    return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', refresh); };
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  async function find(event: FormEvent) {
    event.preventDefault();
    setFinding(true); setFindError('');
    try {
      const [found] = await lookup([{ reference: reference.trim().toUpperCase(), phone }]);
      if (!found) { setFindError('We could not find a booking with that reference and mobile number.'); return; }
      saveBooking({ reference: found.reference, phone });
      setSaved(current => [found, ...(current ?? []).filter(item => item.reference !== found.reference)]);
      setTab(past(found) ? 'history' : 'upcoming');
      setReference(''); setPhone('');
    } catch (e) { setFindError((e as Error).message); } finally { setFinding(false); }
  }
  const remove = (ref: string) => { forgetBooking(ref); setSaved(current => current?.filter(item => item.reference !== ref) ?? null); };
  const query = tenant ? `?tenant=${encodeURIComponent(tenant.slug)}` : '';
  const signedOutWithAccounts = !auth.user && (googleLogin || guestLoginRequired);

  return <GuestShell tenant={tenant}>
    <Head title="My bookings"/>
    <div className="container max-w-4xl py-8 sm:py-14">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div><p className="ac-eyebrow">My bookings</p><h1 className="ac-title mt-3 !text-4xl sm:!text-5xl">{guest ? `Hi, ${guest.name.split(' ')[0]}` : 'Your bookings'}</h1></div>
        <Link href={`/book${query}`} className="ac-btn ac-btn-primary !min-h-11"><CalendarPlus size={18}/> Book a stay or event</Link>
      </div>

      {guest && <section className="ac-me" aria-label="Your details">
        {guest.avatar ? <img src={guest.avatar} alt="" referrerPolicy="no-referrer"/> : <span className="ac-me-initial">{guest.name.charAt(0).toUpperCase()}</span>}
        <div className="min-w-0 flex-1">
          <p className="ac-me-name">{guest.name}</p>
          <p className="ac-me-line"><Mail size={14}/><span className="truncate">{guest.email}</span></p>
          {guest.phone && <p className="ac-me-line"><Phone size={14}/>{guest.phone}</p>}
        </div>
        <a href="/account/complete?return=/booking" className="ac-me-edit"><Pencil size={15}/> Edit details</a>
      </section>}

      {signedOutWithAccounts && <section className="ac-signin-card">
        <span className="ac-signin-icon"><CalendarCheck2 size={26}/></span>
        <div className="min-w-0 flex-1">
          <h2>Sign in to see your bookings</h2>
          <p>All your bookings and their status in one place, on any phone. No booking number needed.</p>
        </div>
        {googleLogin ? <GoogleButton returnTo="/booking" className="w-full sm:w-auto"/>
          : <div className="flex w-full gap-2 sm:w-auto"><a href="/login?return=/booking" className="ac-btn ac-btn-primary !min-h-11 flex-1">Sign in</a><a href="/register?return=/booking" className="ac-btn ac-btn-outline !min-h-11 flex-1">Create account</a></div>}
      </section>}

      {(guest || (list && list.length > 0)) && <>
        <div className="ac-tabs" role="tablist" aria-label="Bookings">
          {([['upcoming', 'Upcoming', upcoming.length, CalendarDays], ['history', 'History', history.length, History]] as const).map(([key, label, count, Icon]) =>
            <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setTab(key)}><Icon size={16}/> {label}<span>{count}</span></button>)}
        </div>
        <section className="mt-4 grid gap-4" aria-live="polite">
          {list === null ? <p className="flex items-center gap-2 py-6 text-sm text-slate"><LoaderCircle size={16} className="animate-spin"/> Loading your bookings…</p>
            : shown.length === 0 ? <div className="ac-empty">
                {tab === 'upcoming' ? <CalendarDays size={28}/> : <History size={28}/>}
                <p className="font-semibold">{tab === 'upcoming' ? 'No upcoming bookings' : 'No past bookings yet'}</p>
                <p className="text-sm text-slate">{tab === 'upcoming' ? 'Your next stay or event shows up here as soon as you send a request.' : 'Stays and events you’ve had, and cancelled requests, are kept here.'}</p>
                {tab === 'upcoming' && <Link href={`/book${query}`} className="ac-btn ac-btn-outline mt-2 !min-h-11">Book now</Link>}
              </div>
            : shown.map(item => <BookingCard key={item.reference} item={item} isPast={past(item)} onRemove={onAccount.has(item.reference) ? undefined : () => remove(item.reference)}/>)}
          {error && <p role="alert" className="field-error">{error}</p>}
        </section>
      </>}

      <details className="ac-lookup" open={!signedOutWithAccounts && !guest && !(list && list.length)}>
        <summary><Search size={16}/> {guest ? 'Booked before you had an account?' : 'Have a booking reference?'}</summary>
        <form onSubmit={find} className="mt-4">
          <p className="text-sm text-slate">Enter the reference and the mobile number you booked with.</p>
          <div className="mt-4 grid gap-4 sm:grid-cols-2">
            <label className="field">Booking reference<input required autoComplete="off" placeholder="ACV-2026-XXXXXXXX" className="font-mono uppercase" value={reference} onChange={e => setReference(e.target.value.toUpperCase())}/></label>
            <label className="field">Mobile number<input required type="tel" inputMode="tel" autoComplete="tel" placeholder="0917 123 4567" value={phone} onChange={e => setPhone(e.target.value)}/></label>
          </div>
          {findError && <p role="alert" className="field-error mt-3">{findError}</p>}
          <button className="ac-btn ac-btn-outline mt-5 w-full sm:w-auto" disabled={finding}>{finding ? <LoaderCircle size={17} className="animate-spin"/> : <Search size={17}/>} Find booking</button>
        </form>
      </details>
    </div>
  </GuestShell>;
}

/** One booking: what, when, its status (with progress while it's upcoming) and the money side. */
function BookingCard({ item, isPast, onRemove }: { item: Summary; isPast: boolean; onRemove?: () => void }) {
  const stage = isPast && item.stage === 'confirmed' ? 'completed' : item.stage;
  const [label, tone] = STAGES[stage] ?? STAGES.closed;
  const at = STEP_AT[stage];
  const balance = Math.max(0, item.total - item.paid);
  const pay = ['due', 'rejected'].includes(stage);
  return <article className={`ac-trip tone-${tone}`}>
    <div className="ac-trip-head">
      <div className="min-w-0">
        <p className="ac-trip-ref">{item.reference}</p>
        <h2 className="ac-trip-title">{item.facilities.join(' + ')}</h2>
      </div>
      <span className={`ac-status tone-${tone}`}>{label}</span>
    </div>
    <div className="ac-trip-meta">
      <span><CalendarDays size={15}/>{item.event ? `Event · ${day(item.check_in)}` : `${day(item.check_in)} to ${day(item.check_out)}`}</span>
      <span><Users size={15}/>{item.guests} {item.guests === 1 ? 'guest' : 'guests'}</span>
    </div>
    {!isPast && at !== undefined && <ol className="ac-progress" aria-label={`Progress: ${STEPS[at]}`}>
      {STEPS.map((step, i) => <li key={step} className={i < at || stage === 'confirmed' ? 'done' : i === at ? 'now' : ''}><span>{i < at || stage === 'confirmed' ? <Check size={12}/> : i + 1}</span><small>{step}</small></li>)}
    </ol>}
    <div className="ac-trip-foot">
      <dl className="ac-trip-money">
        <div><dt>Total</dt><dd>{money(item.total)}</dd></div>
        {item.paid > 0 && <div><dt>Paid</dt><dd className="text-emerald-700">{money(item.paid)}</dd></div>}
        {item.paid > 0 && balance > 0 && !isPast && <div><dt>Balance</dt><dd>{money(balance)}</dd></div>}
      </dl>
      <div className="flex items-center gap-2">
        {onRemove && <button type="button" onClick={onRemove} className="grid size-10 place-items-center rounded-full text-slate hover:bg-slate-100" aria-label={`Remove ${item.reference} from this phone`} title="Remove from this phone"><X size={16}/></button>}
        <Link href={`/booking/success/${item.reference}`} className={`ac-btn !min-h-11 !px-5 !text-sm ${pay ? 'ac-btn-primary' : 'ac-btn-outline'}`}>{pay ? 'Pay & upload receipt' : 'View details'} <ArrowRight size={16}/></Link>
      </div>
    </div>
  </article>;
}
