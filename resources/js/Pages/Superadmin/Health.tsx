import { Head, router } from '@inertiajs/react';
import { CircleAlert, CircleCheck, CircleX, DatabaseBackup, MailWarning, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import DashboardShell from '../../Components/DashboardShell';

type Status = 'ok' | 'warn' | 'fail';
type Check = { key: string; label: string; status: Status; detail: string; fix: string | null };
type Backup = { name: string; size: number; created_at: string };
type Props = { checks: Check[]; overall: Status; backups: Backup[]; environment: Record<string, string>; checkedAt: string };

const look: Record<Status, { icon: typeof CircleCheck; tone: string; word: string }> = {
  ok: { icon: CircleCheck, tone: 'text-emerald-600', word: 'OK' },
  warn: { icon: CircleAlert, tone: 'text-amber-600', word: 'Needs attention' },
  fail: { icon: CircleX, tone: 'text-rose-600', word: 'Action required' },
};
const banner: Record<Status, [string, string]> = {
  ok: ['Everything is running normally.', 'border-emerald-200 bg-emerald-50 text-emerald-900'],
  warn: ['The platform is running, but some items need attention.', 'border-amber-200 bg-amber-50 text-amber-900'],
  fail: ['Some problems could lose bookings, emails or data. Fix the items marked “Action required”.', 'border-rose-200 bg-rose-50 text-rose-900'],
};
const when = (iso: string) => new Date(iso).toLocaleString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
const mb = (bytes: number) => `${(bytes / 1048576).toFixed(bytes < 1048576 ? 2 : 1)} MB`;

export default function Health({ checks, overall, backups, environment, checkedAt }: Props) {
  const [busy, setBusy] = useState<string | null>(null);
  const post = (url: string) => { setBusy(url); router.post(url, {}, { preserveScroll: true, onFinish: () => setBusy(null) }); };
  const refresh = () => { setBusy('refresh'); router.reload({ onFinish: () => setBusy(null) }); };
  const [message, tone] = banner[overall];

  return <DashboardShell platform title="System health" subtitle="Whether the server has what it needs to take bookings, send emails and keep data safe."
    actions={<button type="button" className="btn-soft inline-flex items-center gap-2" disabled={busy !== null} onClick={refresh}><RefreshCw size={16} className={busy === 'refresh' ? 'animate-spin' : ''}/> Check again</button>}>
    <Head title="System health"/>
    <div className={`mb-6 rounded-2xl border p-4 text-sm font-semibold ${tone}`} role="status">{message} <span className="font-normal opacity-75">Checked {when(checkedAt)}.</span></div>

    <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
      <ul className="surface-card divide-y divide-slate-100 overflow-hidden">
        {checks.map(check => { const { icon: Icon, tone: color, word } = look[check.status]; return <li key={check.key} className="flex gap-4 p-5">
          <Icon size={22} className={`mt-0.5 shrink-0 ${color}`} aria-hidden/>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3"><h2 className="font-semibold text-navy">{check.label}</h2><span className={`text-xs font-bold uppercase tracking-wide ${color}`}>{word}</span></div>
            <p className="mt-1 text-sm text-slate">{check.detail}</p>
            {check.fix && <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-navy"><span className="font-semibold">How to fix: </span>{check.fix}</p>}
          </div>
        </li>; })}
      </ul>

      <div className="grid h-fit gap-6">
        <section className="surface-card p-5">
          <h2 className="text-lg font-semibold">Backups</h2>
          <p className="mt-1 text-sm text-slate">Made every night at 2:30 AM. Copy them to another machine or cloud storage too, since they sit on this server.</p>
          <button type="button" className="btn-pink mt-4 inline-flex w-full items-center justify-center gap-2" disabled={busy !== null} onClick={() => post('/superadmin/health/backup')}><DatabaseBackup size={16}/>{busy === '/superadmin/health/backup' ? 'Backing up…' : 'Back up now'}</button>
          {backups.length ? <ul className="mt-4 grid gap-1.5 text-sm">{backups.map(b => <li key={b.name} className="flex justify-between gap-3"><span className="truncate">{when(b.created_at)}</span><span className="shrink-0 text-slate">{mb(b.size)}</span></li>)}</ul>
            : <p className="mt-4 text-sm text-slate">No backups yet.</p>}
        </section>
        <section className="surface-card p-5">
          <h2 className="text-lg font-semibold">Guest emails</h2>
          <p className="mt-1 text-sm text-slate">Failed emails are retried automatically for 48 hours. Retry the ones that are due now, e.g. after fixing the mail settings.</p>
          <button type="button" className="btn-navy mt-4 inline-flex w-full items-center justify-center gap-2" disabled={busy !== null} onClick={() => post('/superadmin/health/retry-emails')}><MailWarning size={16}/>{busy === '/superadmin/health/retry-emails' ? 'Retrying…' : 'Retry failed emails'}</button>
        </section>
        <section className="surface-card p-5">
          <h2 className="text-lg font-semibold">Server</h2>
          <dl className="mt-3 grid gap-2 text-sm">{Object.entries({ Environment: environment.app, PHP: environment.php, Laravel: environment.laravel, Database: environment.database, Mailer: environment.mailer, Timezone: environment.timezone }).map(([label, value]) =>
            <div key={label} className="flex justify-between gap-3 border-b border-slate-100 pb-2"><dt className="text-slate">{label}</dt><dd className="font-semibold">{value}</dd></div>)}</dl>
          <p className="mt-3 text-xs text-slate">From a terminal: <code className="rounded bg-slate-100 px-1">php artisan platform:health</code></p>
        </section>
      </div>
    </div>
  </DashboardShell>;
}
