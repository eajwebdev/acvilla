import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowRight, CalendarDays, Luggage, MapPin, Star, Users } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import GuestShell from '../../Components/GuestShell';
import { iso, Status } from '../../Components/ops';
import { money, type Booking } from '../../types';

type Tab = 'upcoming' | 'past' | 'cancelled';
const CLOSED = ['CANCELLED', 'NO_SHOW', 'EXPIRED'];
const today = iso(new Date());
const tabOf = (booking: Booking): Tab => CLOSED.includes(booking.status) ? 'cancelled'
  : ['COMPLETED', 'CHECKED_OUT'].includes(booking.status) || booking.check_out.slice(0, 10) < today ? 'past' : 'upcoming';
const day = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' });
const nights = (b: Booking) => Math.max(1, Math.round((Date.parse(b.check_out.slice(0, 10)) - Date.parse(b.check_in.slice(0, 10))) / 86_400_000));
const labels: Record<Tab, string> = { upcoming: 'Upcoming', past: 'Past stays', cancelled: 'Cancelled' };

export default function Bookings({ bookings }: { bookings: Booking[] }) {
  const counts = bookings.reduce((acc, b) => ({ ...acc, [tabOf(b)]: acc[tabOf(b)] + 1 }), { upcoming: 0, past: 0, cancelled: 0 } as Record<Tab, number>);
  const [tab, setTab] = useState<Tab>(counts.upcoming || !bookings.length ? 'upcoming' : 'past');
  const shown = bookings.filter(b => tabOf(b) === tab);
  return <GuestShell tenant={null}><Head title="My bookings"/>
    <div className="container min-h-[65vh] py-12 sm:py-16">
      <div className="flex flex-wrap items-end justify-between gap-5">
        <div><p className="section-eyebrow">Your journeys</p><h1 className="section-title mt-3">My bookings</h1><p className="mt-3 text-slate">Reservations, payments and reviews for every stay.</p></div>
        <Link href="/stays" className="btn btn-light">Plan another stay <ArrowRight size={17}/></Link>
      </div>
      {bookings.length > 0 && <div role="tablist" aria-label="Booking filter" className="mt-9 inline-flex gap-1 rounded-2xl border border-[var(--border)] bg-white p-1">
        {(Object.keys(labels) as Tab[]).map(key => <button key={key} type="button" role="tab" aria-selected={tab === key} onClick={() => setTab(key)} className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${tab === key ? 'bg-navy text-white shadow' : 'text-slate hover:text-navy'}`}>{labels[key]} <span className={`ml-1 text-xs ${tab === key ? 'text-white/70' : 'text-slate/70'}`}>{counts[key]}</span></button>)}
      </div>}
      <div className="mt-6 grid gap-4">
        {shown.map(booking => <BookingCard key={booking.reference} booking={booking}/>)}
        {bookings.length > 0 && !shown.length && <p className="surface-card p-10 text-center text-sm text-slate">No {labels[tab].toLowerCase()} bookings.</p>}
        {!bookings.length && <div className="surface-card grid place-items-center px-6 py-16 text-center"><span className="grid size-14 place-items-center rounded-2xl bg-pink/10 text-pink"><Luggage size={26}/></span><h2 className="mt-5 text-xl font-bold">No bookings yet</h2><p className="mt-2 max-w-sm text-slate">When you reserve a stay, it shows up here with your reference and payment status.</p><Link href="/stays" className="btn btn-pink mt-6">Explore stays <ArrowRight size={17}/></Link></div>}
      </div>
    </div>
  </GuestShell>;
}

function BookingCard({ booking }: { booking: Booking }) {
  const awaitingPayment = booking.status === 'PENDING' && booking.expires_at && Date.parse(booking.expires_at) > Date.now();
  const n = nights(booking);
  return <article className="surface-card overflow-hidden md:grid md:grid-cols-[240px_minmax(0,1fr)]">
    <div className="relative h-44 bg-slate-200 md:h-full">{booking.property.cover_image && <img src={booking.property.cover_image} alt="" loading="lazy" className="absolute inset-0 h-full w-full object-cover"/>}</div>
    <div className="p-5 sm:p-6">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0"><p className="font-mono text-xs font-semibold tracking-wide text-pink">{booking.reference}</p><h2 className="mt-1.5 text-xl font-bold tracking-[-.01em]">{booking.property.name}</h2><p className="mt-1 flex items-center gap-1 text-sm text-slate"><MapPin size={14}/>{booking.property.city}, {booking.property.province}</p></div>
        <div className="flex flex-wrap gap-1.5"><Status value={booking.status}/>{booking.payment_status !== booking.status && <Status value={booking.payment_status}/>}</div>
      </div>
      <dl className="mt-5 grid gap-3 rounded-xl bg-[var(--surface-muted)] p-4 text-sm sm:grid-cols-3">
        <div><dt className="flex items-center gap-1.5 text-xs font-semibold text-slate"><CalendarDays size={13}/>Check-in</dt><dd className="mt-1 font-semibold">{day(booking.check_in)}</dd></div>
        <div><dt className="flex items-center gap-1.5 text-xs font-semibold text-slate"><CalendarDays size={13}/>Check-out</dt><dd className="mt-1 font-semibold">{day(booking.check_out)}</dd></div>
        <div><dt className="flex items-center gap-1.5 text-xs font-semibold text-slate"><Users size={13}/>Stay</dt><dd className="mt-1 font-semibold">{booking.room.name} · {n} {n === 1 ? 'night' : 'nights'} · {booking.guests} {booking.guests === 1 ? 'guest' : 'guests'}</dd></div>
      </dl>
      <div className="mt-4 flex flex-wrap items-center justify-between gap-3"><p className="text-sm text-slate">Total <strong className="ml-1 text-lg text-navy">{money(booking.total)}</strong></p>
        {awaitingPayment ? <Link href={`/checkout/${booking.reference}`} className="btn btn-pink min-h-10 text-sm">Complete payment <ArrowRight size={16}/></Link> : <Link href={`/stays/${booking.property.slug}`} className="text-sm font-semibold text-pink hover:underline">View property →</Link>}
      </div>
      {booking.review ? <div className="mt-5 border-t border-[var(--border)] pt-4"><div className="flex gap-1" aria-label={`${booking.review.rating} out of 5 stars`}>{Array.from({ length: 5 }, (_, index) => <Star key={index} size={17} className={index < booking.review!.rating ? 'fill-amber-400 text-amber-400' : 'text-slate-200'}/>)}</div><p className="mt-2 text-sm text-slate">{booking.review.comment || 'Rating submitted.'} {!booking.review.approved && <span className="font-semibold">Awaiting moderation.</span>}</p></div>
        : booking.status === 'COMPLETED' ? <ReviewForm bookingId={booking.id}/> : null}
    </div>
  </article>;
}

function ReviewForm({ bookingId }: { bookingId: number }) {
  const form = useForm({ booking_id: bookingId, rating: 5, comment: '' });
  const [hover, setHover] = useState(0);
  const submit = (event: FormEvent) => { event.preventDefault(); form.post('/account/reviews', { preserveScroll: true }); };
  return <form onSubmit={submit} className="mt-5 border-t border-[var(--border)] pt-4"><p className="font-semibold">How was your stay?</p>
    <div className="mt-2 flex gap-1" role="radiogroup" aria-label="Rating" onMouseLeave={() => setHover(0)}>{[1, 2, 3, 4, 5].map(value => <button key={value} type="button" role="radio" aria-checked={form.data.rating === value} aria-label={`${value} star${value === 1 ? '' : 's'}`} onMouseEnter={() => setHover(value)} onClick={() => form.setData('rating', value)} className="transition hover:scale-110"><Star size={26} className={value <= (hover || form.data.rating) ? 'fill-amber-400 text-amber-400' : 'text-slate-300'}/></button>)}</div>
    <label className="field mt-3">Review (optional)<textarea maxLength={1000} rows={3} value={form.data.comment} onChange={event => form.setData('comment', event.target.value)} placeholder="Tell future guests what stood out."/></label>
    {Object.values(form.errors).map((error, index) => <p key={index} className="field-error mt-1">{error}</p>)}
    <button disabled={form.processing} className="guest-button mt-3">Submit review</button>
  </form>;
}
