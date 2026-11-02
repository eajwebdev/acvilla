import { Head, router, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import SettingsLayout from '../../Components/SettingsLayout';
import { money } from '../../types';

type Season = { id: number; name: string; starts_on: string; ends_on: string; adjustment_type: 'percent' | 'fixed'; adjustment_value: string; min_nights: number | null; room_ids: number[] | null; active: boolean };
type RoomOption = { id: number; name: string; property: { id: number; name: string } };
type SeasonForm = { name: string; starts_on: string; ends_on: string; adjustment_type: 'percent' | 'fixed'; adjustment_value: number | ''; min_nights: number | ''; room_ids: number[]; active: boolean };

const blank: SeasonForm = { name: '', starts_on: '', ends_on: '', adjustment_type: 'percent', adjustment_value: 20, min_nights: '', room_ids: [], active: true };
const toForm = (s: Season): SeasonForm => ({ name: s.name, starts_on: s.starts_on.slice(0, 10), ends_on: s.ends_on.slice(0, 10), adjustment_type: s.adjustment_type, adjustment_value: Number(s.adjustment_value), min_nights: s.min_nights ?? '', room_ids: s.room_ids ?? [], active: s.active });
const day = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
const today = new Date().toLocaleDateString('en-CA');
const effect = (type: string, value: number) => type === 'fixed' ? `${money(value)} per night` : `${value > 0 ? '+' : '−'}${Math.abs(value)}% on nightly rates`;

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
  return <label className="field">{label}{children}{hint && <small className="font-normal text-slate">{hint}</small>}{error && <small role="alert" className="font-normal text-red-600">{error}</small>}</label>;
}

export default function SeasonalRates({ seasons, rooms }: { seasons: Season[]; rooms: RoomOption[] }) {
  const [editing, setEditing] = useState<number | null>(null);
  const form = useForm<SeasonForm>(blank);
  const d = form.data;
  const e = form.errors as Record<string, string>;
  const roomName = (id: number) => { const r = rooms.find(room => room.id === id); return r ? `${r.property.name} · ${r.name}` : 'Removed room'; };
  const byProperty = rooms.reduce<Record<string, RoomOption[]>>((groups, room) => ({ ...groups, [room.property.name]: [...(groups[room.property.name] || []), room] }), {});

  function reset() { setEditing(null); form.setDefaults(blank); form.setData(blank); form.clearErrors(); }
  function submit(ev: FormEvent) {
    ev.preventDefault();
    form.transform(data => ({ ...data, min_nights: data.min_nights === '' ? null : data.min_nights }) as unknown as SeasonForm);
    const done = { preserveScroll: true, onSuccess: reset };
    if (editing) form.put(`/admin/settings/seasons/${editing}`, done); else form.post('/admin/settings/seasons', done);
  }
  const save = (s: Season, patch: Partial<SeasonForm>) => router.put(`/admin/settings/seasons/${s.id}`, { ...toForm(s), ...patch }, { preserveScroll: true });

  return <SettingsLayout title="Seasonal rates" subtitle="Raise or lower prices for holidays and peak or low seasons. Guests see the adjusted price automatically."><Head title="Seasonal rates · Settings"/>
    <form onSubmit={submit} className="surface-card grid gap-5 p-6">
      <h3 className="text-lg font-semibold">{editing ? 'Edit season' : 'Add a season'}</h3>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label="Name" hint="Shown to guests, e.g. Holy Week" error={e.name}><input required maxLength={80} value={d.name} onChange={ev => form.setData('name', ev.target.value)}/></Field>
        <Field label="First night" error={e.starts_on}><input type="date" required value={d.starts_on} onChange={ev => form.setData('starts_on', ev.target.value)}/></Field>
        <Field label="Last night" hint="The season covers this night too." error={e.ends_on}><input type="date" required min={d.starts_on || undefined} value={d.ends_on} onChange={ev => form.setData('ends_on', ev.target.value)}/></Field>
      </div>
      <div className="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-[auto_1fr_1fr]">
        <fieldset><legend className="text-sm font-semibold text-navy">Price change</legend><div className="mt-2 flex rounded-xl bg-slate-100 p-1">{([['percent', 'By %'], ['fixed', 'Set price']] as const).map(([value, label]) => <button type="button" key={value} aria-pressed={d.adjustment_type === value} onClick={() => form.setData(data => ({ ...data, adjustment_type: value, adjustment_value: value === 'percent' ? 20 : 5000 }))} className={`rounded-lg px-3 py-2 text-sm font-semibold ${d.adjustment_type === value ? 'bg-white text-navy shadow-sm' : 'text-slate'}`}>{label}</button>)}</div></fieldset>
        <Field label={d.adjustment_type === 'percent' ? 'Change (%)' : 'Nightly price'} hint={d.adjustment_type === 'percent' ? 'Use a negative number for a discount, e.g. -15. Applies to weekday and weekend rates.' : 'Replaces weekday and weekend rates on these nights.'} error={e.adjustment_value}><input type="number" required step="0.01" min={d.adjustment_type === 'percent' ? -90 : 1} max={d.adjustment_type === 'percent' ? 500 : undefined} value={String(d.adjustment_value)} onChange={ev => form.setData('adjustment_value', ev.target.value === '' ? '' : Number(ev.target.value))}/></Field>
        <Field label="Minimum nights (optional)" hint="Stays touching this season need at least this many nights." error={e.min_nights}><input type="number" min={1} max={365} value={String(d.min_nights)} onChange={ev => form.setData('min_nights', ev.target.value === '' ? '' : Number(ev.target.value))}/></Field>
      </div>
      <fieldset className="border-t border-slate-100 pt-5"><legend className="text-sm font-semibold text-navy">Rooms</legend><p className="mt-1 text-xs text-slate">Leave all unticked to apply to every room, including rooms you add later.</p>
        <div className="mt-3 grid gap-3 sm:grid-cols-2">{Object.entries(byProperty).map(([property, list]) => <div key={property}><p className="text-xs font-bold uppercase tracking-wide text-slate">{property}</p><div className="mt-1 grid gap-1">{list.map(room => <label key={room.id} className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-[var(--brand-pink)]" checked={d.room_ids.includes(room.id)} onChange={ev => form.setData('room_ids', ev.target.checked ? [...d.room_ids, room.id] : d.room_ids.filter(id => id !== room.id))}/>{room.name}</label>)}</div></div>)}</div>
        {!rooms.length && <p className="mt-2 text-sm text-slate">No rooms yet.</p>}
      </fieldset>
      <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5"><button disabled={form.processing} className="btn btn-pink">{editing ? 'Save season' : 'Add season'}</button>{editing && <button type="button" onClick={reset} className="text-sm font-semibold text-slate hover:text-navy">Cancel edit</button>}<p className="text-xs text-slate">If seasons overlap, the shorter one wins on shared nights.</p></div>
    </form>

    <div className="mt-6 grid gap-3">{seasons.map(s => { const ended = s.ends_on.slice(0, 10) < today; return <div key={s.id} className={`surface-card flex flex-wrap items-center justify-between gap-4 p-5 ${!s.active || ended ? 'opacity-70' : ''}`}>
      <div className="min-w-0"><p className="flex flex-wrap items-center gap-2 font-semibold">{s.name}<span className={`rounded px-1.5 py-0.5 text-[10px] font-bold uppercase ${ended ? 'bg-slate-200 text-slate' : s.active ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'}`}>{ended ? 'Ended' : s.active ? 'Active' : 'Paused'}</span></p>
        <p className="mt-1 text-sm text-slate">{day(s.starts_on)} to {day(s.ends_on)} · <strong className="text-navy">{effect(s.adjustment_type, Number(s.adjustment_value))}</strong>{s.min_nights ? ` · min. ${s.min_nights} nights` : ''}</p>
        <p className="mt-1 text-xs text-slate">{s.room_ids?.length ? s.room_ids.map(roomName).join(', ') : 'All rooms'}</p></div>
      <div className="flex items-center gap-2"><button type="button" onClick={() => save(s, { active: !s.active })} className="btn btn-light min-h-0 border border-slate-200 px-3 py-1.5 text-xs">{s.active ? 'Pause' : 'Resume'}</button><button type="button" onClick={() => { setEditing(s.id); form.setDefaults(toForm(s)); form.setData(toForm(s)); form.clearErrors(); window.scrollTo({ top: 0, behavior: 'smooth' }); }} className="btn btn-light min-h-0 border border-slate-200 px-3 py-1.5 text-xs"><Pencil size={14}/> Edit</button><button type="button" aria-label={`Delete ${s.name}`} onClick={() => { if (confirm(`Delete "${s.name}"? Existing bookings keep their prices.`)) router.delete(`/admin/settings/seasons/${s.id}`, { preserveScroll: true }); }} className="icon-button text-slate hover:text-red-600"><Trash2 size={17}/></button></div>
    </div>; })}{!seasons.length && <div className="surface-card p-8 text-center text-sm text-slate">No seasons yet. Add your first peak season or holiday above.</div>}</div>
  </SettingsLayout>;
}
