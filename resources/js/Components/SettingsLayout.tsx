import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Trash2 } from 'lucide-react';
import type { PropsWithChildren, ReactNode } from 'react';
import DashboardShell from './DashboardShell';
import { settingsGroups as groups } from './nav';


export default function SettingsLayout({ title, subtitle, actions, children }: PropsWithChildren<{ title: string; subtitle: ReactNode; actions?: ReactNode }>) {
  const { auth } = usePage<{ auth: { user: { name: string; role: string } } }>().props;
  const admin = auth.user.role === 'ADMIN';
  const active = typeof window !== 'undefined' ? window.location.pathname : '';
  const linkClass = (on: boolean) => `flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-[13.5px] font-semibold ${on ? 'bg-[var(--mint)] text-forest' : 'text-[#3D4A43] hover:bg-[#F1F5F0] hover:text-forest'}`;
  return <DashboardShell bare>
    <div className="lg:grid lg:min-h-[calc(100vh-3.5rem)] lg:grid-cols-[218px_minmax(0,1fr)]">
      <nav aria-label="Settings sections" className="border-b border-[var(--line)] bg-white px-3 pb-3 pt-5 thin-scroll lg:sticky lg:top-14 lg:h-[calc(100vh-3.5rem)] lg:overflow-y-auto lg:border-b-0 lg:border-r">
        <div className="px-1.5"><h2 className="font-serif text-[26px] font-semibold leading-[1.15] text-forest">Settings</h2><p className="mt-0.5 text-[11.5px] font-semibold text-[var(--muted)]">Signed in as {auth.user.name} · {admin ? 'Admin' : 'Staff'}</p></div>
        <div className="mt-2 flex gap-1 overflow-x-auto lg:block">
          {groups.filter(([, , adminOnly]) => admin || !adminOnly).map(([group, links]) => <div key={group} className="shrink-0 lg:mb-1">
            <p className="hidden px-3 pb-1 pt-3 text-[10.5px] font-bold uppercase tracking-[.12em] text-[var(--muted)] lg:block">{group}</p>
            <div className="flex gap-1 lg:grid">{links.map(([href, label, Icon]) => <Link key={href} href={href} aria-current={active === href ? 'page' : undefined} className={`${linkClass(active === href)} whitespace-nowrap lg:whitespace-normal`}><Icon size={16} strokeWidth={1.8}/>{label}</Link>)}</div>
          </div>)}
          <div className="shrink-0 lg:mt-2 lg:grid lg:gap-1 lg:border-t lg:border-[var(--line)] lg:pt-3">
            <button type="button" onClick={() => router.post('/logout')} className={`${linkClass(false)} whitespace-nowrap`}><LogOut size={16}/> Sign out</button>
            <Link href="/admin/settings/profile#delete-account" className="hidden items-center gap-2.5 rounded-xl px-3 py-2 text-[13.5px] font-semibold text-red-700 hover:bg-red-50 lg:flex"><Trash2 size={16}/> Delete account</Link>
          </div>
        </div>
      </nav>
      <section className="min-w-0">
        <header className="page-head px-5 pb-4 pt-6 md:px-8"><div className="flex flex-wrap items-start justify-between gap-3"><div><h1 className="font-serif text-[32px] font-semibold leading-[1.15] text-forest">{title}</h1><p className="mt-1 text-[13px] font-semibold text-[var(--muted)]">{subtitle}</p></div>{actions}</div></header>
        <div key={active} className="page-enter max-w-4xl px-5 pb-12 md:px-8">{children}</div>
      </section>
    </div>
  </DashboardShell>;
}
