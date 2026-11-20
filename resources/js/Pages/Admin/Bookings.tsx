import { Head, router, usePage } from '@inertiajs/react';
import {
  ArrowDown,
  ArrowUp,
  ArrowUpDown,
  BedDouble,
  BookOpen,
  ChevronRight,
  LogIn,
  Phone,
  Plus,
  ReceiptText,
  Search,
  ShieldCheck,
  Wallet,
  X,
} from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import BookingDrawer from '../../Components/BookingDrawer';
import DashboardShell from '../../Components/DashboardShell';
import NewBookingDialog from '../../Components/NewBookingDialog';
import {
  Avatar,
  Empty,
  exactMoney,
  fmtDay,
  nightsBetween,
  parseDay,
  rowAction,
  SOURCE_GROUPS,
  SourceBadge,
  SOURCES,
  Status,
} from '../../Components/ops';
import Pagination from '../../Components/Pagination';
import { money } from '../../types';

type Row = {
  id: number;
  reference: string;
  guest_name: string;
  guest_phone: string | null;
  check_in: string;
  check_out: string;
  guests: number;
  status: string;
  payment_status: string;
  total: string;
  paid_amount: string | null;
  open_charges: string | null;
  source: string | null;
  group_code: string | null;
  room: { name: string } | null;
  property: { name: string } | null;
  payments: { id: number }[];
};
type Filters = {
  tab?: string;
  source?: string;
  channel?: 'direct' | 'ota';
  when?: 'upcoming' | 'inhouse' | 'past';
  search?: string;
  sort?: string;
  dir?: 'asc' | 'desc';
  status?: string;
  from?: string;
  to?: string;
  open?: number;
};
type Summary = {
  arriving_today: number;
  arriving_week: number;
  in_house: number;
  to_collect: number;
  to_collect_count: number;
  to_verify: number;
};
type Props = {
  bookings: {
    data: Row[];
    links: { url: string | null; label: string; active: boolean }[];
    total: number;
    from: number | null;
    to: number | null;
  };
  filters: Filters;
  active: number;
  today: string;
  counts: Record<string, number>;
  summary: Summary;
};

const tabs = [
  ['all', 'All'],
  ['requests', 'Requests to call'],
  ['verify', 'To verify'],
  ['unpaid', 'Unpaid'],
  ['paid', 'Paid'],
  ['cancelled', 'Cancelled'],
] as const;

const whens = [
  [undefined, 'Any time'],
  ['upcoming', 'Upcoming'],
  ['inhouse', 'In house'],
  ['past', 'Past'],
] as const;

const CANCELLED = ['CANCELLED', 'EXPIRED', 'NO_SHOW'];

const balanceOf = (b: Row) => Math.max(0, Number(b.total) - Number(b.paid_amount || 0)) + Number(b.open_charges || 0);

/** "Today", "in 6 days", "In house", "3 days ago": where this stay sits relative to today. */
function timing(b: Row, today: string): { text: string; tone: string } | null {
  if (CANCELLED.includes(b.status)) return null;
  const days = Math.round((parseDay(b.check_in).getTime() - parseDay(today).getTime()) / 86_400_000);
  if (b.status === 'CHECKED_IN' || (b.check_in <= today && b.check_out > today))
    return b.check_out === today
      ? { text: 'Leaves today', tone: 'bg-amber-50 text-amber-700' }
      : { text: 'In house', tone: 'bg-blue-50 text-blue-700' };
  if (days === 0) return { text: 'Arrives today', tone: 'bg-emerald-600 text-white' };
  if (days === 1) return { text: 'Tomorrow', tone: 'bg-emerald-50 text-emerald-700' };
  if (days > 1 && days <= 14) return { text: `In ${days} days`, tone: 'bg-emerald-50 text-emerald-700' };
  if (days > 14)
    return { text: fmtDay(b.check_in, { month: 'short', year: 'numeric' }), tone: 'bg-slate-100 text-slate-600' };
  return { text: 'Stayed', tone: 'bg-slate-100 text-slate-500' };
}

/** A column header that sorts the list; aria-sort tells screen readers which way it is sorted. */
function SortHead({
  k,
  label,
  right = false,
  sort,
  dir,
  onSort,
}: {
  k: string;
  label: string;
  right?: boolean;
  sort?: string;
  dir?: 'asc' | 'desc';
  onSort: (key: string) => void;
}) {
  const on = sort === k;
  const Icon = !on ? ArrowUpDown : dir === 'desc' ? ArrowDown : ArrowUp;
  return (
    <th
      scope="col"
      aria-sort={on ? (dir === 'desc' ? 'descending' : 'ascending') : 'none'}
      className={right ? 'text-right' : ''}
    >
      <button
        type="button"
        onClick={() => onSort(k)}
        className={`inline-flex items-center gap-1 hover:text-ink ${on ? 'text-ink' : ''}`}
      >
        {label}
        <Icon size={11} aria-hidden="true" className={on ? '' : 'opacity-50'} />
      </button>
    </th>
  );
}

export default function Bookings({ bookings, filters, today, counts, summary }: Props) {
  const [search, setSearch] = useState(filters.search ?? '');
  const [drawer, setDrawer] = useState<number | null>(filters.open ? Number(filters.open) : null);
  const tab = filters.tab ?? 'all';
  const { requestCount = 0 } = usePage<{ requestCount?: number }>().props;
  const visit = (next: Partial<Filters>) =>
    router.get(
      '/admin/bookings',
      { ...filters, open: undefined, ...next },
      { preserveState: true, preserveScroll: true, replace: true, only: ['bookings', 'filters', 'counts', 'summary'] },
    );

  useEffect(() => {
    if (filters.open) setDrawer(Number(filters.open));
  }, [filters.open]);

  // Search as you type, a moment after the last key.
  const first = useRef(true);
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    const t = setTimeout(() => {
      if ((filters.search ?? '') !== search.trim()) visit({ search: search.trim() || undefined });
    }, 350);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  const sortBy = (key: string) =>
    visit({ sort: key, dir: filters.sort === key && filters.dir !== 'desc' ? 'desc' : 'asc' });
  const sortProps = { sort: filters.sort, dir: filters.dir, onSort: sortBy };

  // Channel and source share one menu: "@direct" / "@ota" are the groups, anything else one source.
  const channelValue = filters.source ?? (filters.channel ? `@${filters.channel}` : '');
  const pickChannel = (v: string) =>
    visit(
      v.startsWith('@')
        ? { channel: v.slice(1) as Filters['channel'], source: undefined }
        : { source: v || undefined, channel: undefined },
    );

  const chips: [string, () => void][] = [
    ...(filters.when ? [[whens.find(w => w[0] === filters.when)?.[1] ?? '', () => visit({ when: undefined })]] : []),
    ...(filters.channel
      ? [[filters.channel === 'direct' ? 'Direct bookings' : 'Booking sites', () => visit({ channel: undefined })]]
      : []),
    ...(filters.source ? [[SOURCES[filters.source] ?? filters.source, () => visit({ source: undefined })]] : []),
    ...(filters.status
      ? [[filters.status.replaceAll('_', ' ').toLowerCase(), () => visit({ status: undefined })]]
      : []),
    ...(filters.from || filters.to
      ? [
          [
            `Booked ${filters.from ? fmtDay(filters.from) : '…'} – ${filters.to ? fmtDay(filters.to) : '…'}`,
            () => visit({ from: undefined, to: undefined }),
          ],
        ]
      : []),
    ...(filters.search
      ? [
          [
            `“${filters.search}”`,
            () => {
              setSearch('');
              visit({ search: undefined });
            },
          ],
        ]
      : []),
  ] as [string, () => void][];

  return (
    <DashboardShell
      title="Bookings"
      subtitle={<span>Every reservation, from first message to checkout.</span>}
      actions={
        <NewBookingDialog
          trigger={
            <button type="button" className="btn btn-pink">
              <Plus size={16} /> New booking
            </button>
          }
        />
      }
    >
      <Head title="Bookings" />

      {/* What needs attention, at a glance. Each card filters the list below. */}
      <div className="mb-4 grid grid-cols-2 gap-2.5 lg:grid-cols-4">
        <Tile
          icon={<LogIn size={16} />}
          tone="bg-emerald-50 text-emerald-700"
          label="Arriving"
          value={summary.arriving_week}
          detail={summary.arriving_today ? `${summary.arriving_today} today · next 7 days` : 'Next 7 days · none today'}
          pressed={filters.when === 'upcoming' && tab === 'all'}
          onClick={() => visit({ when: 'upcoming', tab: undefined, sort: undefined, dir: undefined })}
        />
        <Tile
          icon={<BedDouble size={16} />}
          tone="bg-blue-50 text-blue-700"
          label="In house now"
          value={summary.in_house}
          detail={summary.in_house ? 'Currently staying' : 'Nobody staying'}
          pressed={filters.when === 'inhouse'}
          onClick={() => visit({ when: 'inhouse', tab: undefined, sort: undefined, dir: undefined })}
        />
        <Tile
          icon={<Wallet size={16} />}
          tone="bg-rose-50 text-rose-700"
          label="To collect"
          value={money(summary.to_collect)}
          detail={
            summary.to_collect_count
              ? `${summary.to_collect_count} ${summary.to_collect_count === 1 ? 'stay' : 'stays'} with a balance`
              : 'All current stays paid'
          }
          pressed={tab === 'unpaid'}
          onClick={() => visit({ tab: 'unpaid', when: undefined, sort: 'balance', dir: 'desc' })}
        />
        <Tile
          icon={<ShieldCheck size={16} />}
          tone={summary.to_verify ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600'}
          label="Payments to verify"
          value={summary.to_verify}
          detail={summary.to_verify ? 'Guests sent proof of payment' : 'Nothing waiting'}
          pressed={tab === 'verify'}
          onClick={() => visit({ tab: 'verify', when: undefined })}
        />
      </div>

      <div className="card overflow-hidden">
        {/* Tabs */}
        <div
          role="tablist"
          aria-label="Bookings"
          className="thin-scroll flex gap-1 overflow-x-auto border-b border-[var(--line)] px-3"
        >
          {tabs.map(([key, label]) => {
            const on = tab === key && !filters.status;
            const n = counts[key] ?? (key === 'requests' ? requestCount : 0);
            const alert = (key === 'requests' || key === 'verify') && n > 0;
            return (
              <button
                key={key}
                type="button"
                role="tab"
                aria-selected={on}
                onClick={() => visit({ tab: key === 'all' ? undefined : key, status: undefined })}
                className={`relative flex shrink-0 items-center gap-1.5 px-3 py-3 text-[13px] font-bold transition ${on ? 'text-forest' : 'text-[var(--muted)] hover:text-ink'}`}
              >
                {label}
                <span
                  className={`num rounded-full px-1.5 py-px text-[10.5px] ${alert ? 'bg-amber-400 text-leaf-ink' : on ? 'bg-forest text-white' : 'bg-mist text-ink-soft'}`}
                >
                  {n}
                </span>
                {on && <span className="absolute inset-x-2 bottom-0 h-[3px] rounded-t-full bg-forest" />}
              </button>
            );
          })}
        </div>

        {/* Filters */}
        <div className="flex flex-wrap items-center gap-2 border-b border-[var(--line)] bg-paper px-3 py-2.5">
          <label className="flex min-w-[220px] flex-1 items-center gap-2 rounded-xl border border-[var(--line)] bg-white px-3 focus-within:border-blue-400 sm:max-w-sm">
            <Search size={15} className="shrink-0 text-[var(--muted)]" />
            <input
              value={search}
              onChange={e => setSearch(e.target.value)}
              onKeyDown={e => e.key === 'Escape' && setSearch('')}
              placeholder="Guest, phone, unit or reference"
              aria-label="Search bookings"
              className="w-full bg-transparent py-2 text-sm font-semibold outline-none"
            />
            {search && (
              <button
                type="button"
                aria-label="Clear search"
                onClick={() => setSearch('')}
                className="text-[var(--muted)] hover:text-ink"
              >
                <X size={14} />
              </button>
            )}
          </label>
          <div className="seg" role="group" aria-label="Stay dates">
            {whens.map(([w, label]) => (
              <button
                key={label}
                type="button"
                aria-pressed={filters.when === w}
                onClick={() => visit({ when: w, sort: undefined, dir: undefined })}
              >
                {label}
              </button>
            ))}
          </div>
          <select
            aria-label="Channel"
            value={channelValue}
            onChange={e => pickChannel(e.target.value)}
            className={`rounded-full border px-3 py-2 text-xs font-bold ${channelValue ? 'border-forest bg-forest text-white' : 'border-[var(--line)] bg-white text-ink-soft'}`}
          >
            <option value="">All channels</option>
            <option value="@direct">Direct bookings (all)</option>
            <option value="@ota">Booking sites (all)</option>
            {SOURCE_GROUPS.map(([group, keys]) => (
              <optgroup key={group} label={group}>
                {keys.map(k => (
                  <option key={k} value={k}>
                    {SOURCES[k]}
                  </option>
                ))}
              </optgroup>
            ))}
          </select>
          <span className="num ml-auto text-xs font-bold text-[var(--muted)]">
            {bookings.total === 0 ? 'No bookings' : `${bookings.from}–${bookings.to} of ${bookings.total}`}
          </span>
        </div>

        {chips.length > 0 && (
          <div className="flex flex-wrap items-center gap-1.5 border-b border-[var(--line)] px-3 py-2">
            <span className="text-[11px] font-bold tracking-wide text-[var(--muted)] uppercase">Filtered by</span>
            {chips.map(([label, remove]) => (
              <button
                key={label}
                type="button"
                onClick={remove}
                className="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-blue-50 px-2.5 py-1 text-[11.5px] font-bold text-forest capitalize hover:border-blue-400"
              >
                {label}
                <X size={12} aria-label="Remove" />
              </button>
            ))}
            <button
              type="button"
              onClick={() => {
                setSearch('');
                router.get('/admin/bookings', tab === 'all' ? {} : { tab });
              }}
              className="ml-1 text-xs font-bold text-ocean hover:underline"
            >
              Clear all
            </button>
          </div>
        )}

        {bookings.data.length === 0 ? (
          <Empty
            icon={<BookOpen size={18} />}
            title={chips.length || tab !== 'all' ? 'No bookings match' : 'No bookings yet'}
            body={
              chips.length || tab !== 'all'
                ? 'Try another tab, widen the dates, or clear the filters.'
                : 'Add one for a walk-in or a guest who messaged you.'
            }
          />
        ) : (
          <>
            {/* Desktop: a sortable table */}
            <div className="hidden overflow-x-auto md:block">
              <table className="table w-full min-w-[940px]">
                <thead>
                  <tr>
                    <SortHead k="guest" label="Guest" {...sortProps} />
                    <SortHead k="stay" label="Stay" {...sortProps} />
                    <SortHead k="unit" label="Unit" {...sortProps} />
                    <th scope="col">Status</th>
                    <SortHead k="balance" label="Payment" right {...sortProps} />
                    <th scope="col" aria-label="Open" className="w-8" />
                  </tr>
                </thead>
                <tbody>
                  {bookings.data.map(b => (
                    <DesktopRow key={b.id} b={b} today={today} onOpen={() => setDrawer(b.id)} />
                  ))}
                </tbody>
              </table>
            </div>
            {/* Phone: cards */}
            <ul className="divide-y divide-[var(--line)] md:hidden">
              {bookings.data.map(b => (
                <MobileRow key={b.id} b={b} today={today} onOpen={() => setDrawer(b.id)} />
              ))}
            </ul>
          </>
        )}
      </div>
      <Pagination links={bookings.links} />
      <BookingDrawer id={drawer} onClose={() => setDrawer(null)} />
    </DashboardShell>
  );
}

function Tile({
  icon,
  tone,
  label,
  value,
  detail,
  pressed,
  onClick,
}: {
  icon: ReactNode;
  tone: string;
  label: string;
  value: ReactNode;
  detail: string;
  pressed: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      aria-pressed={pressed}
      onClick={onClick}
      className={`card block min-w-0 px-4 py-3 text-left transition hover:border-blue-400 ${pressed ? 'border-forest ring-2 ring-forest/15' : ''}`}
    >
      <span className="flex items-center justify-between gap-2">
        <span className="text-[11px] font-bold tracking-wide text-[var(--muted)] uppercase">{label}</span>
        <span className={`grid size-7 shrink-0 place-items-center rounded-lg ${tone}`}>{icon}</span>
      </span>
      <span className="num mt-1 block text-[26px] leading-none font-semibold text-forest">{value}</span>
      <span className="mt-1.5 block truncate text-[11.5px] font-semibold text-[var(--muted)]" title={detail}>
        {detail}
      </span>
    </button>
  );
}

/** Paid so far against the total, as a bar, with what is still owed. */
function PaymentCell({ b, align = 'right' }: { b: Row; align?: 'right' | 'left' }) {
  const total = Number(b.total) + Number(b.open_charges || 0);
  const paid = Number(b.paid_amount || 0);
  const due = balanceOf(b);
  const cancelled = CANCELLED.includes(b.status);
  const pct = total > 0 ? Math.min(100, (paid / total) * 100) : 100;
  if (cancelled)
    return (
      <span
        className={`block text-[12.5px] font-semibold text-[var(--muted)] ${align === 'right' ? 'text-right' : ''}`}
      >
        {paid > 0 ? `${money(paid)} received` : '—'}
      </span>
    );
  return (
    <span className={`flex flex-col gap-1 ${align === 'right' ? 'items-end' : 'items-start'}`}>
      <span className={`num text-[14px] font-bold ${due > 0 ? 'text-[var(--danger)]' : 'text-emerald-700'}`}>
        {due > 0 ? `${exactMoney(due)} due` : 'Paid in full'}
      </span>
      <span className="h-1.5 w-28 overflow-hidden rounded-full bg-mist" aria-hidden="true">
        <span
          className={`block h-full rounded-full ${due > 0 ? 'bg-amber-500' : 'bg-emerald-500'}`}
          style={{ width: `${pct}%` }}
        />
      </span>
      <span className="num text-[11px] font-semibold text-[var(--muted)]">
        {money(paid)} of {money(total)}
      </span>
    </span>
  );
}

function StatusCell({ b }: { b: Row }) {
  return (
    <span className="flex flex-wrap items-center gap-1">
      <Status value={b.status} />
      {b.payments.length > 0 && (
        <span className="pill border-amber-300 bg-amber-100 text-amber-900">
          <ReceiptText size={11} /> verify payment
        </span>
      )}
    </span>
  );
}

function DesktopRow({ b, today, onOpen }: { b: Row; today: string; onOpen: () => void }) {
  const t = timing(b, today);
  const nights = nightsBetween(b.check_in, b.check_out);
  const cancelled = CANCELLED.includes(b.status);
  return (
    <tr className={`clickable group ${cancelled ? 'opacity-60' : ''}`} {...rowAction(onOpen)}>
      <td>
        <span className="flex items-center gap-3">
          <Avatar name={b.guest_name} size={34} />
          <span className="min-w-0">
            <span className="block truncate font-bold">{b.guest_name}</span>
            <span className="flex flex-wrap items-center gap-x-2 text-[11.5px] font-semibold text-[var(--muted)]">
              {b.guest_phone && (
                <a
                  href={`tel:${b.guest_phone.replace(/\s+/g, '')}`}
                  onClick={e => e.stopPropagation()}
                  className="inline-flex items-center gap-1 text-ocean hover:underline"
                >
                  <Phone size={10} />
                  {b.guest_phone}
                </a>
              )}
              <span className="num">{b.reference}</span>
              {b.group_code && <span className="num">· {b.group_code}</span>}
            </span>
          </span>
        </span>
      </td>
      <td>
        <span className="flex items-center gap-2 font-bold text-ink">
          {fmtDay(b.check_in, { weekday: 'short', month: 'short', day: 'numeric' })}
          <span className="text-[var(--muted)]">→</span>
          {fmtDay(b.check_out, { month: 'short', day: 'numeric' })}
        </span>
        <span className="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11.5px] font-semibold text-[var(--muted)]">
          {t && <span className={`rounded-full px-2 py-px text-[10.5px] font-bold ${t.tone}`}>{t.text}</span>}
          {nights} {nights === 1 ? 'night' : 'nights'} · {b.guests} {b.guests === 1 ? 'guest' : 'guests'}
        </span>
      </td>
      <td>
        <span className="block font-semibold">{b.room?.name ?? '—'}</span>
        <span className="mt-0.5 flex items-center gap-1.5">
          <SourceBadge source={b.source} />
        </span>
      </td>
      <td>
        <StatusCell b={b} />
      </td>
      <td className="text-right">
        <PaymentCell b={b} />
      </td>
      <td className="pr-3">
        <ChevronRight
          size={16}
          className="text-[var(--muted)] transition group-hover:translate-x-0.5 group-hover:text-forest"
        />
      </td>
    </tr>
  );
}

function MobileRow({ b, today, onOpen }: { b: Row; today: string; onOpen: () => void }) {
  const t = timing(b, today);
  const nights = nightsBetween(b.check_in, b.check_out);
  return (
    <li
      role="button"
      tabIndex={0}
      onClick={onOpen}
      onKeyDown={e => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), onOpen())}
      className={`grid gap-2 px-4 py-3.5 active:bg-[rgba(0,57,213,.05)] ${CANCELLED.includes(b.status) ? 'opacity-60' : ''}`}
    >
      <span className="flex items-start gap-3">
        <Avatar name={b.guest_name} size={36} />
        <span className="min-w-0 flex-1">
          <span className="flex items-center justify-between gap-2">
            <span className="truncate font-bold">{b.guest_name}</span>
            {t && (
              <span className={`shrink-0 rounded-full px-2 py-px text-[10.5px] font-bold ${t.tone}`}>{t.text}</span>
            )}
          </span>
          <span className="block text-[12.5px] font-semibold text-ink-soft">
            {fmtDay(b.check_in, { weekday: 'short', month: 'short', day: 'numeric' })} → {fmtDay(b.check_out)} ·{' '}
            {nights} {nights === 1 ? 'night' : 'nights'}
          </span>
          <span className="block truncate text-[11.5px] font-semibold text-[var(--muted)]">
            {b.room?.name} · {b.guests} {b.guests === 1 ? 'guest' : 'guests'} · {b.reference}
          </span>
        </span>
      </span>
      <span className="flex items-end justify-between gap-3 pl-12">
        <StatusCell b={b} />
        <PaymentCell b={b} />
      </span>
    </li>
  );
}
