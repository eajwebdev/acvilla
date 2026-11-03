import type { ReactNode } from 'react';
import { currency } from '../types';

/** "₱4.2K": the compact KPI style used across the operations screens. */
export const kMoney = (n: number | string) => {
  const value = Number(n) || 0;
  const symbol = new Intl.NumberFormat('en-PH', { style: 'currency', currency: currency() }).formatToParts(0).find(part => part.type === 'currency')?.value ?? '';
  const abs = Math.abs(value);
  const body = abs >= 1_000_000 ? `${(abs / 1_000_000).toFixed(1)}M` : `${(abs / 1000).toFixed(1)}K`;
  return `${value < 0 ? '-' : ''}${symbol}${body}`;
};
/** "₱4K" on chart axes. */
export const axisMoney = (n: number) => {
  const symbol = new Intl.NumberFormat('en-PH', { style: 'currency', currency: currency() }).formatToParts(0).find(part => part.type === 'currency')?.value ?? '';
  return n >= 1000 ? `${symbol}${Math.round(n / 1000)}K` : `${symbol}${Math.round(n)}`;
};
export const exactMoney = (n: number | string) => new Intl.NumberFormat('en-PH', { style: 'currency', currency: currency(), minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n) || 0);

export const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
export const parseDay = (s: string) => new Date(`${s.slice(0, 10)}T12:00:00`);
export const addDays = (s: string, n: number) => { const d = parseDay(s); d.setDate(d.getDate() + n); return iso(d); };
export const fmtDay = (s: string, opts: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' }) => parseDay(s).toLocaleDateString('en-PH', opts);
export const fmtTime = (hhmm?: string | null) => hhmm ? new Date(`2000-01-01T${hhmm.slice(0, 5)}:00`).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : '';
export const nightsBetween = (a: string, b: string) => Math.max(0, Math.round((parseDay(b).getTime() - parseDay(a).getTime()) / 86_400_000));

// Keep in sync with Booking::SOURCES / Booking::DIRECT_SOURCES.
export const SOURCES: Record<string, string> = { facebook: 'Facebook', messenger: 'Messenger', instagram: 'Instagram', whatsapp: 'WhatsApp', viber: 'Viber', website: 'Website', phone: 'Phone / SMS', walk_in: 'Walk-in', direct: 'Direct (other)', airbnb: 'Airbnb', booking_com: 'Booking.com', agoda: 'Agoda', other: 'Other' };
export const DIRECT_SOURCES = ['facebook', 'messenger', 'instagram', 'whatsapp', 'viber', 'website', 'phone', 'walk_in', 'direct'];
export const SOURCE_GROUPS: [string, string[]][] = [['Social & chat', ['facebook', 'messenger', 'instagram', 'whatsapp', 'viber']], ['Direct', ['website', 'phone', 'walk_in', 'direct']], ['Booking sites', ['airbnb', 'booking_com', 'agoda', 'other']]];
/** Brand colour per channel, used for the badge dot and the Insights bars. */
export const SOURCE_COLORS: Record<string, string> = { facebook: '#1877F2', messenger: '#A334FA', instagram: '#E1306C', whatsapp: '#25D366', viber: '#7360F2', website: '#0A2266', phone: '#5E6679', walk_in: '#0039D5', direct: '#939BAC', airbnb: '#FF5A5F', booking_com: '#003580', agoda: '#5392F9', other: '#AFB4C1' };
export function SourceBadge({ source }: { source?: string | null }) {
  const key = source || 'direct';
  return <span className="pill"><span className="size-1.5 rounded-full" style={{ background: SOURCE_COLORS[key] ?? '#AFB4C1' }}/>{SOURCES[key] ?? key}</span>;
}

const statusTone: Record<string, string> = {
  CONFIRMED: 'border-emerald-200 bg-emerald-50 text-emerald-800', PAID: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  CHECKED_IN: 'border-teal-200 bg-teal-50 text-teal-800', CHECKED_OUT: 'border-sky-200 bg-sky-50 text-sky-800', COMPLETED: 'border-sky-200 bg-sky-50 text-sky-800',
  PENDING: 'border-amber-200 bg-amber-50 text-amber-800', AWAITING_PAYMENT: 'border-amber-200 bg-amber-50 text-amber-800', PARTIALLY_PAID: 'border-amber-200 bg-amber-50 text-amber-800',
  UNPAID: 'border-rose-200 bg-rose-50 text-rose-700', CANCELLED: 'border-rose-200 bg-rose-50 text-rose-700', NO_SHOW: 'border-rose-200 bg-rose-50 text-rose-700',
  FAILED: 'border-rose-200 bg-rose-50 text-rose-700', VOID: 'border-slate-200 bg-slate-100 text-slate-500', EXPIRED: 'border-slate-200 bg-slate-100 text-slate-500',
  ACTIVE: 'border-emerald-200 bg-emerald-50 text-emerald-800', TRIAL: 'border-sky-200 bg-sky-50 text-sky-800', REFUNDED: 'border-slate-200 bg-slate-100 text-slate-500',
  LOCKED: 'border-rose-200 bg-rose-50 text-rose-700', SUSPENDED: 'border-rose-200 bg-rose-50 text-rose-700',
};
/** The one status pill used everywhere: guest site, operations and platform screens. */
export function Status({ value }: { value: string }) {
  return <span className={`pill ${statusTone[value] ?? ''}`}>{value.replaceAll('_', ' ').toLowerCase()}</span>;
}

export function Empty({ icon, title, body, action }: { icon: ReactNode; title: string; body?: ReactNode; action?: ReactNode }) {
  return <div className="grid place-items-center px-6 py-12 text-center">
    <span className="grid size-11 place-items-center rounded-xl border border-[var(--line)] bg-[var(--mint)] text-ocean">{icon}</span>
    <p className="mt-4 text-sm font-bold">{title}</p>
    {body && <p className="mt-1 max-w-sm text-xs text-[var(--muted)]">{body}</p>}
    {action && <div className="mt-4">{action}</div>}
  </div>;
}

export function Card({ title, subtitle, action, children, className = '', bodyClass = '' }: { title?: ReactNode; subtitle?: ReactNode; action?: ReactNode; children: ReactNode; className?: string; bodyClass?: string }) {
  return <section className={`card min-w-0 ${className}`}>
    {(title || action) && <header className="flex flex-wrap items-start justify-between gap-3 border-b border-[var(--line)] px-5 py-4">
      <div className="min-w-0"><h2 className="text-[15px] font-bold">{title}</h2>{subtitle && <p className="mt-0.5 text-xs text-[var(--muted)]">{subtitle}</p>}</div>
      {action}
    </header>}
    <div className={bodyClass}>{children}</div>
  </section>;
}

export function Avatar({ name, size = 36 }: { name: string; size?: number }) {
  return <span className="grid shrink-0 place-items-center rounded-full border border-green-200 bg-green-50 font-bold text-forest" style={{ width: size, height: size, fontSize: size * 0.4 }}>{(name.trim()[0] || '?').toUpperCase()}</span>;
}

/** Shrink a phone photo (often 4-10 MB) to a ~1600px JPEG before upload so it is quick on mobile data. Falls back to the original file. */
export async function compressImage(file: File, max = 1600, quality = 0.82): Promise<File> {
  if (!file.type.startsWith('image/') || file.type === 'image/gif') return file;
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', quality));
    return blob && blob.size < file.size ? new File([blob], `${file.name.replace(/\.[^.]+$/, '') || 'photo'}.jpg`, { type: 'image/jpeg' }) : file;
  } catch {
    return file;
  }
}

/** Fetch JSON from an admin endpoint with the session cookie. */
export async function getJson<T>(url: string): Promise<T> {
  const res = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
  if (!res.ok) {
    const body = await res.json().catch(() => null) as { message?: string; errors?: Record<string, string[]> } | null;
    throw new Error(body?.errors ? Object.values(body.errors)[0]?.[0] : body?.message || `Request failed (${res.status})`);
  }
  return res.json() as Promise<T>;
}
