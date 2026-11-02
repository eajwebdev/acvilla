import { Link, router, usePage } from '@inertiajs/react';
import { ArrowUpRight, LayoutDashboard, LogOut, Menu, X } from 'lucide-react';
import { useEffect, useState, type PropsWithChildren } from 'react';
import Brand from './Brand';
import FlashToast from './FlashToast';

type AuthUser = { name: string; role: string } | null;

export const dashboardHref = (role: string) => role === 'SUPERADMIN' ? '/superadmin' : role === 'GUEST' ? '/booking' : role === 'STAFF' ? '/admin/my-tasks' : '/admin';

/** Hook for the frosted headers: true once the page has scrolled past the top. */
export function useScrolled(offset = 8) {
  const [scrolled, setScrolled] = useState(false);
  useEffect(() => {
    const update = () => setScrolled(window.scrollY > offset);
    update();
    window.addEventListener('scroll', update, { passive: true });
    return () => window.removeEventListener('scroll', update);
  }, [offset]);
  return scrolled;
}

const links = [['/stays', 'Explore stays'], ['/platform', 'Platform'], ['/quotation', 'Pricing']] as const;

/** Marketing and account pages for the StaySuite platform itself (not a client's guest site). */
export default function Shell({ children }: PropsWithChildren) {
  const { auth } = usePage<{ auth: { user: AuthUser } }>().props;
  const [open, setOpen] = useState(false);
  const scrolled = useScrolled();
  const path = typeof window !== 'undefined' ? window.location.pathname : '';
  return <div className="flex min-h-screen flex-col bg-[var(--background)]">
    <FlashToast/>
    <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:font-semibold">Skip to content</a>
    <header className={`guest-header ${scrolled || open ? 'is-scrolled' : ''}`}>
      <div className="container flex h-[72px] items-center justify-between gap-6">
        <Brand compact/>
        <nav aria-label="Main" className="hidden items-center gap-1 md:flex">
          {links.map(([href, label]) => <Link key={href} href={href} aria-current={path === href ? 'page' : undefined} className={`rounded-full px-4 py-2 text-sm font-semibold transition ${path === href ? 'bg-navy/[.06] text-navy' : 'text-slate hover:text-navy'}`}>{label}</Link>)}
        </nav>
        <div className="hidden items-center gap-2 md:flex">
          {auth.user ? <>
            <Link href={dashboardHref(auth.user.role)} className="btn btn-navy min-h-10 px-4 text-sm"><LayoutDashboard size={16}/> Dashboard</Link>
            <button type="button" onClick={() => router.post('/logout')} className="btn btn-ghost min-h-10 px-3 text-sm" aria-label="Sign out" title="Sign out"><LogOut size={16}/></button>
          </> : <>
            <Link href="/login" className="rounded-full px-4 py-2 text-sm font-semibold text-slate hover:text-navy">Sign in</Link>
            <Link href="/quotation" className="btn btn-pink min-h-10 rounded-full px-5 text-sm">Get started <ArrowUpRight size={16}/></Link>
          </>}
        </div>
        <button type="button" aria-label={open ? 'Close menu' : 'Open menu'} aria-expanded={open} aria-controls="site-nav" className="guest-menu-button" onClick={() => setOpen(!open)}>{open ? <X/> : <Menu/>}</button>
      </div>
      {open && <nav id="site-nav" aria-label="Main" className="animate-fade border-t border-[var(--border)] bg-white px-4 pb-5 pt-2 md:hidden">
        {links.map(([href, label]) => <Link key={href} href={href} className="block rounded-xl px-3 py-3 font-semibold text-navy hover:bg-slate-50">{label}</Link>)}
        <div className="mt-3 grid gap-2 border-t border-[var(--border)] pt-4">
          {auth.user ? <><Link href={dashboardHref(auth.user.role)} className="btn btn-navy">Dashboard</Link><button type="button" onClick={() => router.post('/logout')} className="btn btn-light">Sign out</button></>
            : <><Link href="/quotation" className="btn btn-pink">Get started</Link><Link href="/login" className="btn btn-light">Sign in</Link></>}
        </div>
      </nav>}
    </header>
    <main id="main" className="page-enter flex-1">{children}</main>
    <SiteFooter/>
  </div>;
}

export function SiteFooter() {
  return <footer className="bg-navy text-white">
    <div className="container grid gap-10 py-14 md:grid-cols-[1.6fr_1fr_1fr]">
      <div><Brand tone="light"/><p className="mt-5 max-w-xs text-sm leading-6 text-white/60">Direct bookings, payments, housekeeping and insights for modern Philippine hospitality.</p></div>
      <FooterColumn title="Product" links={[['/platform', 'Platform'], ['/quotation', 'Pricing'], ['/stays', 'Demo guest site']]}/>
      <FooterColumn title="Account" links={[['/login', 'Sign in'], ['/register', 'Create guest account'], ['/booking', 'My bookings']]}/>
    </div>
    <div className="border-t border-white/10"><div className="container flex flex-wrap items-center justify-between gap-3 py-5 text-xs text-white/45"><span>© {new Date().getFullYear()} EAJ Web Development Services</span><span>Built in the Philippines 🇵🇭</span></div></div>
  </footer>;
}

function FooterColumn({ title, links }: { title: string; links: readonly (readonly [string, string])[] }) {
  return <div><h3 className="text-sm font-bold">{title}</h3><ul className="mt-4 grid gap-2.5 text-sm text-white/60">{links.map(([href, label]) => <li key={href}><Link href={href} className="transition hover:text-white">{label}</Link></li>)}</ul></div>;
}
