import { Head, useForm } from '@inertiajs/react';
import { ChevronLeft, Phone, ShieldCheck } from 'lucide-react';
import { type FormEvent } from 'react';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';

/** Philippine mobile numbers only: 917 123 4567, also accepted as 0917… or +63 917…. */
const local = (value: string) => { const d = value.replace(/\D+/g, ''); return d.startsWith('63') ? d.slice(2) : d.startsWith('0') ? d.slice(1) : d; };
const valid = (value: string) => /^9\d{9}$/.test(local(value));
const pretty = (value: string) => { const d = local(value).slice(0, 10); return [d.slice(0, 3), d.slice(3, 6), d.slice(6)].filter(Boolean).join(' '); };

/**
 * After the first Google sign-in: the guest's name and a Philippine mobile number the owner can call. Asked once;
 * every booking then uses it, and the guest's bookings are on their account.
 */
export default function CompleteProfile({ tenant, first_name, last_name, phone, email, avatar, return: back, first }: {
  tenant: SiteTenant; first_name: string; last_name: string; phone: string; email: string; avatar: string | null; return: string; first: boolean;
}) {
  const form = useForm({ first_name, last_name, phone: pretty(phone) });
  const submit = (event: FormEvent) => { event.preventDefault(); form.put('/account/complete', { preserveScroll: true }); };
  const ready = form.data.first_name.trim() && form.data.last_name.trim() && valid(form.data.phone);

  return <GuestShell tenant={tenant}>
    <Head title="Your details"/>
    <div className="container max-w-lg py-10 sm:py-16">
      <form onSubmit={submit} className="ac-panel !p-6 sm:!p-8">
        {!first && <a href={back} className="-ml-2 mb-4 inline-grid size-10 place-items-center rounded-full text-slate hover:bg-slate-100" aria-label="Back"><ChevronLeft size={22}/></a>}
        <h1 className="text-2xl font-semibold text-[#1F2A24]">{first ? 'First, tell us your name.' : 'Your details'}</h1>
        <p className="mt-2 text-sm text-slate">{first ? 'We’re setting up your account. Add your name and a mobile number so the owner can call you to confirm your bookings.' : 'The owner calls this number to confirm your bookings.'}</p>
        <p className="mt-4 flex items-center gap-3 rounded-2xl bg-[#F3F8F2] px-4 py-3 text-sm">
          {avatar ? <img src={avatar} alt="" className="size-9 rounded-full" referrerPolicy="no-referrer"/> : <span className="grid size-9 place-items-center rounded-full bg-[var(--ac-green)] font-semibold text-white">{email.charAt(0).toUpperCase()}</span>}
          <span className="min-w-0"><span className="block text-xs text-slate">Signed in with Google</span><strong className="block truncate">{email}</strong></span>
        </p>

        <div className="mt-6 grid gap-4">
          <label className="ac-float"><span>First name</span><input required autoComplete="given-name" maxLength={60} value={form.data.first_name} onChange={e => form.setData('first_name', e.target.value)}/></label>
          {form.errors.first_name && <p className="field-error -mt-2">{form.errors.first_name}</p>}
          <label className="ac-float"><span>Last name</span><input required autoComplete="family-name" maxLength={60} value={form.data.last_name} onChange={e => form.setData('last_name', e.target.value)}/></label>
          {form.errors.last_name && <p className="field-error -mt-2">{form.errors.last_name}</p>}
          <div className="grid grid-cols-[112px_minmax(0,1fr)] gap-3">
            <label className="ac-float"><span>Country</span><select aria-label="Country code" value="+63" onChange={() => {}}><option value="+63">🇵🇭 +63</option></select></label>
            <label className="ac-float"><span>Mobile number</span><input required type="tel" inputMode="numeric" autoComplete="tel-national" placeholder="917 123 4567" value={form.data.phone}
              onChange={e => form.setData('phone', pretty(e.target.value))} aria-describedby="phone-help"/></label>
          </div>
          <p id="phone-help" className={`-mt-2 text-xs ${form.errors.phone ? 'field-error' : 'text-slate'}`}>{form.errors.phone ?? (form.data.phone && !valid(form.data.phone) ? 'A Philippine mobile number has 10 digits after +63 and starts with 9, e.g. 917 123 4567.' : 'Philippine mobile number. We only use it about your bookings.')}</p>
        </div>

        <button className="ac-btn ac-btn-primary mt-6 w-full" disabled={!ready || form.processing}><Phone size={18}/> {form.processing ? 'Saving…' : first ? 'Continue' : 'Save'}</button>
        <p className="mt-4 flex items-start gap-2 text-xs text-slate"><ShieldCheck size={15} className="mt-px shrink-0 text-[var(--ac-green)]"/> Your bookings are saved on this account, so you can see them on any phone without a booking number.</p>
      </form>
    </div>
  </GuestShell>;
}
