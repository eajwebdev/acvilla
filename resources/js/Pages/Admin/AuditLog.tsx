import { Head, Link, router } from '@inertiajs/react';
import { useState, type FormEvent } from 'react';
import SettingsLayout from '../../Components/SettingsLayout';

type Log = { id: number; action: string; entity_type: string; entity_id: number; metadata: Record<string, unknown> | null; ip_address: string | null; created_at: string; actor: { id: number; name: string } | null };
type Filters = { action?: string; user?: number; from?: string; to?: string };

/** "booking.status_changed" → "Booking status changed" */
const describe = (action: string) => { const text = action.replace(/[._]/g, ' '); return text.charAt(0).toUpperCase() + text.slice(1); };
const details = (metadata: Log['metadata']) => Object.entries(metadata ?? {}).map(([key, value]) => `${key.replace(/_/g, ' ')}: ${typeof value === 'object' ? JSON.stringify(value) : String(value)}`).join(' · ');

export default function AuditLog({ logs, filters, areas, people }: { logs: { data: Log[]; links: { url: string | null; label: string; active: boolean }[] }; filters: Filters; areas: string[]; people: { id: number; name: string }[] }) {
  const [f, setF] = useState<Filters>(filters);
  const apply = (ev: FormEvent) => { ev.preventDefault(); router.get('/admin/settings/audit', Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true }); };

  return <SettingsLayout title="Audit log" subtitle="Every change made by your team, newest first."><Head title="Audit log · Settings"/>
    <form onSubmit={apply} className="surface-card mb-4 grid gap-3 p-4 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_auto_auto_auto] xl:items-end">
      <label className="field">Area<select value={f.action ?? ''} onChange={ev => setF({ ...f, action: ev.target.value })}><option value="">Everything</option>{areas.map(area => <option key={area} value={area}>{describe(area)}</option>)}</select></label>
      <label className="field">Person<select value={f.user ?? ''} onChange={ev => setF({ ...f, user: ev.target.value ? Number(ev.target.value) : undefined })}><option value="">Anyone</option>{people.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
      <label className="field">From<input type="date" value={f.from ?? ''} onChange={ev => setF({ ...f, from: ev.target.value })}/></label>
      <label className="field">To<input type="date" min={f.from} value={f.to ?? ''} onChange={ev => setF({ ...f, to: ev.target.value })}/></label>
      <button className="btn btn-navy">Filter</button>
    </form>
    <div className="surface-card divide-y divide-slate-100">{logs.data.map(log => <div key={log.id} className="grid gap-1 p-4 text-sm sm:grid-cols-[170px_minmax(0,1fr)]">
      <time className="text-xs text-slate" dateTime={log.created_at}>{new Date(log.created_at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}</time>
      <div className="min-w-0"><p><strong>{log.actor?.name ?? 'System or guest'}</strong> · {describe(log.action)}</p>{log.metadata && Object.keys(log.metadata).length > 0 && <p className="mt-0.5 break-words text-xs text-slate">{details(log.metadata)}</p>}{log.ip_address && <p className="mt-0.5 text-[11px] text-slate/70">IP {log.ip_address}</p>}</div>
    </div>)}{!logs.data.length && <p className="p-8 text-center text-sm text-slate">Nothing matches these filters.</p>}</div>
    {logs.links.length > 3 && <nav aria-label="Pagination" className="mt-4 flex flex-wrap justify-center gap-2">{logs.links.map((link, i) => link.url ? <Link key={i} href={link.url} preserveScroll className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-navy text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }}/> : null)}</nav>}
  </SettingsLayout>;
}
