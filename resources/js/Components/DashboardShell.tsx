import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, Ellipsis, LogOut, Menu, Search, Settings, X } from 'lucide-react';
import { useEffect, useState, type PropsWithChildren, type ReactNode } from 'react';
import { BrandIcon } from './Brand';
import CommandPalette from './CommandPalette';
import FlashToast from './FlashToast';
import { refreshPage, useLive } from './live';
import NotificationBell from './NotificationBell';
import { moreLinks, opsNav, platformNav, staffNav } from './nav';

type Props = PropsWithChildren<{ title?: string; subtitle?: ReactNode; actions?: ReactNode; platform?: boolean; bare?: boolean }>;

const staffShortcuts = staffNav.filter(([href]) => href !== '/admin/calendar');
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);

export default function DashboardShell({ children, title, subtitle, actions, platform = false, bare = false }: Props) {
  const [open, setOpen] = useState(false);
  const [palette, setPalette] = useState(false);
  const { auth, myTaskCount, requestCount, notificationCount } = usePage<{ auth: { user: { name: string; role: string } }; myTaskCount?: number; requestCount?: number; notificationCount?: number }>().props;
  const path = typeof window !== 'undefined' ? window.location.pathname : '';
  const staff = auth.user.role === 'STAFF' && !platform;
  const nav = platform ? platformNav : staff ? staffNav : opsNav;
  const isActive = (href: string) => href === '/admin' || href === '/superadmin' ? path === href : path.startsWith(href);
  const [more, setMore] = useState(() => moreLinks.some(([href]) => href !== '/' && path.startsWith(href)));
  const inSettings = path.startsWith('/admin/settings') || path === '/admin/branding';
  useEffect(() => { document.body.style.overflow = open ? 'hidden' : ''; return () => { document.body.style.overflow = ''; }; }, [open]);
  // New requests, receipts and changes made by other staff show up on every admin page without a refresh.
  useLive<{ stamp: string; unread: number }>(platform ? null : '/admin/live', () => { refreshPage(); window.dispatchEvent(new Event('tapp:changed')); },
    { onTick: data => window.dispatchEvent(new CustomEvent('tapp:unread', { detail: data.unread })) });
  // Like the guest site's menu: the current page is a light pill, the rest soft white on the logo's green.
  const linkClass = (on: boolean) => `flex items-center gap-3 rounded-full px-4 py-2.5 text-[15px] transition ${on ? 'bg-[rgba(0,57,213,.1)] font-semibold text-forest' : 'font-medium text-[#2E333E] hover:bg-[rgba(0,57,213,.07)] hover:text-forest'}`;
  const home = platform ? '/superadmin' : staff ? '/admin/my-tasks' : '/admin';
  const role = auth.user.role === 'ADMIN' ? 'Admin' : auth.user.role === 'STAFF' ? 'Staff' : 'Platform owner';

  return <div className={`tapp min-h-screen md:grid md:grid-cols-[200px_minmax(0,1fr)] ${staff ? 'staff-workspace' : ''}`}>
    <FlashToast/>
    <CommandPalette role={auth.user.role} platform={platform} open={palette} onOpenChange={setPalette}/>
    <div className="sticky top-0 z-40 flex h-14 items-center justify-between border-b border-[var(--line)] bg-white/95 px-3 backdrop-blur md:hidden">
      <BrandIcon href={home} height={40}/>
      <div className="flex items-center gap-1">{!platform && <NotificationBell initial={notificationCount}/>}<button type="button" className="grid size-10 place-items-center rounded-full text-forest" aria-label="Search pages" onClick={() => setPalette(true)}><Search size={20}/></button><button type="button" className="grid size-10 place-items-center rounded-full text-forest" aria-label={open ? 'Close navigation' : 'Open navigation'} aria-expanded={open} onClick={() => setOpen(!open)}>{open ? <X/> : <Menu/>}</button></div>
    </div>
    {open && <button type="button" className="fixed inset-0 z-40 bg-black/40 md:hidden" aria-label="Close navigation" onClick={() => setOpen(false)}/>}
    <aside className={`${open ? 'fixed inset-y-0 left-0 z-50 flex w-[260px]' : 'hidden'} flex-col border-r border-[var(--line)] bg-white text-[var(--ink)] md:sticky md:top-0 md:flex md:h-screen md:w-auto thin-scroll overflow-y-auto`}>
      <div className="px-3 pb-1 pt-4"><BrandIcon href={home}/></div>
      <button type="button" onClick={() => setPalette(true)} className="mx-2.5 mt-4 flex items-center gap-2 rounded-full border border-[var(--line)] bg-[#FBFAF6] px-3.5 py-2 text-left text-[13px] font-medium text-[var(--muted)] transition hover:border-[#D8D2C4] hover:text-forest"><Search size={15}/><span className="flex-1">Search</span><kbd className="rounded-md border border-[var(--line)] bg-white px-1.5 font-mono text-[10px] text-[var(--muted)]">{isMac ? '⌘' : 'Ctrl'} K</kbd></button>
      <p className="mt-5 px-5 text-[10px] font-semibold uppercase tracking-[.2em] text-gold">{platform ? 'Platform' : staff ? 'Staff workspace' : 'Operations'}</p>
      <nav className="mt-2 grid gap-1 px-2.5" aria-label="Main">
        {nav.map(([href, label, Icon]) => <Link key={href} href={href} onClick={() => setOpen(false)} aria-current={isActive(href) ? 'page' : undefined} className={linkClass(isActive(href))}><Icon size={19} strokeWidth={1.8}/>{label}
          {href === '/admin/my-tasks' && (myTaskCount ?? 0) > 0 && <span className={`num ml-auto grid min-w-5 place-items-center rounded-full px-1.5 text-[11px] font-bold ${isActive(href) ? 'bg-forest text-white' : 'bg-green-600 text-white'}`} aria-label={`${myTaskCount} open`}>{myTaskCount}</span>}
          {href === '/admin/bookings' && (requestCount ?? 0) > 0 && <span className="num ml-auto grid min-w-5 place-items-center rounded-full bg-amber-400 px-1.5 text-[11px] font-bold text-[#3a2a00]" title="Reservation requests waiting for your call" aria-label={`${requestCount} requests to call`}>{requestCount}</span>}</Link>)}
        {!platform && !staff && <>
          <button type="button" onClick={() => setMore(!more)} aria-expanded={more} className={linkClass(false)}><Ellipsis size={19}/>More<ChevronDown size={15} className={`ml-auto transition ${more ? 'rotate-180' : ''}`}/></button>
          {more && <div className="ml-5 grid gap-0.5 border-l border-[var(--line)] pl-2">
            {moreLinks.map(([href, label, Icon]) => <Link key={href} href={href} onClick={() => setOpen(false)} className={`flex items-center gap-2.5 rounded-lg px-2.5 py-2 text-[13px] font-semibold ${href !== '/' && path.startsWith(href) ? 'bg-[rgba(0,57,213,.1)] text-forest' : 'text-[var(--muted)] hover:text-forest'}`}><Icon size={15}/>{label}</Link>)}
          </div>}
        </>}
        {staff && <Link href="/admin/settings/profile" onClick={() => setOpen(false)} className={linkClass(inSettings)}><Settings size={19}/>My profile</Link>}
      </nav>
      <div className="mt-auto border-t border-[var(--line)] p-2.5">
        <div className={`flex items-center gap-2.5 rounded-xl p-2 ${inSettings ? 'bg-[rgba(0,57,213,.1)]' : ''}`}>
          <span className="grid size-[34px] shrink-0 place-items-center rounded-full bg-sea text-sm font-bold text-white">{(auth.user.name.trim()[0] || '?').toUpperCase()}</span>
          <div className="min-w-0 flex-1"><p className="truncate text-[13px] font-bold">{auth.user.name}</p><p className="text-[11px] font-semibold text-[var(--muted)]">{role}</p></div>
          {platform
            ? <button type="button" title="Sign out" aria-label="Sign out" onClick={() => router.post('/logout')} className="grid size-8 place-items-center rounded-lg text-[var(--muted)] hover:bg-[#EDF1FC] hover:text-forest"><LogOut size={17}/></button>
            : <Link href="/admin/settings/profile" title="Settings" aria-label="Settings" className={`grid size-8 place-items-center rounded-lg ${inSettings ? 'border border-forest text-forest' : 'text-[var(--muted)] hover:bg-[#EDF1FC] hover:text-forest'}`}><Settings size={17}/></Link>}
        </div>
      </div>
    </aside>
    <div className="min-w-0">
      {!platform && <div className="sticky top-0 z-30 hidden h-14 items-center justify-between gap-4 border-b border-[var(--line)] bg-white/90 px-5 backdrop-blur md:flex md:px-7">
        <p className="truncate text-[13px] font-medium text-[var(--muted)]">{new Date().toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' })}</p>
        <NotificationBell initial={notificationCount}/>
      </div>}
      {bare ? children : <>
        <header className="page-head px-5 pb-4 pt-5 md:px-7">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0"><h1 className="font-serif text-[32px] font-semibold leading-[1.15] text-forest">{title}</h1>{subtitle && <div className="mt-1 text-[13px] font-semibold text-[var(--muted)]">{subtitle}</div>}</div>
            <div className="flex flex-wrap items-center gap-2">{actions}</div>
          </div>
        </header>
        <main key={path} className="page-enter px-5 pb-10 md:px-7">{children}</main>
      </>}
    </div>
    {staff && <nav className="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-[var(--line)] bg-white pb-[env(safe-area-inset-bottom)] shadow-[0_-8px_24px_rgba(10,34,102,.08)] md:hidden" aria-label="Staff shortcuts">
      {staffShortcuts.map(([href, label, Icon]) => <Link key={href} href={href} aria-current={isActive(href) ? 'page' : undefined} className={`flex min-h-[62px] flex-col items-center justify-center gap-1 px-1 text-[11px] font-bold ${isActive(href) ? 'text-forest' : 'text-[#5E6679]'}`}><Icon size={20}/><span>{label}</span></Link>)}
    </nav>}
  </div>;
}
