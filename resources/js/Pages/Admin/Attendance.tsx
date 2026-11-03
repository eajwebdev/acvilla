import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Clock3, LogIn, LogOut, Pencil, Users } from 'lucide-react';
import { useState } from 'react';
import DashboardShell from '../../Components/DashboardShell';

type Entry = { id: number; work_date: string; clocked_in_at: string; clocked_out_at: string | null; method: string } | null;
type StaffRow = { id: number; name: string; entry: Entry };
type Props = { date: string; today: string; entry: Entry; staff: StaffRow[] | null };

const time = (value: string) => new Date(value).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit', timeZone: 'Asia/Manila' });
const day = (value: string) => new Date(`${value}T12:00:00`).toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
const localInput = (value: string) => new Date(value).toLocaleString('sv-SE', { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Manila' }).replace(' ', 'T');

export default function Attendance({ date, today, entry, staff }: Props) {
  const { auth } = usePage<{ auth: { user: { role: string; name: string } } }>().props;
  const admin = auth.user.role === 'ADMIN';
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState<number | null>(null);
  const [inTime, setInTime] = useState('');
  const [outTime, setOutTime] = useState('');
  const [reason, setReason] = useState('');
  const edit = (entry: NonNullable<Entry>) => {
    setEditing(entry.id); setInTime(localInput(entry.clocked_in_at));
    setOutTime(entry.clocked_out_at ? localInput(entry.clocked_out_at) : '');
    setReason(''); setError('');
  };
  const saveCorrection = () => {
    if (!editing) return;
    setBusy(true); setError('');
    router.put(`/admin/attendance/${editing}`, { clocked_in_at: inTime, clocked_out_at: outTime || null, reason }, {
      preserveScroll: true,
      onError: errors => setError(Object.values(errors)[0] || 'Please check the shift times.'),
      onSuccess: () => setEditing(null),
      onFinish: () => setBusy(false),
    });
  };
  const action = (kind: 'clock-in' | 'clock-out') => {
    setBusy(true); setError('');
    router.post(`/admin/attendance/${kind}`, {}, {
      preserveScroll: true,
      onError: errors => setError(Object.values(errors)[0] || 'Please try again.'),
      onFinish: () => setBusy(false),
    });
  };

  return <DashboardShell title={admin ? 'Staff attendance' : 'Attendance'} subtitle={day(date)}>
    <Head title="Attendance"/>
    {admin ? <div className="mx-auto max-w-3xl">
      <div className="card flex flex-wrap items-center justify-between gap-3 p-4 sm:p-5">
        <div><p className="font-bold">Daily register</p><p className="mt-1 text-sm text-[var(--muted)]">Clock times entered by staff on their own device.</p></div>
        <input type="date" aria-label="Attendance date" value={date} max={today} onChange={event => event.target.value && router.get('/admin/attendance', { date: event.target.value }, { preserveState: true })} className="input min-h-11 w-full sm:w-auto"/>
      </div>
      <div className="mt-4 grid gap-3">
        {staff?.length ? staff.map(member => <div key={member.id} className="card p-4 sm:p-5">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex min-w-0 items-center gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-full bg-[#F5F4F0] font-bold">{member.name.trim()[0]?.toUpperCase() || '?'}</span><div className="min-w-0"><p className="truncate font-bold">{member.name}</p><p className="text-xs text-[var(--muted)]">{member.entry ? 'Manual record' : 'No record for this date'}</p></div></div>
            <div className="flex flex-wrap items-center gap-3 text-sm font-semibold">
              {member.entry ? <><span>In <strong className="num">{time(member.entry.clocked_in_at)}</strong></span><span>{member.entry.clocked_out_at ? <>Out <strong className="num">{time(member.entry.clocked_out_at)}</strong></> : <span className="text-emerald-700">On shift</span>}</span><button type="button" onClick={() => edit(member.entry!)} className="btn btn-light btn-sm border border-[var(--line)]"><Pencil size={14}/>Correct</button></> : <span className="text-[var(--muted)]">No clock-in</span>}
            </div>
          </div>
          {editing === member.entry?.id && <div className="mt-4 grid gap-3 border-t border-[var(--line)] pt-4 sm:grid-cols-2">
            <label className="field">Clock in<input type="datetime-local" value={inTime} onChange={event => setInTime(event.target.value)}/></label>
            <label className="field">Clock out<input type="datetime-local" value={outTime} onChange={event => setOutTime(event.target.value)}/></label>
            <label className="field sm:col-span-2">Reason for correction<input value={reason} maxLength={300} onChange={event => setReason(event.target.value)} placeholder="What needed to be corrected?"/></label>
            {error && <p role="alert" className="text-sm font-semibold text-rose-700 sm:col-span-2">{error}</p>}
            <div className="flex gap-2 sm:col-span-2"><button type="button" disabled={busy || reason.trim().length < 5} onClick={saveCorrection} className="btn btn-pink">Save correction</button><button type="button" onClick={() => setEditing(null)} className="btn btn-light">Cancel</button></div>
          </div>}
        </div>) : <div className="card p-10 text-center text-[var(--muted)]"><Users className="mx-auto mb-3" size={24}/><p>No active staff members yet.</p></div>}
      </div>
    </div> : <div className="mx-auto max-w-xl">
      <div className="card overflow-hidden p-5 sm:p-7">
        <p className="label">{entry?.work_date && entry.work_date !== today ? `Shift started ${day(entry.work_date)}` : 'Your shift today'}</p>
        <h2 className="mt-2 text-2xl font-bold">{entry?.clocked_out_at ? 'Shift completed' : entry ? 'You are on shift' : 'Ready to start?'}</h2>
        <p className="mt-2 text-sm leading-6 text-[var(--muted)]">Clock in when you begin work and clock out when your shift ends. Your times are saved in the daily register.</p>
        <div className="mt-6 grid grid-cols-2 gap-3 rounded-xl bg-[#F8F7F4] p-4">
          <div><p className="label">Clock in</p><p className="num mt-2 font-bold">{entry ? time(entry.clocked_in_at) : '-'}</p></div>
          <div><p className="label">Clock out</p><p className="num mt-2 font-bold">{entry?.clocked_out_at ? time(entry.clocked_out_at) : '-'}</p></div>
        </div>
        {error && <p role="alert" className="mt-4 rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-700">{error}</p>}
        {!entry ? <button type="button" disabled={busy} onClick={() => action('clock-in')} className="btn btn-pink mt-6 w-full !min-h-12"><LogIn size={18}/>Clock in</button>
          : !entry.clocked_out_at ? <button type="button" disabled={busy} onClick={() => action('clock-out')} className="btn btn-navy mt-6 w-full !min-h-12"><LogOut size={18}/>Clock out</button>
            : <p className="mt-6 flex items-center gap-2 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-800"><CheckCircle2 size={18}/>Your attendance for today is complete.</p>}
      </div>
      <p className="mt-4 flex items-start gap-2 px-1 text-xs leading-5 text-[var(--muted)]"><Clock3 size={15} className="mt-0.5 shrink-0"/>Times use Philippine time. Contact your manager if a recorded time is incorrect.</p>
    </div>}
  </DashboardShell>;
}
