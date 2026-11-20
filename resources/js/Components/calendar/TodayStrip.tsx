import { BedDouble, DoorOpen, LogIn, LogOut, Wallet } from 'lucide-react';
import type { ReactNode } from 'react';
import { exactMoney, fmtTime } from '../ops';
import { ARRIVED, inHouseOn, LEFT, vacantOn, type Block, type Kind, type Row } from './shared';

/**
 * Today at a glance: who checks in and out today (and when the next one is), who sleeps here tonight, what's still
 * free and what to collect at the desk. Each card jumps the schedule to that list.
 */
export default function TodayStrip({
  today,
  bookings,
  blocks,
  inventory,
  onPick,
}: {
  today: string;
  bookings: Row[];
  blocks: Block[];
  inventory: number;
  onPick: (kind: Kind) => void;
}) {
  const ins = bookings.filter(b => b.check_in === today).sort((a, b) => a.check_in_time.localeCompare(b.check_in_time));
  const outs = bookings
    .filter(b => b.check_out === today)
    .sort((a, b) => a.check_out_time.localeCompare(b.check_out_time));
  const arrived = ins.filter(b => ARRIVED.includes(b.status)).length;
  const left = outs.filter(b => LEFT.includes(b.status)).length;
  const nextIn = ins.find(b => !ARRIVED.includes(b.status));
  const nextOut = outs.find(b => !LEFT.includes(b.status));
  const tonight = bookings.filter(b => inHouseOn(b, today));
  const vacant = vacantOn(bookings, blocks, inventory, today);
  const collect = ins.filter(b => !ARRIVED.includes(b.status)).reduce((sum, b) => sum + Number(b.balance), 0);

  return (
    <div className="grid grid-cols-2 gap-2.5 lg:grid-cols-5">
      <Tile
        icon={<LogIn size={16} />}
        tone="in"
        label="Checking in today"
        value={ins.length}
        progress={ins.length ? `${arrived} of ${ins.length} arrived` : undefined}
        detail={
          nextIn
            ? `Next: ${nextIn.guest_name} · ${fmtTime(nextIn.check_in_time)}`
            : ins.length
              ? 'Everyone has arrived'
              : 'No arrivals'
        }
        onClick={() => onPick('in')}
      />
      <Tile
        icon={<LogOut size={16} />}
        tone="out"
        label="Checking out today"
        value={outs.length}
        progress={outs.length ? `${left} of ${outs.length} left` : undefined}
        detail={
          nextOut
            ? `Next: ${nextOut.guest_name} · ${fmtTime(nextOut.check_out_time)}`
            : outs.length
              ? 'Everyone has left'
              : 'No departures'
        }
        onClick={() => onPick('out')}
      />
      <Tile
        icon={<BedDouble size={16} />}
        tone="stay"
        label="In house tonight"
        value={tonight.length}
        detail={tonight.length ? `${tonight.reduce((n, b) => n + b.guests, 0)} guests` : 'Nobody staying'}
        onClick={() => onPick('stay')}
      />
      <Tile
        icon={<DoorOpen size={16} />}
        tone="free"
        label="Vacant tonight"
        value={vacant}
        detail={`of ${inventory} units`}
      />
      <Tile
        icon={<Wallet size={16} />}
        tone="money"
        label="To collect on arrival"
        value={exactMoney(collect)}
        detail={collect > 0 ? 'Balance of guests still to arrive' : 'Nothing left to collect'}
        className="col-span-2 lg:col-span-1"
        onClick={() => onPick('in')}
      />
    </div>
  );
}

const tones = {
  in: 'bg-emerald-50 text-emerald-700',
  out: 'bg-amber-50 text-amber-700',
  stay: 'bg-blue-50 text-blue-700',
  free: 'bg-slate-100 text-slate-600',
  money: 'bg-rose-50 text-rose-700',
};

function Tile({
  icon,
  tone,
  label,
  value,
  detail,
  progress,
  onClick,
  className = '',
}: {
  icon: ReactNode;
  tone: keyof typeof tones;
  label: string;
  value: ReactNode;
  detail: string;
  progress?: string;
  onClick?: () => void;
  className?: string;
}) {
  const body = (
    <>
      <span className="flex items-center justify-between gap-2">
        <span className="text-[11px] font-bold tracking-wide text-[var(--muted)] uppercase">{label}</span>
        <span className={`grid size-7 shrink-0 place-items-center rounded-lg ${tones[tone]}`}>{icon}</span>
      </span>
      <span className="num mt-1 block text-[26px] leading-none font-semibold text-forest">{value}</span>
      {progress && <span className="mt-1.5 block text-[11px] font-bold text-ink-soft">{progress}</span>}
      <span className="mt-1 block truncate text-[11.5px] font-semibold text-[var(--muted)]" title={detail}>
        {detail}
      </span>
    </>
  );
  const cls = `card block min-w-0 px-3.5 py-3 text-left ${className}`;
  return onClick ? (
    <button type="button" onClick={onClick} className={`${cls} transition hover:border-blue-400`}>
      {body}
    </button>
  ) : (
    <div className={cls}>{body}</div>
  );
}
