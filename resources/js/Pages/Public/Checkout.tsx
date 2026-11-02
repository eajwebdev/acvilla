import { Head, router } from '@inertiajs/react';
import { Check, Clock3, CreditCard, Landmark, ShieldCheck, Wallet } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';
import type { Booking, PaymentOption } from '../../types';
import { currency, money, time12 } from '../../types';
export default function Checkout({ booking, tenant, paymentOptions, amountDueNow }: { booking: Booking; tenant: SiteTenant; paymentOptions: PaymentOption[]; amountDueNow: number }) {
  const seasons = [...new Set((booking.price?.nightly_rates || []).map(n => n.season).filter(Boolean))];
  const nights = Math.max(1, Math.round((Date.parse(booking.check_out.slice(0, 10)) - Date.parse(booking.check_in.slice(0, 10))) / 86_400_000));
  return <GuestShell tenant={tenant}><Head title={`Checkout ${booking.reference}`}/><div className="container max-w-5xl py-8 sm:py-14">
    <ol className="flex items-center gap-2 text-xs font-bold sm:text-sm" aria-label="Booking progress">
      {['Choose your stay', 'Review & pay', 'Confirmed'].map((step, i) => <li key={step} className="flex min-w-0 items-center gap-2" aria-current={i === 1 ? 'step' : undefined}>
        <span className={`grid size-7 shrink-0 place-items-center rounded-full text-xs ${i === 0 ? 'bg-emerald-500 text-white' : i === 1 ? 'text-white' : 'bg-slate-200 text-slate'}`} style={i === 1 ? { background: 'var(--guest-accent)' } : undefined}>{i === 0 ? <Check size={14}/> : i + 1}</span>
        <span className={`truncate ${i === 1 ? 'text-navy' : 'text-slate'} ${i === 1 ? '' : 'hidden sm:inline'}`}>{step}</span>
        {i < 2 && <span className="h-px w-6 shrink-0 bg-slate-300 sm:w-12"/>}
      </li>)}
    </ol>
    <h1 className="section-title mt-6">Finish your booking</h1><p className="mt-3 text-slate">Check your stay and choose how you would like to pay.</p>
    {booking.expires_at && <HoldTimer until={booking.expires_at}/>}
    <div className="mt-7 grid gap-5 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start lg:gap-7">
      <div className="order-last min-w-0 rounded-[var(--radius-card)] border border-[var(--border)] bg-white p-5 shadow-[var(--shadow-soft)] sm:p-7 lg:order-first">
        <div className="flex gap-4">{booking.property.cover_image && <img src={booking.property.cover_image} alt="" className="size-20 shrink-0 rounded-2xl object-cover sm:size-24"/>}<div className="min-w-0"><h2 className="text-xl font-bold sm:text-2xl">{booking.property.name}</h2><p className="mt-1 text-slate">{booking.room.name} · {booking.guests} {booking.guests === 1 ? 'guest' : 'guests'} · {nights} {nights === 1 ? 'night' : 'nights'}</p></div></div>
        <div className="mt-5 grid grid-cols-2 gap-3 rounded-xl bg-[var(--surface-muted)] p-4 text-sm sm:gap-4 sm:p-5"><div><span className="text-slate">Check-in</span><p className="mt-1 font-semibold">{day(booking.check_in)}</p>{tenant?.settings && <p className="text-xs text-slate">from {time12(tenant.settings.check_in_time)}</p>}</div><div><span className="text-slate">Check-out</span><p className="mt-1 font-semibold">{day(booking.check_out)}</p>{tenant?.settings && <p className="text-xs text-slate">by {time12(tenant.settings.check_out_time)}</p>}</div></div>
        <p className="mt-5 text-sm text-slate">Guest: <strong className="text-navy">{booking.guest_name}</strong>. We’ll email your confirmation.</p>
        <PaymentChooser booking={booking} options={paymentOptions} amountDueNow={amountDueNow} depositPercent={tenant?.settings?.deposit_percent ?? 100} houseRules={tenant?.settings?.house_rules ?? ''} cancellationPolicy={tenant?.settings?.cancellation_policy ?? ''}/></div>
      <aside className="order-first h-fit overflow-hidden rounded-[var(--radius-card)] p-5 text-white shadow-[var(--shadow-lift)] sm:p-7 lg:sticky lg:top-24 lg:order-last" style={{ background: 'var(--guest-primary)' }}>
        <h2 className="text-lg font-bold">Price details</h2>
        <div className="mt-5 grid gap-3 border-b border-white/20 pb-5 text-sm text-white/75">
          {seasons.length > 0 && <p className="text-xs text-white/55">Includes {seasons.join(', ')} pricing</p>}
          <p className="flex justify-between gap-3"><span>Room · {nights} {nights === 1 ? 'night' : 'nights'}</span><span>{money(booking.price?.subtotal || 0)}</span></p>
          {Number(booking.price?.extra_guest_fee || 0) > 0 && <p className="flex justify-between gap-3"><span>Extra guests</span><span>{money(booking.price?.extra_guest_fee || 0)}</span></p>}
          {(booking.price?.discounts || []).map(line => <p key={line.label} className="flex justify-between gap-3 text-emerald-300"><span>{line.label}</span><span>−{money(line.amount)}</span></p>)}
          {(booking.price?.add_ons || []).map(line => <p key={line.id} className="flex justify-between gap-3"><span>{line.name}{line.quantity > 1 ? ` ×${line.quantity}` : ''}</span><span>{money(line.amount)}</span></p>)}
          <p className="flex justify-between gap-3"><span>Cleaning fee</span><span>{money(booking.price?.cleaning_fee || 0)}</span></p>
          <p className="flex justify-between gap-3"><span>Service fee</span><span>{money(booking.price?.service_fee || 0)}</span></p>
          {Number(booking.price?.tax || 0) > 0 && <p className="flex justify-between gap-3"><span>{tenant?.settings?.tax_label || 'Tax'}</span><span>{money(booking.price?.tax || 0)}</span></p>}
        </div>
        <p className="mt-5 flex items-baseline justify-between text-xl font-bold"><span>Total</span><span>{money(booking.total)}</span></p>
        <p className="mt-4 flex items-center gap-2 text-xs text-white/60"><ShieldCheck size={14}/>Reference {booking.reference} · {currency()}</p>
      </aside>
    </div></div></GuestShell>;
}


/** Live countdown for the temporary hold on the guest's dates. */
function HoldTimer({ until }: { until: string }) {
  const [left, setLeft] = useState(() => Date.parse(until) - Date.now());
  useEffect(() => { const t = window.setInterval(() => setLeft(Date.parse(until) - Date.now()), 1000); return () => window.clearInterval(t); }, [until]);
  if (left <= 0) return <p role="status" className="mt-5 flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900"><Clock3 size={16}/>Your hold on these dates has ended. Go back to the stay to check availability again.</p>;
  const mins = Math.floor(left / 60000), secs = Math.floor((left % 60000) / 1000);
  const label = mins >= 60 ? `${Math.floor(mins / 60)}h ${mins % 60}m` : `${mins}:${String(secs).padStart(2, '0')}`;
  return <p role="timer" aria-live="off" className="mt-5 inline-flex items-center gap-2 rounded-full border border-[var(--border)] bg-white px-4 py-2 text-sm font-semibold"><Clock3 size={16} style={{ color: 'var(--guest-accent)' }}/>Your dates are held for <span className="font-mono tabular-nums">{label}</span></p>;
}

const day = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });

function PaymentChooser({ booking, options, amountDueNow, depositPercent, houseRules, cancellationPolicy }: { booking: Booking; options: PaymentOption[]; amountDueNow: number; depositPercent: number; houseRules: string; cancellationPolicy: string }) {
  const [accepted, setAccepted] = useState(false);
  const needsAcceptance = Boolean(houseRules || cancellationPolicy);
  const [method, setMethod] = useState(options[0]?.method ?? '');
  const [reference, setReference] = useState('');
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const option = options.find(o => o.method === method);
  const deposit = amountDueNow < Number(booking.total) - 0.001;
  const sendsMoney = !['mock', 'cash', 'manual'].includes(method);
  function submit(ev: FormEvent) {
    ev.preventDefault(); setError(''); setBusy(true);
    router.post(`/checkout/${booking.reference}/pay`, { method, payer_reference: reference || null, accept_policies: accepted }, { onError: errors => setError(Object.values(errors)[0] || 'Payment could not be started.'), onFinish: () => setBusy(false) });
  }
  return <form onSubmit={submit} className="mt-9">
    <h3 className="text-lg font-semibold">Choose payment</h3>
    <div className="mt-3 rounded-xl bg-slate-50 p-4 text-sm"><p className="flex justify-between font-semibold"><span>{deposit ? `Deposit due now (${depositPercent}%)` : 'Due now'}</span><span>{money(amountDueNow)}</span></p>{deposit && <p className="mt-1 text-xs text-slate">The remaining {money(Number(booking.total) - amountDueNow)} is due{booking.balance_due_on ? ` by ${day(booking.balance_due_on)}` : ' before check-in'}. Your booking is confirmed once the deposit is received.</p>}</div>
    <div className="mt-4 grid gap-2" role="radiogroup" aria-label="Payment method">{options.map(o => <label key={o.method} className={`flex cursor-pointer items-center gap-3 rounded-xl border p-4 text-sm font-semibold ${method === o.method ? 'border-pink bg-pink/5' : 'border-slate-200 hover:border-slate-300'}`}><input type="radio" name="method" className="accent-[var(--brand-pink)]" checked={method === o.method} onChange={() => setMethod(o.method)}/>{o.method === 'mock' ? <CreditCard size={18}/> : o.method === 'bank_transfer' || o.method === 'manual' ? <Landmark size={18}/> : <Wallet size={18}/>}{o.label}</label>)}</div>
    {option && <div className="mt-4 rounded-xl border border-slate-200 p-4 text-sm">
      <div className="flex flex-wrap gap-4">{option.qr_image && <img src={option.qr_image} alt={`${option.label} QR code`} className="size-32 rounded-lg border border-slate-200 object-contain"/>}<div className="min-w-0 flex-1">{option.account_name && <p><span className="text-slate">Account name:</span> <strong>{option.account_name}</strong></p>}{option.account_number && <p className="mt-1"><span className="text-slate">{option.method === 'bank_transfer' ? 'Account:' : 'Number:'}</span> <strong className="font-mono">{option.account_number}</strong></p>}{option.instructions && <p className="mt-2 whitespace-pre-line text-slate">{option.instructions}</p>}{sendsMoney && <p className="mt-2 text-slate">Send <strong className="text-navy">{money(amountDueNow)}</strong> and include <strong className="font-mono text-navy">{booking.reference}</strong> in the note.</p>}</div></div>
      {sendsMoney && <label className="field mt-4">Payment reference number (optional)<input maxLength={100} value={reference} onChange={ev => setReference(ev.target.value)} placeholder="e.g. GCash ref. no."/><small className="font-normal text-slate">Helps us match your payment faster.</small></label>}
    </div>}
    {needsAcceptance && <div className="mt-4 rounded-xl border border-slate-200 p-4 text-sm">{houseRules && <details><summary className="cursor-pointer font-semibold">House rules</summary><p className="mt-2 whitespace-pre-line text-slate">{houseRules}</p></details>}{cancellationPolicy && <details className={houseRules ? 'mt-2' : ''}><summary className="cursor-pointer font-semibold">Cancellation policy</summary><p className="mt-2 whitespace-pre-line text-slate">{cancellationPolicy}</p></details>}<label className="mt-3 flex items-start gap-2"><input type="checkbox" required className="mt-0.5 size-4 accent-[var(--brand-pink)]" checked={accepted} onChange={ev => setAccepted(ev.target.checked)}/><span>I agree to the {[houseRules && 'house rules', cancellationPolicy && 'cancellation policy'].filter(Boolean).join(' and ')}.</span></label></div>}
    {error && <p role="alert" className="mt-3 text-sm text-red-600">{error}</p>}
    <button disabled={!option || busy || (needsAcceptance && !accepted)} className="btn btn-pink mt-5 w-full disabled:opacity-60">{method === 'mock' ? `Pay ${money(amountDueNow)} now` : method === 'cash' ? 'Reserve and pay on arrival' : method === 'manual' ? 'Request manual payment' : `I've sent ${money(amountDueNow)}`}</button>
    {method !== 'mock' && <p className="mt-2 text-center text-xs text-slate">We'll hold your dates while we confirm your payment and email you once it's verified.</p>}
  </form>;
}
