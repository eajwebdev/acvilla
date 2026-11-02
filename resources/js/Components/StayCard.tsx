import { Link } from '@inertiajs/react';
import { ArrowUpRight, MapPin } from 'lucide-react';
import type { Property } from '../types';
import { money } from '../types';

export default function StayCard({ property, href }: { property: Property; href?: string }) {
  const nightly = property.rooms.filter(room => room.kind !== 'venue' && !room.whole_property);
  const lowest = (nightly.length ? nightly : property.rooms).reduce((min, room) => Math.min(min, Number(room.base_rate)), Infinity);
  return <Link href={href || `/stays/${property.slug}`} className="surface-card interactive group flex flex-col overflow-hidden">
    <div className="relative h-60 overflow-hidden bg-slate-200">
      {property.cover_image && <img src={property.cover_image} alt={property.name} loading="lazy" className="h-full w-full object-cover transition duration-700 group-hover:scale-105"/>}
      {Number.isFinite(lowest) && <span className="absolute bottom-3 left-3 rounded-full bg-white/90 px-3 py-1.5 text-xs font-bold text-navy shadow backdrop-blur">From {money(lowest)} / night</span>}
    </div>
    <div className="flex flex-1 flex-col p-6">
      <div className="flex items-start justify-between gap-3"><h3 className="text-xl font-bold tracking-[-.015em] text-navy">{property.name}</h3><span className="grid size-8 shrink-0 place-items-center rounded-full bg-[var(--surface-muted)] transition group-hover:rotate-45" style={{ color: 'var(--guest-accent)' }}><ArrowUpRight size={17}/></span></div>
      <p className="mt-2 flex items-center gap-1 text-sm text-slate"><MapPin size={14}/>{property.city}, {property.province}</p>
      <p className="mt-4 line-clamp-2 text-sm leading-6 text-slate">{property.short_description}</p>
      <p className="mt-auto pt-5 text-sm font-semibold" style={{ color: 'var(--guest-accent)' }}>View stay →</p>
    </div>
  </Link>;
}
