import { router } from '@inertiajs/react';
import * as Dialog from '@radix-ui/react-dialog';
import { Check, LoaderCircle, X } from 'lucide-react';
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';

export type TaskDraft = {
  id?: number;
  title: string;
  date: string;
  due_time: string;
  room_id: number | '';
  assigned_to: number | '';
  notes: string;
};
type Person = { id: number; name: string; role?: string };

const blank = (date: string): TaskDraft => ({ title: '', date, due_time: '', room_id: '', assigned_to: '', notes: '' });

/** Add a task by hand, or change one added by hand: any job, for any day from today, optionally for a room and by a set time. */
export default function TaskDialog({
  open,
  onOpenChange,
  task,
  date,
  today,
  rooms,
  people,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  task?: TaskDraft | null;
  date: string;
  today: string;
  rooms: { id: number; name: string }[];
  people: Person[];
}) {
  const [form, setForm] = useState<TaskDraft>(() => task ?? blank(date < today ? today : date));
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    if (open) {
      setForm(task ?? blank(date < today ? today : date));
      setErrors({});
    }
  }, [open, task, date, today]);
  const set = <K extends keyof TaskDraft>(key: K, value: TaskDraft[K]) => setForm(f => ({ ...f, [key]: value }));
  const editing = Boolean(task?.id);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const data = {
      title: form.title,
      date: form.date,
      due_time: form.due_time || null,
      room_id: form.room_id === '' ? null : form.room_id,
      assigned_to: form.assigned_to === '' ? null : form.assigned_to,
      notes: form.notes || null,
    };
    const options = {
      preserveScroll: true,
      preserveState: true,
      onStart: () => setBusy(true),
      onFinish: () => setBusy(false),
      onError: (errs: Record<string, string>) => setErrors(errs),
      onSuccess: () => onOpenChange(false),
    };
    if (editing) router.put(`/admin/cleaning/${task!.id}`, data, options);
    else router.post('/admin/cleaning', data, options);
  };
  const err = (key: string): ReactNode =>
    errors[key] && (
      <small role="alert" className="font-semibold text-red-600">
        {errors[key]}
      </small>
    );

  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="tapp-overlay" />
        <Dialog.Content className="tapp-dialog" aria-describedby={undefined}>
          <form onSubmit={submit}>
            <div className="flex items-center justify-between border-b border-[var(--line)] px-5 py-4">
              <Dialog.Title className="text-lg font-bold">{editing ? 'Edit task' : 'Add a task'}</Dialog.Title>
              <Dialog.Close
                className="grid size-8 place-items-center rounded-lg border border-[var(--line)]"
                aria-label="Close"
              >
                <X size={16} />
              </Dialog.Close>
            </div>
            <div className="grid gap-4 px-5 py-4">
              <label className="field">
                What needs doing?
                <input
                  required
                  autoFocus
                  maxLength={120}
                  value={form.title}
                  onChange={e => set('title', e.target.value)}
                  placeholder="Clean the pool, fix the aircon in AH Room 1…"
                />
                {err('title')}
              </label>
              <div className="grid grid-cols-2 gap-3">
                <label className="field">
                  Day
                  <input
                    type="date"
                    required
                    min={editing ? undefined : today}
                    value={form.date}
                    onChange={e => set('date', e.target.value)}
                  />
                  {err('date')}
                </label>
                <label className="field">
                  Due by (optional)
                  <input type="time" value={form.due_time} onChange={e => set('due_time', e.target.value)} />
                  {err('due_time')}
                </label>
              </div>
              <label className="field">
                Room (optional)
                <select
                  value={form.room_id}
                  onChange={e => set('room_id', e.target.value ? Number(e.target.value) : '')}
                >
                  <option value="">Not about a room</option>
                  {rooms.map(r => (
                    <option key={r.id} value={r.id}>
                      {r.name}
                    </option>
                  ))}
                </select>
                {err('room_id')}
              </label>
              <label className="field">
                Assign to
                <select
                  value={form.assigned_to}
                  onChange={e => set('assigned_to', e.target.value ? Number(e.target.value) : '')}
                >
                  <option value="">Nobody yet</option>
                  {people.map(p => (
                    <option key={p.id} value={p.id}>
                      {p.name}
                      {p.role === 'MANAGER' ? ' (manager)' : p.role === 'ADMIN' ? ' (admin)' : ''}
                    </option>
                  ))}
                </select>
                <small className="font-normal text-[var(--muted)]">It shows up in their My tasks.</small>
                {err('assigned_to')}
              </label>
              <label className="field">
                Notes (optional)
                <textarea
                  value={form.notes}
                  maxLength={500}
                  onChange={e => set('notes', e.target.value)}
                  placeholder="Where the tools are, what to check…"
                  className="!min-h-[70px]"
                />
                {err('notes')}
              </label>
            </div>
            <div className="grid grid-cols-[1fr_2fr] gap-2 border-t border-[var(--line)] bg-paper px-5 py-4">
              <Dialog.Close asChild>
                <button type="button" className="btn btn-light">
                  Cancel
                </button>
              </Dialog.Close>
              <button type="submit" disabled={busy} className="btn btn-pink">
                {busy ? <LoaderCircle size={16} className="animate-spin" /> : <Check size={16} />}
                {editing ? 'Save task' : 'Add task'}
              </button>
            </div>
          </form>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}
