import { Head, Link, router } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import SettingsLayout from '../../Components/SettingsLayout';
import { money } from '../../types';

type Deleted = { id: number; reference: string; guest_name: string; guest_email: string; check_in: string; check_out: string; status: string; total: string; deleted_at: string; property: { name: string } | null; room: { name: string } | null };

export default function DeletedBookings({ bookings }: { bookings: { data: Deleted[]; links: { url: string | null; label: string; active: boolean }[] } }) {
  return <SettingsLayout title="Deleted bookings" subtitle="Cancelled, expired and no-show bookings you removed from Reservations. Restore any of them at any time."><Head title="Deleted bookings · Settings"/>
    <div className="grid gap-3">{bookings.data.map(b => <div key={b.id} className="surface-card flex flex-wrap items-center justify-between gap-4 p-5">
      <div className="min-w-0"><p className="text-xs font-bold text-pink">{b.reference}</p><p className="mt-1 font-semibold">{b.guest_name} <span className="font-normal text-slate">· {b.guest_email}</span></p>
        <p className="mt-1 text-sm text-slate">{b.property?.name} · {b.room?.name} · {b.check_in.slice(0, 10)} → {b.check_out.slice(0, 10)} · {money(b.total)} · {b.status.replaceAll('_', ' ').toLowerCase()}</p>
        <p className="mt-1 text-xs text-slate">Deleted {new Date(b.deleted_at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}</p></div>
      <button type="button" onClick={() => router.put(`/admin/settings/deleted-bookings/${b.id}/restore`, {}, { preserveScroll: true })} className="btn btn-light border border-slate-200"><RotateCcw size={15}/> Restore</button>
    </div>)}{!bookings.data.length && <div className="surface-card p-8 text-center text-sm text-slate">No deleted bookings. Delete cancelled or expired bookings from Reservations to tidy the list.</div>}</div>
    {bookings.links.length > 3 && <nav aria-label="Pagination" className="mt-4 flex flex-wrap justify-center gap-2">{bookings.links.map((link, i) => link.url ? <Link key={i} href={link.url} className={`rounded-lg px-3 py-2 text-sm ${link.active ? 'bg-navy text-white' : 'bg-white'}`} dangerouslySetInnerHTML={{ __html: link.label }}/> : null)}</nav>}
  </SettingsLayout>;
}
