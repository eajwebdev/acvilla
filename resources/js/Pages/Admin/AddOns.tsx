import { Head, router, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Pencil, Trash2 } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import SettingsLayout from '../../Components/SettingsLayout';
import { money, type AddOn } from '../../types';

type RoomOption = { id: number; name: string; property: { id: number; name: string } };
type AddOnForm = { name: string; description: string; price: number | ''; pricing: AddOn['pricing']; max_quantity: number; room_ids: number[]; active: boolean };

const blank: AddOnForm = { name: '', description: '', price: 500, pricing: 'per_stay', max_quantity: 1, room_ids: [], active: true };
const toForm = (a: AddOn): AddOnForm => ({ name: a.name, description: a.description ?? '', price: Number(a.price), pricing: a.pricing, max_quantity: a.max_quantity, room_ids: a.room_ids ?? [], active: a.active ?? true });

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
  return <label className="field">{label}{children}{hint && <small className="font-normal text-slate">{hint}</small>}{error && <small role="alert" className="font-normal text-red-600">{error}</small>}</label>;
}

export default function AddOns({ addOns, rooms, pricingOptions }: { addOns: AddOn[]; rooms: RoomOption[]; pricingOptions: Record<AddOn['pricing'], string> }) {
  const [editing, setEditing] = useState<number | null>(null);
  const form = useForm<AddOnForm>(blank);
  const d = form.data;
  const e = form.errors as Record<string, string>;
  const roomName = (id: number) => { const r = rooms.find(room => room.id === id); return r ? `${r.property.name} · ${r.name}` : 'Removed room'; };
  const byProperty = rooms.reduce<Record<string, RoomOption[]>>((groups, room) => ({ ...groups, [room.property.name]: [...(groups[room.property.name] || []), room] }), {});

  function reset() { setEditing(null); form.setDefaults(blank); form.setData(blank); form.clearErrors(); }
  function submit(ev: FormEvent) {
    ev.preventDefault();
    const done = { preserveScroll: true, onSuccess: reset };
    if (editing) form.put(`/admin/settings/add-ons/${editing}`, done); else form.post('/admin/settings/add-ons', done);
  }
  const save = (a: AddOn, patch: Partial<AddOnForm>) => router.put(`/admin/settings/add-ons/${a.id}`, { ...toForm(a), ...patch }, { preserveScroll: true });
  const move = (a: AddOn, direction: 'up' | 'down') => router.put(`/admin/settings/add-ons/${a.id}/move`, { direction }, { preserveScroll: true });

  return <SettingsLayout title="Add-ons" subtitle="Extras guests can add while booking, like breakfast or an airport transfer. They're added to the total and aren't discounted by promo codes."><Head title="Add-ons · Settings"/>
    <form onSubmit={submit} className="surface-card grid gap-5 p-6">
      <h3 className="text-lg font-semibold">{editing ? 'Edit add-on' : 'Create an add-on'}</h3>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label="Name" error={e.name}><input required maxLength={80} value={d.name} onChange={ev => form.setData('name', ev.target.value)} placeholder="Breakfast for two"/></Field>
        <Field label="Short description (optional)" error={e.description}><input maxLength={300} value={d.description} onChange={ev => form.setData('description', ev.target.value)} placeholder="Filipino or continental, served 7-10 AM"/></Field>
      </div>
      <div className="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-3">
        <Field label="Price" error={e.price}><input type="number" required min={0} step="0.01" value={String(d.price)} onChange={ev => form.setData('price', ev.target.value === '' ? '' : Number(ev.target.value))}/></Field>
        <Field label="Charged" error={e.pricing}><select value={d.pricing} onChange={ev => form.setData('pricing', ev.target.value as AddOn['pricing'])}>{Object.entries(pricingOptions).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></Field>
        <Field label="Most a guest can order" hint="1 shows a checkbox; more shows a quantity picker." error={e.max_quantity}><input type="number" required min={1} max={20} value={String(d.max_quantity)} onChange={ev => form.setData('max_quantity', Number(ev.target.value))}/></Field>
      </div>
      <fieldset className="border-t border-slate-100 pt-5"><legend className="text-sm font-semibold text-navy">Offered with</legend><p className="mt-1 text-xs text-slate">Leave all unticked to offer it with every room.</p>
        <div className="mt-3 grid gap-3 sm:grid-cols-2">{Object.entries(byProperty).map(([property, list]) => <div key={property}><p className="text-xs font-bold uppercase tracking-wide text-slate">{property}</p><div className="mt-1 grid gap-1">{list.map(room => <label key={room.id} className="flex items-center gap-2 text-sm"><input type="checkbox" className="size-4 accent-[var(--brand-pink)]" checked={d.room_ids.includes(room.id)} onChange={ev => form.setData('room_ids', ev.target.checked ? [...d.room_ids, room.id] : d.room_ids.filter(id => id !== room.id))}/>{room.name}</label>)}</div></div>)}</div>
      </fieldset>
      <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5"><button disabled={form.processing} className="btn btn-pink">{editing ? 'Save add-on' : 'Create add-on'}</button>{editing && <button type="button" onClick={reset} className="text-sm font-semibold text-slate hover:text-navy">Cancel edit</button>}</div>
    </form>

    <div className="mt-6 grid gap-3">{addOns.map((a, i) => <div key={a.id} className={`surface-card flex flex-wrap items-center justify-between gap-4 p-5 ${a.active ? '' : 'opacity-70'}`}>
      <div className="flex min-w-0 items-center gap-3">
        <div className="flex flex-col"><button type="button" aria-label={`Move ${a.name} up`} disabled={i === 0} onClick={() => move(a, 'up')} className="rounded p-0.5 text-slate hover:text-navy disabled:opacity-30"><ArrowUp size={15}/></button><button type="button" aria-label={`Move ${a.name} down`} disabled={i === addOns.length - 1} onClick={() => move(a, 'down')} className="rounded p-0.5 text-slate hover:text-navy disabled:opacity-30"><ArrowDown size={15}/></button></div>
        <div className="min-w-0"><p className="flex flex-wrap items-center gap-2 font-semibold">{a.name}<span className={`rounded px-1.5 py-0.5 text-[10px] font-bold uppercase ${a.active ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800'}`}>{a.active ? 'Offered' : 'Paused'}</span></p>
          <p className="mt-1 text-sm"><strong>{money(a.price)}</strong> <span className="text-slate">{pricingOptions[a.pricing]}{a.max_quantity > 1 ? ` · up to ${a.max_quantity}` : ''}</span></p>
          <p className="mt-1 text-xs text-slate">{a.room_ids?.length ? a.room_ids.map(roomName).join(', ') : 'All rooms'}{a.description ? ` · ${a.description}` : ''}</p></div>
      </div>
      <div className="flex items-center gap-2"><button type="button" onClick={() => save(a, { active: !a.active })} className="btn btn-light min-h-0 border border-slate-200 px-3 py-1.5 text-xs">{a.active ? 'Pause' : 'Offer'}</button><button type="button" onClick={() => { setEditing(a.id); form.setDefaults(toForm(a)); form.setData(toForm(a)); form.clearErrors(); window.scrollTo({ top: 0, behavior: 'smooth' }); }} className="btn btn-light min-h-0 border border-slate-200 px-3 py-1.5 text-xs"><Pencil size={14}/> Edit</button><button type="button" aria-label={`Delete ${a.name}`} onClick={() => { if (confirm(`Delete "${a.name}"? Past bookings keep what they ordered.`)) router.delete(`/admin/settings/add-ons/${a.id}`, { preserveScroll: true }); }} className="icon-button text-slate hover:text-red-600"><Trash2 size={17}/></button></div>
    </div>)}{!addOns.length && <div className="surface-card p-8 text-center text-sm text-slate">No add-ons yet. Breakfast, airport transfers, early check-in and extra beds are popular starters.</div>}</div>
  </SettingsLayout>;
}
