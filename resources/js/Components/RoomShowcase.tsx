import * as Dialog from '@radix-ui/react-dialog';
import { Link } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight, Images, Users, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { includedGuests, money, rateUnit, type Room } from '../types';

/** A room's photos, cover first; units without photos fall back to the property cover. */
export const roomPhotos = (room: Room, fallback?: string | null) => room.photos?.length ? room.photos : fallback ? [fallback] : [];

/** Swipeable photo browser: large photo, arrows, counter and a thumbnail strip. Arrow keys work while it has focus. */
export function PhotoBrowser({ photos, alt }: { photos: string[]; alt: string }) {
  const [index, setIndex] = useState(0);
  const touch = useRef<number | null>(null);
  const strip = useRef<HTMLDivElement>(null);
  const go = (delta: number) => setIndex(i => (i + delta + photos.length) % photos.length);
  useEffect(() => { strip.current?.children[index]?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' }); }, [index]);
  if (!photos.length) return <div className="ac-browser-main grid place-items-center text-sm text-slate">No photos yet</div>;
  const many = photos.length > 1;
  return <div className="ac-browser" tabIndex={0} aria-roledescription="carousel" aria-label={`${alt} photos`}
    onKeyDown={e => { if (e.key === 'ArrowLeft') { e.preventDefault(); go(-1); } if (e.key === 'ArrowRight') { e.preventDefault(); go(1); } }}>
    <div className="ac-browser-main" onTouchStart={e => { touch.current = e.touches[0].clientX; }}
      onTouchEnd={e => { if (touch.current === null) return; const d = e.changedTouches[0].clientX - touch.current; if (Math.abs(d) > 45) go(d < 0 ? 1 : -1); touch.current = null; }}>
      {/* Portrait and landscape photos both show whole, over a blurred copy of themselves. */}
      <img key={`bg-${photos[index]}`} src={photos[index]} alt="" aria-hidden="true" className="backdrop"/>
      <img key={photos[index]} src={photos[index]} alt={`${alt}, photo ${index + 1} of ${photos.length}`} className="animate-fade"/>
      {/* Preload the neighbours so swiping feels instant. */}
      {many && <link rel="prefetch" as="image" href={photos[(index + 1) % photos.length]}/>}
      {many && <>
        <button type="button" className="ac-browser-arrow left" aria-label="Previous photo" onClick={() => go(-1)}><ChevronLeft size={22}/></button>
        <button type="button" className="ac-browser-arrow right" aria-label="Next photo" onClick={() => go(1)}><ChevronRight size={22}/></button>
      </>}
      <span className="ac-browser-count" aria-live="polite"><Images size={14}/> {index + 1} / {photos.length}</span>
    </div>
    {many && <div ref={strip} className="ac-browser-strip">{photos.map((src, i) => <button key={`${src}-${i}`} type="button" onClick={() => setIndex(i)} aria-label={`Show photo ${i + 1}`} aria-current={i === index}><img src={src} alt="" loading="lazy"/></button>)}</div>}
  </div>;
}

/** Full details of one room, hall or package with its photo browser, opened from a room card. */
export function RoomDialog({ room, fallbackPhoto, reserveHref, open, onOpenChange }: { room: Room | null; fallbackPhoto?: string | null; reserveHref?: string; open: boolean; onOpenChange: (open: boolean) => void }) {
  return <Dialog.Root open={open && !!room} onOpenChange={onOpenChange}>
    <Dialog.Portal>
      <Dialog.Overlay className="date-overlay animate-fade"/>
      {room && <Dialog.Content className="ac-room-dialog animate-fade" aria-describedby={undefined}>
        <PhotoBrowser photos={roomPhotos(room, fallbackPhoto)} alt={room.name}/>
        <div className="ac-room-dialog-body">
          <p className="ac-eyebrow">{room.whole_property ? 'Exclusive package' : room.kind === 'venue' ? 'Events venue' : 'Room accommodation'}</p>
          <Dialog.Title className="mt-2 font-serif text-2xl font-semibold leading-tight text-[var(--ac-green)]">{room.name}</Dialog.Title>
          <p className="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-slate"><Users size={16}/>{room.kind === 'venue' ? `Up to ${room.maximum_occupancy} guests` : `Good for ${includedGuests(room)} pax${room.maximum_occupancy > includedGuests(room) ? ` · up to ${room.maximum_occupancy}` : ''}`}</p>
          <div className="mt-5 rounded-2xl bg-[var(--ac-paper)] p-4 ring-1 ring-[#E3EAE1]">
            <p className="font-serif text-2xl font-semibold text-[var(--ac-green)]">{money(room.base_rate)} <span className="font-sans text-sm font-semibold text-slate">{rateUnit(room)}</span></p>
            {Number(room.extra_guest_fee) > 0 && <p className="mt-1 text-sm text-slate">Extra person: {money(room.extra_guest_fee)} each</p>}
          </div>
          {room.description && <p className="mt-5 whitespace-pre-line text-[15px] leading-7 text-[#4A5A52]">{room.description}</p>}
          {(room.amenities?.length ?? 0) > 0 && <ul className="mt-5 grid grid-cols-2 gap-x-4 gap-y-2 text-sm" aria-label="Amenities">{room.amenities!.map(item => <li key={item} className="flex items-center gap-2"><Check size={15} className="shrink-0 text-[var(--ac-green-mid)]"/>{item}</li>)}</ul>}
          {reserveHref && <Link href={reserveHref} className="ac-btn ac-btn-primary mt-7 w-full justify-center" onClick={() => onOpenChange(false)}>Reserve {room.kind === 'venue' ? 'this venue' : room.whole_property ? 'the whole resort' : 'this room'} <ChevronRight size={20}/></Link>}
          <p className="mt-3 text-center text-xs text-slate">No payment online. The owner calls you to confirm.</p>
        </div>
        <Dialog.Close className="ac-room-dialog-close" aria-label="Close"><X size={20}/></Dialog.Close>
      </Dialog.Content>}
    </Dialog.Portal>
  </Dialog.Root>;
}
