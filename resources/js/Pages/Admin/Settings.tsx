import { Head, useForm } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import ImageInput from '../../Components/ImageInput';
import SettingsLayout from '../../Components/SettingsLayout';
import { money, type PaymentMethodConfig, type TenantSettings } from '../../types';

type Section = 'general' | 'booking' | 'pricing' | 'amenities' | 'payments' | 'policies' | 'targets' | 'cleaning';
const sectionKeys = {
  general: ['currency', 'reference_prefix', 'check_in_time', 'check_out_time', 'event_start_time', 'event_end_time', 'turnover_minutes'],
  booking: ['min_nights', 'max_nights', 'advance_notice_days', 'booking_window_days', 'hold_minutes', 'manual_payment_hours', 'request_hold_hours'],
  pricing: ['service_fee_percent', 'tax_label', 'tax_percent', 'weekly_discount_percent', 'monthly_discount_percent', 'weekend_days'],
  amenities: ['amenities'],
  targets: ['monthly_revenue_target'],
  cleaning: ['cleaning_checklist', 'cleaning_photo_required'],
  payments: ['deposit_percent', 'balance_due_days', 'test_payments', 'payment_methods'],
  policies: ['house_rules', 'cancellation_policy'],
} as const satisfies Record<Section, readonly (keyof TenantSettings)[]>;
const copy = {
  general: ['General', 'Currency, booking references and default check-in and check-out times.'],
  booking: ['Booking rules', 'How long guests can stay, how far ahead they can book, and how long unpaid holds last.'],
  pricing: ['Pricing & taxes', 'Fees and taxes added to every quote, and which nights use the weekend rate.'],
  targets: ['Revenue target', 'A monthly goal for booked stay revenue. The dashboard tracks pacing and the gap left to close.'],
  cleaning: ['Cleaning checklist', 'Steps housekeeping ticks off for every turnover. Ticking every step marks the room ready.'],
  amenities: ['Amenities', 'The list you pick from when describing each room. Guests see the ones you tick per room.'],
  payments: ['Payments', 'How guests pay at checkout and how much they pay upfront. Staff confirm manual payments under Reservations.'],
  policies: ['House rules & policies', 'Shown on every stay page. When either is filled in, guests must accept them before paying.'],
} as const;
const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
  return <label className="field">{label}{children}{hint && <small className="font-normal text-slate">{hint}</small>}{error && <small role="alert" className="font-normal text-red-600">{error}</small>}</label>;
}

export default function Settings({ section, settings, currencies, paymentMethods }: { section: Section; settings: TenantSettings; currencies: string[]; paymentMethods: Record<string, string> }) {
  const [title, subtitle] = copy[section];
  return <SettingsLayout title={title} subtitle={subtitle}><Head title={`${title} · Settings`}/><SectionForm key={section} section={section} settings={settings} currencies={currencies} paymentMethods={paymentMethods}/></SettingsLayout>;
}

function SectionForm({ section, settings, currencies, paymentMethods }: { section: Section; settings: TenantSettings; currencies: string[]; paymentMethods: Record<string, string> }) {
  const form = useForm(Object.fromEntries(sectionKeys[section].map(key => [key, settings[key]])) as Partial<TenantSettings>);
  const d = form.data as TenantSettings;
  const e = form.errors as Partial<Record<keyof TenantSettings, string>>;
  const num = (key: keyof TenantSettings) => ({ type: 'number', value: String(d[key] ?? ''), onChange: (ev: React.ChangeEvent<HTMLInputElement>) => form.setData(key, ev.target.value === '' ? '' as never : Number(ev.target.value) as never) });
  const submit = (ev: FormEvent) => { ev.preventDefault(); form.put(`/admin/settings/${section}`, { preserveScroll: true, onSuccess: () => form.setDefaults() }); };
  const [newAmenity, setNewAmenity] = useState('');
  const [newStep, setNewStep] = useState('');
  const addStep = () => { const name = newStep.trim(); if (!name || d.cleaning_checklist.some(a => a.toLowerCase() === name.toLowerCase())) { setNewStep(''); return; } form.setData('cleaning_checklist', [...d.cleaning_checklist, name]); setNewStep(''); };
  const moveStep = (i: number, by: number) => { const list = [...d.cleaning_checklist]; const [x] = list.splice(i, 1); list.splice(Math.max(0, Math.min(list.length, i + by)), 0, x); form.setData('cleaning_checklist', list); };
  const addAmenity = () => { const name = newAmenity.trim(); if (!name || d.amenities.some(a => a.toLowerCase() === name.toLowerCase())) { setNewAmenity(''); return; } form.setData('amenities', [...d.amenities, name]); setNewAmenity(''); };
  const setMethod = (method: string, patch: Partial<PaymentMethodConfig>) => form.setData('payment_methods', { ...d.payment_methods, [method]: { ...d.payment_methods[method], ...patch } });
  const sample = 10000, service = sample * (Number(d.service_fee_percent) || 0) / 100, tax = (sample + service) * (Number(d.tax_percent) || 0) / 100;

  return <form onSubmit={submit} className="surface-card grid gap-5 p-6">
    {section === 'general' && <>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Currency" hint="Used for room rates, quotes, reports and exports." error={e.currency}><select value={d.currency} onChange={ev => form.setData('currency', ev.target.value)}>{currencies.map(c => <option key={c}>{c}</option>)}</select></Field>
        <Field label="Booking reference prefix" hint={`New bookings look like ${d.reference_prefix || 'EAJ'}-${new Date().getFullYear()}-7K2Q9XMA. 2-8 letters or digits.`} error={e.reference_prefix}><input required maxLength={8} value={d.reference_prefix} onChange={ev => form.setData('reference_prefix', ev.target.value.toUpperCase().replace(/[^A-Z0-9]/g, ''))}/></Field>
      </div>
      <div className="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
        <Field label="Check-in time" hint="Shown to guests on the stay page and at checkout." error={e.check_in_time}><input type="time" required value={d.check_in_time} onChange={ev => form.setData('check_in_time', ev.target.value)}/></Field>
        <Field label="Check-out time" error={e.check_out_time}><input type="time" required value={d.check_out_time} onChange={ev => form.setData('check_out_time', ev.target.value)}/></Field>
        <Field label="Events start" hint="Standard hours for the function hall and other venues." error={e.event_start_time}><input type="time" required value={d.event_start_time ?? '08:00'} onChange={ev => form.setData('event_start_time', ev.target.value)}/></Field>
        <Field label="Events end" error={e.event_end_time}><input type="time" required value={d.event_end_time ?? '17:00'} onChange={ev => form.setData('event_end_time', ev.target.value)}/></Field>
        <Field label="Cleaning gap between guests (minutes)" hint="Kept between one guest leaving and the next arriving, e.g. 120 = 12:00 NN out, 2:00 PM in." error={e.turnover_minutes}><input type="number" required min={0} max={720} step={15} value={d.turnover_minutes ?? 120} onChange={ev => form.setData('turnover_minutes', Number(ev.target.value))}/></Field>
      </div>
    </>}
    {section === 'booking' && <>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Minimum nights" error={e.min_nights}><input required min={1} max={365} {...num('min_nights')}/></Field>
        <Field label="Maximum nights" error={e.max_nights}><input required min={1} max={365} {...num('max_nights')}/></Field>
      </div>
      <div className="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
        <Field label="Advance notice (days)" hint="0 allows same-day bookings. Staff-created bookings skip this rule." error={e.advance_notice_days}><input required min={0} max={365} {...num('advance_notice_days')}/></Field>
        <Field label="Booking window (days ahead)" hint="How far into the future guests can choose a check-in date." error={e.booking_window_days}><input required min={1} max={1095} {...num('booking_window_days')}/></Field>
      </div>
      <div className="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
        <Field label="Unpaid hold (minutes)" hint="Dates stay reserved this long while a guest completes checkout." error={e.hold_minutes}><input required min={5} max={1440} {...num('hold_minutes')}/></Field>
        <Field label="Manual payment window (hours)" hint="Time a guest has to settle a manual payment before the booking lapses." error={e.manual_payment_hours}><input required min={1} max={168} {...num('manual_payment_hours')}/></Field>
        <Field label="Reservation request hold (hours)" hint="Dates stay reserved this long after a guest sends a request, while you call them to confirm." error={e.request_hold_hours}><input required min={1} max={168} {...num('request_hold_hours')}/></Field>
      </div>
    </>}
    {section === 'pricing' && <>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Service fee (%)" error={e.service_fee_percent}><input required min={0} max={30} step="0.01" {...num('service_fee_percent')}/></Field>
        <Field label="Tax name" hint="e.g. VAT or Tourism tax" error={e.tax_label}><input required maxLength={30} value={d.tax_label} onChange={ev => form.setData('tax_label', ev.target.value)}/></Field>
        <Field label="Tax (%)" hint="Applied to room subtotal plus service fee." error={e.tax_percent}><input required min={0} max={30} step="0.01" {...num('tax_percent')}/></Field>
      </div>
      <div className="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-2">
        <Field label="Weekly stay discount (%)" hint="Stays of 7-27 nights. 0 turns it off." error={e.weekly_discount_percent}><input required min={0} max={90} step="0.01" {...num('weekly_discount_percent')}/></Field>
        <Field label="Monthly stay discount (%)" hint="Stays of 28 nights or more; replaces the weekly discount." error={e.monthly_discount_percent}><input required min={0} max={90} step="0.01" {...num('monthly_discount_percent')}/></Field>
      </div>
      <fieldset className="border-t border-slate-100 pt-5"><legend className="text-sm font-semibold text-navy">Weekend nights</legend><p className="mt-1 text-xs text-slate">Rooms with a weekend rate charge it on these nights. A night is named by its date, so Friday means the Friday-to-Saturday night.</p><div className="mt-3 flex flex-wrap gap-2">{days.map((day, i) => { const on = d.weekend_days.includes(i); return <button type="button" key={day} aria-pressed={on} onClick={() => form.setData('weekend_days', on ? d.weekend_days.filter(x => x !== i) : [...d.weekend_days, i].sort())} className={`min-w-14 rounded-xl border px-3 py-2 text-sm font-semibold ${on ? 'border-pink bg-pink/10 text-pink' : 'border-slate-200 text-slate hover:border-slate-300'}`}>{day}</button>; })}</div>{e.weekend_days && <p role="alert" className="mt-2 text-xs text-red-600">{e.weekend_days}</p>}</fieldset>
      <div className="rounded-xl bg-slate-50 p-4 text-sm"><p className="font-semibold">Example on a {money(sample)} room subtotal</p><dl className="mt-2 grid max-w-sm grid-cols-[1fr_auto] gap-1 text-slate"><dt>Service fee</dt><dd>{money(service)}</dd><dt>{d.tax_label || 'Tax'}</dt><dd>{money(tax)}</dd><dt className="font-semibold text-navy">Guest pays (before cleaning fee)</dt><dd className="font-semibold text-navy">{money(sample + service + tax)}</dd></dl></div>
    </>}
    {section === 'amenities' && <>
      <div className="flex flex-wrap gap-2">{d.amenities.map(name => <span key={name} className="inline-flex items-center gap-1 rounded-full border border-slate-200 bg-slate-50 py-1.5 pl-3 pr-1.5 text-sm font-semibold">{name}<button type="button" onClick={() => form.setData('amenities', d.amenities.filter(a => a !== name))} className="grid size-6 place-items-center rounded-full text-slate hover:bg-slate-200 hover:text-navy" aria-label={`Remove ${name}`}><X size={14}/></button></span>)}{!d.amenities.length && <p className="text-sm text-slate">No amenities yet. Add the first one below.</p>}</div>
      <div className="flex max-w-md gap-2"><label className="field flex-1">Add amenity<input maxLength={40} value={newAmenity} onChange={ev => setNewAmenity(ev.target.value)} onKeyDown={ev => { if (ev.key === 'Enter') { ev.preventDefault(); addAmenity(); } }} placeholder="e.g. Karaoke"/></label><button type="button" onClick={addAmenity} className="btn btn-light self-end border border-slate-200">Add</button></div>
      {Object.entries(e).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-600">{error}</p>)}
      <p className="text-xs text-slate">Removing an amenity also unticks it on every room once you save.</p>
    </>}
    {section === 'targets' && <>
      <Field label="Monthly revenue target" hint="Booked stay value per month, counted by check-in date. 0 turns target tracking off." error={e.monthly_revenue_target}><input required min={0} max={1000000000} step="1000" {...num('monthly_revenue_target')}/></Field>
      {Number(d.monthly_revenue_target) > 0 && <p className="rounded-xl bg-[#F4F7F3] p-4 text-sm">That is about <strong>{money(Number(d.monthly_revenue_target) / 30)}</strong> a day, or <strong>{money(Number(d.monthly_revenue_target) * 12)}</strong> a year.</p>}
    </>}
    {section === 'cleaning' && <>
      <ol className="grid gap-1.5">{d.cleaning_checklist.map((step, i) => <li key={step} className="flex items-center gap-2 rounded-xl border border-[var(--line)] bg-white px-3 py-2 text-sm font-semibold"><span className="num w-5 text-xs text-[var(--muted)]">{i + 1}</span><span className="flex-1">{step}</span>
        <button type="button" disabled={i === 0} onClick={() => moveStep(i, -1)} className="grid size-7 place-items-center rounded-lg text-[var(--muted)] hover:bg-[#F1F5F0] disabled:opacity-30" aria-label={`Move ${step} up`}>↑</button>
        <button type="button" disabled={i === d.cleaning_checklist.length - 1} onClick={() => moveStep(i, 1)} className="grid size-7 place-items-center rounded-lg text-[var(--muted)] hover:bg-[#F1F5F0] disabled:opacity-30" aria-label={`Move ${step} down`}>↓</button>
        <button type="button" onClick={() => form.setData('cleaning_checklist', d.cleaning_checklist.filter(a => a !== step))} className="grid size-7 place-items-center rounded-lg text-[var(--muted)] hover:bg-rose-50 hover:text-rose-700" aria-label={`Remove ${step}`}><X size={14}/></button></li>)}
        {!d.cleaning_checklist.length && <p className="text-sm text-[var(--muted)]">No steps yet. Without a checklist, cleaners just mark rooms ready.</p>}</ol>
      <label className="flex items-start gap-3 rounded-xl border border-[var(--line)] bg-white p-3 text-sm font-semibold"><input type="checkbox" checked={d.cleaning_photo_required} onChange={ev => form.setData('cleaning_photo_required', ev.target.checked)} className="mt-0.5 size-4 accent-sea"/><span>Require a photo before staff mark a room ready<small className="block text-xs font-semibold text-[var(--muted)]">Staff take the photo from My tasks on their phone. Admins can still close a task from the Cleaning board.</small></span></label>
      <div className="flex max-w-md gap-2"><label className="field flex-1">Add step<input maxLength={80} value={newStep} onChange={ev => setNewStep(ev.target.value)} onKeyDown={ev => { if (ev.key === 'Enter') { ev.preventDefault(); addStep(); } }} placeholder="e.g. Refill drinking water"/></label><button type="button" onClick={addStep} className="btn btn-light self-end">Add</button></div>
      {Object.entries(e).map(([key, error]) => <p key={key} role="alert" className="text-sm text-red-600">{error}</p>)}
    </>}
    {section === 'policies' && <>
      <Field label="House rules" hint="One rule per line works best, e.g. Quiet hours from 10 PM." error={e.house_rules}><textarea maxLength={3000} className="min-h-40 rounded-xl border border-slate-200 p-3 font-normal leading-6" value={d.house_rules} onChange={ev => form.setData('house_rules', ev.target.value)} placeholder={'No smoking indoors.\nQuiet hours from 10 PM to 7 AM.\nNo parties or events.'}/></Field>
      <Field label="Cancellation policy" hint="Explain refunds in plain words. Shown at checkout and available in emails as {cancellation_policy}." error={e.cancellation_policy}><textarea maxLength={3000} className="min-h-32 rounded-xl border border-slate-200 p-3 font-normal leading-6" value={d.cancellation_policy} onChange={ev => form.setData('cancellation_policy', ev.target.value)} placeholder="Full refund up to 7 days before check-in. 50% refund up to 48 hours before. No refund after that."/></Field>
    </>}
    {section === 'payments' && <>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Downpayment (%)" hint="The share guests pay after you approve their dates; 100 = full payment. Change it any time, and per booking in its drawer." error={e.deposit_percent}><input required min={1} max={100} {...num('deposit_percent')}/></Field>
        {Number(d.deposit_percent) < 100 && <Field label="Balance due (days before check-in)" hint="0 = on the check-in day. Shown to guests and in their emails." error={e.balance_due_days}><input required min={0} max={90} {...num('balance_due_days')}/></Field>}
      </div>
      <label className="flex items-start gap-3 rounded-xl border border-slate-200 p-4"><input type="checkbox" className="mt-0.5 size-4 accent-[var(--brand-pink)]" checked={d.test_payments} onChange={ev => form.setData('test_payments', ev.target.checked)}/><span className="text-sm"><strong className="block text-navy">Offer “Pay now (test mode)”</strong><span className="text-slate">Confirms instantly without real money. Turn off before taking real bookings.</span></span></label>
      <div className="grid gap-3 border-t border-slate-100 pt-5"><p className="text-sm font-semibold text-navy">Payment methods</p><p className="-mt-2 text-xs text-slate">Guests send money outside the app and can enter their reference number. If none is on, checkout offers a generic “Request manual payment”.</p>
        {Object.entries(paymentMethods).map(([method, label]) => { const m = d.payment_methods[method]; const err = (field: string) => (e as Record<string, string>)[`payment_methods.${method}.${field}`]; return <div key={method} className={`rounded-xl border p-4 ${m.enabled ? 'border-pink/40 bg-pink/[.03]' : 'border-slate-200'}`}>
          <label className="flex items-center gap-3 font-semibold"><input type="checkbox" className="size-4 accent-[var(--brand-pink)]" checked={m.enabled} onChange={ev => setMethod(method, { enabled: ev.target.checked })}/>{label}</label>
          {m.enabled && <div className="mt-4 grid gap-4 sm:grid-cols-2">
            {method !== 'cash' && <><Field label="Name shown when scanned" hint="What guests see after scanning, e.g. JOL***L A. They check it before sending." error={err('account_name')}><input maxLength={120} value={m.account_name} onChange={ev => setMethod(method, { account_name: ev.target.value })} placeholder="JOL***L A."/></Field>
            <Field label={method === 'bank_transfer' ? 'Bank and account number' : `Mobile / account number${m.qr_image ? ' (optional with a QR)' : ''}`} error={err('account_number')}><input maxLength={60} required={!m.qr_image} value={m.account_number} onChange={ev => setMethod(method, { account_number: ev.target.value })} placeholder={method === 'bank_transfer' ? 'BPI 1234 5678 90' : '0917 123 4567'}/></Field></>}
            <Field label="Instructions for guests" error={err('instructions')}><textarea maxLength={1000} className="min-h-20 rounded-xl border border-slate-200 p-3 font-normal" value={m.instructions} onChange={ev => setMethod(method, { instructions: ev.target.value })} placeholder="e.g. Put your booking reference in the message."/></Field>
            {method !== 'cash' && <ImageInput label={`${label} QR code`} hint="Guests scan this to pay the downpayment. Save the QR from your app and upload it here." value={m.qr_image} onChange={url => setMethod(method, { qr_image: url })} error={err('qr_image')}/>}
          </div>}
        </div>; })}
      </div>
    </>}
    <div className="flex items-center gap-3 border-t border-slate-100 pt-5"><button disabled={form.processing || !form.isDirty} className="btn btn-pink w-fit disabled:cursor-not-allowed disabled:opacity-50">Save changes</button>{form.isDirty && <button type="button" onClick={() => form.reset()} className="text-sm font-semibold text-slate hover:text-navy">Discard</button>}{form.recentlySuccessful && <span role="status" className="text-sm text-green-700">Saved</span>}</div>
  </form>;
}
