import { Link } from '@inertiajs/react';
import { CalendarCheck2, ChartLine, Eye, EyeOff, ShieldCheck } from 'lucide-react';
import { useState, type InputHTMLAttributes, type PropsWithChildren, type ReactNode } from 'react';
import Brand from './Brand';
import FlashToast from './FlashToast';
import PageSign from './PageSign';
import '../../css/acvilla.css';

const highlights = [
  [CalendarCheck2, 'Reservation requests', 'See every new request and call the guest to confirm.'],
  [ChartLine, 'Resort insights', 'Occupancy, revenue and events at a glance.'],
  [ShieldCheck, 'Private & secure', 'Role-based access for owners and staff.'],
] as const;

/** Split-screen frame shared by sign in and registration. */
export default function AuthLayout({ eyebrow, title, intro, children, footer }: PropsWithChildren<{ eyebrow: string; title: string; intro: ReactNode; footer?: ReactNode }>) {
  return <div className="ac-site ac-auth">
    <div className="ac-page-sky" aria-hidden="true"/>
    <FlashToast/>
    <div className="ac-auth-bar"><Brand plank/><Link href="/" className="ac-crumbs relative shrink-0 text-sm font-semibold">Back to resort →</Link></div>
    <div className="ac-auth-grid">
    <div className="min-w-0">
      <main id="main" className="page-enter">
        <PageSign eyebrow={eyebrow} title={title}/>
        <div className="ac-card mt-4 p-6 sm:p-8">
          <p className="text-[15px] leading-7 text-slate">{intro}</p>
          {children}
          <div className="mt-6 text-xs text-slate">{footer ?? <>© {new Date().getFullYear()} AC Villa &amp; Private Resort</>}</div>
        </div>
      </main>
    </div>
    <aside className="ac-auth-side relative hidden overflow-hidden lg:block" aria-hidden="true">
      <img src="/images/acvilla/hero-mabinay.jpg" alt="" className="absolute inset-0 h-full w-full object-cover opacity-60"/>
      <div className="absolute inset-0 bg-[linear-gradient(160deg,rgba(10,34,102,.3)_0%,rgba(10,34,102,.92)_70%)]"/>
            <div className="relative flex h-full flex-col justify-end p-12 text-white xl:p-16">
        <p className="eaj-kicker">AC Villa &amp; Private Resort</p>
        <h2 className="mt-5 max-w-lg font-serif text-5xl font-semibold leading-[1.08]">Your privacy, more memories, <em className="text-[#FFD84D]">more love.</em></h2>
        <ul className="mt-10 grid max-w-xl gap-3">
          {highlights.map(([Icon, label, body]) => <li key={label} className="flex items-start gap-4 rounded-2xl border border-white/10 bg-white/[.06] p-4 backdrop-blur-md">
            <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-[#FFC800] text-[#06195A]"><Icon size={19}/></span>
            <span><strong className="block text-sm">{label}</strong><span className="mt-0.5 block text-sm text-white/65">{body}</span></span>
          </li>)}
        </ul>
      </div>
    </aside>
    </div>
  </div>;
}

/** Password input with a show/hide toggle; renders inside a `.field` label. */
export function PasswordInput(props: Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
  const [show, setShow] = useState(false);
  return <span className="relative block">
    <input {...props} type={show ? 'text' : 'password'} className="!pr-12"/>
    <button type="button" onClick={() => setShow(!show)} aria-label={show ? 'Hide password' : 'Show password'} aria-pressed={show} className="absolute inset-y-0 right-1 my-auto grid size-10 place-items-center rounded-lg text-slate hover:text-navy">{show ? <EyeOff size={18}/> : <Eye size={18}/>}</button>
  </span>;
}
