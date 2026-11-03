import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, BriefcaseBusiness, Check, ClipboardCheck, Loader2, UserRound } from 'lucide-react';
import { useState } from 'react';
import AuthLayout, { PasswordInput } from '../../Components/AuthLayout';
import GoogleButton from '../../Components/GoogleButton';

const roles = [
  ['admin', 'Administrator', 'Property management', BriefcaseBusiness],
  ['staff', 'Staff', 'Daily operations', ClipboardCheck],
  ['guest', 'Guest', 'Booking experience', UserRound],
] as const;

export default function Login({ google = false, return: back }: { google?: boolean; return?: string | null }) {
  const { demo } = usePage<{ demo: boolean }>().props;
  const [demoRole, setDemoRole] = useState('');
  const form = useForm<{ email: string; password: string; remember: boolean }>({
    email: '',
    password: '',
    remember: false,
  });

  return <AuthLayout eyebrow="Welcome" title="Sign in to continue." intro={google ? 'Book your stay and follow every booking in one place.' : 'Book your stay, follow your bookings, or manage the resort.'}
    footer={google ? <>New guest? <strong className="text-navy">Continue with Google</strong> creates your account.</> : <>New here? <Link href="/register" className="font-semibold text-pink hover:underline">Create a guest account</Link></>}>
    <Head title="Sign in"/>
    {google && <section className="mt-8 rounded-2xl border border-[#E3DED2] bg-[linear-gradient(135deg,#EDF1FC,#fff_70%)] p-5" aria-labelledby="guest-signin">
      <div className="flex items-center gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-forest text-white"><UserRound size={19}/></span>
        <div><h2 id="guest-signin" className="text-[15px] font-bold text-navy">Guests</h2><p className="text-xs text-slate">Sign in or create your account in one tap.</p></div>
      </div>
      <GoogleButton returnTo={back ?? '/booking'} className="mt-4 w-full"/>
      <ul className="mt-3 grid gap-1 text-xs text-slate">
        <li className="flex items-center gap-2"><Check size={13} className="text-emerald-700"/>See all your bookings and their status on any phone</li>
        <li className="flex items-center gap-2"><Check size={13} className="text-emerald-700"/>No passwords or booking numbers to remember</li>
      </ul>
    </section>}
    {google && <p className="mt-8 flex items-center gap-3 text-xs font-semibold uppercase tracking-wider text-slate before:h-px before:flex-1 before:bg-slate-200 after:h-px after:flex-1 after:bg-slate-200">Owner &amp; staff</p>}
    <form onSubmit={e => { e.preventDefault(); form.post('/login', { onFinish: () => form.reset('password') }); }} className="mt-8 grid gap-5">
      <label className="field">Email address
        <input type="email" required autoFocus={!google} autoComplete="email" placeholder={google ? 'owner@acvilla.ph' : 'you@example.com'} value={form.data.email} onChange={e => form.setData('email', e.target.value)} aria-invalid={Boolean(form.errors.email)}/>
        {form.errors.email && <span className="field-error">{form.errors.email}</span>}
      </label>
      <label className="field">Password
        <PasswordInput required autoComplete="current-password" value={form.data.password} onChange={e => form.setData('password', e.target.value)}/>
      </label>
      <label className="flex w-fit cursor-pointer items-center gap-2.5 text-sm font-medium text-slate">
        <input type="checkbox" className="size-4 rounded accent-[var(--brand-pink)]" checked={form.data.remember} onChange={e => form.setData('remember', e.target.checked)}/>
        Keep me signed in
      </label>
      <button className="btn btn-pink w-full" disabled={form.processing}>{form.processing ? <Loader2 size={18} className="animate-spin"/> : null}Sign in {!form.processing && <ArrowRight size={18}/>}</button>
    </form>

    {demo && <section className="mt-9 rounded-2xl border border-dashed border-[var(--border)] bg-[var(--surface-muted)]/60 p-4 sm:p-5" aria-labelledby="demo-access">
      <div className="flex items-center justify-between gap-3"><h2 id="demo-access" className="text-sm font-bold">Quick demo access</h2><span className="pill border-pink/20 bg-pink/10 text-pink">Demo</span></div>
      <p className="mt-1 text-xs text-slate">Explore the platform with a seeded account. No password needed.</p>
      <div className="mt-4 grid gap-2 sm:grid-cols-3">
        {roles.map(([role, label, detail, Icon]) => <button key={role} type="button" disabled={Boolean(demoRole)}
          onClick={() => { setDemoRole(role); router.post('/demo/login', { role }, { onFinish: () => setDemoRole('') }); }}
          className="group flex items-center gap-3 rounded-xl border border-[var(--border)] bg-white p-3 text-left transition hover:-translate-y-0.5 hover:border-pink/40 hover:shadow-[var(--shadow-soft)] disabled:opacity-60 sm:flex-col sm:items-start">
          <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-pink/10 text-pink transition group-hover:bg-pink group-hover:text-white">{demoRole === role ? <Loader2 size={17} className="animate-spin"/> : <Icon size={17}/>}</span>
          <span className="min-w-0"><strong className="block text-sm">{label}</strong><span className="block text-xs text-slate">{detail}</span></span>
        </button>)}
      </div>
    </section>}
  </AuthLayout>;
}
