import { Head, router } from '@inertiajs/react';
import { Camera, Check, CircleCheck, ClipboardCheck, Clock, Play, TriangleAlert, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';
import DashboardShell from '../../Components/DashboardShell';
import { Empty, fmtDay, fmtTime } from '../../Components/ops';
import TaskPhotos, { type TaskPhoto } from '../../Components/TaskPhotos';

type Task = { id: number; unit: string; date: string; status: 'NOT_STARTED' | 'IN_PROGRESS' | 'DONE'; risk: boolean; ready_by: string; check_out_time: string; next_guest: string | null; departing_guest: string | null; checklist: string[]; checklist_total: number; notes: string | null; completed_at: string | null; photos: TaskPhoto[] };
type Props = { today: string; tasks: Task[]; checklist: string[]; photoRequired: boolean; maxPhotos: number };

export default function MyTasks({ today, tasks, checklist, photoRequired, maxPhotos }: Props) {
  const overdue = tasks.filter(t => t.date < today && t.status !== 'DONE');
  const todays = tasks.filter(t => t.date === today && t.status !== 'DONE');
  const upcoming = tasks.filter(t => t.date > today && t.status !== 'DONE');
  const finished = tasks.filter(t => t.status === 'DONE');
  const open = overdue.length + todays.length;
  const sections: [string, Task[]][] = [['Overdue', overdue], ['Today', todays], ['Coming up', upcoming], ['Finished', finished]];

  const summary = [open ? `${open} to do` : tasks.length ? 'Nothing due today' : '', upcoming.length ? `${upcoming.length} coming up` : '', finished.length ? `${finished.length} finished today` : ''].filter(Boolean).join(' · ');
  return <DashboardShell title="Your shift" subtitle={fmtDay(today, { weekday: 'long', month: 'long', day: 'numeric' })}>
    <Head title="Your shift"/>
    <div className="mx-auto mb-5 grid max-w-2xl grid-cols-3 gap-2 sm:gap-3" aria-label="Task summary">
      {[['Due', open], ['Coming up', upcoming.length], ['Finished', finished.length]].map(([label, count]) => <div key={label} className="card px-3 py-3 text-center sm:px-4"><strong className="num block text-2xl leading-none">{count}</strong><span className="mt-1 block text-[11px] font-bold text-[var(--muted)]">{label}</span></div>)}
    </div>
    <p className="mx-auto mb-4 max-w-2xl text-sm font-semibold text-[var(--muted)]">{summary || 'Nothing assigned to you yet'}. Tap a room to see its checklist, photos and notes.</p>
    {tasks.length === 0 ? <div className="card mx-auto max-w-xl"><Empty icon={<ClipboardCheck size={18}/>} title="No tasks assigned to you" body="When a room is assigned to you on the Cleaning board, it shows up here with its checklist."/></div>
      : <div className="mx-auto grid max-w-2xl gap-6">{sections.filter(([, list]) => list.length).map(([title, list]) => <section key={title}>
        <h2 className={`label mb-2 ${title === 'Overdue' ? '!text-[var(--danger)]' : ''}`}>{title} · {list.length}</h2>
        <div className="grid gap-3">{list.map(t => <TaskCard key={t.id} task={t} today={today} items={checklist} photoRequired={photoRequired} maxPhotos={maxPhotos}/>)}</div>
      </section>)}</div>}
  </DashboardShell>;
}

function TaskCard({ task: t, today, items, photoRequired, maxPhotos }: { task: Task; today: string; items: string[]; photoRequired: boolean; maxPhotos: number }) {
  const done = t.status === 'DONE';
  const future = t.date > today && !done;
  const [expanded, setExpanded] = useState(!done && t.date <= today);
  const [notes, setNotes] = useState(t.notes ?? '');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  useEffect(() => setNotes(t.notes ?? ''), [t.notes]);
  const save = (data: Record<string, unknown>) => {
    setBusy(true); setError('');
    router.put(`/admin/cleaning/${t.id}`, data as never, { preserveScroll: true, preserveState: true, onError: e => setError(Object.values(e)[0] as string), onFinish: () => setBusy(false) });
  };
  const toggle = (item: string) => save({ checklist: t.checklist.includes(item) ? t.checklist.filter(i => i !== item) : [...t.checklist, item] });
  const needPhoto = photoRequired && t.photos.length === 0;
  const allTicked = t.checklist_total === 0 || t.checklist.length === t.checklist_total;
  const when = t.date === today ? 'Today' : t.date < today ? `Overdue · ${fmtDay(t.date, { weekday: 'short', month: 'short', day: 'numeric' })}` : fmtDay(t.date, { weekday: 'short', month: 'short', day: 'numeric' });

  return <article className={`card overflow-hidden ${t.risk && !done ? 'border-rose-300' : ''}`}>
    <button type="button" onClick={() => setExpanded(!expanded)} className="flex w-full items-start justify-between gap-3 p-4 text-left">
      <span className="min-w-0">
        <span className="block text-lg font-bold">{t.unit}</span>
        <span className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs font-semibold text-[var(--muted)]">
          <span className={t.date < today && !done ? 'text-[var(--danger)]' : ''}>{when}</span>
          <span className={`flex items-center gap-1 ${t.risk && !done ? 'text-[var(--danger)]' : ''}`}><Clock size={12}/>Ready by {fmtTime(t.ready_by)}</span>
          <span className="flex items-center gap-1"><UserRound size={12}/>{t.next_guest ? `Next: ${t.next_guest}` : 'No arrival'}</span>
        </span>
      </span>
      <span className="flex shrink-0 flex-col items-end gap-1">
        {done ? <span className="pill border-emerald-200 bg-emerald-50 text-emerald-800">ready</span> : t.risk ? <span className="pill border-rose-200 bg-rose-50 text-rose-700">at risk</span> : t.status === 'IN_PROGRESS' ? <span className="pill border-blue-200 bg-blue-50 text-blue-700">cleaning</span> : <span className="pill border-amber-200 bg-amber-50 text-amber-800">to do</span>}
        <span className="num text-[11px] font-semibold text-[var(--muted)]">{t.checklist.length}/{t.checklist_total} · <Camera size={11} className="inline"/> {t.photos.length}</span>
      </span>
    </button>
    {expanded && future && <div className="grid gap-3 border-t border-[var(--line)] p-4">
      <p className="flex items-center gap-2 rounded-xl bg-[#F5F4F0] px-3 py-2.5 text-sm font-semibold text-[#3B404C]"><Clock size={15}/>Starts on {fmtDay(t.date, { weekday: 'long', month: 'short', day: 'numeric' })}, after the guest checks out at {fmtTime(t.check_out_time)}.</p>
      {items.length > 0 && <p className="text-xs font-semibold text-[var(--muted)]">{items.length} checklist steps · photo {photoRequired ? 'required' : 'optional'}</p>}
    </div>}
    {expanded && !future && <div className="grid gap-4 border-t border-[var(--line)] p-4">
      {done && <p className="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2 text-sm font-semibold text-emerald-800"><CircleCheck size={16}/>Marked ready{t.completed_at ? ` at ${new Date(t.completed_at).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })}` : ''}</p>}
      {t.status === 'NOT_STARTED' && <button type="button" disabled={busy} onClick={() => save({ status: 'IN_PROGRESS' })} className="btn btn-navy w-full !min-h-[48px]"><Play size={16}/>Start cleaning</button>}
      {items.length > 0 && <div><p className="label mb-2">Checklist{t.departing_guest ? ` · after ${t.departing_guest}` : ''}</p>
        <div className="grid gap-1.5">{items.map(item => { const on = t.checklist.includes(item); return <label key={item} className={`flex min-h-[46px] cursor-pointer items-center gap-3 rounded-xl border px-3 py-2 text-sm font-semibold ${on ? 'border-emerald-200 bg-emerald-50/60' : 'border-[var(--line)] bg-white'} ${done ? 'pointer-events-none' : ''}`}>
          <input type="checkbox" checked={on} disabled={busy || done} onChange={() => toggle(item)} className="size-5 shrink-0 accent-sea"/><span className={on ? 'text-[var(--muted)] line-through' : ''}>{item}</span></label>; })}</div></div>}
      <div><p className="label mb-2">Photos {photoRequired && !done && <span className="normal-case tracking-normal text-[var(--danger)]">· required before marking ready</span>}</p>
        <TaskPhotos taskId={t.id} photos={t.photos} done={done} max={maxPhotos} big/></div>
      <div><p className="label mb-2">Notes for the front desk</p>
        <textarea value={notes} maxLength={500} disabled={done} onChange={e => setNotes(e.target.value)} placeholder="Damage, missing items, maintenance needed…" className="input min-h-[80px] disabled:opacity-60"/>
        {!done && notes !== (t.notes ?? '') && <button type="button" disabled={busy} onClick={() => save({ notes: notes || null })} className="btn btn-light btn-sm mt-2">Save note</button>}</div>
      {error && <p role="alert" className="flex items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-semibold text-rose-700"><TriangleAlert size={15}/>{error}</p>}
      {!done && <div>
        <button type="button" disabled={busy || needPhoto} onClick={() => { if (!allTicked && !confirm('Some checklist steps are not ticked. Mark the room ready anyway?')) return; save({ status: 'DONE' }); }} className="btn btn-pink w-full !min-h-[50px] !text-[15px]"><Check size={17}/>Mark room ready</button>
        {needPhoto ? <p className="mt-1.5 text-center text-xs font-semibold text-[var(--muted)]">Take at least one photo of the finished room first.</p>
          : !allTicked && <p className="mt-1.5 text-center text-xs font-semibold text-[var(--muted)]">Some checklist steps are not ticked yet.</p>}
      </div>}
    </div>}
  </article>;
}
