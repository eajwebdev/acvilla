import * as Dialog from '@radix-ui/react-dialog';
import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { Link, router, usePage } from '@inertiajs/react';
import { CalendarCheck2, CalendarDays, ChevronDown, LayoutDashboard, LogOut, Mail, MapPin, Menu, Phone, UserRound, X } from 'lucide-react';
import { useEffect, useLayoutEffect, useState, type PropsWithChildren, type SVGProps } from 'react';
import Brand from './Brand';
import FlashToast from './FlashToast';
import GoogleButton, { GoogleG } from './GoogleButton';
import { dashboardHref, useScrolled } from './Shell';
import type { TenantSettings } from '../types';
import type { SiteContent } from './siteContent';
import '../../css/acvilla.css';

export type SiteTenant = { slug: string; business_name: string; logo: string | null; primary_color: string; secondary_color: string; business_email: string | null; business_phone: string | null; hero_headline: string | null; hero_description: string | null; hero_image: string | null; business_address: string | null; settings?: TenantSettings; site_content?: SiteContent } | null;
type GuestShellProps = PropsWithChildren<{ tenant: SiteTenant }>;

/** Facebook's "f" mark (lucide no longer ships brand icons). */
export function Facebook({ size = 16, ...props }: SVGProps<SVGSVGElement> & { size?: number }) {
  return <svg viewBox="0 0 24 24" width={size} height={size} fill="currentColor" aria-hidden="true" {...props}><path d="M13.5 21v-7.5H16l.4-3h-2.9V8.6c0-.9.3-1.5 1.5-1.5h1.6V4.4c-.3 0-1.2-.1-2.3-.1-2.3 0-3.8 1.4-3.8 3.9v2.3H8v3h2.5V21h3Z"/></svg>;
}

const tel = (phone: string) => `tel:${phone.replace(/[^\d+]/g, '')}`;

/** Which home-page section is on screen, for highlighting its menu link. */
function useActiveSection(ids: readonly string[], enabled: boolean) {
  const [active, setActive] = useState('');
  const key = ids.join(',');
  useEffect(() => {
    if (!enabled) return;
    const seen = new Map<string, boolean>();
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => seen.set(entry.target.id, entry.isIntersecting));
      setActive(ids.find(id => seen.get(id)) ?? '');
    }, { rootMargin: '-45% 0px -50% 0px' });
    ids.forEach(id => { const el = document.getElementById(id); if (el) observer.observe(el); });
    return () => observer.disconnect();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, enabled]);
  return active;
}

/** The AC Villa guest site: info bar, sticky menu bar, page, footer. */
export default function GuestShell({ tenant, children }: GuestShellProps) {
  const [open, setOpen] = useState(false);
  const scrolled = useScrolled(40);
  const { auth, googleLogin } = usePage<{ auth: { user: { name: string; role: string; avatar?: string | null } | null }; googleLogin?: boolean }>().props;
  const query = tenant ? `?tenant=${encodeURIComponent(tenant.slug)}` : '';
  const home = `/${query}`;
  const book = `/book${query}`;
  const primary = tenant?.primary_color || '#0A2266';
  const accent = tenant?.secondary_color || '#0039D5';
  const name = tenant?.business_name || 'AC Villa & Private Resort';
  const path = typeof window !== 'undefined' ? window.location.pathname : '/';
  const onHome = path === '/';
  const close = () => setOpen(false);
  // Dialogs (date picker, photo viewer) render outside this wrapper, so the colours and resort fonts are also set on <body>,
  // before the first paint so text never flashes in the admin font.
  useLayoutEffect(() => {
    document.body.style.setProperty('--guest-accent', accent);
    document.body.style.setProperty('--guest-primary', primary);
    document.body.classList.add('ac-guest');
    return () => { document.body.style.removeProperty('--guest-accent'); document.body.style.removeProperty('--guest-primary'); document.body.classList.remove('ac-guest'); };
  }, [accent, primary]);
  // The phone menu is a side drawer (Radix handles focus, Escape and scroll lock); it closes if the screen widens to the full menu.
  useEffect(() => {
    if (!open) return;
    const wide = window.matchMedia('(min-width: 1024px)');
    const onWide = () => { if (wide.matches) setOpen(false); };
    wide.addEventListener('change', onWide);
    return () => wide.removeEventListener('change', onWide);
  }, [open]);
  const content = tenant?.site_content;
  const phones = [tenant?.business_phone, content?.phone_alt].filter((phone): phone is string => !!phone);
  const facebook = content?.facebook_url;
  // Menu links follow the sections switched on in Admin → Website content.
  const sections = ([['rooms', 'Rooms'], ['events', 'Events'], ['amenities', 'Amenities'], ['gallery', 'Gallery'], ['contact', 'Contact']] as const)
    .filter(([id]) => content?.sections[id] ?? true);
  const active = useActiveSection(sections.map(([id]) => id), onHome);
  const current = (id: string) => onHome && active === id ? 'location' as const : undefined;
  const account = auth.user && (auth.user.role === 'GUEST' ? { href: '/booking', label: 'My bookings' } : { href: dashboardHref(auth.user.role), label: 'Dashboard' });
  // Visitors sign in (or create their account) with Google, then come back to this page; email until Google is set up.
  const here = typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/';
  const signIn = googleLogin ? `/auth/google?return=${encodeURIComponent(here)}` : `/login?return=${encodeURIComponent(here)}`;

  return <div className="guest-site ac-site flex min-h-screen flex-col" style={{ '--guest-primary': primary, '--guest-accent': accent } as React.CSSProperties}>
    <FlashToast/>
    <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[60] focus:rounded-lg focus:bg-white focus:px-4 focus:py-2 focus:font-semibold">Skip to content</a>
    <header className={`ac-header ${onHome ? 'ac-header-home' : ''} ${scrolled ? 'is-scrolled' : ''}`}>
      <div className="ac-topbar" aria-label="Contact">
        <div className="container flex h-full items-center justify-between gap-6">
          <p className="inline-flex min-w-0 items-center gap-2 truncate"><MapPin size={14} className="shrink-0"/>{tenant?.business_address || 'North Poblacion, Mabinay, Negros Oriental'}</p>
          <div className="flex shrink-0 items-center gap-5">
            {phones.length > 0 && <span className="inline-flex items-center gap-2"><Phone size={14}/>{phones.map((phone, i) => <span key={phone}>{i > 0 && <span className="mr-2 opacity-50">/</span>}<a href={tel(phone)}>{phone}</a></span>)}</span>}
            {facebook && <a href={facebook} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5"><Facebook size={15}/> Message us</a>}
          </div>
        </div>
      </div>
      <div className={`ac-mainbar ${onHome ? 'ac-mainbar-home' : ''}`}>
        <div className="container flex h-full items-center gap-4">
          <div className="min-w-0 shrink">{tenant?.logo ? <Link href={home} aria-label={`${name} home`}><img src={tenant.logo} alt={name} className="max-h-12 w-auto max-w-[220px] object-contain"/></Link> : <Brand href={home} compact={!onHome} plank={onHome}/>}</div>
          <nav aria-label="Main" className="ac-nav">
            <Link href={home} aria-current={onHome && !active ? 'page' : undefined}>Home</Link>
            {sections.map(([id, label]) => <a key={id} href={`${home}#${id}`} aria-current={current(id)}>{label}</a>)}
          </nav>
          <div className="ml-auto flex shrink-0 items-center gap-2">
            {auth.user && account && <DropdownMenu.Root modal={false}>
              <DropdownMenu.Trigger className="ac-account hidden lg:inline-flex" aria-label="Your account">{auth.user.avatar ? <img src={auth.user.avatar} alt="" referrerPolicy="no-referrer" className="size-8 rounded-full"/> : <span className="ac-avatar">{auth.user.name.trim().charAt(0).toUpperCase() || <UserRound size={16}/>}</span>}<ChevronDown size={15}/></DropdownMenu.Trigger>
              <DropdownMenu.Portal><DropdownMenu.Content align="end" sideOffset={10} className="ac-menu animate-fade">
                <DropdownMenu.Label className="px-3 pb-2 pt-1.5"><span className="block text-sm font-bold text-[#123]">{auth.user.name}</span><span className="block text-xs capitalize text-slate">{auth.user.role.toLowerCase()}</span></DropdownMenu.Label>
                <DropdownMenu.Separator className="my-1 h-px bg-slate-100"/>
                <DropdownMenu.Item asChild><Link href={account.href}><LayoutDashboard size={16}/>{account.label}</Link></DropdownMenu.Item>
                <DropdownMenu.Item onSelect={() => router.post('/logout')}><LogOut size={16}/>Sign out</DropdownMenu.Item>
              </DropdownMenu.Content></DropdownMenu.Portal>
            </DropdownMenu.Root>}
            {!auth.user && <a className="ac-signin hidden lg:inline-flex" href={signIn}>{googleLogin ? <GoogleG size={18}/> : <UserRound size={17}/>} Sign in</a>}
            {!auth.user && <a className="ac-icon-btn inline-grid lg:hidden" href={signIn} aria-label={googleLogin ? 'Sign in with Google' : 'Sign in'}>{googleLogin ? <GoogleG size={19}/> : <UserRound size={20}/>}</a>}
            {auth.user && <Link className="ac-icon-btn inline-grid lg:hidden" href={account!.href} aria-label={account!.label}>{auth.user.avatar ? <img src={auth.user.avatar} alt="" referrerPolicy="no-referrer" className="size-7 rounded-full"/> : <span className="ac-avatar">{auth.user.name.trim().charAt(0).toUpperCase()}</span>}</Link>}
            {auth.user?.role === 'GUEST' && <Link className="ac-mybook hidden lg:inline-flex" href="/booking" aria-current={path === '/booking' ? 'page' : undefined}><CalendarCheck2 size={17}/> My bookings</Link>}
            <Link className="ac-nav-cta hidden sm:inline-flex" href={book}><CalendarDays size={17}/> Book Now</Link>
            {phones[0] && <a href={tel(phones[0])} className="ac-icon-btn inline-grid lg:hidden" aria-label={`Call ${phones[0]}`}><Phone size={19}/></a>}
            <button type="button" className="ac-icon-btn inline-grid lg:hidden" aria-label="Open menu" aria-expanded={open} aria-controls="guest-navigation" onClick={() => setOpen(true)}><Menu size={22}/></button>
          </div>
        </div>
      </div>
    </header>
    <Dialog.Root open={open} onOpenChange={setOpen}>
      <Dialog.Portal>
        <Dialog.Overlay className="ac-drawer-overlay lg:hidden"/>
        <Dialog.Content id="guest-navigation" className="ac-drawer lg:hidden" aria-describedby={undefined}>
        <div className="ac-drawer-head">
          <Brand href={home} compact/>
          <Dialog.Title className="sr-only">Menu</Dialog.Title>
          <Dialog.Close className="ac-icon-btn inline-grid" aria-label="Close menu"><X size={22}/></Dialog.Close>
        </div>
        <nav aria-label="Main" className="grid gap-1 px-4 py-4">
          <Link href={home} onClick={close}>Home</Link>
          {sections.map(([id, label]) => <a key={id} href={`${home}#${id}`} onClick={close} aria-current={current(id)}>{label}</a>)}
          {account && <Link href={account.href} onClick={close}>{account.label}</Link>}
          {auth.user && <button type="button" onClick={() => router.post('/logout')}>Sign out</button>}
        </nav>
        <div className="mt-auto grid gap-3 border-t border-[#EBE8E0] px-4 py-5">
          {!auth.user && (googleLogin ? <GoogleButton returnTo={here} label="Sign in with Google" className="w-full"/>
            : <a href={signIn} className="ac-btn ac-btn-outline w-full justify-center"><UserRound size={18}/> Sign in or create account</a>)}
          {auth.user && <p className="text-center text-sm text-slate">Signed in as <strong className="text-[#141C33]">{auth.user.name}</strong></p>}
          <Link href={book} onClick={close} className="ac-btn ac-btn-primary w-full justify-center"><CalendarDays size={19}/> Book Now</Link>
          <div className="grid grid-cols-2 gap-3">
            {phones[0] && <a href={tel(phones[0])} className="ac-btn ac-btn-outline justify-center !px-3 text-sm"><Phone size={17}/> Call us</a>}
            {facebook && <a href={facebook} target="_blank" rel="noreferrer" className="ac-btn ac-btn-outline justify-center !px-3 text-sm"><Facebook size={17}/> Facebook</a>}
          </div>
          {phones.length > 0 && <p className="text-center text-xs text-slate">{phones.join('  ·  ')}</p>}
        </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
    <main id="main" className="page-enter flex-1">{children}</main>
    <footer className="guest-footer">
      <div className="container grid gap-10 py-16 md:grid-cols-[1.4fr_1fr_1.2fr]">
        <div>
          {onHome ? <Link href={home} aria-label={`${name} home`}><img src="/images/acvilla/ui/logo-plaque.png" alt={name} className="ac-footer-plaque"/></Link> : <Brand href={home}/>}
          {content?.footer_tagline && <p className="ac-display mt-6 max-w-sm text-lg italic text-[#FFD84D]">{content.footer_tagline}</p>}
          {content?.footer_about && <p className="mt-3 max-w-sm text-sm leading-7 text-white/65">{content.footer_about}</p>}
        </div>
        <div><h3 className="font-serif text-lg font-semibold">Explore</h3><div className="mt-4 grid gap-3 text-sm text-white/70">
          {sections.map(([id, label]) => <a key={id} href={`${home}#${id}`} className="hover:text-white">{label}</a>)}
          {auth.user?.role === 'GUEST' && <Link href="/booking" className="hover:text-white">My bookings</Link>}
          {auth.user?.role === 'GUEST' ? <button type="button" onClick={() => router.post('/logout')} className="text-left hover:text-white">Sign out</button>
            : auth.user ? <Link href={dashboardHref(auth.user.role)} className="hover:text-white">Dashboard</Link> : <Link href="/login" className="hover:text-white">Owner &amp; staff sign in</Link>}
        </div></div>
        <div><h3 className="font-serif text-lg font-semibold">Visit &amp; contact</h3><div className="mt-4 grid gap-3 text-sm text-white/70">
          <p className="inline-flex items-start gap-2"><MapPin size={15} className="mt-0.5 shrink-0 text-[#FFD84D]"/>{tenant?.business_address || 'North Poblacion, Mabinay, Negros Oriental'}</p>
          {phones.map(phone => <a key={phone} href={tel(phone)} className="inline-flex items-center gap-2 hover:text-white"><Phone size={15} className="text-[#FFD84D]"/>{phone}</a>)}
          {facebook && <a href={facebook} target="_blank" rel="noreferrer" className="inline-flex items-center gap-2 hover:text-white"><Facebook size={15} className="text-[#FFD84D]"/>AC Villa Private Resort on Facebook</a>}
          {tenant?.business_email && <a href={`mailto:${tenant.business_email}`} className="inline-flex items-center gap-2 hover:text-white"><Mail size={15} className="text-[#FFD84D]"/>{tenant.business_email}</a>}
        </div></div>
      </div>
      <div className="border-t border-white/10"><div className="container flex flex-wrap items-center justify-between gap-3 py-5 text-xs text-white/50"><span>© {new Date().getFullYear()} {name}</span><span>Website by <Link href="/platform" className="font-semibold text-white/70 underline-offset-2 hover:underline">EAJ Web Development</Link></span></div></div>
    </footer>
  </div>;
}
