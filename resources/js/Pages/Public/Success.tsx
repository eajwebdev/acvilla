import { Head, Link, router } from '@inertiajs/react';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { CalendarPlus, Camera, ChevronDown, Check, CircleCheck, CircleX, Clock3, Copy, ImageUp, LoaderCircle, PhoneCall, Printer, QrCode, X } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';
import { refreshPage, useLive } from '../../Components/live';
import { saveBooking } from '../../Components/myBookings';
import { compressImage } from '../../Components/ops';
import { money, type Booking, type PaymentOption } from '../../types';

type Pending = { method: string; amount: string; payer_reference: string | null; option?: PaymentOption } | null;
type GroupItem = { id: number; reference: string; guests: number; status: string; payment_status: string; check_in: string; check_out: string; room: { name: string; kind: string; whole_property: boolean } | null; total: number };
type Stage = 'requested' | 'due' | 'rejected' | 'reviewing' | 'confirmed' | 'completed' | 'closed';
type Downpayment = { stage: Stage; amount: number; sent?: number; total: number; paid: number; percent: number; deadline: string | null; options: PaymentOption[] };

const day = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
const when = (iso: string) => new Date(iso).toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });

/** An all-day calendar entry spanning the stay; DTEND is the check-out date, exclusive as iCalendar expects. */
type CalendarLinks = { ics: string; google: string; outlook: string };

/**
 * "Add to calendar" with every option as a plain link, so it works in any browser (Safari, Chrome, Samsung Internet,
 * Facebook's in-app browser): the calendar file opens Apple Calendar on iPhone/iPad/Mac and the calendar app on Android
 * and Windows; Google Calendar and Outlook open in the browser. The phone's own calendar is offered first.
 */
function AddToCalendar({ links }: { links: CalendarLinks }) {
  const ua = typeof navigator === 'undefined' ? '' : navigator.userAgent;
  const apple = /iPhone|iPad|iPod|Macintosh/.test(ua);
  const android = /Android/.test(ua);
  const options = [
    { key: 'apple', href: links.ics, label: 'Apple Calendar', hint: 'iPhone, iPad, Mac', external: false },
    { key: 'google', href: links.google, label: 'Google Calendar', hint: android ? 'Your Android calendar' : 'Opens in the browser', external: true },
    { key: 'outlook', href: links.outlook, label: 'Outlook', hint: 'Outlook.com, Hotmail', external: true },
    { key: 'file', href: links.ics, label: 'Other calendar', hint: 'Download the .ics file', external: false },
  ].sort((a, b) => Number(b.key === (apple ? 'apple' : android ? 'google' : '')) - Number(a.key === (apple ? 'apple' : android ? 'google' : '')));
  return <DropdownMenu.Root modal={false}>
    <DropdownMenu.Trigger className="btn btn-light"><CalendarPlus size={17}/> Add to calendar <ChevronDown size={15}/></DropdownMenu.Trigger>
    <DropdownMenu.Portal><DropdownMenu.Content align="center" sideOffset={8} className="ac-menu ac-calendar-menu animate-fade">
      {options.map(o => <DropdownMenu.Item key={o.key} asChild>
        <a href={o.href} {...(o.external ? { target: '_blank', rel: 'noreferrer' } : { download: o.key === 'file' ? '' : undefined })}>
          <CalendarPlus size={16}/><span><strong>{o.label}</strong><small>{o.hint}</small></span>
        </a>
      </DropdownMenu.Item>)}
    </DropdownMenu.Content></DropdownMenu.Portal>
  </DropdownMenu.Root>;
}

const HEAD: Record<Stage, { icon: typeof PhoneCall; kicker: string; title: string; tone: string }> = {
  requested: { icon: PhoneCall, kicker: 'Reservation request sent', title: 'Thank you! We’ll call you soon.', tone: 'text-[#FFD84D]' },
  due: { icon: QrCode, kicker: 'Your dates are approved', title: 'Pay your downpayment to confirm', tone: 'text-[#FFD84D]' },
  rejected: { icon: CircleX, kicker: 'Receipt not accepted', title: 'Please send a clearer receipt', tone: 'text-amber-200' },
  reviewing: { icon: Clock3, kicker: 'Receipt received', title: 'We’re checking your payment', tone: 'text-amber-200' },
  confirmed: { icon: CircleCheck, kicker: 'Booking confirmed', title: 'See you at AC Villa!', tone: 'text-emerald-300' },
  completed: { icon: CircleCheck, kicker: 'Stay completed', title: 'Thank you for staying with us', tone: 'text-emerald-300' },
  closed: { icon: CircleX, kicker: 'Request closed', title: 'This request is no longer active', tone: 'text-white/70' },
};

/** The guest's booking page: where the request stands, and the downpayment step once the owner approves the dates. */
export default function Success({ booking, tenant, pendingPayment, group = [], downpayment, calendar }: {
  booking: Booking; tenant: SiteTenant; pendingPayment: Pending; amountPaid: number; balanceDue: number; group?: GroupItem[]; downpayment?: Downpayment; calendar?: CalendarLinks;
}) {
  const [copied, setCopied] = useState(false);
  // Keep this booking in "My bookings" on this phone.
  useEffect(() => { if (booking.guest_phone) saveBooking({ reference: booking.reference, phone: booking.guest_phone }); }, [booking.reference, booking.guest_phone]);
  // The owner approves the dates, checks the receipt, confirms: this page follows along without a refresh.
  useLive(`/booking/${booking.reference}/live`, refreshPage);
  const copy = () => navigator.clipboard?.writeText(booking.reference).then(() => { setCopied(true); window.setTimeout(() => setCopied(false), 2000); });
  const stage: Stage = downpayment?.stage ?? (pendingPayment ? 'reviewing' : booking.status === 'PENDING' ? 'requested' : 'confirmed');
  const head = HEAD[stage];
  const Icon = head.icon;
  const items = group.length ? group : [{ id: booking.id, reference: booking.reference, guests: booking.guests, status: booking.status, payment_status: booking.payment_status, check_in: booking.check_in, check_out: booking.check_out, room: { name: booking.room.name, kind: booking.room.kind ?? 'room', whole_property: !!booking.room.whole_property }, total: Number(booking.total) }];
  const total = downpayment?.total ?? Number(booking.total);
  const paid = downpayment?.paid ?? 0;
  const steps = ['Request sent', 'Dates confirmed', 'Downpayment', 'Booking confirmed'];
  const at = { requested: 1, due: 2, rejected: 2, reviewing: 2, confirmed: 4, completed: 4, closed: -1 }[stage];
  const home = `/${tenant ? `?tenant=${encodeURIComponent(tenant.slug)}` : ''}`;

  return <GuestShell tenant={tenant}><Head title={`Booking ${booking.reference}`}/>
    <div className="container flex min-h-[70vh] justify-center py-10 sm:py-16">
      <div className="print-area w-full max-w-3xl">
        <div className="surface-card overflow-hidden">
          <div className="relative isolate overflow-hidden px-6 pb-8 pt-10 text-center text-white sm:px-10" style={{ background: 'var(--guest-primary)' }}>
            <div className="absolute -right-16 -top-16 -z-10 size-56 rounded-full opacity-40 blur-3xl" style={{ background: 'var(--guest-accent)' }}/>
            <span className={`mx-auto grid size-16 place-items-center rounded-full bg-white/10 ${head.tone}`}><Icon size={30}/></span>
            <p className="mt-5 text-xs font-extrabold uppercase tracking-[.2em] text-white/60">{head.kicker}</p>
            <h1 className="ac-display mt-2 text-4xl font-bold sm:text-5xl">{head.title}</h1>
            <p className="mx-auto mt-4 max-w-lg leading-7 text-white/75">{{
              requested: <>The owner will call you at <strong className="text-white">{booking.guest_phone}</strong> to confirm your dates. Nothing to pay yet.</>,
              due: <>Send the {downpayment?.percent && downpayment.percent < 100 ? `${downpayment.percent}% ` : ''}downpayment by GCash, then upload or snap a photo of the receipt below.{downpayment?.deadline ? <> Your dates are held until <strong className="text-white">{when(downpayment.deadline)}</strong>.</> : ''}</>,
              rejected: <>We couldn’t match your last receipt to a payment. Please check the amount and upload a clear photo or screenshot.</>,
              reviewing: <>Thanks! Your dates stay held while we confirm the payment. We’ll call or message you once it’s verified.</>,
              confirmed: <>Your booking is confirmed.{booking.guest_email ? ' We’ve emailed the details.' : ''} Keep your reference for check-in.</>,
              completed: <>We hope you had a wonderful time. Your booking stays here in My bookings. We would love to host you again.</>,
              closed: <>This request was cancelled or not confirmed in time. You can send a new request any time.</>,
            }[stage]}</p>
          </div>

          <div className="p-6 sm:p-8">
            {at > 0 && <ol className="ac-track" aria-label="Booking progress">{steps.map((label, i) => <li key={label} className={i + 1 < at || stage === 'confirmed' || stage === 'completed' ? 'done' : i + 1 === at ? 'current' : ''} aria-current={i + 1 === at ? 'step' : undefined}><strong>{i + 1 < at || stage === 'confirmed' ? <Check size={13} className="mr-1 inline"/> : null}{label}</strong></li>)}</ol>}

            <div className="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-dashed border-[var(--border)] p-4">
              <div><p className="text-xs font-semibold text-slate">Booking reference</p><p className="mt-0.5 font-mono text-2xl font-semibold tracking-wider">{booking.reference}</p></div>
              <button type="button" onClick={copy} className="btn btn-light no-print min-h-10 px-3.5 text-sm">{copied ? <><Check size={16} className="text-emerald-600"/> Copied</> : <><Copy size={16}/> Copy</>}</button>
            </div>

            <ul className="mt-5 grid gap-2" aria-label="Your facilities">{items.map(item => <li key={item.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-[var(--surface-muted)] px-4 py-3 text-sm">
              <span><strong className="block">{item.room?.name}</strong><span className="text-slate">{item.room?.kind === 'venue' ? `Event day · ${day(item.check_in)}` : `${day(item.check_in)} to ${day(item.check_out)}`} · {item.guests} {item.guests === 1 ? 'guest' : 'guests'}</span>{['CANCELLED', 'EXPIRED'].includes(item.status) && <span className="ml-2 text-xs font-semibold text-rose-700">{item.status.toLowerCase()}</span>}</span>
              <strong>{money(item.total)}</strong>
            </li>)}</ul>

            {(stage === 'due' || stage === 'rejected') && downpayment && <DownpaymentForm reference={booking.reference} downpayment={downpayment}/>}
            {stage === 'reviewing' && <p className="mt-6 flex items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900"><Clock3 size={17}/>Receipt received{downpayment?.sent ? ` for ${money(downpayment.sent)}` : pendingPayment ? ` for ${money(pendingPayment.amount)}` : ''}. The owner is checking it.</p>}
            {stage === 'requested' && tenant?.business_phone && <p className="mt-6 text-center text-sm text-slate">Need us sooner? Call <a href={`tel:${tenant.business_phone.replace(/[^\d+]/g, '')}`} className="font-semibold text-navy underline-offset-2 hover:underline">{tenant.business_phone}</a> and mention your reference.</p>}

            <dl className="mt-6 grid gap-2 border-t border-[var(--border)] pt-5 text-sm">
              <div className="flex justify-between"><dt className="text-slate">Total</dt><dd className="text-lg font-semibold">{money(total)}</dd></div>
              {paid > 0 && <div className="flex justify-between"><dt className="text-slate">Paid</dt><dd className="font-semibold text-emerald-700">{money(paid)}</dd></div>}
              {paid > 0 && total - paid > 0.009 && <div className="flex justify-between"><dt className="text-slate">Balance (pay on arrival)</dt><dd className="font-semibold">{money(total - paid)}</dd></div>}
            </dl>
            <p className="no-print mt-4 text-center text-xs text-slate">This booking is saved in <Link href="/booking" className="font-semibold underline">My bookings</Link> on this phone. On another phone, find it with your reference and mobile number.</p>
          </div>
        </div>
        <div className="no-print mt-6 flex flex-wrap justify-center gap-3">
          {calendar && <AddToCalendar links={calendar}/>}
          <button type="button" onClick={() => window.print()} className="btn btn-light"><Printer size={17}/> Print</button>
          <Link href={home} className="btn btn-pink">Back to AC Villa</Link>
        </div>
      </div>
    </div>
  </GuestShell>;
}

/** Pay by GCash (scan the QR) or another channel, then attach the receipt: a fresh photo from the camera or a screenshot. */
function DownpaymentForm({ reference, downpayment }: { reference: string; downpayment: Downpayment }) {
  const [method, setMethod] = useState(downpayment.options.find(o => o.method === 'gcash')?.method ?? downpayment.options[0]?.method ?? '');
  const [payerRef, setPayerRef] = useState('');
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState('');
  const [busy, setBusy] = useState(false);
  const [preparing, setPreparing] = useState(false);
  const [error, setError] = useState('');
  const camera = useRef<HTMLInputElement>(null);
  const gallery = useRef<HTMLInputElement>(null);
  const option = downpayment.options.find(o => o.method === method);
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview); }, [preview]);

  async function pick(picked: File | undefined) {
    if (!picked) return;
    setError('');
    if (!picked.type.startsWith('image/') && !/\.(heic|heif)$/i.test(picked.name)) { setError('Please choose a photo or screenshot of the receipt.'); return; }
    setPreparing(true);
    const ready = await compressImage(picked);
    setPreparing(false);
    if (ready.size > 10 * 1024 * 1024) { setError('That photo is over 10 MB. Try a screenshot instead.'); return; }
    setFile(ready);
    setPreview(URL.createObjectURL(ready));
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    if (!file) { setError('Attach a photo or screenshot of your payment receipt.'); return; }
    setBusy(true); setError('');
    router.post(`/booking/${reference}/proof`, { method, payer_reference: payerRef.trim() || null, proof: file }, {
      forceFormData: true, preserveScroll: true,
      onError: errors => setError(Object.values(errors)[0] || 'The receipt could not be sent. Please try again.'),
      onFinish: () => setBusy(false),
    });
  }

  return <form onSubmit={submit} className="mt-7 grid gap-5" aria-labelledby="downpayment-title">
    <div className="ac-amount"><span id="downpayment-title">Downpayment to send{downpayment.percent < 100 ? ` (${downpayment.percent}%)` : ''}</span><strong>{money(downpayment.amount)}</strong></div>
    {downpayment.options.length > 1 && <div className="ac-methods" role="radiogroup" aria-label="How you will pay">{downpayment.options.map(o => <button key={o.method} type="button" role="radio" aria-pressed={method === o.method} aria-checked={method === o.method} onClick={() => setMethod(o.method)}>{o.label}</button>)}</div>}
    <div className="ac-pay">
      <div className="ac-qr">
        {option?.qr_image ? <img src={option.qr_image} alt={`${option.label} QR code`}/> : <div className="empty">{option?.account_number ? 'Send to the account number shown.' : 'The owner will give you the account on the call.'}</div>}
        {option?.qr_image && <a href={option.qr_image} download className="text-xs font-semibold text-[var(--ac-blue)] underline">Save QR to phone</a>}
      </div>
      <div className="grid content-start gap-2 text-sm">
        <p className="text-base font-semibold text-[#141C33]">{option?.label ?? 'Payment'}</p>
        {option?.account_name && <p className="ac-payee"><span>{option.qr_image ? 'Name shown when you scan' : 'Account name'}</span><strong>{option.account_name}</strong>{option.qr_image && <small>Check that this name appears before you send.</small>}</p>}
        {option?.account_number && <p><span className="text-slate">Number: </span><strong className="font-mono text-base">{option.account_number}</strong></p>}
        <ol className="mt-1 grid list-decimal gap-1 pl-5 text-slate">
          <li>{method === 'gcash' ? 'Open GCash, Maya or your bank app' : `Open ${option?.label ?? 'your app'}`} and {option?.qr_image ? 'scan the QR (or save it and upload it in the app)' : 'send to the account above'}.</li>
          <li>Send exactly <strong className="text-[#141C33]">{money(downpayment.amount)}</strong>.</li>
          <li>Take a screenshot or photo of the receipt and attach it below.</li>
        </ol>
        {option?.instructions && <p className="whitespace-pre-line text-xs text-slate">{option.instructions}</p>}
      </div>
    </div>

    <label className="field">Reference number <span className="font-normal text-slate">(optional, from your receipt)</span><input maxLength={100} value={payerRef} onChange={e => setPayerRef(e.target.value)} placeholder="e.g. 1012 345 678901"/></label>

    <div className={`ac-proof ${file ? 'has' : ''}`}>
      <p className="font-semibold text-[#141C33]">Proof of payment</p>
      {preview ? <div className="relative"><img src={preview} alt="Your receipt"/><button type="button" className="absolute right-2 top-2 grid size-9 place-items-center rounded-full bg-white/95 shadow" aria-label="Remove photo" onClick={() => { setFile(null); setPreview(''); }}><X size={17}/></button></div>
        : <div className="ac-proof-actions">
          <button type="button" className="ac-btn ac-btn-outline !min-h-12 !px-3 !text-sm" onClick={() => camera.current?.click()}><Camera size={18}/> Take a photo</button>
          <button type="button" className="ac-btn ac-btn-outline !min-h-12 !px-3 !text-sm" onClick={() => gallery.current?.click()}><ImageUp size={18}/> Upload screenshot</button>
        </div>}
      {preparing && <p className="flex items-center gap-2 text-xs text-slate"><LoaderCircle size={14} className="animate-spin"/> Preparing photo…</p>}
      <input ref={camera} type="file" accept="image/*" capture="environment" className="sr-only" tabIndex={-1} aria-hidden="true" onChange={e => { void pick(e.target.files?.[0]); e.target.value = ''; }}/>
      <input ref={gallery} type="file" accept="image/*,.heic,.heif" className="sr-only" tabIndex={-1} aria-hidden="true" onChange={e => { void pick(e.target.files?.[0]); e.target.value = ''; }}/>
    </div>
    {error && <p role="alert" className="field-error">{error}</p>}
    <button className="ac-btn ac-btn-primary w-full" disabled={busy || preparing || !file}>{busy ? 'Sending…' : 'Send receipt'}</button>
    <p className="-mt-2 text-center text-xs text-slate">We check every receipt by hand and confirm your booking once the money arrives.</p>
  </form>;
}
