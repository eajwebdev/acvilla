import { Head, router, useForm } from '@inertiajs/react';
import { Copy, Pencil, Trash2 } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import SettingsLayout from '../../Components/SettingsLayout';
import { money } from '../../types';

type Promo = { id: number; code: string; discount_type: 'percent' | 'amount'; discount_value: string; starts_on: string | null; ends_on: string | null; min_nights: number | null; max_uses: number | null; active: boolean; uses: number };
type PromoForm = { code: string; discount_type: 'percent' | 'amount'; discount_value: number | ''; starts_on: string; ends_on: string; min_nights: number | ''; max_uses: number | ''; active: boolean };

const blank: PromoForm = { code: '', discount_type: 'percent', discount_value: 10, starts_on: '', ends_on: '', min_nights: '', max_uses: '', active: true };
const toForm = (p: Promo): PromoForm => ({ code: p.code, discount_type: p.discount_type, discount_value: Number(p.discount_value), starts_on: p.starts_on?.slice(0, 10) ?? '', ends_on: p.ends_on?.slice(0, 10) ?? '', min_nights: p.min_nights ?? '', max_uses: p.max_uses ?? '', active: p.active });
const day = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
const today = new Date().toLocaleDateString('en-CA');

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
  return <label className="field">{label}{children}{hint && <small className="font-normal text-slate">{hint}</small>}{error && <small role="alert" className="font-normal text-red-600">{error}</small>}</label>;
}

function status(p: Promo): [string, string] {
  if (!p.active) return ['Paused', 'bg-amber-100 text-amber-800'];
  if (p.ends_on && p.ends_on.slice(0, 10) < today) return ['Expired', 'bg-slate-200 text-slate'];
  if (p.starts_on && p.starts_on.slice(0, 10) > today) return ['Scheduled', 'bg-sky-100 text-sky-800'];
  if (p.max_uses !== null && p.uses >= p.max_uses) return ['Used up', 'bg-slate-200 text-slate'];
  return ['Active', 'bg-green-100 text-green-800'];
}

export default function PromoCodes({ promos }: { promos: Promo[] }) {
  const [editing, setEditing] = useState<number | null>(null);
  const form = useForm<PromoForm>(blank);
  const d = form.data;
  const e = form.errors as Record<string, string>;
  const opt = (key: 'min_nights' | 'max_uses') => ({ type: 'number', value: String(d[key]), onChange: (ev: React.ChangeEvent<HTMLInputElement>) => form.setData(key, ev.target.value === '' ? '' : Number(ev.target.value)) });

  function reset() { setEditing(null); form.setDefaults(blank); form.setData(blank); form.clearErrors(); }
  function submit(ev: FormEvent) {
    ev.preventDefault();
    form.transform(data => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' ? null : v])) as unknown as PromoForm);
    const done = { preserveScroll: true, onSuccess: reset };
    if (editing) form.put(`/admin/settings/promos/${editing}`, done); else form.post('/admin/settings/promos', done);
  }
  const toggle = (p: Promo) => router.put(`/admin/settings/promos/${p.id}`, Object.fromEntries(Object.entries({ ...toForm(p), active: !p.active }).map(([k, v]) => [k, v === '' ? null : v])), { preserveScroll: true });

  return <SettingsLayout title="Promo codes" subtitle="Codes guests enter on the stay page for a discount on room charges. Cleaning fees are never discounted."><Head title="Promo codes · Settings"/>
    <form onSubmit={submit} className="surface-card grid gap-5 p-6">
      <h3 className="text-lg font-semibold">{editing ? 'Edit promo code' : 'Create a promo code'}</h3>
      <div className="grid gap-4 sm:grid-cols-[1fr_auto_1fr]">
        <Field label="Code" hint="Guests type this. Letters, numbers, - and _." error={e.code}><input required maxLength={20} className="font-mono uppercase" value={d.code} onChange={ev => form.setData('code', ev.target.value.toUpperCase().replace(/[^A-Z0-9_-]/g, ''))} placeholder="SUMMER10"/></Field>
        <fieldset><legend className="text-sm font-semibold text-navy">Discount</legend><div className="mt-2 flex rounded-xl bg-slate-100 p-1">{([['percent', '%'], ['amount', 'Amount']] as const).map(([value, label]) => <button type="button" key={value} aria-pressed={d.discount_type === value} onClick={() => form.setData(data => ({ ...data, discount_type: value, discount_value: value === 'percent' ? 10 : 500 }))} className={`rounded-lg px-3 py-2 text-sm font-semibold ${d.discount_type === value ? 'bg-white text-navy shadow-sm' : 'text-slate'}`}>{label}</button>)}</div></fieldset>
        <Field label={d.discount_type === 'percent' ? 'Percent off' : 'Amount off'} hint={d.discount_type === 'amount' ? 'Taken off room charges once per booking.' : undefined} error={e.discount_value}><input type="number" required step="0.01" min={1} max={d.discount_type === 'percent' ? 100 : undefined} value={String(d.discount_value)} onChange={ev => form.setData('discount_value', ev.target.value === '' ? '' : Number(ev.target.value))}/></Field>
      </div>
      <div className="grid gap-4 border-t border-slate-100 pt-5 sm:grid-cols-4">
        <Field label="Valid from" hint="Date the guest books." error={e.starts_on}><input type="date" value={d.starts_on} onChange={ev => form.setData('starts_on', ev.target.value)}/></Field>
        <Field label="Valid until" error={e.ends_on}><input type="date" min={d.starts_on || undefined} value={d.ends_on} onChange={ev => form.setData('ends_on', ev.target.value)}/></Field>
        <Field label="Minimum nights" error={e.min_nights}><input min={1} max={365} placeholder="Any" {...opt('min_nights')}/></Field>
        <Field label="Total uses" hint="Blank = unlimited." error={e.max_uses}><input min={1} placeholder="Unlimited" {...opt('max_uses')}/></Field>
      </div>
      <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5"><button disabled={form.processing} className="btn btn-pink">{editing ? 'Save code' : 'Create code'}</button>{editing && <button type="button" onClick={reset} className="text-sm font-semibold text-slate hover:text-navy">Cancel edit</button>}<p className="text-xs text-slate">A code combines with weekly or monthly stay discounts and applies after them.</p></div>
    </form>

    <div className="mt-6 grid gap-3">{promos.map(p => { const [label, tone] = status(p); return <div key={p.id} className="surface-card flex flex-wrap items-center justify-between gap-4 p-5">
      <div className="min-w-0"><p className="flex flex-wrap items-center gap-2"><span className="rounded-lg bg-slate-100 px-2 py-1 font-mono text-sm font-bold">{p.code}</span><button type="button" aria-label={`Copy ${p.code}`} onClick={() => navigator.clipboard?.writeText(p.code)} className="text-slate hover:text-navy"><Copy size={14}/></button><span className={`rounded px-1.5 py-0.5 text-[10px] font-bold uppercase ${tone}`}>{label}</span></p>
        <p className="mt-2 text-sm"><strong>{p.discount_type === 'percent' ? `${Number(p.discount_value)}% off` : `${money(p.discount_value)} off`}</strong><span className="text-slate">{p.min_nights ? ` · ${p.min_nights}+ nights` : ''}{p.starts_on || p.ends_on ? ` · ${p.starts_on ? day(p.starts_on) : 'now'} to ${p.ends_on ? day(p.ends_on) : 'no end'}` : ''}</span></p>
        <p className="mt-1 text-xs text-slate">Used {p.uses}{p.max_uses !== null ? ` of ${p.max_uses}` : ''} {p.uses === 1 ? 'time' : 'times'}</p></div>
      <div className="flex items-center gap-2"><button type="button" onClick={() => toggle(p)} className="btn btn-light min-h-0 border border-slate-200 px-3 py-1.5 text-xs">{p.active ? 'Pause' : 'Resume'}</button><button type="button" onClick={() => { setEditing(p.id); form.setDefaults(toForm(p)); form.setData(toForm(p)); form.clearErrors(); window.scrollTo({ top: 0, behavior: 'smooth' }); }} className="btn btn-light min-h-0 border border-slate-200 px-3 py-1.5 text-xs"><Pencil size={14}/> Edit</button><button type="button" aria-label={`Delete ${p.code}`} onClick={() => { if (confirm(`Delete ${p.code}? Past bookings keep their discount. Pausing keeps the history visible.`)) router.delete(`/admin/settings/promos/${p.id}`, { preserveScroll: true }); }} className="icon-button text-slate hover:text-red-600"><Trash2 size={17}/></button></div>
    </div>; })}{!promos.length && <div className="surface-card p-8 text-center text-sm text-slate">No promo codes yet.</div>}</div>
  </SettingsLayout>;
}
