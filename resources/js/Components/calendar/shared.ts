import { addDays, fmtDay, parseDay } from '../ops';

/** A booking as the calendar receives it (OperationsService::row). */
export type Row = {
  id: number;
  reference: string;
  guest_name: string;
  guest_phone: string | null;
  unit: string;
  room_id: number;
  check_in: string;
  check_out: string;
  check_in_time: string;
  check_out_time: string;
  status: string;
  payment_status: string;
  source: string;
  guests: number;
  nights: number;
  total: number;
  paid: number;
  balance: number;
};
export type Block = {
  id: number;
  room_id: number;
  unit: string;
  reason: string | null;
  starts_on: string;
  ends_on: string;
};
export type Unit = { id: number; name: string; property: string; quantity: number; active: boolean };
export type Kind = 'all' | 'in' | 'out' | 'stay';

/** Has the guest already arrived / left? Used to tick off today's list. */
export const ARRIVED = ['CHECKED_IN', 'CHECKED_OUT', 'COMPLETED'];
export const LEFT = ['CHECKED_OUT', 'COMPLETED'];

export const inHouseOn = (b: Row, d: string) => b.check_in <= d && b.check_out > d;
export const blockedOn = (b: Block, d: string) => b.starts_on <= d && b.ends_on > d;
export const vacantOn = (bookings: Row[], blocks: Block[], inventory: number, d: string) =>
  Math.max(0, inventory - bookings.filter(b => inHouseOn(b, d)).length - blocks.filter(b => blockedOn(b, d)).length);

export const daysBetween = (from: string, to: string) => {
  const out: string[] = [];
  for (let d = from; d <= to && out.length < 400; d = addDays(d, 1)) out.push(d);
  return out;
};

/** "Today", "Tomorrow", "Yesterday", or "Monday". */
export const relativeDay = (d: string, today: string) =>
  d === today
    ? 'Today'
    : d === addDays(today, 1)
      ? 'Tomorrow'
      : d === addDays(today, -1)
        ? 'Yesterday'
        : parseDay(d).toLocaleDateString('en-PH', { weekday: 'long' });

export const longDay = (d: string) => fmtDay(d, { weekday: 'short', month: 'short', day: 'numeric' });

/** Minutes past midnight now, on the resort's clock (the browser's, which is the front desk's). */
export const nowHHMM = () => {
  const n = new Date();
  return `${String(n.getHours()).padStart(2, '0')}:${String(n.getMinutes()).padStart(2, '0')}`;
};
