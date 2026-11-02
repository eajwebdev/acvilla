import * as Dialog from '@radix-ui/react-dialog';
import { Link, router } from '@inertiajs/react';
import { BedDouble, Check, ChevronDown, CircleAlert, Clock3, LoaderCircle, LogIn, LogOut, Pencil, Phone, PhoneCall, RotateCcw, ShoppingBasket, X } from 'lucide-react';
import { useCallback, useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { money } from '../types';
import { exactMoney, fmtDay, fmtTime, getJson, SOURCE_GROUPS, SOURCES, SourceBadge, Status } from './ops';

type Detail = {
  booking: { id: number; reference: string; guest_name: string; guest_email: string | null; guest_phone: string | null; guests: number; status: string; payment_status: string; source: string; notes: string | null; group_code: string | null; check_in: string; check_out: string; deposit_amount: string | null; check_in_time: string; check_out_time: string; nights: number; expires_at: string | null; created_at: string; unit: string; unit_kind: 'room' | 'venue'; property: string; total: number };
  price: { subtotal: string; cleaning_fee: string; service_fee: string; tax: string; discount: string; extra_guest_fee: string; add_ons_total: string } | null;
  paid: number; balance: number; open_charges: number;
  payments: { id: number; method: string; amount: string; status: string; reference: string | null; payer_reference: string | null; paid_at: string | null; created_at: string; batch: string | null; proof_url: string | null; covers: number; batch_total: number }[];
  downpayment_due: number;
  charges: { id: number; number: string; total: string; status: string; payment_method: string; created_at: string; items: { name: string; quantity: number; amount: string }[] }[];
  messages: { id: number; event: string; subject: string; status: string; error: string | null; created_at: string }[];
  transitions: string[]; expired: boolean; can_hold: boolean; group: { id: number; unit: string; status: string; check_in: string; check_out: string; total: number }[];
};
const methodLabel: Record<string, string> = { cash: 'Cash', gcash: 'GCash', maya: 'Maya', bank_transfer: 'Bank transfer', card: 'Card', manual: 'Manual', mock: 'Online (test)', room_charge: 'Room charge' };
const telHref = (phone: string) => /^[+\d\s()-]+$/.test(phone) ? `tel:${phone.replace(/[^\d+]/g, '')}` : undefined;
const heldUntil = (iso: string) => new Date(iso).toLocaleString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
/** An unconfirmed request is confirmed or declined after the owner's call, so its buttons say so. */
const requestCopy: Record<string, [string, string]> = { AWAITING_PAYMENT: ['Approve dates · ask downpayment', 'btn-navy'], CONFIRMED: ['Confirm (already paid)', 'btn-light'], CANCELLED: ['Decline request', 'btn-danger'] };
const actionCopy: Record<string, [string, string]> = { CONFIRMED: ['Confirm', 'btn-navy'], CHECKED_IN: ['Check in', 'btn-navy'], CHECKED_OUT: ['Check out', 'btn-navy'], COMPLETED: ['Mark completed', 'btn-soft'], CANCELLED: ['Cancel booking', 'btn-danger'], NO_SHOW: ['No-show', 'btn-danger'] };

function Section({ title, children, action }: { title: string; children: ReactNode; action?: ReactNode }) {
  return <section className="border-t border-[var(--line)] px-5 py-4"><div className="mb-2.5 flex items-center justify-between"><h3 className="label">{title}</h3>{action}</div>{children}</section>;
}

export default function BookingDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const [data, setData] = useState<Detail | null>(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState('');
  const [editing, setEditing] = useState(false);
  const load = useCallback(() => { if (!id) return; setError(''); getJson<Detail>(`/admin/bookings/${id}`).then(setData).catch(e => setError(e.message)); }, [id]);
  useEffect(() => { setData(null); setEditing(false); setNotice(''); load(); }, [load]);
  // When the booking changes elsewhere (the guest uploads a receipt, another staff member acts), show it, unless an edit is open.
  useEffect(() => {
    const changed = () => { if (!editing) load(); };
    window.addEventListener('tapp:changed', changed);
    return () => window.removeEventListener('tapp:changed', changed);
  }, [load, editing]);

  const send = (method: 'post' | 'put', url: string, payload: Record<string, unknown>, done?: () => void) => {
    setBusy(true); setNotice('');
    router[method](url, payload as never, { preserveScroll: true, preserveState: true,
      onSuccess: () => { load(); done?.(); }, onError: errs => setNotice(Object.values(errs)[0] as string || 'That did not work.'), onFinish: () => setBusy(false) });
  };
  const b = data?.booking;
  const due = data ? data.balance + data.open_charges : 0;

  return <Dialog.Root open={id !== null} onOpenChange={o => { if (!o) onClose(); }}>
    <Dialog.Portal>
      <Dialog.Overlay className="tapp-overlay"/>
      <Dialog.Content className="tapp-drawer" aria-describedby={undefined}>
        <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-[var(--line)] bg-white px-5 py-4">
          <div className="min-w-0"><Dialog.Title className="truncate text-lg font-bold">{b?.guest_name ?? 'Booking'}</Dialog.Title>{b && <p className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs font-semibold text-[var(--muted)]"><span className="num">{b.reference}</span><Status value={b.status}/><SourceBadge source={b.source}/></p>}</div>
          <Dialog.Close className="grid size-8 shrink-0 place-items-center rounded-lg border border-[var(--line)]" aria-label="Close"><X size={16}/></Dialog.Close>
        </div>
        {!data && !error && <div className="grid h-64 place-items-center"><LoaderCircle className="animate-spin text-[var(--muted)]"/></div>}
        {error && <p role="alert" className="m-5 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm font-semibold text-rose-700">{error}</p>}
        {data && b && <>
          <div className="grid gap-3 px-5 py-4">
            <div className="flex items-center gap-3 rounded-xl bg-[#F4F7F3] p-3"><span className="grid size-10 place-items-center rounded-lg bg-white text-ocean"><BedDouble size={18}/></span><div className="min-w-0 flex-1"><p className="truncate font-bold">{b.unit}</p><p className="truncate text-xs font-semibold text-[var(--muted)]">{b.property} · {b.guests} {b.guests === 1 ? 'guest' : 'guests'}</p></div>{b.guest_phone && <a href={telHref(b.guest_phone)} className="flex items-center gap-1.5 text-xs font-bold text-ocean"><Phone size={14}/>{b.guest_phone}</a>}</div>
            {b.unit_kind === 'venue' ? <div className="rounded-xl border border-[var(--line)] p-2.5 text-center"><p className="label">Event date</p><p className="mt-1 font-bold">{fmtDay(b.check_in, { weekday: 'long', month: 'long', day: 'numeric' })}</p></div> : <div className="grid grid-cols-[1fr_auto_1fr] items-center gap-2 text-center">
              <div className="rounded-xl border border-[var(--line)] p-2.5"><p className="label">Check-in</p><p className="mt-1 font-bold">{fmtDay(b.check_in, { weekday: 'short', month: 'short', day: 'numeric' })}</p><p className="text-xs font-semibold text-[var(--muted)]">{fmtTime(b.check_in_time)}</p></div>
              <span className="num text-xs font-semibold text-[var(--muted)]">{b.nights}n</span>
              <div className="rounded-xl border border-[var(--line)] p-2.5"><p className="label">Check-out</p><p className="mt-1 font-bold">{fmtDay(b.check_out, { weekday: 'short', month: 'short', day: 'numeric' })}</p><p className="text-xs font-semibold text-[var(--muted)]">{fmtTime(b.check_out_time)}</p></div>
            </div>}
            {b.notes && <p className="whitespace-pre-line rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-900">{b.notes}</p>}
            {data.can_hold && !data.expired && <div className="rounded-xl border border-amber-200 bg-amber-50 p-3">
              <p className="text-sm font-bold text-amber-900">{b.status === 'AWAITING_PAYMENT' ? (data.payments.some(p => p.status === 'PENDING') ? 'Receipt uploaded: check it below' : `Dates approved: waiting for the ${money(data.downpayment_due)} downpayment`) : b.source === 'website' ? 'Reservation request: call the guest, then approve the dates' : 'Not confirmed yet'}</p>
              <p className="mt-1 flex items-center gap-1.5 text-xs font-semibold text-amber-800"><Clock3 size={13}/>{data.payments.some(p => p.status === 'PENDING') ? 'Dates stay held while you check the receipt' : `Dates held until ${b.expires_at ? heldUntil(b.expires_at) : 'confirmed'}`}</p>
              <div className="mt-3 flex flex-wrap gap-2">
                {b.guest_phone && telHref(b.guest_phone) && <a href={telHref(b.guest_phone)} className="btn btn-navy flex-1"><PhoneCall size={15}/>Call {b.guest_phone}</a>}
                <button type="button" disabled={busy} className="btn btn-light" onClick={() => send('put', `/admin/bookings/${b.id}/hold`, {})}><Clock3 size={15}/>Hold 24h more</button>
              </div>
            </div>}
            {data.expired && <div className="rounded-xl border border-slate-200 bg-slate-50 p-3">
              <p className="flex items-center gap-2 text-xs font-semibold text-slate-600"><CircleAlert size={15}/>This request was not confirmed in time, so its dates were released.</p>
              {data.can_hold && <button type="button" disabled={busy} className="btn btn-light mt-3 w-full" onClick={() => send('put', `/admin/bookings/${b.id}/hold`, {})}><RotateCcw size={15}/>Reopen request and hold 24h</button>}
            </div>}
            {data.payments.filter(p => p.status === 'PENDING').map(p => <div key={p.id} className="rounded-xl border-2 border-amber-300 bg-amber-50 p-3 text-xs font-semibold">
              <p className="text-sm font-bold text-amber-900">Downpayment receipt to check</p>
              <p className="mt-1"><strong>{methodLabel[p.method] ?? p.method}</strong> · <strong className="num">{money(p.covers > 1 ? p.batch_total : p.amount)}</strong>{p.covers > 1 && <> for {p.covers} facilities</>}{p.payer_reference && <> · ref <span className="num">{p.payer_reference}</span></>}</p>
              {p.proof_url && <a href={p.proof_url} target="_blank" rel="noreferrer" className="mt-2 block overflow-hidden rounded-lg border border-amber-200 bg-white" title="Open the receipt full size"><img src={p.proof_url} alt="Payment receipt" className="max-h-72 w-full object-contain"/></a>}
              <p className="mt-2 font-medium text-amber-900/80">Check the amount and reference in your GCash or bank app before approving.</p>
              <div className="mt-2 flex gap-2"><button type="button" disabled={busy} className="btn btn-navy btn-sm flex-1" onClick={() => send('put', `/admin/payments/${p.id}/review`, { decision: 'approve' })}><Check size={14}/>Payment received</button><button type="button" disabled={busy} className="btn btn-light btn-sm" onClick={() => confirm('Reject this receipt? The guest will be asked to upload a clearer one.') && send('put', `/admin/payments/${p.id}/review`, { decision: 'reject' })}>Reject</button></div>
            </div>)}
            {notice && <p role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs font-semibold text-rose-700">{notice}</p>}
            {data.transitions.length > 0 && <div className="flex flex-wrap gap-2">{data.transitions.filter(next => !data.payments.some(p => p.status === 'PENDING') || next === 'CANCELLED').map(next => {
              const [label, tone] = (data.can_hold ? requestCopy[next] : undefined) ?? actionCopy[next] ?? [next, 'btn-light'];
              const blocked = next === 'CHECKED_OUT' && data.open_charges > 0;
              const danger = next === 'CANCELLED' || next === 'NO_SHOW';
              return <button key={next} type="button" disabled={busy || blocked} title={blocked ? 'Settle room charges first' : undefined} className={`btn ${tone} ${danger ? '' : 'flex-1'}`}
                onClick={() => { if (danger && !confirm(`${label} for ${b.guest_name}?`)) return; send('put', `/admin/bookings/${b.id}/status`, { status: next }); }}>
                {next === 'CHECKED_IN' && <LogIn size={15}/>}{next === 'CHECKED_OUT' && <LogOut size={15}/>}{next === 'CONFIRMED' && <Check size={15}/>}{label}</button>;
            })}</div>}
          </div>

          <Section title="Folio">
            <dl className="grid gap-1.5 text-sm">
              <div className="flex justify-between"><dt className="text-[var(--muted)]">Stay total</dt><dd className="num font-semibold">{exactMoney(b.total)}</dd></div>
              {!['CANCELLED', 'EXPIRED', 'COMPLETED', 'CHECKED_OUT'].includes(b.status) && <DownpaymentRow amount={Number(b.deposit_amount ?? b.total)} total={b.total} busy={busy} onSave={amount => send('put', `/admin/bookings/${b.id}/deposit`, { amount })}/>}
              <div className="flex justify-between"><dt className="text-[var(--muted)]">Paid</dt><dd className="num font-semibold text-emerald-700">−{exactMoney(data.paid)}</dd></div>
              {data.open_charges > 0 && <div className="flex justify-between"><dt className="text-[var(--muted)]">Room charges (POS)</dt><dd className="num font-semibold">{exactMoney(data.open_charges)}</dd></div>}
              <div className="mt-1 flex justify-between border-t border-[var(--line)] pt-2 font-bold"><dt>Due</dt><dd className={`num ${due > 0 ? 'text-[var(--danger)]' : 'text-emerald-700'}`}>{exactMoney(due)}</dd></div>
            </dl>
            {data.balance > 0 && !['CANCELLED', 'EXPIRED', 'COMPLETED'].includes(b.status) && !data.expired && <PaymentForm balance={data.balance} downpayment={Math.max(0, Number(b.deposit_amount ?? 0) - data.paid)} busy={busy} onSubmit={p => send('post', `/admin/bookings/${b.id}/payments`, p)}/>}

          </Section>

          <Section title="Room charges" action={['CONFIRMED', 'PAID', 'CHECKED_IN'].includes(b.status) ? <Link href={`/admin/pos?booking=${b.id}`} className="flex items-center gap-1 text-xs font-bold text-ocean"><ShoppingBasket size={14}/>Sell on POS</Link> : undefined}>
            {data.charges.length === 0 ? <p className="text-xs font-semibold text-[var(--muted)]">No POS purchases on this stay.</p> : <ul className="grid gap-1.5">{data.charges.map(c => <li key={c.id} className="flex items-start justify-between gap-3 rounded-lg bg-[#F8FAF7] px-3 py-2 text-xs">
              <span className="min-w-0"><span className="num font-semibold">{c.number}</span> · {c.items.map(i => `${i.quantity}× ${i.name}`).join(', ')}</span>
              <span className="shrink-0 text-right"><span className={`num block font-bold ${c.status === 'VOID' ? 'line-through opacity-50' : ''}`}>{money(c.total)}</span><span className="text-[10px] font-bold uppercase text-[var(--muted)]">{c.status === 'UNPAID' ? 'open' : c.status === 'VOID' ? 'void' : methodLabel[c.payment_method] ?? 'paid'}</span></span>
            </li>)}</ul>}
            {data.open_charges > 0 && <SettleForm amount={data.open_charges} busy={busy} onSubmit={p => send('post', `/admin/bookings/${b.id}/charges/settle`, p)}/>}
          </Section>

          {['PENDING', 'AWAITING_PAYMENT', 'PAID', 'CONFIRMED', 'CHECKED_IN'].includes(b.status) && !data.expired && <Section title={b.unit_kind === 'venue' ? 'Extend event' : 'Extend stay'}>
            <ExtendForm checkOut={b.check_out} venue={b.unit_kind === 'venue'} busy={busy} onSubmit={p => send('put', `/admin/bookings/${b.id}/extend`, p)}/>
          </Section>}

          {data.group.length > 1 && <Section title={`Group ${b.group_code}`}><ul className="grid gap-1 text-sm">{data.group.map(g => <li key={g.id} className="flex justify-between"><span className={g.id === b.id ? 'font-bold' : ''}>{g.unit}</span><Status value={g.status}/></li>)}</ul></Section>}

          <Section title="Guest details" action={<button type="button" onClick={() => setEditing(!editing)} className="flex items-center gap-1 text-xs font-bold text-ocean"><Pencil size={13}/>{editing ? 'Close' : 'Edit'}</button>}>
            {editing ? <EditForm detail={data} busy={busy} onSubmit={p => send('put', `/admin/bookings/${b.id}`, p, () => setEditing(false))}/> : <dl className="grid gap-1.5 text-sm">
              {[['Contact', b.guest_phone || '-'], ['Email', b.guest_email || '-'], ['Source', SOURCES[b.source] ?? b.source], ['Booked', new Date(b.created_at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })]].map(([k, v]) => <div key={k} className="flex justify-between gap-3"><dt className="text-[var(--muted)]">{k}</dt><dd className="truncate font-semibold">{v}</dd></div>)}
            </dl>}
          </Section>

          {data.payments.filter(p => p.status !== 'PENDING').length > 0 && <Section title="Payments"><ul className="grid gap-1.5 text-sm">{data.payments.filter(p => p.status !== 'PENDING').map(p => <li key={p.id} className="flex items-center justify-between gap-3"><span className="min-w-0 truncate">{methodLabel[p.method] ?? p.method} <span className="text-xs text-[var(--muted)]">· {new Date(p.paid_at ?? p.created_at).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })}</span></span><span className="flex items-center gap-2"><span className={`num font-semibold ${p.status === 'FAILED' ? 'line-through opacity-50' : ''}`}>{money(p.amount)}</span>{p.status === 'FAILED' && <Status value="FAILED"/>}</span></li>)}</ul></Section>}

          {data.price && <Section title="Price breakdown"><dl className="grid gap-1 text-xs">{([['Room', data.price.subtotal], ['Extra guests', data.price.extra_guest_fee], ['Add-ons', data.price.add_ons_total], ['Discounts', `-${data.price.discount}`], ['Cleaning', data.price.cleaning_fee], ['Service fee', data.price.service_fee], ['Tax', data.price.tax]] as const).filter(([, v]) => Math.abs(Number(v)) > 0).map(([k, v]) => <div key={k} className="flex justify-between"><dt className="text-[var(--muted)]">{k}</dt><dd className="num">{exactMoney(v)}</dd></div>)}</dl></Section>}

          {data.messages.length > 0 && <Section title="Messages sent"><ul className="grid gap-1 text-xs">{data.messages.map(m => <li key={m.id} className="flex items-center justify-between gap-3"><span className="truncate" title={m.error ?? undefined}>{m.subject}</span><span className="flex shrink-0 items-center gap-2">{m.status === 'FAILED' && <button type="button" disabled={busy} className="font-semibold text-pink underline-offset-2 hover:underline" onClick={() => send('post', `/admin/messages/${m.id}/retry`, {})}>Resend</button>}<span className={m.status === 'SENT' ? 'text-emerald-700' : m.status === 'SKIPPED' ? 'text-[var(--muted)]' : 'text-rose-700'} title={m.status === 'SKIPPED' ? 'Not resent: the booking has moved on.' : undefined}>{m.status === 'FAILED' ? 'not delivered' : m.status.toLowerCase()}</span></span></li>)}</ul></Section>}
        </>}
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>;
}

/** Pick a later check-out; the server only accepts it when the extra nights are free and adds their price to the bill. */
function ExtendForm({ checkOut, venue, busy, onSubmit }: { checkOut: string; venue: boolean; busy: boolean; onSubmit: (p: Record<string, unknown>) => void }) {
  const plus = (days: number) => { const d = new Date(`${checkOut}T12:00:00`); d.setDate(d.getDate() + days); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
  const [date, setDate] = useState(plus(1));
  useEffect(() => setDate(plus(1)), [checkOut]); // eslint-disable-line react-hooks/exhaustive-deps
  return <form onSubmit={e => { e.preventDefault(); onSubmit({ check_out: date }); }} className="grid gap-2">
    <p className="text-xs font-semibold text-[var(--muted)]">{venue ? 'Add another day to this event.' : 'Move check-out later.'} Only saved if no other guest or block is on those dates; the extra nights are added to the bill.</p>
    <div className="flex items-end gap-2"><label className="field flex-1">New check-out<input type="date" required min={plus(1)} value={date} onChange={e => setDate(e.target.value)}/></label>
      <button disabled={busy} className="btn btn-navy">Extend</button></div>
  </form>;
}

/** The downpayment this booking needs; normally the standard percentage, changeable for this guest. */
function DownpaymentRow({ amount, total, busy, onSave }: { amount: number; total: string | number; busy: boolean; onSave: (amount: number) => void }) {
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(amount.toFixed(2));
  useEffect(() => setValue(amount.toFixed(2)), [amount]);
  const percent = Number(total) > 0 ? Math.round(amount / Number(total) * 100) : 0;
  if (!editing) return <div className="flex justify-between"><dt className="text-[var(--muted)]">Downpayment <span className="text-[11px]">({percent}%)</span> <button type="button" onClick={() => setEditing(true)} className="ml-1 text-[11px] font-bold text-ocean">Change</button></dt><dd className="num font-semibold">{exactMoney(amount)}</dd></div>;
  return <form className="flex flex-wrap items-end gap-2 rounded-lg bg-[#F4F7F3] p-2" onSubmit={e => { e.preventDefault(); onSave(Number(value)); setEditing(false); }}>
    <label className="field flex-1">Downpayment<input type="number" min="0" step="0.01" max={Number(total)} value={value} onChange={e => setValue(e.target.value)} required/></label>
    <div className="flex gap-1">{[30, 50, 100].map(p => <button key={p} type="button" className="chip" onClick={() => setValue((Math.round(Number(total) * p) / 100).toFixed(2))}>{p}%</button>)}</div>
    <div className="flex gap-1"><button type="button" className="btn btn-light btn-sm" onClick={() => setEditing(false)}>Cancel</button><button disabled={busy} className="btn btn-navy btn-sm">Save</button></div>
  </form>;
}

function PaymentForm({ balance, downpayment, busy, onSubmit }: { balance: number; downpayment: number; busy: boolean; onSubmit: (p: Record<string, unknown>) => void }) {
  const [open, setOpen] = useState(false);
  const [amount, setAmount] = useState(balance.toFixed(2));
  // Guests often pay part now (a downpayment) and the rest on arrival.
  const presets = ([[downpayment, 'Downpayment'], [balance / 2, 'Half'], [balance, 'Full balance']] as const)
    .filter(([value], i, all) => value > 0 && value <= balance && all.findIndex(([other]) => Math.abs(other - value) < 0.01) === i);
  const [method, setMethod] = useState('cash');
  const [ref, setRef] = useState('');
  useEffect(() => setAmount(balance.toFixed(2)), [balance]);
  if (!open) return <button type="button" onClick={() => setOpen(true)} className="btn btn-soft mt-3 w-full">Record payment<ChevronDown size={15}/></button>;
  const submit = (e: FormEvent) => { e.preventDefault(); onSubmit({ amount: Number(amount), method, payer_reference: ref || null }); };
  return <form onSubmit={submit} className="mt-3 grid gap-2 rounded-xl border border-[var(--line)] p-3">
    <div className="flex flex-wrap gap-1.5">{presets.map(([value, label]) => <button key={label} type="button" className="chip" aria-pressed={Math.abs(Number(amount) - value) < 0.01} onClick={() => setAmount(value.toFixed(2))}>{label} · {money(value)}</button>)}</div>
    <div className="grid grid-cols-2 gap-2"><label className="field">Amount<input type="number" min="0.01" step="0.01" max={balance} required value={amount} onChange={e => setAmount(e.target.value)}/></label>
      <label className="field">Method<select value={method} onChange={e => setMethod(e.target.value)}><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="bank_transfer">Bank transfer</option></select></label></div>
    {method !== 'cash' && <label className="field">Reference no.<input maxLength={100} value={ref} onChange={e => setRef(e.target.value)} placeholder="Transaction or receipt no."/></label>}
    <div className="flex gap-2"><button type="button" className="btn btn-light flex-1" onClick={() => setOpen(false)}>Cancel</button><button disabled={busy} className="btn btn-navy flex-[2]">Save payment</button></div>
  </form>;
}

function SettleForm({ amount, busy, onSubmit }: { amount: number; busy: boolean; onSubmit: (p: Record<string, unknown>) => void }) {
  const [method, setMethod] = useState('cash');
  return <div className="mt-3 flex items-end gap-2 rounded-xl border border-[var(--line)] p-3">
    <label className="field flex-1">Settle {money(amount)} by<select value={method} onChange={e => setMethod(e.target.value)}><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option></select></label>
    <button type="button" disabled={busy} className="btn btn-navy" onClick={() => onSubmit({ method })}>Settle</button>
  </div>;
}

function EditForm({ detail, busy, onSubmit }: { detail: Detail; busy: boolean; onSubmit: (p: Record<string, unknown>) => void }) {
  const b = detail.booking;
  const [f, setF] = useState({ guest_name: b.guest_name, guest_phone: b.guest_phone ?? '', guest_email: b.guest_email ?? '', source: b.source, check_in_time: b.check_in_time, check_out_time: b.check_out_time, notes: b.notes ?? '' });
  const set = (k: keyof typeof f, v: string) => setF(x => ({ ...x, [k]: v }));
  return <form onSubmit={e => { e.preventDefault(); onSubmit({ ...f, guest_email: f.guest_email || null, guest_phone: f.guest_phone || null, notes: f.notes || null }); }} className="grid gap-2">
    <label className="field">Guest name<input required maxLength={120} value={f.guest_name} onChange={e => set('guest_name', e.target.value)}/></label>
    <div className="grid grid-cols-2 gap-2"><label className="field">Contact<input maxLength={120} value={f.guest_phone} onChange={e => set('guest_phone', e.target.value)}/></label><label className="field">Email<input type="email" maxLength={255} value={f.guest_email} onChange={e => set('guest_email', e.target.value)}/></label></div>
    <div className="grid grid-cols-3 gap-2"><label className="field">Source<select value={f.source} onChange={e => set('source', e.target.value)}>{SOURCE_GROUPS.map(([group, keys]) => <optgroup key={group} label={group}>{keys.map(k => <option key={k} value={k}>{SOURCES[k]}</option>)}</optgroup>)}</select></label><label className="field">Check-in<input type="time" value={f.check_in_time} onChange={e => set('check_in_time', e.target.value)}/></label><label className="field">Check-out<input type="time" value={f.check_out_time} onChange={e => set('check_out_time', e.target.value)}/></label></div>
    <label className="field">Notes<textarea maxLength={1000} value={f.notes} onChange={e => set('notes', e.target.value)}/></label>
    <button disabled={busy} className="btn btn-navy">Save details</button>
  </form>;
}
