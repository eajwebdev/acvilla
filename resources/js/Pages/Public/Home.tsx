import { Head, Link, router } from '@inertiajs/react';
import { AirVent, Armchair, BedDouble, CalendarCheck2, CalendarDays, Camera, Check, ChevronRight, Gem, Heart, House, Images, Mail, MapPin, MapPinned, Moon, Navigation, Phone, Plane, ShowerHead, Sofa, Sparkles, Tent, Users, Waves, Wifi, type LucideIcon } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import DateRangePicker, { type DateRange } from '../../Components/DateRangePicker';
import GuestShell, { Facebook, type SiteTenant } from '../../Components/GuestShell';
import ImageGallery from '../../Components/ImageGallery';
import { RoomDialog, roomPhotos } from '../../Components/RoomShowcase';
import { Rich, SiteIcon, type IconItem, type SiteContent } from '../../Components/siteContent';
import { includedGuests, money, rateUnit, time12, type Property, type Room } from '../../types';

const AMENITY_ICONS: [RegExp, string][] = [[/cabana/i, 'cabana'], [/pool|swim/i, 'pool'], [/\bair/i, 'aircon'], [/wi-?fi/i, 'wifi'], [/shower/i, 'shower'], [/bath|toilet/i, 'bathroom'], [/\btv\b|television/i, 'tv'], [/park/i, 'parking'],
  [/hall|function|event/i, 'hall'], [/sound|speaker/i, 'sound'], [/videoke|karaoke|mic/i, 'videoke'], [/towel|linen/i, 'towels'], [/garden/i, 'garden'], [/chair|table/i, 'chairs']];
const amenityIcon = (name: string) => AMENITY_ICONS.find(([pattern]) => pattern.test(name))?.[1];
/** "Sound system with microphone" → "Sound System with Microphone". */
const amenityLabel = (name: string) => name.replace(/[A-Za-z][\w-]*/g, word => /^(with|and|of|the)$/i.test(word) ? word : word.charAt(0).toUpperCase() + word.slice(1));

const ROOM_ICONS: [RegExp, LucideIcon][] = [[/\bair/i, AirVent], [/wi-?fi/i, Wifi], [/shower/i, ShowerHead], [/hall|function/i, House], [/chair|table/i, Armchair]];
const roomIcon = (name: string) => ROOM_ICONS.find(([pattern]) => pattern.test(name))?.[1] ?? Check;
const CAPTION_ICONS: [RegExp, LucideIcon][] = [[/balcony|lounge/i, Armchair], [/night/i, Moon], [/wedding/i, Gem], [/aerial/i, Plane], [/main house/i, House], [/a-?frame|villa/i, Tent], [/pool/i, Waves], [/bedroom|loft/i, BedDouble], [/hall|living/i, Sofa]];
const captionIcon = (caption: string) => CAPTION_ICONS.find(([pattern]) => pattern.test(caption))?.[1] ?? Camera;

/** "Your Private Escape in Mabinay" → navy first line, gold italic "in Mabinay". */
function splitHeadline(text: string): [string, string] {
  const at = text.lastIndexOf(' in ');
  return at > 0 ? [text.slice(0, at), text.slice(at + 1)] : [text, ''];
}

export default function Home({ tenant, featured, hero, requestTypes = {} }: { tenant: SiteTenant; featured: Property[]; hero: Property | null; requestTypes?: Record<string, string> }) {
  const c = tenant?.site_content;
  const [dates, setDates] = useState<DateRange>({ start: '', end: '' });
  const [guests, setGuests] = useState(2);
  const [type, setType] = useState(Object.keys(requestTypes)[0] ?? '');
  const name = tenant?.business_name || 'AC Villa & Private Resort';
  const rooms = featured.flatMap(property => property.rooms.map(room => ({ room, property })));
  const stays = rooms.filter(({ room }) => room.kind !== 'venue' && !room.whole_property);
  const venues = rooms.filter(({ room }) => room.kind === 'venue');
  const wholeResortItem = rooms.find(({ room }) => room.whole_property);
  const wholeResort = wholeResortItem?.room;
  const [viewing, setViewing] = useState<{ room: Room; property: Property } | null>(null);
  const maxGuests = Math.max(20, ...rooms.map(({ room }) => room.maximum_occupancy));
  const [first, second] = splitHeadline(tenant?.hero_headline || 'Your Private Escape in Mabinay');
  const amenities = tenant?.settings?.amenities ?? [];
  const heroImage = tenant?.hero_image || '/images/acvilla/hero-mabinay.jpg';
  const on = (section: keyof SiteContent['sections']) => c?.sections[section] ?? true;
  // Every booking button opens the guided booking page, with the room or kind of booking already chosen.
  const bookHref = (params: Record<string, string> = {}) => `/book?${new URLSearchParams({ ...(tenant ? { tenant: tenant.slug } : {}), ...params })}`;
  const roomHref = (room: Room, property: Property) => bookHref({ property: property.slug, room: String(room.id) });
  const reserveHref = (requestType: string, room?: Room) => bookHref({ ...(requestType in requestTypes ? { type: requestType } : {}), ...(room ? { room: String(room.id) } : {}) });

  function reserve(event: FormEvent) {
    event.preventDefault();
    router.get('/book', { tenant: tenant?.slug, check_in: dates.start || undefined, check_out: dates.end || undefined, guests, type: type || undefined });
  }

  if (!c) return <GuestShell tenant={tenant}><div className="container py-24 text-center"><h1 className="ac-title">{name}</h1><p className="mt-4 text-slate">This website has not been set up yet.</p></div></GuestShell>;

  return <GuestShell tenant={tenant}>
    <Head title={`${name} | ${c.hero_eyebrow || 'Private resort'}`}>
      <meta name="description" content={tenant?.hero_description || c.footer_about}/>
    </Head>

    <div className="ac-home">

    <section className="ac-hero" aria-labelledby="ac-hero-title">
      <div className="ac-hero-image"><img src={heroImage} alt={name} fetchPriority="high"/></div>
      {(c.hero_card_image_1 || c.hero_card_image_2) && <div className="ac-hero-cards" aria-hidden="true">{[c.hero_card_image_1, c.hero_card_image_2].filter(Boolean).map(src => <img key={src} src={src} alt=""/>)}</div>}
      <div className="container">
        <div className="ac-hero-copy">
          {c.hero_eyebrow && <p className="ac-eyebrow center"><MapPinned aria-hidden="true"/>{c.hero_eyebrow}</p>}
          <h1 id="ac-hero-title" className="mt-4"><span>{first}</span>{second && <span className="ac-accent-text">{second}</span>}</h1>
          <Swash/>
          {tenant?.hero_description && <p className="ac-hero-sub">{tenant.hero_description}</p>}
          <div className="ac-hero-actions">
            {c.hero_primary_label && <Link href={bookHref()} className="ac-btn ac-btn-primary"><CalendarDays size={20}/> {c.hero_primary_label} <ChevronRight size={20}/></Link>}
            {c.hero_secondary_label && <a href={on('rooms') ? '#rooms' : '#reserve'} className="ac-btn ac-btn-outline"><BedDouble size={20}/> {c.hero_secondary_label}</a>}
          </div>
        </div>
      </div>
    </section>

    <section id="reserve" className="ac-reserve" aria-label="Reserve your stay">
      <div className="container">
        <form onSubmit={reserve} className="ac-reserve-form">
          <DateRangePicker split value={dates} onChange={setDates} label="Check-in to check-out"/>
          <label className="ac-select"><Users size={20}/><span><small>Guests</small><select value={guests} onChange={event => setGuests(Number(event.target.value))}>{Array.from({ length: maxGuests }, (_, i) => i + 1).map(n => <option key={n} value={n}>{n} {n === 1 ? 'guest' : 'guests'}</option>)}</select></span></label>
          <label className="ac-select"><CalendarCheck2 size={20}/><span><small>Event or stay</small><select value={type} onChange={event => setType(event.target.value)}>{Object.entries(requestTypes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></span></label>
          <button type="submit" className="ac-btn ac-btn-blue">{c.reserve_button || 'Reserve Now'} <ChevronRight size={20}/></button>
        </form>
        {c.reserve_note && <p className="ac-reserve-note">{c.reserve_note}</p>}
      </div>
    </section>

    {on('highlights') && c.highlights.length > 0 && <section className="ac-highlights" aria-label="Highlights"><div className="container"><Features items={c.highlights}/></div></section>}

    {on('rooms') && <section id="rooms" className="ac-section" aria-labelledby="ac-rooms-title">
      <div className="container">
        <div className="ac-section-head">
          <ArtHeading id="ac-rooms-title" src="/images/acvilla/ui/title-rooms.webp" width={581} height={178}>{c.rooms_eyebrow && <>{c.rooms_eyebrow}: </>}<Rich text={c.rooms_title}/></ArtHeading>
          <div>
            {c.rooms_text && <p className="ac-lead">{c.rooms_text}</p>}
            {stays.length > 0 && <p className="ac-from">Rooms from <strong>{money(Math.min(...stays.map(({ room }) => Number(room.base_rate))))}</strong> a night</p>}
          </div>
        </div>
        {stays.length > 0 && <div className="ac-rooms">{stays.map(({ room, property }) => <RoomCard key={room.id} room={room} property={property} href={roomHref(room, property)} onView={() => setViewing({ room, property })}/>)}</div>}
        {stays.length > 0 && c.rooms_note && <p className="ac-rooms-note"><span>{c.rooms_note}</span></p>}
      </div>
    </section>}

    {on('events') && <section id="events" className="ac-section ac-events ac-on-dark" aria-labelledby="ac-events-title">
      <div className="container">
        <div className="ac-events-grid">
          <div className="ac-events-copy">
            <ArtHeading id="ac-events-title" src="/images/acvilla/ui/title-events.webp" width={694} height={146}>{c.events_eyebrow && <>{c.events_eyebrow}: </>}<Rich text={c.events_title}/></ArtHeading>
            <div className="ac-heart-rule" aria-hidden="true"><Heart/></div>
            {c.events_text && <p className="ac-events-text">{c.events_text}</p>}
            <div className="ac-events-actions">
              {c.events_primary_label && <Link href={reserveHref('wedding', wholeResort)} className="ac-pill ac-pill-yellow"><CalendarDays/> {c.events_primary_label} <ChevronRight/></Link>}
              {c.events_secondary_label && <a href="#wedding-package" className="ac-pill ac-pill-navy"><Images/> {c.events_secondary_label} <ChevronRight/></a>}
            </div>
          </div>
          <div id="wedding-package" className="ac-wedding">
            <div className="sr-only">
              {c.package_kicker && <p>{c.package_kicker}</p>}
              <h3>{c.package_title}</h3>
              {c.package_subtitle && <p>{c.package_subtitle}</p>}
              {c.package_heading && <p>{c.package_heading}</p>}
              {c.package_note && <p>{c.package_note}</p>}
            </div>
            <img className="ac-wedding-art" src="/images/acvilla/ui/wedding-card.webp" alt="" aria-hidden="true" width={642} height={505} loading="lazy"/>
            {wholeResort && <p className="ac-wedding-price"><strong>{money(wholeResort.base_rate)}</strong>total package</p>}
            {c.package_includes && <p className="ac-wedding-includes">{c.package_includes}</p>}
            <div className="ac-wedding-actions">
              {c.package_button && <Link href={reserveHref('whole_resort', wholeResort)} className="ac-pill ac-pill-yellow"><CalendarDays/> {c.package_button} <ChevronRight/></Link>}
              {wholeResortItem && roomPhotos(wholeResortItem.room).length > 0 && <button type="button" onClick={() => setViewing(wholeResortItem)} className="ac-pill ac-pill-white"><Images/> View photos</button>}
            </div>
          </div>
        </div>
        {venues.length > 0 && <div id="venues" className="scroll-mt-28">
          <div className="ac-venues-head"><h3 className="ac-title-art ac-venues-art"><span className="sr-only"><Rich text={c.venues_title || 'Function Hall *Rates*'}/></span><img src="/images/acvilla/ui/title-venues.webp" alt="" aria-hidden="true" width={647} height={97} loading="lazy"/></h3>{c.venues_text && <p>{c.venues_text}</p>}</div>
          <div className="ac-rooms">{venues.map(({ room, property }) => <RoomCard key={room.id} room={room} property={property} href={roomHref(room, property)} onView={() => setViewing({ room, property })}/>)}</div>
        </div>}
      </div>
    </section>}

    {on('amenities') && amenities.length > 0 && <section id="amenities" className="ac-section" aria-labelledby="ac-amenities-title">
      <div className="container">
        <div className="ac-sign-head">
          {c.amenities_eyebrow && <p className="ac-sign-eyebrow">{c.amenities_eyebrow}</p>}
          <ArtHeading id="ac-amenities-title" src="/images/acvilla/ui/title-amenities.webp" width={1110} height={164}><Rich text={c.amenities_title}/></ArtHeading>
          {c.amenities_text && <p className="ac-sign-text">{c.amenities_text}</p>}
        </div>
        <ul className="ac-amenity-cards">{amenities.map(item => { const icon = amenityIcon(item); return <li key={item}>{icon ? <img src={`/images/acvilla/ui/amenities/${icon}.webp`} alt="" aria-hidden="true"/> : <Sparkles aria-hidden="true" className="text-[#0039D5]"/>}<span>{amenityLabel(item)}</span></li>; })}</ul>
        {tenant?.settings && <div className="ac-stay-times"><p>Check-in from {time12(tenant.settings.check_in_time)}<i aria-hidden="true">•</i>Check-out by {time12(tenant.settings.check_out_time)}</p></div>}
      </div>
    </section>}

    {on('gallery') && c.gallery.length > 0 && <section id="gallery" className="ac-section" aria-labelledby="ac-gallery-title">
      <div className="container">
        <div className="ac-gallery-head">
          <ArtHeading id="ac-gallery-title" src="/images/acvilla/ui/title-gallery.webp" width={870} height={139}>{c.gallery_eyebrow && <>{c.gallery_eyebrow}: </>}<Rich text={c.gallery_title}/></ArtHeading>
          {c.gallery_text && <p className="ac-gallery-note"><Camera aria-hidden="true"/>{c.gallery_text}</p>}
        </div>
        <ImageGallery className="ac-gallery-grid" imageClassName="ac-gallery-img" renderCaption={caption => { const Icon = captionIcon(caption); return <span className="ac-gallery-caption"><Icon aria-hidden="true"/>{caption}</span>; }} items={c.gallery.map(item => ({ src: item.image, alt: item.caption || name, caption: item.caption || undefined }))}/>
      </div>
    </section>}

    {on('steps') && c.steps.length > 0 && <section className="ac-section" aria-labelledby="ac-how-title">
      <div className="container">
        <div className="mx-auto max-w-2xl text-center">{c.steps_eyebrow && <p className="ac-eyebrow center justify-center">{c.steps_eyebrow}</p>}<h2 id="ac-how-title" className="ac-title mt-4"><Rich text={c.steps_title}/></h2>{c.steps_text && <p className="ac-lead mt-4">{c.steps_text}</p>}</div>
        <ol className="ac-steps">{c.steps.map((step, i) => <li key={i}><span><SiteIcon name={step.icon} size={24}/></span><h3>{step.title}</h3>{step.body && <p>{step.body}</p>}</li>)}</ol>
      </div>
    </section>}

    {on('contact') && <section id="contact" className="ac-section" aria-labelledby="ac-contact-title">
      <div className="container ac-contact">
        <div className="ac-contact-col">
          <ArtHeading id="ac-contact-title" src="/images/acvilla/ui/title-contact.webp" width={648} height={137}>{c.contact_eyebrow && <>{c.contact_eyebrow}: </>}<Rich text={c.contact_title}/></ArtHeading>
          <div className="ac-contact-card">
            <ul>
              {tenant?.business_address && <li><span><MapPin/></span><div><small>Address</small><p className="ac-contact-value ac-contact-address">{tenant.business_address}</p>{c.location_note && <p className="ac-contact-note">{c.location_note}</p>}</div></li>}
              {(tenant?.business_phone || c.phone_alt) && <li><span><Phone/></span><div><small>Call or text</small><div className="ac-contact-phones">{[tenant?.business_phone, c.phone_alt].filter(Boolean).map(phone => <a key={phone} href={`tel:${phone!.replace(/\s/g, '')}`} className="ac-contact-value">{phone}</a>)}</div></div></li>}
              {c.facebook_url && <li><span><Facebook/></span><div><small>Facebook page</small><a href={c.facebook_url} target="_blank" rel="noreferrer" className="ac-contact-value">Message us on Facebook</a></div></li>}
              {tenant?.business_email && <li><span><Mail/></span><div><small>Email</small><a href={`mailto:${tenant.business_email}`} className="ac-contact-value">{tenant.business_email}</a></div></li>}
            </ul>
            {c.map_url && <a href={c.map_url} target="_blank" rel="noreferrer" className="ac-pill ac-pill-navy ac-contact-maps"><MapPinned/> Open in Google Maps <ChevronRight/></a>}
            <img src="/images/acvilla/ui/flower-double.png" alt="" aria-hidden="true" className="ac-contact-flower" loading="lazy"/>
          </div>
        </div>
        {c.contact_image && <div className="ac-contact-photo"><img src={c.contact_image} alt="" loading="lazy"/>{(c.contact_photo_title || c.contact_photo_text) && <div>{c.contact_photo_title && <p className="ac-contact-photo-title">{c.contact_photo_title}</p>}{c.contact_photo_text && <p className="ac-contact-photo-text">{c.contact_photo_text}</p>}</div>}</div>}
      </div>
      {c.map_pin && <div className="container"><div className="ac-map">
        <iframe title={`Map showing where ${name} is`} src={`https://maps.google.com/maps?q=${encodeURIComponent(c.map_pin)}&z=16&output=embed`} loading="lazy" referrerPolicy="no-referrer-when-downgrade" allowFullScreen/>
        <div className="ac-map-card">
          <span className="ac-map-pin"><MapPin size={22}/></span>
          <div className="min-w-0">
            <p className="ac-map-name">{name}</p>
            {tenant?.business_address && <p className="ac-map-address">{tenant.business_address}</p>}
            <a href={`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(c.map_pin)}`} target="_blank" rel="noreferrer" className="ac-pill ac-pill-navy"><Navigation/> Get directions <ChevronRight/></a>
          </div>
        </div>
      </div></div>}
    </section>}

    {on('final') && <section className="ac-final" aria-labelledby="ac-final-title">
      <div className="container">
        <div className="ac-final-sign"><ArtHeading id="ac-final-title" src="/images/acvilla/ui/title-final.webp" width={827} height={141}>{c.final_eyebrow && <>{c.final_eyebrow}: </>}<Rich text={c.final_title}/></ArtHeading></div>
        {c.final_text && <p className="ac-final-text">{c.final_text}</p>}
        {c.final_button && <div className="ac-final-action"><Link href={bookHref()} className="ac-pill ac-pill-yellow">{c.final_button} <ChevronRight/></Link></div>}
      </div>
    </section>}

    </div>

    <RoomDialog room={viewing?.room ?? null} fallbackPhoto={viewing?.property.cover_image} reserveHref={viewing ? roomHref(viewing.room, viewing.property) : undefined} open={!!viewing} onOpenChange={next => { if (!next) setViewing(null); }}/>
  </GuestShell>;
}

function Features({ items }: { items: IconItem[] }) {
  return <div className="ac-features" style={{ '--ac-cols': Math.min(items.length, 4) } as React.CSSProperties}>{items.map((item, i) => <div key={i}><SiteIcon name={item.icon} aria-hidden/><strong>{item.label}</strong></div>)}</div>;
}

function Swash() {
  return <svg className="ac-swash" viewBox="0 0 320 16" fill="none" aria-hidden="true"><defs><linearGradient id="ac-swash" x1="0" x2="320" y1="0" y2="0" gradientUnits="userSpaceOnUse"><stop stopColor="#FFC800"/><stop offset="1" stopColor="#FFD84D"/></linearGradient></defs><path d="M2 13C80 4 200 1 318 7" stroke="url(#ac-swash)" strokeWidth="3" strokeLinecap="round"/><path d="M50 15c70-5 150-6 230-3" stroke="url(#ac-swash)" strokeWidth="1.2" strokeLinecap="round" opacity=".55"/></svg>;
}

/** A section heading drawn as a wooden sign; the words stay in the page for screen readers and search. */
function ArtHeading({ id, src, width, height, children }: { id: string; src: string; width: number; height: number; children: React.ReactNode }) {
  return <h2 id={id} className="ac-title-art"><span className="sr-only">{children}</span><img src={src} alt="" aria-hidden="true" width={width} height={height} loading="lazy"/></h2>;
}

/** A room, hall or package: the photo and title open its photo browser; Reserve goes straight to the booking form. */
function RoomCard({ room, property, href, onView }: { room: Room; property: Property; href: string; onView: () => void }) {
  const photos = roomPhotos(room, property.cover_image);
  const capacity = room.kind === 'venue' ? `Up to ${room.maximum_occupancy} guests` : room.whole_property ? `Whole resort · up to ${room.maximum_occupancy} guests` : `Good for ${includedGuests(room)} pax`;
  return <article className="ac-room">
    <button type="button" className="ac-room-img" onClick={onView} aria-label={`View ${photos.length} photos of ${room.name}`}>
      {photos[0] && <img src={photos[0]} alt="" loading="lazy"/>}
      <span>{capacity}</span>
      {photos.length > 1 && <em className="ac-room-photos"><Images size={14}/> {photos.length}</em>}
      <i className="ac-room-view" aria-hidden="true"><Images size={16}/> View photos</i>
    </button>
    <div className="ac-room-body">
      <h3><button type="button" onClick={onView} className="text-left">{room.name}</button></h3>
      <p className="line-clamp-2">{room.description || property.short_description}</p>
      {(room.amenities?.length ?? 0) > 0 && <div className="ac-room-meta">{room.amenities!.slice(0, 3).map(item => { const Icon = roomIcon(item); return <span key={item}><Icon aria-hidden="true"/>{item}</span>; })}</div>}
      <div className="ac-room-foot">
        <div><strong>{money(room.base_rate)}</strong> <small>{rateUnit(room)}</small></div>
        <Link href={href} className="go">Reserve <ChevronRight size={17}/></Link>
      </div>
    </div>
  </article>;
}
