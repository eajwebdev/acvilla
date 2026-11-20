import { Lock } from 'lucide-react';
import { fmtDay, fmtTime, parseDay } from '../ops';
import { daysBetween, type Block, type Row, type Unit } from './shared';

const barTone = (status: string) =>
  status === 'CHECKED_IN'
    ? 'bg-teal-600 text-white'
    : ['CHECKED_OUT', 'COMPLETED'].includes(status)
      ? 'bg-slate-300 text-slate-700'
      : ['PENDING', 'AWAITING_PAYMENT'].includes(status)
        ? 'border border-dashed border-amber-500 bg-amber-50 text-amber-900'
        : 'bg-forest text-white';

/**
 * Every unit down the side, the dates across: who is in which unit, and where the gaps are. A stay runs from the
 * middle of its check-in day to the middle of its check-out day, so a same-day turnover sits side by side.
 * Click a stay to open it, or an empty day to book that unit.
 */
export default function UnitBoard({
  from,
  to,
  today,
  rooms,
  bookings,
  blocks,
  onOpen,
  onBook,
}: {
  from: string;
  to: string;
  today: string;
  rooms: Unit[];
  bookings: Row[];
  blocks: Block[];
  onOpen: (id: number) => void;
  onBook: (date: string, roomId: number) => void;
}) {
  const days = daysBetween(from, to);
  const col = days.length <= 7 ? 132 : days.length <= 14 ? 84 : 52;
  const width = days.length * col;
  const index = (d: string) => days.indexOf(d);
  // Fraction of the board from a date: before the window = 0, after it = the full width.
  const x = (d: string, half = true) => (d < from ? 0 : d > to ? width : index(d) * col + (half ? col / 2 : 0));

  return (
    <div className="card overflow-hidden">
      <div className="thin-scroll overflow-x-auto">
        <div style={{ minWidth: width + 170 }}>
          {/* Dates across the top */}
          <div className="sticky top-0 z-10 flex border-b border-[var(--line)] bg-paper">
            <div className="sticky left-0 z-20 w-[170px] shrink-0 border-r border-[var(--line)] bg-paper px-4 py-2 text-[11px] font-bold tracking-wide text-[var(--muted)] uppercase">
              Unit
            </div>
            {days.map(d => {
              const weekend = [0, 6].includes(parseDay(d).getDay());
              return (
                <div
                  key={d}
                  style={{ width: col }}
                  className={`shrink-0 border-r border-[var(--line)] px-1 py-1.5 text-center last:border-r-0 ${d === today ? 'bg-forest text-white' : weekend ? 'bg-mist' : ''}`}
                >
                  <span className="block text-[10px] font-bold uppercase opacity-80">
                    {parseDay(d).toLocaleDateString('en-PH', { weekday: 'short' })}
                  </span>
                  <span className="num block text-[13px] font-bold">{col >= 84 ? fmtDay(d) : Number(d.slice(8))}</span>
                </div>
              );
            })}
          </div>
          {rooms.length === 0 && (
            <p className="px-4 py-10 text-center text-sm font-semibold text-[var(--muted)]">No units yet.</p>
          )}
          {rooms.map(room => {
            const stays = bookings
              .filter(b => b.room_id === room.id && b.check_out > from && b.check_in <= to)
              .sort((a, b) => a.check_in.localeCompare(b.check_in));
            // Units with more than one of the same kind: overlapping stays go on separate lanes.
            const lanes: string[] = [];
            const laneOf = new Map<number, number>();
            for (const s of stays) {
              let lane = lanes.findIndex(end => end <= s.check_in);
              if (lane === -1) lane = lanes.length;
              lanes[lane] = s.check_out;
              laneOf.set(s.id, lane);
            }
            const height = Math.max(1, lanes.length) * 34 + 14;
            const roomBlocks = blocks.filter(b => b.room_id === room.id && b.ends_on > from && b.starts_on <= to);
            return (
              <div key={room.id} className="flex border-b border-[var(--line)] last:border-b-0">
                <div className="sticky left-0 z-[5] w-[170px] shrink-0 border-r border-[var(--line)] bg-white px-4 py-2.5">
                  <p className="truncate text-[13px] font-bold" title={room.name}>
                    {room.name}
                  </p>
                  <p className="text-[11px] font-semibold text-[var(--muted)]">
                    {stays.filter(s => s.check_in <= today && s.check_out > today).length ? 'Occupied now' : 'Free now'}
                    {room.quantity > 1 ? ` · ${room.quantity} units` : ''}
                  </p>
                </div>
                <div className="relative shrink-0" style={{ width, height }}>
                  {/* Day cells: click to book */}
                  <div className="absolute inset-0 flex">
                    {days.map(d => (
                      <button
                        key={d}
                        type="button"
                        aria-label={`Book ${room.name} on ${fmtDay(d)}`}
                        disabled={d < today}
                        onClick={() => onBook(d, room.id)}
                        style={{ width: col }}
                        className={`h-full shrink-0 border-r border-[var(--line)] last:border-r-0 enabled:hover:bg-[rgba(0,57,213,.05)] ${d === today ? 'bg-[rgba(0,57,213,.05)]' : [0, 6].includes(parseDay(d).getDay()) ? 'bg-mist/60' : ''}`}
                      />
                    ))}
                  </div>
                  {roomBlocks.map(bl => (
                    <div
                      key={`b${bl.id}`}
                      title={`${bl.reason ?? 'Blocked'} · ${fmtDay(bl.starts_on)} to ${fmtDay(bl.ends_on)}`}
                      className="pointer-events-none absolute top-[7px] bottom-[7px] flex items-center gap-1 overflow-hidden rounded-md px-2 text-[11px] font-bold text-slate-600"
                      style={{
                        left: x(bl.starts_on, false) + 2,
                        width: Math.max(8, x(bl.ends_on, false) - x(bl.starts_on, false) - 4),
                        background: 'repeating-linear-gradient(45deg,#eef0f4 0 6px,#e1e5ec 6px 12px)',
                      }}
                    >
                      <Lock size={11} className="shrink-0" />
                      <span className="truncate">{bl.reason ?? 'Blocked'}</span>
                    </div>
                  ))}
                  {stays.map(s => {
                    const left = x(s.check_in);
                    const right = x(s.check_out);
                    const lane = laneOf.get(s.id) ?? 0;
                    return (
                      <button
                        key={s.id}
                        type="button"
                        onClick={() => onOpen(s.id)}
                        title={`${s.guest_name} · ${s.unit}\n${fmtDay(s.check_in)} ${fmtTime(s.check_in_time)} → ${fmtDay(s.check_out)} ${fmtTime(s.check_out_time)}\n${s.guests} guests · ${s.status.replaceAll('_', ' ').toLowerCase()}`}
                        className={`absolute flex h-[28px] items-center gap-1.5 overflow-hidden px-2 text-left text-[11.5px] font-bold shadow-sm transition hover:ring-2 hover:ring-blue-300 hover:brightness-110 ${barTone(s.status)} ${s.check_in < from ? 'rounded-r-md' : s.check_out > to ? 'rounded-l-md' : 'rounded-md'}`}
                        style={{ left: left + 1, width: Math.max(18, right - left - 2), top: 7 + lane * 34 }}
                      >
                        <span className="truncate">{s.guest_name}</span>
                        {right - left > 150 && (
                          <span className="shrink-0 font-semibold opacity-75">· {s.guests} pax</span>
                        )}
                      </button>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-4 border-t border-[var(--line)] bg-paper px-4 py-2 text-[11px] font-semibold text-[var(--muted)]">
        {[
          ['bg-forest', 'Confirmed'],
          ['bg-teal-600', 'Checked in'],
          ['border border-dashed border-amber-500 bg-amber-50', 'Waiting for payment'],
          ['bg-slate-300', 'Checked out'],
        ].map(([c, l]) => (
          <span key={l} className="flex items-center gap-1.5">
            <span className={`h-2.5 w-5 rounded-sm ${c}`} />
            {l}
          </span>
        ))}
        <span className="flex items-center gap-1.5">
          <Lock size={11} /> Blocked
        </span>
        <span className="ml-auto">Click an empty day to book that unit.</span>
      </div>
    </div>
  );
}
