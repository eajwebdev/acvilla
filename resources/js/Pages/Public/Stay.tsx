import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, BedDouble, Check, Clock3, Images, MapPin, PhoneCall, Share2, ShieldCheck, Star, Users, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';
import ImageGallery from '../../Components/ImageGallery';
import PageSign from '../../Components/PageSign';
import { RoomDialog, roomPhotos } from '../../Components/RoomShowcase';
import type { Property, Room } from '../../types';
import { includedGuests, money, rateUnit, roomSpecs, time12, type AddOn } from '../../types';

type GuestReview = { id: number; rating: number; comment: string | null; created_at: string; user: { name: string } | null };

/** The resort's details page: photos, what it offers and every unit. Booking happens on the guided /book page. */
export default function Stay({ property, tenant, bookable, selection, reviews = [], reviewSummary }: {
  property: Property;
  tenant: SiteTenant;
  bookable: boolean;
  selection?: { check_in?: string; check_out?: string; guests?: number; type?: string; room?: number };
  requestTypes?: Record<string, string>;
  callTimes?: Record<string, string>;
  addOns?: AddOn[];
  reviews?: GuestReview[];
  reviewSummary?: { count: number; average: number | null };
}) {
  const [roomId, setRoomId] = useState(property.rooms.find(item => item.id === selection?.room)?.id || property.rooms[0]?.id || 0);
  const [viewing, setViewing] = useState<Room | null>(null);
  const [showAll, setShowAll] = useState(false);
  const room = property.rooms.find(item => item.id === roomId);
  const rules = tenant?.settings;
  const maxGuests = Math.max(1, ...property.rooms.map(item => item.maximum_occupancy));
  const nightly = property.rooms.filter(item => item.kind !== 'venue' && !item.whole_property);
  const lowest = Math.min(...(nightly.length ? nightly : property.rooms).map(item => Number(item.base_rate)), Infinity);
  const amenities = useMemo(() => Array.from(new Set([...(rules?.amenities ?? []), ...property.rooms.flatMap(item => item.amenities ?? [])])), [rules?.amenities, property.rooms]);
  const gallery = useMemo(() => [...(property.cover_image ? [{ src: property.cover_image, alt: property.name }] : []),
    ...property.rooms.flatMap(item => (item.photos ?? []).map((src, i) => ({ src, alt: `${item.name} photo ${i + 1}`, caption: item.name })))], [property]);
  const bookHref = (item?: Room) => `/book?${new URLSearchParams({ ...(tenant ? { tenant: tenant.slug } : {}), property: property.slug,
    ...(item ? { room: String(item.id) } : {}), ...(selection?.check_in && selection.check_out ? { check_in: selection.check_in, check_out: selection.check_out } : {}),
    ...(selection?.guests ? { guests: String(selection.guests) } : {}) })}`;

  return <GuestShell tenant={tenant}>
    <Head title={`${property.name} | ${tenant?.business_name || 'Stay'}`}>
      <meta name="description" content={property.short_description || property.description || `Book ${property.name} in ${property.city}, ${property.province}.`}/>
    </Head>
    <div className="container pb-32 pt-6 sm:pt-9 lg:pb-16">
      <nav aria-label="Breadcrumb" className="ac-crumbs flex w-fit max-w-full items-center gap-1.5 text-sm font-semibold text-slate"><Link href={`/${tenant ? `?tenant=${encodeURIComponent(tenant.slug)}` : ''}`} className="inline-flex items-center gap-1.5 hover:text-navy"><ArrowLeft size={16}/> Home</Link><span aria-hidden="true">/</span><span className="truncate text-navy">{property.name}</span></nav>
      <div className="mt-4 flex flex-wrap items-center justify-between gap-4">
        <div className="min-w-0"><PageSign title={property.name}/>
          <p className="ac-page-sub mt-3 flex flex-wrap items-center gap-x-3 gap-y-1"><span className="inline-flex items-center gap-1"><MapPin size={16}/>{property.city}, {property.province}</span>{reviewSummary?.average && <a href="#guest-reviews" className="inline-flex items-center gap-1 font-semibold hover:underline"><Star size={15} className="fill-amber-400 text-amber-400"/>{reviewSummary.average} · {reviewSummary.count} {reviewSummary.count === 1 ? 'review' : 'reviews'}</a>}</p></div>
        <div className="flex items-center gap-2"><ShareButton title={property.name}/>{bookable && <Link href={bookHref()} className="ac-btn ac-btn-primary hidden !min-h-11 sm:inline-flex">Book now <ArrowRight size={17}/></Link>}</div>
      </div>
      {gallery.length ? <ImageGallery variant="mosaic" className="mt-6" items={gallery}/> : <div className="mt-6 h-64 rounded-3xl bg-slate-200"/>}

      <div className="mt-9 grid gap-8 lg:grid-cols-[minmax(0,1fr)_360px] lg:items-start lg:gap-10">
        <div className="ac-card min-w-0 p-5 sm:p-7">
          <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-2xl border border-[var(--border)] bg-[var(--border)] sm:grid-cols-4">
            {[[Users, 'Guests', `Up to ${maxGuests}`], [BedDouble, 'Rooms & halls', String(property.rooms.length)], [Clock3, 'Check-in', rules ? time12(rules.check_in_time) : 'Flexible'], [Wallet, 'Rooms from', Number.isFinite(lowest) ? `${money(lowest)} / night` : '-']].map(([Icon, label, value]) => { const I = Icon as typeof Users; return <div key={label as string} className="bg-white p-4"><dt className="flex items-center gap-1.5 text-xs font-semibold text-slate"><I size={14} style={{ color: 'var(--guest-accent)' }}/>{label as string}</dt><dd className="mt-1 font-bold">{value as string}</dd></div>; })}
          </dl>
          <p className="whitespace-pre-line mt-8 max-w-2xl leading-7 text-slate">{property.description || property.short_description}</p>
          {amenities.length > 0 && <section className="mt-8" aria-labelledby="amenities"><h2 id="amenities" className="text-lg font-bold">What this place offers</h2><ul className="mt-4 grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">{(showAll ? amenities : amenities.slice(0, 8)).map(a => <li key={a} className="flex items-center gap-3 text-[15px]"><span className="grid size-8 shrink-0 place-items-center rounded-lg bg-white shadow-[var(--shadow-soft)]" style={{ color: 'var(--guest-accent)' }}><Check size={15}/></span>{a}</li>)}</ul>{amenities.length > 8 && <button type="button" onClick={() => setShowAll(!showAll)} className="btn btn-light mt-5">{showAll ? 'Show fewer' : `Show all ${amenities.length} amenities`}</button>}</section>}

          <div className="mt-10 flex flex-wrap items-end justify-between gap-2"><h2 className="font-serif text-2xl font-semibold text-[var(--ac-green)]">Rooms, halls & packages</h2><p className="text-xs text-slate">Tap a photo to browse it.</p></div>
          <div className="mt-4 grid gap-3">{property.rooms.map(item => <RoomChoice key={item.id} room={item} selected={roomId === item.id} onSelect={() => setRoomId(item.id)} onPhotos={() => setViewing(item)} bookHref={bookable ? bookHref(item) : undefined} fallbackPhoto={property.cover_image}/>)}</div>
          {(rules?.house_rules || rules?.cancellation_policy) && <details className="mt-9 rounded-xl border border-slate-200 bg-white p-5"><summary className="cursor-pointer font-semibold">House rules & cancellation</summary><div className="mt-5 grid gap-5 sm:grid-cols-2">{rules.house_rules && <div><h3 className="font-semibold">House rules</h3><p className="mt-2 whitespace-pre-line text-sm leading-6 text-slate">{rules.house_rules}</p></div>}{rules.cancellation_policy && <div><h3 className="font-semibold">Cancellation policy</h3><p className="mt-2 whitespace-pre-line text-sm leading-6 text-slate">{rules.cancellation_policy}</p></div>}</div></details>}
        </div>

        <aside className="ac-book-summary" aria-label="Book">
          {room && <>{roomPhotos(room, property.cover_image)[0] && <img src={roomPhotos(room, property.cover_image)[0]} alt="" className="ac-summary-photo"/>}
            <h2 className="mt-4 font-serif text-2xl font-semibold text-[var(--ac-green)]">{room.name}</h2>
            <p className="mt-1 text-sm text-slate"><strong className="font-serif text-xl text-[var(--ac-green)]">{money(room.base_rate)}</strong> {rateUnit(room)}</p></>}
          {bookable ? <Link href={bookHref(room)} className="ac-btn ac-btn-primary mt-5 w-full">Check availability & book <ArrowRight size={18}/></Link>
            : <p className="mt-5 rounded-xl bg-slate-50 p-3 text-sm text-slate">Bookings are temporarily unavailable.</p>}
          <p className="ac-summary-note"><ShieldCheck size={16}/><span><strong>No payment online.</strong> Send a request in four quick steps and the owner calls you to confirm.</span></p>
          {tenant?.business_phone && <a href={`tel:${tenant.business_phone.replace(/[^\d+]/g, '')}`} className="ac-summary-note link"><PhoneCall size={16}/><span>Prefer to talk? Call <strong>{tenant.business_phone}</strong></span></a>}
          {rules && <p className="mt-4 text-xs leading-5 text-slate">Check-in from {time12(rules.check_in_time)} · Check-out by {time12(rules.check_out_time)}</p>}
        </aside>
      </div>
      {reviews.length > 0 && <section className="ac-card mt-10 p-5 sm:p-7" aria-labelledby="guest-reviews"><div className="flex flex-wrap items-end justify-between gap-3"><div><p className="section-eyebrow">Guest reviews</p><h2 id="guest-reviews" className="section-title mt-2">Stayed here, loved here.</h2></div><p className="flex items-center gap-2 font-semibold"><Star size={19} className="fill-amber-400 text-amber-400"/>{reviewSummary?.average} from {reviewSummary?.count} {reviewSummary?.count === 1 ? 'review' : 'reviews'}</p></div><div className="mt-6 grid gap-4 md:grid-cols-2 lg:grid-cols-3">{reviews.map(review => <article key={review.id} className="rounded-2xl bg-white p-5 shadow-sm"><div className="flex gap-1" aria-label={`${review.rating} out of 5 stars`}>{Array.from({ length: 5 }, (_, index) => <Star key={index} size={15} className={index < review.rating ? 'fill-amber-400 text-amber-400' : 'text-slate-200'}/>)}</div><p className="mt-3 text-sm leading-6">{review.comment || 'A great stay.'}</p><p className="mt-4 text-xs font-semibold text-slate">{review.user?.name || 'Verified guest'}</p></article>)}</div></section>}
    </div>
    <RoomDialog room={viewing} fallbackPhoto={property.cover_image} reserveHref={viewing && bookable ? bookHref(viewing) : undefined} open={!!viewing} onOpenChange={next => { if (!next) setViewing(null); }}/>
    {bookable && <div className="ac-book-bar lg:hidden">
      <div className="min-w-0"><small>From</small><strong>{money(room?.base_rate || lowest)}<span className="font-sans text-xs font-medium text-slate"> {room ? rateUnit(room) : '/ night'}</span></strong></div>
      <Link href={bookHref(room)} className="ac-btn ac-btn-primary !min-h-11 !px-5">Book now</Link>
    </div>}
  </GuestShell>;
}

function ShareButton({ title }: { title: string }) {
  const [copied, setCopied] = useState(false);
  const share = async () => {
    const url = window.location.href.split('?')[0];
    if (navigator.share) { try { await navigator.share({ title, url }); } catch { /* dismissed */ } return; }
    await navigator.clipboard?.writeText(url); setCopied(true); window.setTimeout(() => setCopied(false), 2000);
  };
  return <button type="button" onClick={share} className="btn btn-light !min-h-11 !px-4">{copied ? <><Check size={16}/> Link copied</> : <><Share2 size={16}/> Share</>}</button>;
}

function RoomChoice({ room, selected, onSelect, onPhotos, bookHref, fallbackPhoto }: {
  room: Room; selected: boolean; onSelect: () => void; onPhotos: () => void; bookHref?: string; fallbackPhoto?: string | null;
}) {
  const photos = roomPhotos(room, fallbackPhoto);
  return <div className={`ac-unit ${selected ? 'selected' : ''}`}>
    {photos[0] && <button type="button" onClick={onPhotos} className="ac-unit-photo" aria-label={`View ${photos.length} photos of ${room.name}`}><img src={photos[0]} alt="" loading="lazy"/><span><Images size={13}/>{photos.length}</span></button>}
    <div className="ac-unit-body">
      <button type="button" onClick={onSelect} aria-pressed={selected} className="block w-full text-left">
        <span className="flex flex-wrap justify-between gap-x-4 gap-y-1"><strong className="ac-unit-name">{room.name}</strong><span className="whitespace-nowrap"><strong className="ac-unit-price">{money(room.base_rate)}</strong> <small className="text-slate">{rateUnit(room)}</small></span></span>
        <small className="block text-slate">{room.kind === 'venue' ? `Up to ${room.maximum_occupancy} guests` : `Good for ${includedGuests(room)} pax${room.maximum_occupancy > includedGuests(room) ? ` · up to ${room.maximum_occupancy}` : ''}`}{room.kind !== 'venue' && !room.whole_property && roomSpecs(room) !== 'Studio' ? ` · ${roomSpecs(room)}` : ''}</small>
        {room.description && <span className={`mt-2 block text-sm leading-6 text-[#505869] ${selected ? '' : 'line-clamp-2'}`}>{room.description}</span>}
      </button>
      {bookHref && <Link href={bookHref} className="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-[var(--ac-blue)]">Book this <ArrowRight size={15}/></Link>}
    </div>
  </div>;
}
