import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowRight, ArrowUpRight, MapPin, Search } from 'lucide-react';
import { useState } from 'react';
import DateRangePicker, { type DateRange } from '../../Components/DateRangePicker';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';
import { money, type Property } from '../../types';
import '../../../css/stays.css';

type Filters = { location?: string; check_in?: string; check_out?: string; guests?: number };
type PageLink = { url: string | null; label: string; active: boolean };

export default function Stays({ tenant, properties, filters }: {
  tenant: SiteTenant;
  properties: { data: Property[]; links: PageLink[] };
  filters: Filters;
}) {
  const [location, setLocation] = useState(filters.location || '');
  const [dates, setDates] = useState<DateRange>({ start: filters.check_in || '', end: filters.check_out || '' });
  const [guests, setGuests] = useState(filters.guests || 2);
  const featured = properties.data.find(property => property.cover_image);
  const heroImage = tenant?.hero_image || featured?.cover_image || '/images/acvilla/hero-mabinay.jpg';
  const stayQuery = new URLSearchParams({
    check_in: filters.check_in || '',
    check_out: filters.check_out || '',
    guests: String(filters.guests || 2),
  }).toString();
  const homeUrl = tenant ? `/stays?tenant=${encodeURIComponent(tenant.slug)}` : '/stays';

  function search(event: React.FormEvent) {
    event.preventDefault();
    router.get('/stays', { tenant: tenant?.slug, location, check_in: dates.start, check_out: dates.end, guests });
  }

  return <GuestShell tenant={tenant}><div className="stays-landing">
    <Head title={`${tenant?.business_name || 'AC Villa & Private Resort'} | Rooms & Villas`}/>
    <Head><meta name="description" content={`Explore the villas and rooms at ${tenant?.business_name || 'AC Villa & Private Resort'} and send a reservation request.`}/></Head>

      <section className="sl-hero" aria-labelledby="sl-hero-title">
        <div className="sl-hero-copy">
          <p className="sl-eyebrow">{tenant?.business_name || 'AC Villa'} <span>/</span> ROOMS &amp; VILLAS</p>
          <h1 id="sl-hero-title">Stay in our<br/><em>cozy villas.</em></h1>
          <p className="sl-hero-description">Experience comfort and relaxation in our well-appointed villas, designed for a truly restful stay.</p>
          <div className="sl-hero-actions">
            <a href="#find-stay" className="sl-button sl-button-accent">Reserve your stay <ArrowUpRight size={18}/></a>
            <a href="#collection" className="sl-text-link">See the villas <ArrowDown size={17}/></a>
          </div>
          <div className="sl-hero-index"><span>MABINAY · NEGROS ORIENTAL</span><span>PRIVATE RESORT</span></div>
        </div>
        <div className="sl-hero-visual">
          <img src={heroImage} alt=""/>
          {featured && <div className="sl-image-caption"><span>FEATURED</span><strong>{featured.name}</strong><small>{featured.city}, {featured.province}</small></div>}
        </div>
      </section>

      <section id="find-stay" className="sl-search-wrap" aria-labelledby="sl-search-title">
        <div className="sl-search-intro"><div><p className="sl-eyebrow">CHECK AVAILABILITY</p><h2 id="sl-search-title">When are you coming?</h2></div><p>Choose your dates to see which villas are free.</p></div>
        <form onSubmit={search} className="sl-search-form">
          <label className="sl-field">Search<input value={location} onChange={event => setLocation(event.target.value)} placeholder="Villa or room"/></label>
          <DateRangePicker value={dates} onChange={setDates} label="Stay dates" className="sl-date-trigger"/>
          <label className="sl-field">Guests<input type="number" min="1" max="20" value={guests} onChange={event => setGuests(Number(event.target.value))}/></label>
          <button type="submit" className="sl-button sl-button-dark"><Search size={18}/> Check dates</button>
        </form>
      </section>

      <section id="collection" className="sl-collection" aria-labelledby="sl-collection-title">
        <div className="sl-section-head"><div><p className="sl-eyebrow">ROOMS &amp; VILLAS</p><h2 id="sl-collection-title">Your private escape.</h2></div><p>A-frame villas, family rooms and the whole resort for weddings and celebrations.</p></div>
        {properties.data.length ? <div className="sl-property-grid">{properties.data.map((property, index) => <LandingStayCard key={property.id} property={property} href={`/stays/${property.slug}?${stayQuery}`} index={index + 1}/>)}</div> : <div className="sl-empty"><p className="sl-eyebrow">NO MATCHES YET</p><h3>Nothing is free for these dates.</h3><p>Try other dates, or call us. We may still be able to help.</p><Link href={homeUrl} className="sl-button sl-button-dark">View all villas <ArrowRight size={17}/></Link></div>}
        {properties.links?.length > 3 && <nav aria-label="Pagination" className="sl-pagination">{properties.links.map((link, index) => link.url ? <Link key={index} href={link.url} className={link.active ? 'is-active' : ''} dangerouslySetInnerHTML={{ __html: link.label }}/> : null)}</nav>}
      </section>

      <section className="sl-how" aria-labelledby="sl-how-title"><div className="sl-how-heading"><p className="sl-eyebrow">HOW BOOKING WORKS</p><h2 id="sl-how-title">Request,<br/>we call, relax.</h2><p>No payment online. The owner confirms every booking personally.</p></div><div className="sl-steps"><div><span>01</span><h3>Choose your villa.</h3><p>Pick your dates, room and the kind of stay or event you are planning.</p></div><div><span>02</span><h3>Send your request.</h3><p>Leave your name and mobile number. Your dates are held while we get in touch.</p></div><div><span>03</span><h3>We call to confirm.</h3><p>The owner calls you to confirm the booking and arrange payment.</p></div></div></section>

      <section className="sl-final" aria-labelledby="sl-final-title"><p className="sl-eyebrow">YOUR PRIVACY, MORE MEMORIES, MORE LOVE</p><h2 id="sl-final-title">Ready to book<br/><em>your stay?</em></h2><a href="#find-stay" className="sl-button sl-button-accent">Reserve now <ArrowUpRight size={18}/></a></section>
  </div></GuestShell>;
}

function LandingStayCard({ property, href, index }: { property: Property; href: string; index: number }) {
  const lowest = property.rooms.length ? Math.min(...property.rooms.map(room => Number(room.base_rate))) : 0;
  return <Link href={href} className="sl-stay-card">
    <div className="sl-stay-image">{property.cover_image ? <img src={property.cover_image} alt={property.name} loading="lazy"/> : <div className="sl-image-fallback"/>}<span>{String(index).padStart(2, '0')} / VILLA</span></div>
    <div className="sl-stay-details"><div><p><MapPin size={14}/>{property.city}, {property.province}</p><h3>{property.name}</h3><span>{property.short_description}</span></div><div className="sl-stay-price"><small>FROM / NIGHT</small><strong>{money(lowest)}</strong><ArrowUpRight size={19}/></div></div>
  </Link>;
}
