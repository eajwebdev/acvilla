import * as Dialog from '@radix-ui/react-dialog';
import { router, useForm } from '@inertiajs/react';
import { AlertTriangle, Eye, EyeOff, Minus, Package, PackagePlus, Pencil, Plus, Search, Trash2, X } from 'lucide-react';
import { useMemo, useState, type FormEvent } from 'react';
import { Empty } from './ops';
import { money } from '../types';

export type CatalogItem = { id: number; name: string; category: string; price: string; track_stock: boolean; stock: number; active: boolean };
export const LOW_STOCK = 5;
type Filter = 'all' | 'low' | 'hidden';
const SUGGESTED = ['Minibar', 'Snacks', 'Food', 'Drinks', 'Services', 'Housekeeping'];
const opts = { preserveScroll: true, preserveState: true };

/** Everything needed to run the POS catalog: find, add, edit, restock, hide and remove items. */
export default function PosCatalog({ items, initialFilter = 'all' }: { items: CatalogItem[]; initialFilter?: Filter }) {
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState('All');
  const [filter, setFilter] = useState<Filter>(initialFilter);
  const [editing, setEditing] = useState<CatalogItem | 'new' | null>(null);
  const [removing, setRemoving] = useState<CatalogItem | null>(null);
  const categories = useMemo(() => Array.from(new Set(items.map(i => i.category))).sort(), [items]);
  const low = items.filter(i => i.track_stock && i.stock <= LOW_STOCK);
  const hidden = items.filter(i => !i.active);
  const shown = items.filter(i => (category === 'All' || i.category === category)
    && (filter === 'all' || (filter === 'low' ? i.track_stock && i.stock <= LOW_STOCK : !i.active))
    && `${i.name} ${i.category}`.toLowerCase().includes(query.trim().toLowerCase()));

  return <div className="grid gap-4">
    <div className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--line)] sm:grid-cols-4">
      {([['Items', items.length, 'all'], ['Categories', categories.length, null], ['Low stock', low.length, 'low'], ['Hidden', hidden.length, 'hidden']] as const).map(([label, value, target]) => {
        const Tag = target ? 'button' : 'div';
        return <Tag key={label} {...(target ? { type: 'button' as const, onClick: () => setFilter(target), 'aria-pressed': filter === target } : {})}
          className={`min-w-0 bg-white p-4 text-left ${target ? 'transition hover:bg-[#FAFBF8] aria-pressed:bg-[var(--mint)]' : ''}`}>
          <p className="label">{label}</p><p className={`num mt-1.5 text-2xl font-semibold ${label === 'Low stock' && value > 0 ? 'text-[var(--danger)]' : ''}`}>{value}</p>
        </Tag>;
      })}
    </div>

    <div className="flex flex-wrap items-center gap-2">
      <label className="flex min-w-0 flex-1 basis-56 items-center gap-2 rounded-xl border border-[var(--line)] bg-white px-3"><Search size={15} className="shrink-0 text-[var(--muted)]"/><input value={query} onChange={e => setQuery(e.target.value)} placeholder="Search items" aria-label="Search items" className="w-full min-w-0 bg-transparent py-2.5 text-sm font-semibold outline-none"/></label>
      <div className="seg" role="group" aria-label="Show">{([['all', 'All'], ['low', 'Low stock'], ['hidden', 'Hidden']] as const).map(([key, label]) => <button key={key} type="button" aria-pressed={filter === key} onClick={() => setFilter(key)}>{label}</button>)}</div>
      <button type="button" onClick={() => setEditing('new')} className="btn btn-pink"><Plus size={15}/>Add item</button>
    </div>
    {categories.length > 1 && <div className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 thin-scroll">{['All', ...categories].map(c => <button key={c} type="button" className="chip shrink-0" aria-pressed={category === c} onClick={() => setCategory(c)}>{c}<span className="num text-[10px] opacity-60">{c === 'All' ? items.length : items.filter(i => i.category === c).length}</span></button>)}</div>}

    {low.length > 0 && filter !== 'low' && <button type="button" onClick={() => setFilter('low')} className="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-left text-sm font-semibold text-amber-900">
      <AlertTriangle size={17} className="shrink-0"/><span className="flex-1">{low.length} {low.length === 1 ? 'item is' : 'items are'} running low: {low.slice(0, 3).map(i => i.name).join(', ')}{low.length > 3 ? '…' : ''}</span><span className="shrink-0 underline">Restock</span>
    </button>}

    <div className="card overflow-hidden">
      {items.length === 0 ? <Empty icon={<Package size={18}/>} title="No items yet" body="Add minibar drinks, snacks, food and services so staff can ring them up in one tap." action={<button type="button" onClick={() => setEditing('new')} className="btn btn-pink"><Plus size={15}/>Add your first item</button>}/>
        : shown.length === 0 ? <Empty icon={<Search size={18}/>} title="No items match" body="Try another search, category or filter."/>
          : <ul className="divide-y divide-[var(--line)]">{shown.map(item => <ItemRow key={item.id} item={item} onEdit={() => setEditing(item)} onRemove={() => setRemoving(item)}/>)}</ul>}
    </div>

    <ItemDialog item={editing} categories={categories} onClose={() => setEditing(null)}/>
    <Dialog.Root open={removing !== null} onOpenChange={o => { if (!o) setRemoving(null); }}>
      <Dialog.Portal><Dialog.Overlay className="tapp-overlay animate-fade"/><Dialog.Content className="tapp-dialog animate-fade p-6" aria-describedby={undefined}>
        <span className="grid size-11 place-items-center rounded-xl bg-rose-50 text-[var(--danger)]"><Trash2 size={19}/></span>
        <Dialog.Title className="mt-4 text-lg font-bold">Delete {removing?.name}?</Dialog.Title>
        <p className="mt-1 text-sm text-[var(--muted)]">Past receipts keep its name and price. To stop selling it for now, hide it instead.</p>
        <div className="mt-6 grid grid-cols-2 gap-2"><Dialog.Close asChild><button type="button" className="btn btn-light">Cancel</button></Dialog.Close>
          <button type="button" className="btn btn-pink !bg-[var(--danger)] !shadow-none" onClick={() => removing && router.delete(`/admin/settings/pos-items/${removing.id}`, { ...opts, onSuccess: () => setRemoving(null) })}>Delete item</button></div>
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>
  </div>;
}

const payload = (item: CatalogItem, changes: Partial<CatalogItem>) => ({ name: item.name, category: item.category, price: item.price, track_stock: item.track_stock, active: item.active, ...changes });

function ItemRow({ item, onEdit, onRemove }: { item: CatalogItem; onEdit: () => void; onRemove: () => void }) {
  const [restock, setRestock] = useState(false);
  const [amount, setAmount] = useState('');
  const adjust = (change: number) => router.put(`/admin/settings/pos-items/${item.id}/stock`, { change }, { ...opts, onSuccess: () => { setRestock(false); setAmount(''); } });
  const lowStock = item.track_stock && item.stock <= LOW_STOCK;
  return <li className={`grid gap-3 px-4 py-3.5 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:px-5 ${item.active ? '' : 'bg-[#FAFBF8]'}`}>
    <div className="flex min-w-0 items-center gap-3">
      <span className={`grid size-10 shrink-0 place-items-center rounded-xl text-sm font-bold ${item.active ? 'bg-[var(--mint)] text-[#00408C]' : 'bg-[#EDF2EB] text-[var(--muted)]'}`}>{item.name.trim()[0]?.toUpperCase()}</span>
      <div className="min-w-0">
        <p className={`truncate font-bold ${item.active ? '' : 'text-[var(--muted)]'}`}>{item.name}{!item.active && <span className="pill ml-2 align-middle">hidden</span>}</p>
        <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs font-semibold text-[var(--muted)]"><span>{item.category}</span><span>·</span><span className="num text-forest">{money(item.price)}</span></p>
      </div>
    </div>
    <div className="flex flex-wrap items-center gap-2 sm:justify-end">
      {item.track_stock ? <div className="flex items-center gap-1 rounded-xl border border-[var(--line)] bg-white p-1">
        <button type="button" aria-label={`Remove one ${item.name}`} disabled={item.stock < 1} onClick={() => adjust(-1)} className="grid size-8 place-items-center rounded-lg hover:bg-[#F1F5F0] disabled:opacity-30"><Minus size={14}/></button>
        <span className={`num min-w-[4.5rem] text-center text-sm font-semibold ${lowStock ? 'text-[var(--danger)]' : ''}`}>{item.stock} <span className="text-[10.5px] font-bold text-[var(--muted)]">left</span></span>
        <button type="button" aria-label={`Add one ${item.name}`} onClick={() => adjust(1)} className="grid size-8 place-items-center rounded-lg hover:bg-[#F1F5F0]"><Plus size={14}/></button>
      </div> : <span className="px-1 text-xs font-semibold text-[var(--muted)]">Unlimited</span>}
      {item.track_stock && <button type="button" onClick={() => setRestock(!restock)} aria-expanded={restock} className={`btn btn-sm ${lowStock ? 'btn-soft' : 'btn-light'}`}><PackagePlus size={13}/>Restock</button>}
      <button type="button" onClick={() => router.put(`/admin/settings/pos-items/${item.id}`, payload(item, { active: !item.active }), opts)} className="btn btn-light btn-sm" title={item.active ? 'Hide from the register' : 'Show on the register'} aria-label={`${item.active ? 'Hide' : 'Show'} ${item.name}`}>{item.active ? <EyeOff size={13}/> : <Eye size={13}/>}<span className="hidden md:inline">{item.active ? 'Hide' : 'Show'}</span></button>
      <button type="button" onClick={onEdit} className="btn btn-light btn-sm" aria-label={`Edit ${item.name}`}><Pencil size={13}/><span className="hidden md:inline">Edit</span></button>
      <button type="button" onClick={onRemove} className="btn btn-danger btn-sm" aria-label={`Delete ${item.name}`}><Trash2 size={13}/></button>
    </div>
    {restock && <form onSubmit={e => { e.preventDefault(); const n = Number(amount); if (Number.isInteger(n) && n !== 0) adjust(n); }} className="flex flex-wrap items-center gap-1.5 rounded-xl bg-[#F4F8F3] p-2.5 sm:col-span-2">
      <span className="mr-1 text-xs font-bold">Delivery received:</span>
      {[6, 12, 24, 48].map(n => <button key={n} type="button" onClick={() => adjust(n)} className="chip !py-1">+{n}</button>)}
      <input type="number" step="1" value={amount} onChange={e => setAmount(e.target.value)} placeholder="+/- other" aria-label={`Stock change for ${item.name}`} className="input !min-h-8 !w-28 !py-1 num"/>
      <button className="btn btn-navy btn-sm" disabled={!amount}>Apply</button>
      <span className="text-[11px] font-semibold text-[var(--muted)]">Use a negative number for breakage or a recount.</span>
    </form>}
  </li>;
}

type Form = { name: string; category: string; price: string; track_stock: boolean; stock: number | null; active: boolean };

function ItemDialog({ item, categories, onClose }: { item: CatalogItem | 'new' | null; categories: string[]; onClose: () => void }) {
  return <Dialog.Root open={item !== null} onOpenChange={o => { if (!o) onClose(); }}>
    <Dialog.Portal><Dialog.Overlay className="tapp-overlay animate-fade"/><Dialog.Content className="tapp-dialog animate-fade" aria-describedby={undefined}>
      {item !== null && <ItemForm key={item === 'new' ? 'new' : item.id} item={item === 'new' ? null : item} categories={categories} onClose={onClose}/>}
    </Dialog.Content></Dialog.Portal>
  </Dialog.Root>;
}

function ItemForm({ item, categories, onClose }: { item: CatalogItem | null; categories: string[]; onClose: () => void }) {
  const form = useForm<Form>(item ? { name: item.name, category: item.category, price: item.price, track_stock: item.track_stock, stock: null, active: item.active }
    : { name: '', category: categories[0] ?? 'Minibar', price: '', track_stock: false, stock: 0, active: true });
  // An item already tracking stock is restocked from the list, so this form never overwrites its count.
  const asksStock = form.data.track_stock && !item?.track_stock;
  const submit = (e: FormEvent, again = false) => {
    e.preventDefault();
    const data = asksStock ? form.data : { ...form.data, stock: undefined };
    const done = { ...opts, onSuccess: () => { if (again) form.reset('name', 'price'); else onClose(); } };
    form.transform(() => data);
    if (item) form.put(`/admin/settings/pos-items/${item.id}`, done); else form.post('/admin/settings/pos-items', done);
  };
  const err = (k: keyof Form) => form.errors[k] && <small role="alert" className="font-semibold text-[var(--danger)]">{form.errors[k]}</small>;
  const chips = Array.from(new Set([...categories, ...SUGGESTED])).slice(0, 8);
  return <form onSubmit={submit}>
    <div className="flex items-center justify-between border-b border-[var(--line)] px-5 py-3.5"><Dialog.Title className="font-bold">{item ? `Edit ${item.name}` : 'New item'}</Dialog.Title><Dialog.Close className="grid size-8 place-items-center rounded-lg border border-[var(--line)]" aria-label="Close"><X size={16}/></Dialog.Close></div>
    <div className="grid gap-4 p-5">
      <label className="field">Name<input required autoFocus maxLength={80} value={form.data.name} onChange={e => form.setData('name', e.target.value)} placeholder="e.g. Bottled water"/>{err('name')}</label>
      <div className="grid gap-4 sm:grid-cols-2">
        <label className="field">Price<input required type="number" inputMode="decimal" min="0" step="0.01" value={form.data.price} onChange={e => form.setData('price', e.target.value)} placeholder="0.00" className="num"/>{err('price')}</label>
        <label className="field">Category<input required maxLength={40} value={form.data.category} onChange={e => form.setData('category', e.target.value)}/>{err('category')}</label>
      </div>
      <div className="-mt-2 flex flex-wrap gap-1.5">{chips.map(c => <button key={c} type="button" className="chip !py-1" aria-pressed={form.data.category === c} onClick={() => form.setData('category', c)}>{c}</button>)}</div>
      <div className="grid gap-2 rounded-xl border border-[var(--line)] p-3">
        <Toggle label="Track stock" hint="Counts down with each sale and warns when it runs low." checked={form.data.track_stock} onChange={v => form.setData('track_stock', v)}/>
        {asksStock && <label className="field mt-1">Opening stock<input type="number" min="0" step="1" inputMode="numeric" value={form.data.stock ?? 0} onChange={e => form.setData('stock', Number(e.target.value))} className="num"/>{err('stock')}</label>}
        {item?.track_stock && form.data.track_stock && <p className="text-xs font-semibold text-[var(--muted)]">{item.stock} in stock. Use Restock on the list to change it.</p>}
        <Toggle label="Show on the register" hint="Hidden items stay in reports but can’t be sold." checked={form.data.active} onChange={v => form.setData('active', v)}/>
      </div>
    </div>
    <div className="flex flex-wrap justify-end gap-2 border-t border-[var(--line)] p-4">
      {!item && <button type="button" disabled={form.processing} onClick={e => submit(e, true)} className="btn btn-light">Save & add another</button>}
      <button disabled={form.processing} className="btn btn-pink">{item ? 'Save changes' : 'Add item'}</button>
    </div>
  </form>;
}

function Toggle({ label, hint, checked, onChange }: { label: string; hint: string; checked: boolean; onChange: (value: boolean) => void }) {
  return <label className="flex cursor-pointer items-center justify-between gap-4 py-1">
    <span><span className="block text-sm font-bold">{label}</span><span className="block text-xs font-semibold text-[var(--muted)]">{hint}</span></span>
    <input type="checkbox" role="switch" className="peer sr-only" checked={checked} onChange={e => onChange(e.target.checked)}/>
    <span aria-hidden="true" className="relative h-6 w-11 shrink-0 rounded-full bg-[#D5E2D3] transition after:absolute after:left-0.5 after:top-0.5 after:size-5 after:rounded-full after:bg-white after:shadow after:transition peer-checked:bg-sea peer-checked:after:translate-x-5 peer-focus-visible:outline peer-focus-visible:outline-2 peer-focus-visible:outline-sea"/>
  </label>;
}
