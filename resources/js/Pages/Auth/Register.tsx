import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, Check, Loader2 } from 'lucide-react';
import AuthLayout, { PasswordInput } from '../../Components/AuthLayout';
import GoogleButton from '../../Components/GoogleButton';

const checks: [string, (value: string) => boolean][] = [
  ['8+ characters', value => value.length >= 8],
  ['Upper & lower case', value => /[a-z]/.test(value) && /[A-Z]/.test(value)],
  ['A number or symbol', value => /[\d\W]/.test(value)],
];
const strengthLabel = ['Too short', 'Weak', 'Good', 'Strong'];
const strengthColor = ['bg-rose-400', 'bg-rose-400', 'bg-amber-400', 'bg-emerald-500'];

export default function Register({ google = false, return: back }: { google?: boolean; return?: string | null }) {
  const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
  const passed = checks.filter(([, test]) => test(form.data.password)).length;
  const mismatch = form.data.password_confirmation !== '' && form.data.password !== form.data.password_confirmation;
  return <AuthLayout eyebrow="Join us" title="Create your account." intro="Save your details, track reservations and leave reviews after your stay."
    footer={<>Already have an account? <Link href="/login" className="font-semibold text-pink hover:underline">Sign in</Link></>}>
    <Head title="Create account"/>
    {google && <><GoogleButton returnTo={back ?? '/booking'} label="Sign up with Google" className="mt-8 w-full"/><p className="mt-6 flex items-center gap-3 text-xs font-semibold uppercase tracking-wider text-slate before:h-px before:flex-1 before:bg-slate-200 after:h-px after:flex-1 after:bg-slate-200">or with email</p></>}
    <form className="mt-8 grid gap-5" onSubmit={e => { e.preventDefault(); form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') }); }}>
      <label className="field">Full name
        <input required autoFocus autoComplete="name" value={form.data.name} onChange={e => form.setData('name', e.target.value)}/>
        {form.errors.name && <span className="field-error">{form.errors.name}</span>}
      </label>
      <label className="field">Email
        <input type="email" required autoComplete="email" value={form.data.email} onChange={e => form.setData('email', e.target.value)}/>
        {form.errors.email && <span className="field-error">{form.errors.email}</span>}
      </label>
      <div className="field">
        <label htmlFor="password">Password</label>
        <PasswordInput id="password" required minLength={8} autoComplete="new-password" aria-describedby="password-strength" value={form.data.password} onChange={e => form.setData('password', e.target.value)}/>
        {form.data.password && <div id="password-strength" className="grid gap-2" aria-live="polite">
          <div className="flex items-center gap-2"><div className="grid flex-1 grid-cols-3 gap-1">{[0, 1, 2].map(i => <span key={i} className={`h-1.5 rounded-full transition ${i < passed ? strengthColor[passed] : 'bg-slate-200'}`}/>)}</div><span className="text-xs font-semibold text-slate">{strengthLabel[passed]}</span></div>
          <ul className="flex flex-wrap gap-x-4 gap-y-1">{checks.map(([label, test]) => <li key={label} className={`inline-flex items-center gap-1 text-xs font-medium ${test(form.data.password) ? 'text-emerald-700' : 'text-slate'}`}><Check size={13}/>{label}</li>)}</ul>
        </div>}
        {form.errors.password && <span className="field-error">{form.errors.password}</span>}
      </div>
      <div className="field">
        <label htmlFor="password_confirmation">Confirm password</label>
        <PasswordInput id="password_confirmation" required autoComplete="new-password" value={form.data.password_confirmation} onChange={e => form.setData('password_confirmation', e.target.value)} aria-invalid={mismatch}/>
        {mismatch && <span className="field-error">Passwords don’t match yet.</span>}
      </div>
      <button className="btn btn-pink mt-1 w-full" disabled={form.processing || mismatch}>{form.processing ? <Loader2 size={18} className="animate-spin"/> : null}Create account {!form.processing && <ArrowRight size={18}/>}</button>
    </form>
  </AuthLayout>;
}
