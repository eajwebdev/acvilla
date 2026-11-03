import * as DropdownMenu from '@radix-ui/react-dropdown-menu';
import { router } from '@inertiajs/react';
import { ArrowRight, Ban, Bell, CalendarCheck2, CheckCheck, CalendarPlus, CircleCheck, Clock3, LoaderCircle, LogIn, LogOut, ReceiptText, RotateCcw, UserPlus, Wallet, type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { getJson } from './ops';

type Item = { id: number; booking_id: number | null; event: string; title: string; detail: string | null; actor: string | null; created_at: string; unread: boolean; needs_action: boolean };

const ICONS: Record<string, LucideIcon> = {
  requested: CalendarPlus, manual_booking: UserPlus, receipt_uploaded: ReceiptText, payment_reported: ReceiptText,
  dates_approved: CalendarCheck2, payment_approved: Wallet, payment_recorded: Wallet, confirmed: CircleCheck,
  checked_in: LogIn, checked_out: LogOut, completed: CircleCheck, extended: CalendarPlus, hold_extended: Clock3,
  request_reopened: RotateCcw, downpayment_changed: Wallet, expired: Clock3, receipt_rejected: Ban, cancelled: Ban, declined: Ban, no_show: Ban,
};
const TONES: Record<string, string> = {
  requested: 'bg-amber-100 text-amber-700', receipt_uploaded: 'bg-amber-100 text-amber-700', payment_reported: 'bg-amber-100 text-amber-700',
  payment_approved: 'bg-emerald-100 text-emerald-700', payment_recorded: 'bg-emerald-100 text-emerald-700', confirmed: 'bg-emerald-100 text-emerald-700', checked_in: 'bg-emerald-100 text-emerald-700',
  cancelled: 'bg-rose-100 text-rose-700', declined: 'bg-rose-100 text-rose-700', receipt_rejected: 'bg-rose-100 text-rose-700', no_show: 'bg-rose-100 text-rose-700', expired: 'bg-slate-100 text-slate-600',
};

const ago = (iso: string) => {
  const s = Math.max(0, (Date.now() - Date.parse(iso)) / 1000);
  if (s < 60) return 'just now';
  if (s < 3600) return `${Math.floor(s / 60)} min ago`;
  if (s < 86400) return `${Math.floor(s / 3600)} h ago`;
  return new Date(iso).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
};
const sameDay = (a: Date, b: Date) => a.toDateString() === b.toDateString();
/** Section heading for the day a notification arrived: Today, Yesterday, or the date. */
const dayLabel = (iso: string) => {
  const date = new Date(iso), yesterday = new Date(Date.now() - 86400000);
  if (sameDay(date, new Date())) return 'Today';
  if (sameDay(date, yesterday)) return 'Yesterday';
  return date.toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric' });
};
/** Relative time for today's items; the clock time for older ones (the day is already in the heading). */
const when = (iso: string) => sameDay(new Date(iso), new Date()) ? ago(iso) : new Date(iso).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
/** Peso amounts stand out in the detail line. */
const withAmounts = (text: string) => text.split(/(₱[\d,]+(?:\.\d+)?)/).map((part, i) => i % 2 ? <strong key={i} className="font-semibold text-[var(--ink)]">{part}</strong> : part);
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

/**
 * The bell at the top of the admin: every step of every booking (new requests, receipts, approvals, payments,
 * check-ins…), newest first. The count follows the shell's live check; opening the bell marks it read.
 */
export default function NotificationBell({ initial = 0 }: { initial?: number }) {
  const [count, setCount] = useState(initial);
  const [items, setItems] = useState<Item[] | null>(null);
  const [failed, setFailed] = useState(false);
  const [filter, setFilter] = useState<'all' | 'unread'>('all');
  useEffect(() => setCount(initial), [initial]);
  // The admin shell checks for changes every few seconds and passes the unread count along.
  useEffect(() => {
    const update = (event: Event) => setCount((event as CustomEvent<number>).detail);
    window.addEventListener('tapp:unread', update);
    return () => window.removeEventListener('tapp:unread', update);
  }, []);

  function onOpenChange(open: boolean) {
    if (open) {
      setFailed(false);
      getJson<{ items: Item[]; unread: number }>('/admin/notifications').then(r => { setItems(r.items); setCount(r.unread); }).catch(() => setFailed(true));
      return;
    }
    // Closing marks what was shown as read (the highlights stay visible while the list is open).
    void markSeen();
  }
  /** Saves "read up to here" once per opening; anything newer than the list that was shown stays unread. */
  function markSeen(): Promise<void> {
    const shown = Math.max(0, ...(items ?? []).map(item => item.id));
    if (shown <= 0 || !(items ?? []).some(item => item.unread)) return Promise.resolve();
    setItems(current => current?.map(item => ({ ...item, unread: false })) ?? null);
    return fetch('/admin/notifications/seen', { method: 'POST', credentials: 'same-origin', body: JSON.stringify({ up_to: shown }),
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() } })
      .then(response => response.ok ? response.json() : null).then(body => { if (body) setCount(body.unread); }).catch(() => {});
  }
  // Save the read state before leaving, so the next page doesn't bring back the old count.
  const open = (item: Item) => { void markSeen().then(() => { if (item.booking_id) router.visit(`/admin/bookings?open=${item.booking_id}`); }); };

  const unread = (items ?? []).filter(item => item.unread).length;
  const shown = (items ?? []).filter(item => filter === 'all' || item.unread);
  const groups: { label: string; items: Item[] }[] = [];
  for (const item of shown) {
    const label = dayLabel(item.created_at);
    if (groups.at(-1)?.label === label) groups.at(-1)!.items.push(item); else groups.push({ label, items: [item] });
  }

  return <DropdownMenu.Root modal={false} onOpenChange={onOpenChange}>
    <DropdownMenu.Trigger className="relative grid size-10 shrink-0 place-items-center rounded-full border border-[var(--line)] bg-white text-forest transition hover:border-[#D8D2C4] hover:bg-[#EDF1FC]"
      aria-label={count > 0 ? `Notifications, ${count} new` : 'Notifications'}>
      <Bell size={19}/>
      {count > 0 && <span className="num absolute -right-1.5 -top-1.5 grid h-5 min-w-5 place-items-center rounded-full bg-rose-600 px-1 text-[11px] font-bold text-white ring-2 ring-white">{count > 99 ? '99+' : count}</span>}
    </DropdownMenu.Trigger>
    <DropdownMenu.Portal>
      <DropdownMenu.Content align="end" sideOffset={10} collisionPadding={12} className="notif-panel z-50 flex w-[min(420px,calc(100vw-24px))] flex-col overflow-hidden rounded-2xl border border-[var(--line)] bg-white shadow-[0_1px_2px_rgba(16,24,40,.04),0_20px_48px_-12px_rgba(16,24,40,.22)]">
        <div className="px-5 pb-3 pt-4">
          <div className="flex items-center justify-between gap-3">
            <div>
              <p className="text-[15px] font-bold text-[var(--ink)]">Notifications</p>
              <p className="mt-0.5 text-xs font-medium text-[var(--muted)]">{unread > 0 ? `${unread} unread` : 'You are all caught up'}</p>
            </div>
            {unread > 0 && <button type="button" onClick={() => void markSeen()} className="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-ocean transition-colors duration-150 hover:bg-[#EEF3FF]"><CheckCheck size={15}/> Mark all as read</button>}
          </div>
          <div className="mt-3 inline-flex rounded-lg bg-[#F5F4F0] p-0.5" role="tablist">
            {(['all', 'unread'] as const).map(key => <button key={key} type="button" role="tab" aria-selected={filter === key} onClick={() => setFilter(key)}
              className={`rounded-md px-3 py-1 text-xs font-semibold transition-colors duration-150 ${filter === key ? 'bg-white text-[var(--ink)] shadow-sm' : 'text-[var(--muted)] hover:text-[var(--ink)]'}`}>
              {key === 'all' ? 'All' : 'Unread'}{key === 'unread' && unread > 0 && <span className="num ml-1.5 rounded-full bg-sea px-1.5 text-[10px] text-white">{unread}</span>}
            </button>)}
          </div>
        </div>
        <div className="thin-scroll max-h-[min(66vh,500px)] overflow-y-auto border-t border-[var(--line)]">
          {failed ? <p className="p-8 text-center text-sm font-semibold text-[var(--danger)]">Could not load notifications. Close and try again.</p>
            : !items ? <p className="flex items-center justify-center gap-2 p-10 text-sm font-medium text-[var(--muted)]"><LoaderCircle size={16} className="animate-spin"/> Loading…</p>
            : !shown.length ? <div className="px-8 py-12 text-center">
                <span className="mx-auto grid size-12 place-items-center rounded-full bg-[#F5F4F0] text-[var(--muted)]">{filter === 'unread' ? <CheckCheck size={20}/> : <Bell size={20}/>}</span>
                <p className="mt-3 text-sm font-bold text-[var(--ink)]">{filter === 'unread' ? 'No unread notifications' : 'No notifications yet'}</p>
                <p className="mt-1 text-xs font-medium text-[var(--muted)]">New requests, receipts and payments will show up here.</p>
              </div>
            : groups.map(group => <div key={group.label}>
                <p className="sticky top-0 z-10 bg-white/95 px-5 pb-1.5 pt-3 text-[11px] font-semibold uppercase tracking-[.08em] text-[var(--muted)] backdrop-blur">{group.label}</p>
                {group.items.map(item => { const Icon = ICONS[item.event] ?? Bell; return <DropdownMenu.Item key={item.id} onSelect={() => open(item)}
                  className={`relative flex cursor-pointer gap-3.5 px-5 py-3 outline-none transition-colors duration-150 data-[highlighted]:bg-[#F3F6FD] ${item.unread ? 'bg-[#F5F7FF]' : ''}`}>
                  {item.unread && <span className="absolute inset-y-2 left-0 w-[3px] rounded-r-full bg-sea" aria-hidden/>}
                  <span className={`grid size-9 shrink-0 place-items-center rounded-full ${TONES[item.event] ?? 'bg-[#EEF3FF] text-ocean'}`}><Icon size={16}/></span>
                  <span className="min-w-0 flex-1">
                    <span className="flex items-baseline justify-between gap-3">
                      <span className={`truncate text-[13.5px] text-[var(--ink)] ${item.unread ? 'font-bold' : 'font-semibold'}`}>{item.title}</span>
                      <span className="num shrink-0 text-[11px] font-medium text-[var(--muted)]">{when(item.created_at)}</span>
                    </span>
                    {item.detail && <span className="mt-0.5 line-clamp-2 block text-[12.5px] leading-5 text-[#434957]">{withAmounts(item.detail)}</span>}
                    <span className="mt-1 flex items-center gap-1.5 text-[11px] font-medium text-[var(--muted)]">
                      {item.actor ? <>by {item.actor}</> : item.needs_action ? <span className="rounded bg-amber-50 px-1.5 py-px font-semibold text-amber-700">From guest</span> : null}
                      {item.unread && <span className="ml-auto size-2 rounded-full bg-sea" aria-label="unread"/>}
                    </span>
                  </span>
                </DropdownMenu.Item>; })}
              </div>)}
        </div>
        <DropdownMenu.Item onSelect={() => router.visit('/admin/bookings')} className="flex cursor-pointer items-center justify-center gap-1.5 border-t border-[var(--line)] px-5 py-3 text-[13px] font-semibold text-ocean outline-none transition-colors duration-150 data-[highlighted]:bg-[#F3F6FD]">View all bookings <ArrowRight size={15}/></DropdownMenu.Item>
      </DropdownMenu.Content>
    </DropdownMenu.Portal>
  </DropdownMenu.Root>;
}
