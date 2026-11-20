import { BedDouble, CalendarCheck2, ChevronRight, LogIn, LogOut, Phone, Plus } from 'lucide-react';
import { exactMoney, fmtTime, Status } from '../ops';
import {
  ARRIVED,
  daysBetween,
  inHouseOn,
  LEFT,
  longDay,
  nowHHMM,
  relativeDay,
  vacantOn,
  type Block,
  type Kind,
  type Row,
} from './shared';

type Event = { kind: 'in' | 'out' | 'stay'; time: string; booking: Row };

/**
 * The front desk's agenda: day by day, every check-in and check-out in time order, with who is staying over.
 * Runs of quiet days fold into one line so a month stays short.
 */
export default function Schedule({
  from,
  to,
  today,
  bookings,
  blocks,
  inventory,
  kind,
  search,
  onOpen,
  onBook,
}: {
  from: string;
  to: string;
  today: string;
  bookings: Row[];
  blocks: Block[];
  inventory: number;
  kind: Kind;
  search: string;
  onOpen: (id: number) => void;
  onBook: (date: string) => void;
}) {
  const q = search.trim().toLowerCase();
  const match = (b: Row) =>
    !q || [b.guest_name, b.reference, b.unit, b.guest_phone ?? ''].some(v => v.toLowerCase().includes(q));
  const now = nowHHMM();

  const days = daysBetween(from, to).map(d => {
    const ins: Event[] = bookings
      .filter(b => b.check_in === d && match(b))
      .map(b => ({ kind: 'in', time: b.check_in_time, booking: b }));
    const outs: Event[] = bookings
      .filter(b => b.check_out === d && match(b))
      .map(b => ({ kind: 'out', time: b.check_out_time, booking: b }));
    const staying: Event[] = bookings
      .filter(b => inHouseOn(b, d) && b.check_in !== d && match(b))
      .map(b => ({ kind: 'stay', time: '', booking: b }));
    const events = [...(kind === 'all' || kind === 'out' ? outs : []), ...(kind === 'all' || kind === 'in' ? ins : [])]
      .concat(kind === 'stay' ? staying : [])
      .sort((a, b) => a.time.localeCompare(b.time));
    return { d, ins, outs, staying, events, vacant: vacantOn(bookings, blocks, inventory, d) };
  });

  // Fold consecutive days with nothing on them into a single "quiet" row.
  const groups: ({ type: 'day'; day: (typeof days)[number] } | { type: 'quiet'; from: string; to: string })[] = [];
  for (const day of days) {
    if (day.events.length || day.d === today) {
      groups.push({ type: 'day', day });
      continue;
    }
    const last = groups[groups.length - 1];
    if (last?.type === 'quiet') last.to = day.d;
    else groups.push({ type: 'quiet', from: day.d, to: day.d });
  }
  const total = days.reduce((n, day) => n + day.events.length, 0);

  if (total === 0 && !days.some(day => day.d === today))
    return (
      <div className="card grid place-items-center px-6 py-14 text-center">
        <span className="grid size-11 place-items-center rounded-xl border border-[var(--line)] bg-[var(--mint)] text-ocean">
          <CalendarCheck2 size={18} />
        </span>
        <p className="mt-4 text-sm font-bold">
          {q ? `Nothing matches “${search}”` : 'Nothing scheduled in these dates'}
        </p>
        <p className="mt-1 text-xs text-[var(--muted)]">Pick a longer range, or another unit.</p>
      </div>
    );

  return (
    <div className="grid gap-3">
      {groups.map(g =>
        g.type === 'quiet' ? (
          <div
            key={`q${g.from}`}
            className="flex items-center gap-3 rounded-xl border border-dashed border-[var(--line)] px-4 py-2.5 text-xs font-semibold text-[var(--muted)]"
          >
            <span className="size-1.5 rounded-full bg-line-strong" />
            {g.from === g.to ? longDay(g.from) : `${longDay(g.from)} – ${longDay(g.to)}`}
            <span>· no check-ins or check-outs</span>
          </div>
        ) : (
          <section key={g.day.d} className={`card overflow-hidden ${g.day.d === today ? 'border-forest/40' : ''}`}>
            <header
              className={`flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-[var(--line)] px-4 py-2.5 ${g.day.d === today ? 'bg-[rgba(0,57,213,.06)]' : 'bg-paper'}`}
            >
              <h3 className="text-[15px] font-bold text-forest">
                {relativeDay(g.day.d, today)}
                <span className="ml-2 text-[13px] font-semibold text-[var(--muted)]">{longDay(g.day.d)}</span>
              </h3>
              <span className="flex flex-wrap items-center gap-1.5 text-[11px] font-bold">
                {g.day.ins.length > 0 && (
                  <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-emerald-700">{g.day.ins.length} in</span>
                )}
                {g.day.outs.length > 0 && (
                  <span className="rounded-full bg-amber-50 px-2 py-0.5 text-amber-700">{g.day.outs.length} out</span>
                )}
                {g.day.staying.length > 0 && (
                  <span className="rounded-full bg-blue-50 px-2 py-0.5 text-blue-700">
                    {g.day.staying.length} staying
                  </span>
                )}
              </span>
              <span className="ml-auto flex items-center gap-2">
                <span className="text-[11px] font-bold text-[var(--muted)]">
                  {g.day.vacant} of {inventory} vacant
                </span>
                {g.day.d >= today && g.day.vacant > 0 && (
                  <button type="button" onClick={() => onBook(g.day.d)} className="btn btn-light btn-sm">
                    <Plus size={12} />
                    Book
                  </button>
                )}
              </span>
            </header>
            {g.day.events.length === 0 ? (
              <p className="px-4 py-4 text-sm font-semibold text-[var(--muted)]">
                {kind === 'all' ? 'No check-ins or check-outs today.' : 'Nothing of this kind today.'}
              </p>
            ) : (
              <ul className="divide-y divide-[var(--line)]">
                {g.day.events.map(e => (
                  <EventRow
                    key={`${e.kind}${e.booking.id}`}
                    e={e}
                    isToday={g.day.d === today}
                    now={now}
                    onOpen={onOpen}
                  />
                ))}
              </ul>
            )}
            {kind === 'all' && g.day.staying.length > 0 && (
              <p className="flex flex-wrap items-center gap-x-2 gap-y-1 border-t border-[var(--line)] bg-paper px-4 py-2 text-[11.5px] font-semibold text-[var(--muted)]">
                <BedDouble size={13} className="text-blue-700" />
                Also staying:
                {g.day.staying.map((s, i) => (
                  <button
                    key={s.booking.id}
                    type="button"
                    onClick={() => onOpen(s.booking.id)}
                    className="text-ink-soft hover:text-forest hover:underline"
                  >
                    {s.booking.guest_name} ({s.booking.unit}){i < g.day.staying.length - 1 ? ',' : ''}
                  </button>
                ))}
              </p>
            )}
          </section>
        ),
      )}
    </div>
  );
}

function EventRow({
  e,
  isToday,
  now,
  onOpen,
}: {
  e: Event;
  isToday: boolean;
  now: string;
  onOpen: (id: number) => void;
}) {
  const b = e.booking;
  const done = e.kind === 'in' ? ARRIVED.includes(b.status) : e.kind === 'out' ? LEFT.includes(b.status) : false;
  // Today only: a guest past their check-out time who is still checked in, or an arrival past its time.
  const overdue = isToday && !done && e.kind === 'out' && b.status === 'CHECKED_IN' && now > b.check_out_time;
  const late = isToday && !done && e.kind === 'in' && now > b.check_in_time;
  const badge =
    e.kind === 'in'
      ? { cls: 'bg-emerald-50 text-emerald-700 border-emerald-200', icon: <LogIn size={12} />, text: 'Check-in' }
      : e.kind === 'out'
        ? { cls: 'bg-amber-50 text-amber-700 border-amber-200', icon: <LogOut size={12} />, text: 'Check-out' }
        : { cls: 'bg-blue-50 text-blue-700 border-blue-200', icon: <BedDouble size={12} />, text: 'Staying' };

  return (
    <li
      role="button"
      tabIndex={0}
      onClick={() => onOpen(b.id)}
      onKeyDown={ev => (ev.key === 'Enter' || ev.key === ' ') && (ev.preventDefault(), onOpen(b.id))}
      className={`grid cursor-pointer grid-cols-[64px_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 px-4 py-3 transition hover:bg-[rgba(0,57,213,.04)] focus-visible:bg-[rgba(0,57,213,.06)] focus-visible:outline-none md:grid-cols-[72px_110px_minmax(0,1.3fr)_minmax(0,1fr)_120px_110px_16px] ${done ? 'opacity-60' : ''}`}
    >
      <span className="num text-[15px] leading-tight font-semibold text-forest">
        {e.kind === 'stay' ? '—' : fmtTime(e.time)}
      </span>
      <span
        className={`hidden w-fit items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-bold md:inline-flex ${badge.cls}`}
      >
        {badge.icon}
        {badge.text}
      </span>
      <span className="min-w-0">
        <span className="flex items-center gap-2">
          <span className={`inline-flex md:hidden ${badge.cls} rounded-full border p-1`}>{badge.icon}</span>
          <span className="truncate text-[14.5px] font-bold">{b.guest_name}</span>
        </span>
        <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-[11.5px] font-semibold text-[var(--muted)]">
          {b.guest_phone && (
            <a
              href={`tel:${b.guest_phone.replace(/\s+/g, '')}`}
              onClick={ev => ev.stopPropagation()}
              className="inline-flex items-center gap-1 text-ocean hover:underline"
            >
              <Phone size={11} />
              {b.guest_phone}
            </a>
          )}
          <span>{b.reference}</span>
        </span>
      </span>
      <span className="col-start-2 row-start-2 min-w-0 text-[12.5px] font-semibold md:col-start-auto md:row-start-auto">
        <span className="block truncate">{b.unit}</span>
        <span className="block text-[11.5px] text-[var(--muted)]">
          {b.guests} {b.guests === 1 ? 'guest' : 'guests'} · {b.nights} {b.nights === 1 ? 'night' : 'nights'}
        </span>
      </span>
      <span className="col-start-3 row-start-1 flex flex-col items-end gap-1 md:col-start-auto md:row-start-auto md:items-start">
        {overdue ? (
          <span className="pill border-rose-200 bg-rose-50 text-rose-700">past check-out</span>
        ) : done ? (
          <span className="pill border-emerald-200 bg-emerald-50 text-emerald-800">
            {e.kind === 'in' ? 'arrived' : 'left'}
          </span>
        ) : late ? (
          <span className="pill border-amber-200 bg-amber-50 text-amber-800">expected</span>
        ) : (
          <Status value={b.status} />
        )}
      </span>
      <span
        className={`num col-start-3 row-start-2 text-right text-[13px] font-semibold md:col-start-auto md:row-start-auto ${b.balance > 0 ? 'text-[var(--danger)]' : 'text-[var(--muted)]'}`}
      >
        {b.balance > 0 ? exactMoney(b.balance) : 'Paid'}
        {b.balance > 0 && <span className="block text-[10.5px] font-semibold text-[var(--muted)]">balance</span>}
      </span>
      <ChevronRight size={15} className="hidden text-[var(--muted)] md:block" />
    </li>
  );
}
