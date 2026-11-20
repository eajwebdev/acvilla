import { Head, router } from '@inertiajs/react';
import {
  Camera,
  Check,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  ClipboardList,
  Pencil,
  Play,
  Plus,
  RotateCcw,
  SprayCan,
  Trash2,
  Users,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import DashboardShell from '../../Components/DashboardShell';
import { addDays, Card, Empty, fmtDay, fmtTime, rowAction } from '../../Components/ops';
import TaskDialog, { type TaskDraft } from '../../Components/TaskDialog';
import TaskPhotos, { type TaskPhoto } from '../../Components/TaskPhotos';

type Task = {
  id: number;
  kind: 'turnover' | 'manual';
  title: string | null;
  room_id: number | null;
  room: string | null;
  date: string;
  created_by: string | null;
  unit: string;
  status: 'NOT_STARTED' | 'IN_PROGRESS' | 'DONE';
  risk: boolean;
  assigned_to: number | null;
  cleaner: string | null;
  /** Null for a task added by hand: it has no check-out before it. */
  check_out_time: string | null;
  /** When it must be done: the next check-in, or a manual task's due time (null = any time that day). */
  ready_by: string | null;
  next_guest: string | null;
  departing_guest: string | null;
  checklist: string[];
  checklist_total: number;
  notes: string | null;
  started_at: string | null;
  completed_at: string | null;
  photos: TaskPhoto[];
};
type Props = {
  date: string;
  today: string;
  view: 'timeline' | 'workload';
  tasks: Task[];
  checklist: string[];
  checkOutTime: string;
  checkInTime: string;
  cleaners: { id: number; name: string; role: string }[];
  rooms: { id: number; name: string }[];
};

const draftOf = (t: Task): TaskDraft => ({
  id: t.id,
  title: t.title ?? '',
  date: t.date,
  due_time: t.ready_by ?? '',
  room_id: t.room_id ?? '',
  assigned_to: t.assigned_to ?? '',
  notes: t.notes ?? '',
});
const dueLabel = (t: Task) => (t.ready_by ? fmtTime(t.ready_by) : 'Any time');

const START = 8,
  END = 19; // 8am to 7pm
const toHours = (hhmm: string) => {
  const [h, m] = hhmm.split(':').map(Number);
  return h + (m || 0) / 60;
};
const pct = (h: number) => `${Math.min(100, Math.max(0, ((h - START) / (END - START)) * 100))}%`;
const isoTime = (s: string | null) =>
  s ? new Date(s).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : null;
const tone = (t: Task) =>
  t.status === 'DONE'
    ? { bar: 'bg-sea', dot: 'bg-sea', label: 'Done' }
    : t.risk
      ? { bar: 'bg-danger', dot: 'bg-danger', label: 'At risk' }
      : t.status === 'IN_PROGRESS'
        ? { bar: 'bg-blue-600', dot: 'bg-blue-600', label: 'In progress' }
        : {
            bar: 'border-2 border-dashed border-amber-500 bg-amber-50',
            dot: 'border-2 border-dashed border-amber-500',
            label: 'Not started',
          };

export default function Cleaning({
  date,
  today,
  view: initialView,
  tasks,
  checklist,
  checkOutTime,
  checkInTime,
  cleaners,
  rooms,
}: Props) {
  const [view, setView] = useState(initialView);
  // null: closed; {}: a new task; a draft: editing that task.
  const [dialog, setDialog] = useState<TaskDraft | null | undefined>(undefined);
  const turnovers = tasks.filter(t => t.kind === 'turnover');
  const remove = (t: Task) => {
    if (confirm(`Remove the task “${t.title}”?`))
      router.delete(`/admin/cleaning/${t.id}`, { preserveScroll: true, preserveState: true });
  };
  const [open, setOpen] = useState<number | null>(null);
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');
  const [now, setNow] = useState(() => new Date());
  useEffect(() => {
    const t = window.setInterval(() => setNow(new Date()), 60_000);
    return () => window.clearInterval(t);
  }, []);
  const isToday = date === today;
  const nowH = now.getHours() + now.getMinutes() / 60;
  const go = (d: string) =>
    router.get('/admin/cleaning', d === today ? {} : { date: d }, { preserveState: true, preserveScroll: true });
  const update = (task: Task, data: Record<string, unknown>) =>
    router.put(`/admin/cleaning/${task.id}`, data as never, { preserveScroll: true, preserveState: true });
  const counts = {
    risk: tasks.filter(t => t.risk && t.status !== 'DONE').length,
    progress: tasks.filter(t => t.status === 'IN_PROGRESS' && !t.risk).length,
    waiting: tasks.filter(t => t.status === 'NOT_STARTED' && !t.risk).length,
    done: tasks.filter(t => t.status === 'DONE').length,
  };
  const sorted = useMemo(
    () => [...tasks].sort((a, b) => (sortDir === 'asc' ? 1 : -1) * a.unit.localeCompare(b.unit)),
    [tasks, sortDir],
  );
  const workload = useMemo(() => {
    const groups = new Map<string, Task[]>();
    tasks.forEach(t => {
      const k = t.cleaner ?? 'Unassigned';
      groups.set(k, [...(groups.get(k) ?? []), t]);
    });
    return [...groups.entries()].sort(([a], [b]) =>
      a === 'Unassigned' ? 1 : b === 'Unassigned' ? -1 : a.localeCompare(b),
    );
  }, [tasks]);

  return (
    <DashboardShell
      title="Cleaning"
      subtitle={
        <span>
          {counts.done} of {tasks.length} ready
        </span>
      }
      actions={
        <div className="flex items-center gap-1">
          <button type="button" onClick={() => setDialog(null)} className="btn btn-pink btn-sm mr-2">
            <Plus size={14} />
            Add task
          </button>
          <button
            type="button"
            aria-label="Previous day"
            onClick={() => go(addDays(date, -1))}
            className="grid size-9 place-items-center rounded-lg border border-[var(--line)] bg-white"
          >
            <ChevronLeft size={16} />
          </button>
          <label className="relative min-w-[110px] cursor-pointer text-center text-sm font-bold">
            {isToday ? 'Today' : fmtDay(date, { weekday: 'short', month: 'short', day: 'numeric' })}
            <input
              type="date"
              value={date}
              onChange={e => e.target.value && go(e.target.value)}
              className="absolute inset-0 cursor-pointer opacity-0"
              aria-label="Pick a date"
            />
          </label>
          <button
            type="button"
            aria-label="Next day"
            onClick={() => go(addDays(date, 1))}
            className="grid size-9 place-items-center rounded-lg border border-[var(--line)] bg-white"
          >
            <ChevronRight size={16} />
          </button>
        </div>
      }
    >
      <Head title="Cleaning" />
      <TaskDialog
        open={dialog !== undefined}
        onOpenChange={o => !o && setDialog(undefined)}
        task={dialog}
        date={date}
        today={today}
        rooms={rooms}
        people={cleaners}
      />
      <div className="mb-3 flex items-center justify-between">
        <p className="label">Overview</p>
        <div className="seg">
          <button type="button" aria-pressed={view === 'timeline'} onClick={() => setView('timeline')}>
            Timeline
          </button>
          <button type="button" aria-pressed={view === 'workload'} onClick={() => setView('workload')}>
            Workload
          </button>
        </div>
      </div>

      {view === 'timeline' ? (
        <Card title="Turnaround timeline" bodyClass="px-5 pb-5">
          <div className="flex flex-wrap gap-4 py-3 text-xs font-semibold">
            {[
              ['bg-sea', 'Done'],
              ['bg-blue-600', 'In progress'],
              ['border-2 border-dashed border-amber-500', 'Not started'],
              ['bg-danger', 'At risk'],
              ['bg-line-soft', 'Waiting'],
            ].map(([c, l]) => (
              <span key={l} className="flex items-center gap-1.5">
                <span className={`h-2.5 w-4 rounded-full ${c}`} />
                {l}
              </span>
            ))}
          </div>
          <div className="overflow-x-auto">
            <div className="relative min-w-[720px] pr-6 pl-28">
              <div className="relative h-6">
                {Array.from({ length: END - START + 1 }, (_, i) => START + i).map(h => (
                  <span
                    key={h}
                    className="num absolute -translate-x-1/2 text-[10px] font-semibold text-[var(--muted)]"
                    style={{ left: pct(h) }}
                  >
                    {h === 12 ? '12nn' : h > 12 ? `${h - 12}pm` : `${h}am`}
                  </span>
                ))}
              </div>
              <div className="relative" style={{ minHeight: Math.max(76, turnovers.length * 40 + 36) }}>
                {Array.from({ length: END - START + 1 }, (_, i) => START + i).map(h => (
                  <span key={h} className="absolute inset-y-0 w-px bg-sand" style={{ left: pct(h) }} />
                ))}
                <span
                  className="absolute inset-y-0 bg-mist"
                  style={{
                    left: pct(toHours(checkOutTime)),
                    width: `calc(${pct(toHours(checkInTime))} - ${pct(toHours(checkOutTime))})`,
                  }}
                />
                {[
                  [checkOutTime, 'Check-out'],
                  [checkInTime, 'Check-in'],
                ].map(([t, l]) => (
                  <span
                    key={l}
                    className="absolute inset-y-0 border-l border-dashed border-slate-400"
                    style={{ left: pct(toHours(t)) }}
                  >
                    <span className="absolute -top-0.5 left-1 rounded-full border border-blue-400 bg-white px-2 py-0.5 text-[10px] font-bold whitespace-nowrap">
                      {l} {fmtTime(t)}
                    </span>
                  </span>
                ))}
                {isToday && nowH >= START && nowH <= END && (
                  <span
                    className="absolute inset-y-0 z-10 border-l border-dashed border-forest"
                    style={{ left: pct(nowH) }}
                  >
                    <span className="absolute -top-0.5 -translate-x-1/2 rounded-full bg-forest px-2 py-0.5 text-[10px] font-bold whitespace-nowrap text-white">
                      Now {now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}
                    </span>
                  </span>
                )}
                {turnovers.length === 0 ? (
                  <p className="absolute inset-0 grid place-items-center pt-6 text-sm font-semibold text-[var(--muted)]">
                    No check-out cleanings this day.
                  </p>
                ) : (
                  turnovers.map((t, i) => {
                    const out = t.check_out_time ?? checkOutTime;
                    const ready = t.ready_by ?? checkInTime;
                    const from = toHours(isoTime(t.started_at) ?? out);
                    const to = toHours(t.status === 'DONE' ? (isoTime(t.completed_at) ?? ready) : ready);
                    const c = tone(t);
                    return (
                      <div
                        key={t.id}
                        className="absolute inset-x-0 flex items-center"
                        style={{ top: 30 + i * 40, height: 28 }}
                      >
                        <span className="absolute -left-28 w-24 truncate text-xs font-bold">{t.unit}</span>
                        <span
                          className="absolute h-1.5 rounded-full bg-line-soft"
                          style={{
                            left: pct(toHours(out)),
                            width: `calc(${pct(toHours(ready))} - ${pct(toHours(out))})`,
                          }}
                        />
                        <button
                          type="button"
                          onClick={() => setOpen(open === t.id ? null : t.id)}
                          title={`${t.unit}: ${c.label}`}
                          className={`absolute h-5 rounded-full ${c.bar}`}
                          style={{
                            left: pct(Math.min(from, to)),
                            width: `max(10px, calc(${pct(Math.max(from, to))} - ${pct(Math.min(from, to))}))`,
                          }}
                        />
                      </div>
                    );
                  })
                )}
              </div>
            </div>
          </div>
        </Card>
      ) : (
        <Card title="Workload" subtitle="Tasks per person for the day" bodyClass="p-5">
          {tasks.length === 0 ? (
            <Empty icon={<Users size={18} />} title="No tasks to share out" />
          ) : (
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {workload.map(([name, list]) => (
                <div key={name} className="rounded-xl border border-[var(--line)] p-4">
                  <div className="flex items-center justify-between">
                    <p className={`font-bold ${name === 'Unassigned' ? 'text-amber-700' : ''}`}>{name}</p>
                    <span className="num text-sm font-semibold">
                      {list.filter(t => t.status === 'DONE').length}/{list.length}
                    </span>
                  </div>
                  <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-sand">
                    <div
                      className="h-full bg-sea"
                      style={{ width: `${(100 * list.filter(t => t.status === 'DONE').length) / list.length}%` }}
                    />
                  </div>
                  <ul className="mt-3 grid gap-1 text-xs font-semibold">
                    {list.map(t => (
                      <li key={t.id} className="flex items-center justify-between">
                        <span className="flex items-center gap-2">
                          <span className={`size-2 rounded-full ${tone(t).dot}`} />
                          {t.unit}
                        </span>
                        <span className="text-[var(--muted)]">
                          {t.ready_by ? `by ${fmtTime(t.ready_by)}` : 'any time'}
                        </span>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )}
        </Card>
      )}

      <Card
        className="mt-4"
        title="Turnaround progress"
        action={
          <span className="num text-sm font-semibold text-[var(--muted)]">
            {counts.done}/{tasks.length} ready
          </span>
        }
        bodyClass="px-5 py-4"
      >
        <div className="flex h-2.5 overflow-hidden rounded-full bg-line-soft">
          {tasks.length > 0 &&
            [
              [counts.done, 'bg-sea'],
              [counts.progress, 'bg-blue-600'],
              [counts.risk, 'bg-danger'],
              [counts.waiting, 'bg-amber-400'],
            ].map(([n, c]) => (
              <span
                key={c as string}
                className={c as string}
                style={{ width: `${(100 * (n as number)) / tasks.length}%` }}
              />
            ))}
        </div>
        <div className="mt-3 flex flex-wrap gap-5 text-xs font-semibold text-[var(--muted)]">
          {[
            [counts.risk, 'At risk', 'bg-danger'],
            [counts.progress, 'In progress', 'bg-blue-600'],
            [counts.waiting, 'Not started', 'bg-amber-400'],
            [counts.done, 'Done', 'bg-sea'],
          ].map(([n, l, c]) => (
            <span key={l as string} className="flex items-center gap-1.5">
              <span className={`size-2 rounded-full ${c}`} />
              <span className="num font-bold text-[var(--ink)]">{n as number}</span>
              {l as string}
            </span>
          ))}
        </div>
      </Card>

      <div className="mt-6 flex items-baseline justify-between">
        <h2 className="text-lg font-bold">{isToday ? "Today's tasks" : 'Tasks'}</h2>
        <span className="text-xs font-semibold text-[var(--muted)]">
          {turnovers.length} rooms · {tasks.length - turnovers.length} added by hand
        </span>
      </div>
      <div className="card mt-2 overflow-x-auto">
        <table className="table w-full min-w-[820px]">
          <thead>
            <tr>
              <th>
                <button
                  type="button"
                  onClick={() => setSortDir(sortDir === 'asc' ? 'desc' : 'asc')}
                  className="flex items-center gap-1"
                >
                  Task
                  <ChevronDown size={12} className={sortDir === 'desc' ? 'rotate-180' : ''} />
                </button>
              </th>
              <th>When</th>
              <th>Cleaner</th>
              <th>Ready by</th>
              <th>Progress</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {sorted.length === 0 && (
              <tr>
                <td colSpan={6}>
                  <Empty
                    icon={<SprayCan size={18} />}
                    title="No tasks this day."
                    body="Check-out cleanings appear here automatically. Use Add task for anything else."
                  />
                </td>
              </tr>
            )}
            {sorted.map(t => {
              const c = tone(t);
              const expanded = open === t.id;
              return [
                <tr key={t.id} className="clickable" {...rowAction(() => setOpen(expanded ? null : t.id), expanded)}>
                  <td className="font-bold">
                    <span className="flex items-center gap-2">
                      <ChevronRight
                        size={14}
                        className={`text-[var(--muted)] transition ${expanded ? 'rotate-90' : ''}`}
                      />
                      {t.kind === 'manual' && <ClipboardList size={14} className="shrink-0 text-ocean" />}
                      <span className="min-w-0">
                        {t.unit}
                        {t.kind === 'manual' && (
                          <span className="block text-[11px] font-semibold text-[var(--muted)]">
                            {[t.room, t.created_by && `added by ${t.created_by}`].filter(Boolean).join(' · ')}
                          </span>
                        )}
                      </span>
                    </span>
                  </td>
                  {t.kind === 'manual' ? (
                    <td className="text-xs font-semibold">
                      {t.ready_by ? (
                        <>
                          Due <span className="num">{fmtTime(t.ready_by)}</span>
                        </>
                      ) : (
                        'Any time today'
                      )}
                      <span className="block text-[var(--muted)]">Added by hand</span>
                    </td>
                  ) : (
                    <td className="text-xs font-semibold">
                      <span className="num">{fmtTime(t.check_out_time)}</span> →{' '}
                      <span className="num">{fmtTime(t.ready_by)}</span>
                      <span className="block text-[var(--muted)]">
                        {t.next_guest ? `Next: ${t.next_guest}` : 'No arrival today'}
                      </span>
                    </td>
                  )}
                  <td onClick={e => e.stopPropagation()}>
                    <select
                      aria-label={`Cleaner for ${t.unit}`}
                      value={t.assigned_to ?? ''}
                      onChange={e => update(t, { assigned_to: e.target.value ? Number(e.target.value) : null })}
                      className="rounded-lg border border-[var(--line)] bg-mist px-2 py-1.5 text-xs font-bold"
                    >
                      <option value="">Unassigned</option>
                      {cleaners.map(u => (
                        <option key={u.id} value={u.id}>
                          {u.name}
                        </option>
                      ))}
                    </select>
                  </td>
                  <td className={`num text-sm font-semibold ${t.risk ? 'text-[var(--danger)]' : ''}`}>{dueLabel(t)}</td>
                  <td>
                    <span className="flex items-center gap-2">
                      {t.kind === 'manual' ? (
                        <span className="text-xs font-semibold text-[var(--muted)]">No checklist</span>
                      ) : (
                        <>
                          <span className="h-1.5 w-20 overflow-hidden rounded-full bg-sand">
                            <span
                              className="block h-full bg-sea"
                              style={{
                                width: `${t.checklist_total ? (100 * t.checklist.length) / t.checklist_total : t.status === 'DONE' ? 100 : 0}%`,
                              }}
                            />
                          </span>
                          <span className="num text-xs font-semibold">
                            {t.checklist.length}/{t.checklist_total}
                          </span>
                        </>
                      )}
                      {t.photos.length > 0 && (
                        <span
                          className="num flex items-center gap-0.5 text-xs font-semibold text-ocean"
                          title={`${t.photos.length} photos`}
                        >
                          <Camera size={12} />
                          {t.photos.length}
                        </span>
                      )}
                    </span>
                  </td>
                  <td onClick={e => e.stopPropagation()}>
                    <span className="flex items-center gap-2">
                      <span
                        className={`flex items-center gap-1.5 text-xs font-bold ${t.risk && t.status !== 'DONE' ? 'text-[var(--danger)]' : ''}`}
                      >
                        <span className={`size-2 rounded-full ${c.dot}`} />
                        {c.label}
                      </span>
                      {t.status === 'NOT_STARTED' && date > today && (
                        <span className="text-[11px] font-semibold text-[var(--muted)]">from {fmtDay(date)}</span>
                      )}
                      {t.status === 'NOT_STARTED' && date <= today && (
                        <button
                          type="button"
                          onClick={() => update(t, { status: 'IN_PROGRESS' })}
                          className="btn btn-light btn-sm"
                        >
                          <Play size={12} />
                          Start
                        </button>
                      )}
                      {t.status === 'IN_PROGRESS' && (
                        <button
                          type="button"
                          onClick={() => update(t, { status: 'DONE' })}
                          className="btn btn-navy btn-sm"
                        >
                          <Check size={12} />
                          Ready
                        </button>
                      )}
                      {t.status === 'DONE' && (
                        <button
                          type="button"
                          onClick={() => update(t, { status: 'NOT_STARTED', checklist: [] })}
                          title="Reset"
                          aria-label="Reset task"
                          className="btn btn-light btn-sm"
                        >
                          <RotateCcw size={12} />
                        </button>
                      )}
                    </span>
                  </td>
                </tr>,
                expanded && (
                  <tr key={`${t.id}-x`}>
                    <td colSpan={6} className="bg-paper">
                      <Checklist
                        task={t}
                        items={t.kind === 'manual' ? [] : checklist}
                        locked={date > today}
                        onSave={data => update(t, data)}
                        onEdit={t.kind === 'manual' ? () => setDialog(draftOf(t)) : undefined}
                        onRemove={t.kind === 'manual' ? () => remove(t) : undefined}
                      />
                    </td>
                  </tr>
                ),
              ];
            })}
          </tbody>
        </table>
      </div>
    </DashboardShell>
  );
}

function Checklist({
  task,
  items,
  locked,
  onSave,
  onEdit,
  onRemove,
}: {
  task: Task;
  items: string[];
  locked: boolean;
  onSave: (d: Record<string, unknown>) => void;
  onEdit?: () => void;
  onRemove?: () => void;
}) {
  const [notes, setNotes] = useState(task.notes ?? '');
  useEffect(() => setNotes(task.notes ?? ''), [task.notes]);
  const toggle = (item: string) =>
    onSave({
      checklist: task.checklist.includes(item) ? task.checklist.filter(i => i !== item) : [...task.checklist, item],
    });
  return (
    <div className="grid gap-4 py-2 md:grid-cols-[1fr_280px]">
      {task.kind === 'manual' ? (
        <div>
          <p className="label mb-2">Task added by hand</p>
          <p className="text-sm font-semibold">{task.title}</p>
          <p className="mt-1 text-xs font-semibold text-[var(--muted)]">
            {[
              task.room ?? 'Not about a room',
              task.ready_by ? `due ${fmtTime(task.ready_by)}` : 'any time that day',
              task.created_by && `added by ${task.created_by}`,
            ]
              .filter(Boolean)
              .join(' · ')}
          </p>
          <div className="mt-3 flex gap-2">
            <button type="button" onClick={onEdit} className="btn btn-light btn-sm">
              <Pencil size={12} />
              Edit
            </button>
            <button type="button" onClick={onRemove} className="btn btn-light btn-sm text-[var(--danger)]">
              <Trash2 size={12} />
              Remove
            </button>
          </div>
        </div>
      ) : (
        <div>
          <p className="label mb-2">
            Checklist{' '}
            {task.departing_guest && (
              <span className="tracking-normal normal-case">· after {task.departing_guest}</span>
            )}
          </p>
          {items.length === 0 ? (
            <p className="text-xs font-semibold text-[var(--muted)]">
              No checklist set up. Add steps under Settings → Cleaning checklist.
            </p>
          ) : (
            <div className="grid gap-1.5 sm:grid-cols-2">
              {items.map(item => (
                <label
                  key={item}
                  className="flex cursor-pointer items-center gap-2.5 rounded-lg border border-[var(--line)] bg-white px-3 py-2 text-sm font-semibold"
                >
                  <input
                    type="checkbox"
                    checked={task.checklist.includes(item)}
                    disabled={locked}
                    onChange={() => toggle(item)}
                    className="size-4 accent-forest"
                  />
                  <span className={task.checklist.includes(item) ? 'text-[var(--muted)] line-through' : ''}>
                    {item}
                  </span>
                </label>
              ))}
            </div>
          )}
        </div>
      )}
      <div className="grid gap-4">
        <div>
          <p className="label mb-2">Photos</p>
          {locked && !task.photos.length ? (
            <p className="text-xs font-semibold text-[var(--muted)]">Photos can be added on the day of the task.</p>
          ) : (
            <TaskPhotos
              taskId={task.id}
              photos={task.photos}
              done={task.status === 'DONE' || locked}
              readOnly={locked}
            />
          )}
        </div>
        <div>
          <p className="label mb-2">Notes for the front desk</p>
          <textarea
            value={notes}
            maxLength={500}
            onChange={e => setNotes(e.target.value)}
            placeholder="Damage, missing items, maintenance needed…"
            className="input min-h-[90px]"
          />
          <button
            type="button"
            disabled={notes === (task.notes ?? '')}
            onClick={() => onSave({ notes: notes || null })}
            className="btn btn-navy btn-sm mt-2"
          >
            Save note
          </button>
        </div>
      </div>
    </div>
  );
}
