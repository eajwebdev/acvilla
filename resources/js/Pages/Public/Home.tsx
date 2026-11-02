import { Head, Link, router } from '@inertiajs/react';
import { AirVent, BedDouble, CalendarCheck2, CalendarDays, Car, ChevronRight, Flower2, Navigation, Gem, HeartHandshake, Images, Mail, MapPin, MapPinned, Music, PartyPopper, Phone, ShowerHead, Sparkles, Sunrise, Users, Utensils, Waves, Wifi, type LucideIcon } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { BrandMark } from '../../Components/Brand';
import DateRangePicker, { type DateRange } from '../../Components/DateRangePicker';
import GuestShell, { Facebook, type SiteTenant } from '../../Components/GuestShell';
import ImageGallery from '../../Components/ImageGallery';
import { RoomDialog, roomPhotos } from '../../Components/RoomShowcase';
import { Rich, SiteIcon, type IconItem, type SiteContent } from '../../Components/siteContent';
import { includedGuests, money, rateUnit, time12, type Property, type Room } from '../../types';

const AMENITY_ICONS: [RegExp, LucideIcon][] = [[/pool|swim/i, Waves], [/air|aircon/i, AirVent], [/wi-?fi/i, Wifi], [/shower|bath/i, ShowerHead], [/park/i, Car],
  [/hall|function|event/i, PartyPopper], [/videoke|karaoke|tv/i, Music], [/kitchen|breakfast|food|coffee/i, Utensils], [/garden/i, Flower2], [/lounge|balcony|view/i, Sunrise], [/towel|linen|bed/i, BedDouble]];
const amenityIcon = (name: string) => AMENITY_ICONS.find(([pattern]) => pattern.test(name))?.[1] ?? Sparkles;

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
      <meta property="og:title" content={`${name} | ${tenant?.hero_headline || 'Your private escape'}`}/>
      <meta property="og:description" content={tenant?.hero_description || c.footer_tagline}/>
      <meta property="og:image" content={heroImage}/>
    </Head>

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
          <div>
            {c.rooms_eyebrow && <p className="ac-eyebrow">{c.rooms_eyebrow}</p>}
            <h2 id="ac-rooms-title" className="ac-title mt-4 [text-wrap:balance]"><Rich text={c.rooms_title.replace(/(\w)-(\w)/g, '$1‑$2')}/></h2>
          </div>
          <div>
            {c.rooms_text && <p className="ac-lead">{c.rooms_text}</p>}
            {stays.length > 0 && <p className="ac-from">Rooms from <strong>{money(Math.min(...stays.map(({ room }) => Number(room.base_rate))))}</strong> a night</p>}
          </div>
        </div>
        {stays.length > 0 && <div className="ac-rooms">{stays.map(({ room, property }) => <RoomCard key={room.id} room={room} property={property} href={roomHref(room, property)} onView={() => setViewing({ room, property })}/>)}</div>}
        {stays.length > 0 && c.rooms_note && <p className="ac-rooms-note">{c.rooms_note}</p>}
      </div>
    </section>}

    {on('events') && <section id="events" className="ac-section ac-events ac-on-dark" aria-labelledby="ac-events-title">
      {c.events_image && <img className="ac-events-bg" src={c.events_image} alt="" loading="lazy"/>}
      <div className="container">
        <div className="ac-events-grid">
          <div>
            {c.events_eyebrow && <p className="ac-eyebrow">{c.events_eyebrow}</p>}
            <h2 id="ac-events-title" className="ac-title mt-4"><Rich text={c.events_title}/></h2>
            <div className="ac-divider mt-5 justify-start"><HeartHandshake size={30} strokeWidth={1.4}/></div>
            {c.events_text && <p className="mt-5 max-w-lg text-lg italic leading-8 text-white/80">{c.events_text}</p>}
            <div className="mt-8 flex flex-wrap gap-3">{c.events_primary_label && <Link href={reserveHref('wedding', wholeResort)} className="ac-btn ac-btn-primary">{c.events_primary_label} <ChevronRight size={20}/></Link>}{c.events_secondary_label && <a href="#wedding-package" className="ac-btn ac-btn-light">{c.events_secondary_label} <ChevronRight size={20}/></a>}</div>
          </div>
          <div id="wedding-package" className="ac-package">
            <BrandMark className="mx-auto h-14"/>
            {c.package_kicker && <p className="ac-package-kicker mt-3">{c.package_kicker}</p>}
            <h3 className="ac-accent-text not-italic uppercase">{c.package_title}</h3>
            {c.package_subtitle && <p className="ac-package-sub">{c.package_subtitle}</p>}
            <div className="ac-divider mt-3"><Gem size={20} strokeWidth={1.5}/></div>
            {c.package_heading && <p className="ac-package-rent">{c.package_heading}</p>}
            {c.package_note && <p className="ac-package-note">{c.package_note}</p>}
            {wholeResort && <p className="mt-4 font-serif text-2xl font-semibold text-navy">{money(wholeResort.base_rate)} <span className="text-sm font-semibold text-slate">total package</span></p>}
            {c.package_includes && <p className="mt-2 text-sm text-slate">{c.package_includes}</p>}
            <div className="mt-6 flex flex-wrap justify-center gap-3">
              {c.package_button && <Link href={reserveHref('whole_resort', wholeResort)} className="ac-btn ac-btn-primary">{c.package_button} <ChevronRight size={20}/></Link>}
              {wholeResortItem && roomPhotos(wholeResortItem.room).length > 0 && <button type="button" onClick={() => setViewing(wholeResortItem)} className="ac-btn ac-btn-outline"><Images size={18}/> View photos</button>}
            </div>
          </div>
        </div>
        {venues.length > 0 && <div id="venues" className="mt-16 scroll-mt-28">
          <div className="flex flex-wrap items-end justify-between gap-4"><h3 className="ac-title ac-venues-title"><Rich text={c.venues_title || 'Function Hall *Rates*'}/></h3>{c.venues_text && <p className="max-w-md text-sm leading-6 text-white/75">{c.venues_text}</p>}</div>
          <div className="ac-rooms !mt-8">{venues.map(({ room, property }) => <RoomCard key={room.id} room={room} property={property} href={roomHref(room, property)} onView={() => setViewing({ room, property })}/>)}</div>
        </div>}
      </div>
    </section>}

    {on('amenities') && amenities.length > 0 && <section id="amenities" className="ac-section" aria-labelledby="ac-amenities-title">
      <div className="container">
        <div className="mx-auto max-w-2xl text-center">{c.amenities_eyebrow && <p className="ac-eyebrow center justify-center">{c.amenities_eyebrow}</p>}<h2 id="ac-amenities-title" className="ac-title mt-4"><Rich text={c.amenities_title}/></h2>{c.amenities_text && <p className="ac-lead mt-4">{c.amenities_text}</p>}</div>
        <ul className="ac-amenities">{amenities.map(item => { const Icon = amenityIcon(item); return <li key={item}><span><Icon size={18}/></span>{item}</li>; })}</ul>
        {tenant?.settings && <p className="mt-6 text-center text-sm text-slate">Check-in from {time12(tenant.settings.check_in_time)} · Check-out by {time12(tenant.settings.check_out_time)}</p>}
      </div>
    </section>}

    {on('gallery') && c.gallery.length > 0 && <section id="gallery" className="ac-section ac-band" aria-labelledby="ac-gallery-title">
      <div className="container">
        <div className="flex flex-wrap items-end justify-between gap-4"><div>{c.gallery_eyebrow && <p className="ac-eyebrow">{c.gallery_eyebrow}</p>}<h2 id="ac-gallery-title" className="ac-title mt-4"><Rich text={c.gallery_title}/></h2></div>{c.gallery_text && <p className="max-w-sm text-sm leading-6 text-slate">{c.gallery_text}</p>}</div>
        <ImageGallery className="mt-10 grid grid-cols-2 gap-3 md:grid-cols-3" imageClassName="h-44 sm:h-60 rounded-2xl" items={c.gallery.map(item => ({ src: item.image, alt: item.caption || name, caption: item.caption || undefined }))}/>
      </div>
    </section>}

    {on('steps') && c.steps.length > 0 && <section className="ac-section" aria-labelledby="ac-how-title">
      <div className="container">
        <div className="mx-auto max-w-2xl text-center">{c.steps_eyebrow && <p className="ac-eyebrow center justify-center">{c.steps_eyebrow}</p>}<h2 id="ac-how-title" className="ac-title mt-4"><Rich text={c.steps_title}/></h2>{c.steps_text && <p className="ac-lead mt-4">{c.steps_text}</p>}</div>
        <ol className="ac-steps">{c.steps.map((step, i) => <li key={i}><span><SiteIcon name={step.icon} size={24}/></span><h3>{step.title}</h3>{step.body && <p>{step.body}</p>}</li>)}</ol>
      </div>
    </section>}

    {on('contact') && <section id="contact" className="ac-section ac-band" aria-labelledby="ac-contact-title">
      <div className="container ac-contact">
        <div className="ac-contact-card">
          {c.contact_eyebrow && <p className="ac-eyebrow">{c.contact_eyebrow}</p>}
          <h2 id="ac-contact-title" className="ac-title mt-4"><Rich text={c.contact_title}/></h2>
          <ul>
            {tenant?.business_address && <li><span><MapPin size={19}/></span><div><small>Address</small><p className="mt-1 font-semibold">{tenant.business_address}</p>{c.location_note && <p className="text-sm text-slate">{c.location_note}</p>}</div></li>}
            {(tenant?.business_phone || c.phone_alt) && <li><span><Phone size={19}/></span><div><small>Call or text</small>{[tenant?.business_phone, c.phone_alt].filter(Boolean).map(phone => <a key={phone} href={`tel:${phone!.replace(/\s/g, '')}`} className="mt-1 block font-semibold">{phone}</a>)}</div></li>}
            {c.facebook_url && <li><span><Facebook size={19}/></span><div><small>Facebook page</small><a href={c.facebook_url} target="_blank" rel="noreferrer" className="mt-1 block font-semibold">Message us on Facebook</a></div></li>}
            {tenant?.business_email && <li><span><Mail size={19}/></span><div><small>Email</small><a href={`mailto:${tenant.business_email}`} className="mt-1 block font-semibold">{tenant.business_email}</a></div></li>}
          </ul>
          {c.map_url && <a href={c.map_url} target="_blank" rel="noreferrer" className="ac-btn ac-btn-outline mt-8">Open in Google Maps <ChevronRight size={20}/></a>}
        </div>
        {c.contact_image && <div className="ac-contact-photo"><img src={c.contact_image} alt="" loading="lazy"/>{(c.contact_photo_title || c.contact_photo_text) && <div>{c.contact_photo_title && <p className="font-serif text-lg font-semibold">{c.contact_photo_title}</p>}{c.contact_photo_text && <p className="text-sm text-white/75">{c.contact_photo_text}</p>}</div>}</div>}
      </div>
      {c.map_pin && <div className="container"><div className="ac-map">
        <iframe title={`Map showing where ${name} is`} src={`https://maps.google.com/maps?q=${encodeURIComponent(c.map_pin)}&z=16&output=embed`} loading="lazy" referrerPolicy="no-referrer-when-downgrade" allowFullScreen/>
        <div className="ac-map-card">
          <span className="ac-map-pin"><MapPin size={20}/></span>
          <div className="min-w-0">
            <p className="font-serif text-xl font-semibold text-navy">{name}</p>
            {tenant?.business_address && <p className="mt-1 text-sm text-slate">{tenant.business_address}</p>}
            <a href={`https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(c.map_pin)}`} target="_blank" rel="noreferrer" className="ac-map-go"><Navigation size={16}/> Get directions</a>
          </div>
        </div>
      </div></div>}
    </section>}

    {on('final') && <section className="ac-final" aria-labelledby="ac-final-title">
      {c.final_image && <img src={c.final_image} alt="" loading="lazy"/>}
      <div className="container">
        {c.final_eyebrow && <p className="ac-eyebrow center justify-center !text-[#ADD3EF]">{c.final_eyebrow}</p>}
        <h2 id="ac-final-title"><Rich text={c.final_title}/></h2>
        {c.final_text && <p className="mx-auto mt-4 max-w-md text-white/75">{c.final_text}</p>}
        {c.final_button && <Link href={bookHref()} className="ac-btn ac-btn-primary mt-8">{c.final_button} <ChevronRight size={20}/></Link>}
      </div>
    </section>}

    <RoomDialog room={viewing?.room ?? null} fallbackPhoto={viewing?.property.cover_image} reserveHref={viewing ? roomHref(viewing.room, viewing.property) : undefined} open={!!viewing} onOpenChange={next => { if (!next) setViewing(null); }}/>
  </GuestShell>;
}

function Features({ items }: { items: IconItem[] }) {
  return <div className="ac-features" style={{ '--ac-cols': Math.min(items.length, 4) } as React.CSSProperties}>{items.map((item, i) => <div key={i}><SiteIcon name={item.icon} aria-hidden/><strong>{item.label}</strong></div>)}</div>;
}

function Swash() {
  return <svg className="ac-swash" viewBox="0 0 320 16" fill="none" aria-hidden="true"><defs><linearGradient id="ac-swash" x1="0" x2="320" y1="0" y2="0" gradientUnits="userSpaceOnUse"><stop stopColor="#0F6A1C"/><stop offset=".5" stopColor="#448810"/><stop offset="1" stopColor="#0070C0"/></linearGradient></defs><path d="M2 13C80 4 200 1 318 7" stroke="url(#ac-swash)" strokeWidth="3" strokeLinecap="round"/><path d="M50 15c70-5 150-6 230-3" stroke="url(#ac-swash)" strokeWidth="1.2" strokeLinecap="round" opacity=".55"/></svg>;
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
      {(room.amenities?.length ?? 0) > 0 && <div className="ac-room-meta">{room.amenities!.slice(0, 3).map(item => <span key={item}>{item}</span>)}</div>}
      <div className="ac-room-foot">
        <div><strong>{money(room.base_rate)}</strong> <small>{rateUnit(room)}</small></div>
        <Link href={href} className="go">Reserve <ChevronRight size={17}/></Link>
      </div>
    </div>
  </article>;
}
