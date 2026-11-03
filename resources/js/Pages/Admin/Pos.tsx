import * as Dialog from '@radix-ui/react-dialog';
import { Head, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Ban, BedDouble, ChevronLeft, ChevronRight, ChevronUp, CreditCard, LoaderCircle, Minus, Package, Plus, Printer, Receipt, Search, ShoppingBasket, Smartphone, Trash2, UserRound, Wallet, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import DashboardShell from '../../Components/DashboardShell';
import { addDays, Card, Empty, exactMoney, fmtDay } from '../../Components/ops';
import PosCatalog, { LOW_STOCK, type CatalogItem } from '../../Components/PosCatalog';
import { money } from '../../types';

type Item = { id: number; name: string; category: string; price: string; track_stock: boolean; stock: number };
type Guest = { id: number; guest_name: string; unit: string; status: string; reference: string };
type Sale = { id: number; number: string; customer_name: string | null; subtotal: string; discount: string; total: string; status: 'PAID' | 'UNPAID' | 'VOID'; payment_method: string; payment_reference: string | null; amount_tendered: string | null; void_reason: string | null; booking_id: number | null; created_at: string; paid_at: string | null; cashier: string | null; unit: string | null; booking_reference: string | null; items: { name: string; unit_price: string; quantity: number; amount: string }[] };
type Summary = { count: number; gross: number; discounts: number; roomCharges: number; byMethod: { method: string; label: string; total: number }[]; collected: number; voids: number };
type Tab = 'register' | 'sales' | 'items';
type Props = { date: string; today: string; tab: Tab; items: Item[]; catalog: CatalogItem[] | null; guests: Guest[]; sales: Sale[]; summary: Summary; methods: Record<string, string>; receipt: Sale | null; business: { business_name: string; business_phone: string | null; business_address: string | null } };
type Line = { key: string; item_id: number | null; name: string; price: number; quantity: number; stock: number | null };

const methodIcon: Record<string, typeof Wallet> = { cash: Wallet, gcash: Smartphone, maya: Smartphone, card: CreditCard, bank_transfer: CreditCard, room_charge: BedDouble };
const time = (s: string) => new Date(s).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
const VOID_REASONS = ['Wrong item', 'Customer changed mind', 'Duplicate sale', 'Price error'];

export default function Pos({ date, today, tab: initialTab, items, catalog, guests, sales, summary, methods, receipt, business }: Props) {
  const { auth } = usePage<{ auth: { user: { role: string; name: string } } }>().props;
  const admin = auth.user.role === 'ADMIN';
  const [tab, setTabState] = useState<Tab>(initialTab === 'items' && !catalog ? 'register' : initialTab);
  const [itemsFilter, setItemsFilter] = useState<'all' | 'low'>('all');
  const [query, setQuery] = useState('');
  const [category, setCategory] = useState('All');
  const [cart, setCart] = useState<Line[]>([]);
  const [customer, setCustomer] = useState<'walk_in' | 'guest'>('walk_in');
  const [walkInName, setWalkInName] = useState('');
  const [bookingId, setBookingId] = useState<number | ''>('');
  const [discount, setDiscount] = useState('');
  const [method, setMethod] = useState('cash');
  const [tendered, setTendered] = useState('');
  const [reference, setReference] = useState('');
  const [custom, setCustom] = useState({ open: false, name: '', price: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [shown, setShown] = useState<Sale | null>(null);
  const [eod, setEod] = useState(false);
  const [sheet, setSheet] = useState(false);
  const search = useRef<HTMLInputElement>(null);

  // The tab lives in the URL so a refresh or a shared link opens the same view.
  const setTab = (next: Tab) => {
    setTabState(next);
    const url = new URL(window.location.href);
    if (next === 'register') url.searchParams.delete('tab'); else url.searchParams.set('tab', next);
    window.history.replaceState(window.history.state, '', url);
  };
  useEffect(() => {
    const id = Number(new URLSearchParams(window.location.search).get('booking'));
    if (id && guests.some(g => g.id === id)) { setCustomer('guest'); setBookingId(id); }
  }, [guests]);
  useEffect(() => { if (receipt) { setShown(receipt); setSheet(false); } }, [receipt]);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = e.target instanceof HTMLElement && ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName);
      if (e.key === '/' && !typing && tab === 'register') { e.preventDefault(); search.current?.focus(); }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [tab]);

  const categories = useMemo(() => ['All', ...Array.from(new Set(items.map(i => i.category)))], [items]);
  const visible = items.filter(i => (category === 'All' || i.category === category) && i.name.toLowerCase().includes(query.trim().toLowerCase()));
  const lowStock = (catalog ?? []).filter(i => i.active && i.track_stock && i.stock <= LOW_STOCK);
  const subtotal = cart.reduce((s, l) => s + l.price * l.quantity, 0);
  const disc = Math.min(Math.max(0, Number(discount) || 0), subtotal);
  const total = Math.round((subtotal - disc) * 100) / 100;
  const change = Math.max(0, (Number(tendered) || 0) - total);
  const guest = guests.find(g => g.id === bookingId);
  const count = cart.reduce((s, l) => s + l.quantity, 0);
  const inCart = (id: number) => cart.filter(l => l.item_id === id).reduce((s, l) => s + l.quantity, 0);

  const add = (item: Item) => {
    if (item.track_stock && inCart(item.id) >= item.stock) { setError(`Only ${item.stock} ${item.name} in stock.`); return; }
    setError('');
    setCart(c => c.some(l => l.item_id === item.id) ? c.map(l => l.item_id === item.id ? { ...l, quantity: l.quantity + 1 } : l)
      : [...c, { key: `i${item.id}`, item_id: item.id, name: item.name, price: Number(item.price), quantity: 1, stock: item.track_stock ? item.stock : null }]);
  };
  const qty = (key: string, delta: number) => setCart(c => c.flatMap(l => {
    if (l.key !== key) return [l];
    const q = l.quantity + delta;
    if (l.stock !== null && q > l.stock) { setError(`Only ${l.stock} ${l.name} in stock.`); return [l]; }
    return q < 1 ? [] : [{ ...l, quantity: q }];
  }));
  const addCustom = () => {
    const price = Number(custom.price);
    if (!custom.name.trim() || !(price >= 0) || custom.price === '') { setError('Custom items need a name and a price.'); return; }
    setCart(c => [...c, { key: `c${Date.now()}`, item_id: null, name: custom.name.trim(), price, quantity: 1, stock: null }]);
    setCustom({ open: false, name: '', price: '' }); setError('');
  };
  const reset = () => { setCart([]); setDiscount(''); setTendered(''); setReference(''); setWalkInName(''); setMethod('cash'); setError(''); if (customer === 'guest') { setBookingId(''); setCustomer('walk_in'); } };

  const checkout = () => {
    setError('');
    if (!cart.length) { setError('Add at least one item.'); return; }
    if (method === 'room_charge' && guest?.status !== 'CHECKED_IN') { setError('Room charges are available only for guests currently checked in.'); return; }
    if (method === 'cash' && tendered !== '' && Number(tendered) + 0.001 < total) { setError('Cash received is less than the total.'); return; }
    setBusy(true);
    router.post('/admin/pos/sales', {
      lines: cart.map(l => l.item_id ? { item_id: l.item_id, quantity: l.quantity } : { name: l.name, price: l.price, quantity: l.quantity }),
      discount: disc || null, payment_method: method, payment_reference: method !== 'cash' && method !== 'room_charge' ? reference || null : null,
      amount_tendered: method === 'cash' ? (tendered === '' ? total : Number(tendered)) : null,
      booking_id: customer === 'guest' && bookingId ? bookingId : null, customer_name: customer === 'walk_in' ? walkInName || null : null,
    } as never, { preserveScroll: true, onSuccess: () => reset(), onError: errs => setError(Object.values(errs)[0] as string), onFinish: () => setBusy(false) });
  };
  const goDate = (d: string) => router.get('/admin/pos', { tab: 'sales', ...(d === today ? {} : { date: d }) }, { preserveState: true, preserveScroll: true });

  const cartPanel = <CartPanel {...{ cart, customer, setCustomer, walkInName, setWalkInName, guests, bookingId, setBookingId, guest, method, setMethod, methods, discount, setDiscount, subtotal, total, tendered, setTendered, change, reference, setReference, error, busy, checkout, reset, qty }}/>;

  return <DashboardShell title="Point of sale" subtitle={`On-site counter · ${fmtDay(today, { weekday: 'long', month: 'long', day: 'numeric' })}`}
    actions={<div className="seg dark max-w-full overflow-x-auto">
      <button type="button" aria-pressed={tab === 'register'} onClick={() => setTab('register')}><ShoppingBasket size={15}/>Register</button>
      <button type="button" aria-pressed={tab === 'sales'} onClick={() => setTab('sales')}><Receipt size={15}/>Sales <span className="num">{summary.count}</span></button>
      {catalog && <button type="button" aria-pressed={tab === 'items'} onClick={() => { setItemsFilter('all'); setTab('items'); }}><Package size={15}/>Items {lowStock.length > 0 && <span className="num grid min-w-4 place-items-center rounded-full bg-sea px-1 text-[10px] text-white">{lowStock.length}</span>}</button>}
    </div>}>
    <Head title="POS"/>
    {tab === 'register' && <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
      <div className="min-w-0 pb-24 lg:pb-0">
        {admin && lowStock.length > 0 && <button type="button" onClick={() => { setItemsFilter('low'); setTab('items'); }} className="mb-3 flex w-full items-center gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-left text-sm font-semibold text-amber-900"><AlertTriangle size={16} className="shrink-0"/><span className="min-w-0 flex-1 truncate">Low stock: {lowStock.map(i => `${i.name} (${i.stock})`).join(', ')}</span><span className="shrink-0 underline">Restock</span></button>}
        <div className="flex flex-wrap items-center gap-2">
          <label className="flex min-w-0 flex-1 basis-56 items-center gap-2 rounded-xl border border-[var(--line)] bg-white px-3"><Search size={15} className="shrink-0 text-[var(--muted)]"/>
            <input ref={search} value={query} onChange={e => setQuery(e.target.value)} onKeyDown={e => { if (e.key === 'Enter' && visible[0]) { e.preventDefault(); add(visible[0]); setQuery(''); } }} placeholder="Search items, Enter to add" aria-label="Search items" className="w-full min-w-0 bg-transparent py-2.5 text-sm font-semibold outline-none"/>
            <kbd className="kbd hidden sm:inline-grid">/</kbd></label>
          <button type="button" onClick={() => setCustom({ ...custom, open: !custom.open })} aria-expanded={custom.open} className="btn btn-light"><Plus size={15}/>Custom item</button>
        </div>
        {custom.open && <div className="card mt-3 grid gap-2 p-3 sm:flex sm:flex-wrap sm:items-end">
          <label className="field min-w-0 sm:min-w-[180px] sm:flex-1">Item or charge<input autoFocus maxLength={80} value={custom.name} onChange={e => setCustom({ ...custom, name: e.target.value })} placeholder="e.g. Broken glass, extra pillow"/></label>
          <label className="field sm:w-32">Price<input type="number" inputMode="decimal" min="0" step="0.01" value={custom.price} onChange={e => setCustom({ ...custom, price: e.target.value })} onKeyDown={e => e.key === 'Enter' && addCustom()}/></label>
          <button type="button" onClick={addCustom} className="btn btn-navy">Add to sale</button>
        </div>}
        <div className="-mx-1 mt-3 flex gap-1.5 overflow-x-auto px-1 pb-1 thin-scroll">{categories.map(c => <button key={c} type="button" className="chip shrink-0" aria-pressed={category === c} onClick={() => setCategory(c)}>{c}</button>)}</div>
        {items.length === 0 ? <div className="card mt-4"><Empty icon={<Package size={18}/>} title="No items for sale yet" body="Add minibar, food and service items so staff can ring them up. You can still sell a custom item now." action={catalog ? <button type="button" onClick={() => setTab('items')} className="btn btn-pink">Add POS items</button> : undefined}/></div>
          : <div className="mt-3 grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-4">{visible.map(item => {
            const taken = inCart(item.id);
            const left = item.track_stock ? item.stock - taken : null; const out = left !== null && left <= 0;
            return <button key={item.id} type="button" disabled={out} onClick={() => add(item)} className={`card relative flex min-h-[104px] flex-col items-start justify-between p-3.5 text-left transition active:scale-[.98] ${taken ? '!border-sea ring-2 ring-sea/15' : ''} ${out && !taken ? 'cursor-not-allowed opacity-50' : 'hover:-translate-y-0.5 hover:border-sea hover:shadow-[0_8px_20px_rgba(10,34,102,.08)]'}`}>
              {taken > 0 && <span className="num absolute -right-1.5 -top-1.5 grid size-6 place-items-center rounded-full bg-sea text-[11px] font-bold text-white shadow">{taken}</span>}
              <span className="min-w-0"><span className="block text-[11px] font-bold uppercase tracking-wider text-[var(--muted)]">{item.category}</span><span className="mt-0.5 block text-sm font-bold leading-snug">{item.name}</span></span>
              <span className="mt-2 flex w-full items-end justify-between gap-2"><span className="num text-base font-semibold text-forest">{money(item.price)}</span>{left !== null && <span className={`text-[10.5px] font-bold ${left <= 3 ? 'text-[var(--danger)]' : 'text-[var(--muted)]'}`}>{out ? 'Sold out' : `${left} left`}</span>}</span>
            </button>;
          })}{visible.length === 0 && <p className="col-span-full py-10 text-center text-sm font-semibold text-[var(--muted)]">No items match.</p>}</div>}
      </div>
      <aside className="card hidden h-fit lg:sticky lg:top-[76px] lg:block">{cartPanel}</aside>
    </div>}
    {tab === 'sales' && <SalesView date={date} today={today} sales={sales} summary={summary} admin={admin} onShow={setShown} onDate={goDate} onEod={() => setEod(true)}/>}
    {tab === 'items' && catalog && <PosCatalog key={itemsFilter} items={catalog} initialFilter={itemsFilter}/>}

    {tab === 'register' && <div className={`fixed inset-x-0 z-30 border-t border-[var(--line)] bg-white/95 px-4 py-3 shadow-[0_-10px_30px_rgba(10,34,102,.08)] backdrop-blur lg:hidden ${admin ? 'bottom-0 pb-[calc(.75rem+env(safe-area-inset-bottom))]' : 'bottom-[calc(62px+env(safe-area-inset-bottom))]'}`}>
      <button type="button" onClick={() => setSheet(true)} className="btn btn-pink w-full !min-h-[52px] justify-between !px-4">
        <span className="flex items-center gap-2"><span className="num grid min-w-6 place-items-center rounded-full bg-white/25 px-1.5 text-xs">{count}</span>{count ? 'Review sale' : 'Current sale'}</span>
        <span className="flex items-center gap-1.5"><span className="num">{money(total)}</span><ChevronUp size={18}/></span>
      </button>
    </div>}
    <Dialog.Root open={sheet} onOpenChange={setSheet}>
      <Dialog.Portal><Dialog.Overlay className="tapp-overlay animate-fade lg:hidden"/><Dialog.Content className="tapp-dialog animate-fade lg:hidden" aria-describedby={undefined}>
        <Dialog.Title className="sr-only">Current sale</Dialog.Title>
        <div className="mx-auto mt-2 h-1.5 w-10 rounded-full bg-[#E3DED2]"/>
        {cartPanel}
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>

    <Dialog.Root open={shown !== null} onOpenChange={o => { if (!o) setShown(null); }}>
      <Dialog.Portal><Dialog.Overlay className="tapp-overlay no-print"/><Dialog.Content className="tapp-dialog" aria-describedby={undefined}>
        <div className="no-print flex items-center justify-between border-b border-[var(--line)] px-5 py-3"><Dialog.Title className="font-bold">Receipt {shown?.number}</Dialog.Title><Dialog.Close className="grid size-8 place-items-center rounded-lg border border-[var(--line)]" aria-label="Close"><X size={16}/></Dialog.Close></div>
        {shown && <ReceiptView sale={shown} business={business}/>}
        <div className="no-print grid grid-cols-2 gap-2 border-t border-[var(--line)] p-4"><button type="button" onClick={() => window.print()} className="btn btn-light"><Printer size={15}/>Print</button><Dialog.Close asChild><button type="button" className="btn btn-pink">{tab === 'register' ? 'New sale' : 'Done'}</button></Dialog.Close></div>
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>
    <Dialog.Root open={eod} onOpenChange={setEod}>
      <Dialog.Portal><Dialog.Overlay className="tapp-overlay no-print"/><Dialog.Content className="tapp-dialog" aria-describedby={undefined}>
        <div className="no-print flex items-center justify-between border-b border-[var(--line)] px-5 py-3"><Dialog.Title className="font-bold">End-of-day report</Dialog.Title><Dialog.Close className="grid size-8 place-items-center rounded-lg border border-[var(--line)]" aria-label="Close"><X size={16}/></Dialog.Close></div>
        <div className="print-area p-6 font-mono text-[12px] leading-relaxed text-black">
          <p className="text-center text-sm font-bold">{business.business_name}</p><p className="text-center">POS END-OF-DAY · {fmtDay(date, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}</p>
          <p className="mt-1 text-center">Printed {new Date().toLocaleString('en-PH')} by {auth.user.name}</p>
          <div className="my-3 border-t border-dashed border-black"/>
          {[['Sales', String(summary.count)], ['Gross sales', exactMoney(summary.gross)], ['Discounts', exactMoney(summary.discounts)], ['Charged to rooms', exactMoney(summary.roomCharges)], ['Voided receipts', String(summary.voids)]].map(([k, v]) => <p key={k} className="flex justify-between"><span>{k}</span><span>{v}</span></p>)}
          <div className="my-3 border-t border-dashed border-black"/><p className="font-bold">COLLECTED</p>
          {summary.byMethod.map(m => <p key={m.method} className="flex justify-between"><span>{m.label}</span><span>{exactMoney(m.total)}</span></p>)}
          <p className="mt-1 flex justify-between font-bold"><span>Total collected</span><span>{exactMoney(summary.collected)}</span></p>
          <p className="mt-3 text-[11px]">Collected includes room charges settled this day. Cash in drawer should equal the Cash line.</p>
        </div>
        <div className="no-print border-t border-[var(--line)] p-4"><button type="button" onClick={() => window.print()} className="btn btn-pink w-full"><Printer size={15}/>Print report</button></div>
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>
  </DashboardShell>;
}

type CartProps = {
  cart: Line[]; customer: 'walk_in' | 'guest'; setCustomer: (c: 'walk_in' | 'guest') => void; walkInName: string; setWalkInName: (v: string) => void;
  guests: Guest[]; bookingId: number | ''; setBookingId: (v: number | '') => void; guest?: Guest; method: string; setMethod: (m: string) => void; methods: Record<string, string>;
  discount: string; setDiscount: (v: string) => void; subtotal: number; total: number; tendered: string; setTendered: (v: string) => void; change: number;
  reference: string; setReference: (v: string) => void; error: string; busy: boolean; checkout: () => void; reset: () => void; qty: (key: string, delta: number) => void;
};

/** The sale being built: shown beside the items on large screens and as a bottom sheet on phones. */
function CartPanel({ cart, customer, setCustomer, walkInName, setWalkInName, guests, bookingId, setBookingId, guest, method, setMethod, methods, discount, setDiscount, subtotal, total, tendered, setTendered, change, reference, setReference, error, busy, checkout, reset, qty }: CartProps) {
  return <>
    <div className="flex items-center justify-between border-b border-[var(--line)] px-4 py-3"><h2 className="font-bold">Current sale</h2>{cart.length > 0 && <button type="button" onClick={reset} className="text-xs font-bold text-[var(--muted)] hover:text-[var(--danger)]">Clear</button>}</div>
    <div className="border-b border-[var(--line)] p-4">
      <div className="seg w-full"><button type="button" className="flex-1" aria-pressed={customer === 'walk_in'} onClick={() => { setCustomer('walk_in'); if (method === 'room_charge') setMethod('cash'); }}><UserRound size={14}/>Walk-in</button><button type="button" className="flex-1" aria-pressed={customer === 'guest'} onClick={() => setCustomer('guest')}><BedDouble size={14}/>In-house guest</button></div>
      {customer === 'walk_in' ? <input value={walkInName} onChange={e => setWalkInName(e.target.value)} maxLength={120} placeholder="Customer name (optional)" aria-label="Customer name" className="input mt-2"/>
        : guests.length === 0 ? <p className="mt-2 text-xs font-semibold text-[var(--muted)]">No confirmed or checked-in guests today.</p>
          : <select value={bookingId} onChange={e => { const id = e.target.value ? Number(e.target.value) : ''; setBookingId(id); if (method === 'room_charge' && guests.find(g => g.id === id)?.status !== 'CHECKED_IN') setMethod('cash'); }} aria-label="Guest" className="input mt-2"><option value="">Choose guest…</option>{guests.map(g => <option key={g.id} value={g.id}>{g.guest_name} · {g.unit}{g.status === 'CHECKED_IN' ? '' : ' (arriving)'}</option>)}</select>}
    </div>
    <div className="max-h-[300px] overflow-y-auto">
      {cart.length === 0 ? <p className="px-4 py-10 text-center text-sm font-semibold text-[var(--muted)]">Tap an item to add it.</p>
        : <ul>{cart.map(l => <li key={l.key} className="flex items-center gap-2 border-b border-[var(--line)] px-4 py-2.5">
          <span className="min-w-0 flex-1"><span className="block truncate text-sm font-bold">{l.name}</span><span className="num text-[11px] text-[var(--muted)]">{money(l.price)} each</span></span>
          <span className="flex items-center gap-1"><button type="button" aria-label={`One less ${l.name}`} onClick={() => qty(l.key, -1)} className="grid size-8 place-items-center rounded-lg border border-[var(--line)]">{l.quantity === 1 ? <Trash2 size={13}/> : <Minus size={13}/>}</button><span className="num w-7 text-center text-sm font-semibold">{l.quantity}</span><button type="button" aria-label={`One more ${l.name}`} onClick={() => qty(l.key, 1)} className="grid size-8 place-items-center rounded-lg border border-[var(--line)]"><Plus size={13}/></button></span>
          <span className="num w-20 text-right text-sm font-semibold">{money(l.price * l.quantity)}</span>
        </li>)}</ul>}
    </div>
    <div className="grid gap-1.5 border-b border-[var(--line)] px-4 py-3 text-sm">
      <div className="flex justify-between"><span className="text-[var(--muted)]">Subtotal</span><span className="num font-semibold">{exactMoney(subtotal)}</span></div>
      <div className="flex items-center justify-between gap-3"><label htmlFor="pos-discount" className="text-[var(--muted)]">Discount</label><input id="pos-discount" type="number" inputMode="decimal" min="0" step="0.01" value={discount} onChange={e => setDiscount(e.target.value)} placeholder="0.00" className="num w-28 rounded-lg border border-[var(--line)] bg-[#F7F6F3] px-2 py-1 text-right text-sm"/></div>
      <div className="mt-1 flex items-baseline justify-between"><span className="font-bold">Total</span><span className="num text-2xl font-semibold text-forest">{exactMoney(total)}</span></div>
    </div>
    <div className="p-4">
      <p className="label mb-2">Payment</p>
      <div className="grid grid-cols-3 gap-1.5">{Object.entries(methods).map(([key, label]) => { const Icon = methodIcon[key] ?? Wallet; const off = key === 'room_charge' && guest?.status !== 'CHECKED_IN'; return <button key={key} type="button" disabled={off} title={off ? 'Choose a checked-in guest first' : undefined} onClick={() => setMethod(key)} aria-pressed={method === key} className={`flex flex-col items-center gap-1 rounded-xl border px-1 py-2 text-[11px] font-bold ${method === key ? 'border-forest bg-forest text-white' : 'border-[var(--line)] bg-white'} ${off ? 'cursor-not-allowed opacity-40' : ''}`}><Icon size={16}/>{label}</button>; })}</div>
      {method === 'cash' && <div className="mt-3"><label className="field">Cash received<input type="number" inputMode="decimal" min="0" step="0.01" value={tendered} onChange={e => setTendered(e.target.value)} placeholder={total.toFixed(2)} className="num"/></label>
        <div className="mt-1.5 flex flex-wrap gap-1">{[total, 100, 200, 500, 1000].filter((v, i) => i === 0 || v >= total).map((v, i) => <button key={i} type="button" onClick={() => setTendered(i === 0 ? '' : v.toFixed(2))} aria-pressed={i === 0 ? tendered === '' : Number(tendered) === v} className="chip !px-2.5 !py-1">{i === 0 ? 'Exact' : money(v)}</button>)}</div>
        <div className="mt-2 flex justify-between rounded-lg bg-[#F7F6F3] px-3 py-2 text-sm font-bold"><span>Change</span><span className="num">{exactMoney(change)}</span></div></div>}
      {['gcash', 'maya', 'card', 'bank_transfer'].includes(method) && <input value={reference} onChange={e => setReference(e.target.value)} maxLength={100} placeholder="Reference / approval no. (optional)" aria-label="Payment reference" className="input mt-3"/>}
      {method === 'room_charge' && guest && <p className="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-xs font-semibold text-ocean">Adds to {guest.guest_name}'s folio ({guest.unit}). Collected at check-out.</p>}
      {error && <p role="alert" className="mt-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">{error}</p>}
      <button type="button" disabled={busy || !cart.length} onClick={checkout} className="btn btn-pink mt-3 w-full !min-h-[48px] !text-[15px]">{busy ? <LoaderCircle size={17} className="animate-spin"/> : null}{method === 'room_charge' ? `Charge to room · ${money(total)}` : `Charge ${money(total)}`}</button>
    </div>
  </>;
}

function SalesView({ date, today, sales, summary, admin, onShow, onDate, onEod }: { date: string; today: string; sales: Sale[]; summary: Summary; admin: boolean; onShow: (s: Sale) => void; onDate: (d: string) => void; onEod: () => void }) {
  const [settling, setSettling] = useState<number | null>(null);
  const [settleMethod, setSettleMethod] = useState('cash');
  const [voiding, setVoiding] = useState<Sale | null>(null);
  const [reason, setReason] = useState('');
  const settle = (s: Sale) => router.post(`/admin/pos/sales/${s.id}/settle`, { method: settleMethod }, { preserveScroll: true, preserveState: true, onSuccess: () => setSettling(null) });
  const confirmVoid = () => voiding && reason.trim() && router.post(`/admin/pos/sales/${voiding.id}/void`, { reason: reason.trim() }, { preserveScroll: true, preserveState: true, onSuccess: () => { setVoiding(null); setReason(''); } });
  const payment = (s: Sale) => s.status === 'VOID' ? <span className="pill">void</span> : s.status === 'UNPAID' ? <span className="pill border-amber-200 bg-amber-50 text-amber-800">room · open</span> : <span className="pill">{s.payment_method === 'room_charge' ? 'room · settled' : s.payment_method.replace('_', ' ')}</span>;
  const actions = (s: Sale) => <span className="inline-flex gap-1">
    {s.status === 'UNPAID' && <button type="button" onClick={() => setSettling(settling === s.id ? null : s.id)} className="btn btn-soft btn-sm">Settle</button>}
    {admin && s.status !== 'VOID' && <button type="button" onClick={() => { setVoiding(s); setReason(''); }} className="btn btn-danger btn-sm" aria-label={`Void ${s.number}`}><Ban size={12}/>Void</button>}
  </span>;
  const settleRow = (s: Sale) => <div className="flex flex-wrap items-center justify-end gap-2"><span className="text-xs font-semibold">Settle {money(s.total)} by</span><select value={settleMethod} onChange={e => setSettleMethod(e.target.value)} className="rounded-lg border border-[var(--line)] bg-white px-2 py-1.5 text-xs font-bold"><option value="cash">Cash</option><option value="gcash">GCash</option><option value="maya">Maya</option><option value="card">Card</option><option value="bank_transfer">Bank transfer</option></select><button type="button" onClick={() => settle(s)} className="btn btn-navy btn-sm">Confirm</button></div>;
  return <>
    <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div className="flex items-center gap-1"><button type="button" aria-label="Previous day" onClick={() => onDate(addDays(date, -1))} className="grid size-9 place-items-center rounded-lg border border-[var(--line)] bg-white"><ChevronLeft size={16}/></button><span className="min-w-[130px] text-center text-sm font-bold">{date === today ? 'Today' : fmtDay(date, { weekday: 'short', month: 'short', day: 'numeric' })}</span><button type="button" aria-label="Next day" disabled={date >= today} onClick={() => onDate(addDays(date, 1))} className="grid size-9 place-items-center rounded-lg border border-[var(--line)] bg-white disabled:opacity-40"><ChevronRight size={16}/></button></div>
      <button type="button" onClick={onEod} className="btn btn-light"><Printer size={15}/>End-of-day report</button>
    </div>
    <div className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--line)] md:grid-cols-4">
      {[['Sales', String(summary.count), `${summary.voids} voided`], ['Gross', money(summary.gross), `${money(summary.discounts)} discounts`], ['Collected', money(summary.collected), summary.byMethod.filter(m => m.total > 0).map(m => `${m.label} ${money(m.total)}`).join(' · ') || 'nothing yet'], ['On room folios', money(summary.roomCharges), 'charged today']].map(([l, v, s]) => <div key={l} className="min-w-0 bg-[#FBFAF6] p-4"><p className="label">{l}</p><p className="num mt-2 text-xl font-semibold sm:text-2xl">{v}</p><p className="mt-1 truncate text-[11.5px] font-semibold text-[var(--muted)]">{s}</p></div>)}
    </div>
    <Card className="mt-5" title="Receipts" subtitle="Tap a receipt to view or reprint it">
      {sales.length === 0 ? <Empty icon={<Receipt size={18}/>} title="No sales on this day"/> : <>
        <ul className="divide-y divide-[var(--line)] md:hidden">{sales.map(s => <li key={s.id} className={s.status === 'VOID' ? 'opacity-55' : ''}>
          <button type="button" onClick={() => onShow(s)} className="flex w-full items-start gap-3 px-4 py-3 text-left">
            <span className="min-w-0 flex-1"><span className="flex items-center gap-2"><span className="num text-sm font-semibold">{s.number}</span><span className="num text-[11px] text-[var(--muted)]">{time(s.created_at)}</span></span>
              <span className="mt-0.5 block truncate text-sm font-semibold">{s.customer_name || 'Walk-in'}{s.unit ? ` · ${s.unit}` : ''}</span>
              <span className="mt-0.5 block truncate text-xs text-[var(--muted)]">{s.items.map(i => `${i.quantity}× ${i.name}`).join(', ')}</span></span>
            <span className="grid justify-items-end gap-1"><span className={`num text-sm font-semibold ${s.status === 'VOID' ? 'line-through' : ''}`}>{money(s.total)}</span>{payment(s)}</span>
          </button>
          {(s.status === 'UNPAID' || (admin && s.status !== 'VOID')) && <div className="flex justify-end px-4 pb-3">{actions(s)}</div>}
          {settling === s.id && <div className="bg-[#FAF9F7] px-4 py-3">{settleRow(s)}</div>}
        </li>)}</ul>
        <div className="hidden overflow-x-auto md:block"><table className="table w-full">
          <thead><tr><th>Time</th><th>Receipt</th><th>Customer</th><th>Items</th><th>Payment</th><th className="text-right">Total</th><th/></tr></thead>
          <tbody>{sales.map(s => [<tr key={s.id} className={`clickable ${s.status === 'VOID' ? 'opacity-55' : ''}`} onClick={() => onShow(s)}>
            <td className="num text-xs">{time(s.created_at)}</td>
            <td className="num font-semibold">{s.number}</td>
            <td className="font-semibold">{s.customer_name || 'Walk-in'}{s.unit && <span className="block text-[11px] text-[var(--muted)]">{s.unit}</span>}</td>
            <td className="max-w-[240px] truncate text-xs">{s.items.map(i => `${i.quantity}× ${i.name}`).join(', ')}</td>
            <td>{payment(s)}</td>
            <td className={`num text-right font-semibold ${s.status === 'VOID' ? 'line-through' : ''}`}>{money(s.total)}</td>
            <td onClick={e => e.stopPropagation()} className="text-right">{actions(s)}</td>
          </tr>, settling === s.id && <tr key={`${s.id}-s`}><td colSpan={7} className="bg-[#FAF9F7]">{settleRow(s)}</td></tr>])}</tbody>
        </table></div>
      </>}
    </Card>
    <Dialog.Root open={voiding !== null} onOpenChange={o => { if (!o) setVoiding(null); }}>
      <Dialog.Portal><Dialog.Overlay className="tapp-overlay animate-fade"/><Dialog.Content className="tapp-dialog animate-fade p-6" aria-describedby={undefined}>
        <span className="grid size-11 place-items-center rounded-xl bg-rose-50 text-[var(--danger)]"><Ban size={19}/></span>
        <Dialog.Title className="mt-4 text-lg font-bold">Void {voiding?.number}?</Dialog.Title>
        <p className="mt-1 text-sm text-[var(--muted)]">{voiding && money(voiding.total)} is removed from today’s totals and tracked stock is returned. This is recorded in the audit log.</p>
        <div className="mt-4 flex flex-wrap gap-1.5">{VOID_REASONS.map(r => <button key={r} type="button" className="chip !py-1" aria-pressed={reason === r} onClick={() => setReason(r)}>{r}</button>)}</div>
        <label className="field mt-3">Reason<input autoFocus maxLength={200} value={reason} onChange={e => setReason(e.target.value)} onKeyDown={e => e.key === 'Enter' && confirmVoid()} placeholder="Why is this sale being voided?"/></label>
        <div className="mt-6 grid grid-cols-2 gap-2"><Dialog.Close asChild><button type="button" className="btn btn-light">Keep sale</button></Dialog.Close><button type="button" disabled={!reason.trim()} onClick={confirmVoid} className="btn btn-pink !bg-[var(--danger)] !shadow-none">Void sale</button></div>
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>
  </>;
}

function ReceiptView({ sale, business }: { sale: Sale; business: Props['business'] }) {
  const change = sale.amount_tendered ? Number(sale.amount_tendered) - Number(sale.total) : 0;
  return <div className="print-area px-6 py-5 font-mono text-[12px] leading-relaxed text-black">
    <p className="text-center text-sm font-bold">{business.business_name}</p>
    {business.business_address && <p className="text-center">{business.business_address}</p>}
    {business.business_phone && <p className="text-center">{business.business_phone}</p>}
    <div className="my-3 border-t border-dashed border-black"/>
    <p className="flex justify-between"><span>Receipt</span><span>{sale.number}</span></p>
    <p className="flex justify-between"><span>Date</span><span>{new Date(sale.created_at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}</span></p>
    {sale.cashier && <p className="flex justify-between"><span>Cashier</span><span>{sale.cashier}</span></p>}
    <p className="flex justify-between"><span>Customer</span><span>{sale.customer_name || 'Walk-in'}{sale.unit ? ` · ${sale.unit}` : ''}</span></p>
    <div className="my-3 border-t border-dashed border-black"/>
    {sale.items.map((i, n) => <div key={n}><p>{i.name}</p><p className="flex justify-between pl-3"><span>{i.quantity} × {exactMoney(i.unit_price)}</span><span>{exactMoney(i.amount)}</span></p></div>)}
    <div className="my-3 border-t border-dashed border-black"/>
    <p className="flex justify-between"><span>Subtotal</span><span>{exactMoney(sale.subtotal)}</span></p>
    {Number(sale.discount) > 0 && <p className="flex justify-between"><span>Discount</span><span>-{exactMoney(sale.discount)}</span></p>}
    <p className="flex justify-between text-sm font-bold"><span>TOTAL</span><span>{exactMoney(sale.total)}</span></p>
    {sale.status === 'UNPAID' ? <p className="mt-2 font-bold">CHARGED TO ROOM: settle at check-out</p> : sale.status === 'VOID' ? <p className="mt-2 font-bold">*** VOID *** {sale.void_reason}</p> : <>
      <p className="flex justify-between"><span>Paid by</span><span>{sale.payment_method === 'room_charge' ? 'Room (settled)' : sale.payment_method.replace('_', ' ').toUpperCase()}</span></p>
      {sale.amount_tendered && <><p className="flex justify-between"><span>Cash</span><span>{exactMoney(sale.amount_tendered)}</span></p><p className="flex justify-between"><span>Change</span><span>{exactMoney(change)}</span></p></>}
      {sale.payment_reference && <p className="flex justify-between"><span>Ref</span><span>{sale.payment_reference}</span></p>}
    </>}
    <div className="my-3 border-t border-dashed border-black"/>
    <p className="text-center">Thank you! Salamat po!</p>
    <p className="mt-1 text-center text-[10px]">This is not an official receipt.</p>
  </div>;
}
