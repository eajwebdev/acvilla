import { AirVent, BedDouble, CalendarCheck2, Camera, Car, Flower2, Gem, Heart, House, Music, PartyPopper, PhoneCall, Sparkles, Star, Sun, Sunrise, Tent, TreePalm, Users, Utensils, Waves, Wifi, ShowerHead, Wine, type LucideIcon } from 'lucide-react';
import { Fragment } from 'react';

export type IconItem = { icon: string; label: string };
export type Option = { key: string; label: string };
/** The guest website's editable content (Admin → Website content); see App\Services\SiteContent. */
export type SiteContent = {
  sections: Record<'highlights' | 'rooms' | 'events' | 'amenities' | 'gallery' | 'steps' | 'contact' | 'final', boolean>;
  hero_eyebrow: string; hero_card_image_1: string; hero_card_image_2: string; hero_primary_label: string; hero_secondary_label: string; reserve_button: string; reserve_note: string; highlights: IconItem[];
  rooms_eyebrow: string; rooms_title: string; rooms_text: string; rooms_image: string; rooms_inset_image: string; rooms_features: IconItem[]; rooms_primary_label: string; rooms_secondary_label: string;
  rooms_note: string; venues_title: string; venues_text: string;
  events_eyebrow: string; events_title: string; events_text: string; events_image: string; events_primary_label: string; events_secondary_label: string;
  package_kicker: string; package_title: string; package_subtitle: string; package_heading: string; package_note: string; package_includes: string; package_button: string;
  events_features: IconItem[]; events_tagline: string;
  amenities_eyebrow: string; amenities_title: string; amenities_text: string;
  gallery_eyebrow: string; gallery_title: string; gallery_text: string; gallery: { image: string; caption: string }[];
  steps_eyebrow: string; steps_title: string; steps_text: string; steps: { icon: string; title: string; body: string }[]; steps_button: string;
  contact_eyebrow: string; contact_title: string; location_note: string; map_url: string; map_pin: string; contact_image: string; contact_photo_title: string; contact_photo_text: string;
  final_eyebrow: string; final_title: string; final_text: string; final_button: string; final_image: string;
  footer_tagline: string; footer_about: string; phone_alt: string; facebook_url: string;
  request_types: Option[]; call_times: Option[];
};

export const ICONS: Record<string, LucideIcon> = { waves: Waves, 'a-frame': Tent, diamond: Gem, bed: BedDouble, aircon: AirVent, sunrise: Sunrise, palm: TreePalm,
  glass: Wine, users: Users, heart: Heart, party: PartyPopper, sparkles: Sparkles, music: Music, car: Car, food: Utensils, flower: Flower2, wifi: Wifi,
  shower: ShowerHead, calendar: CalendarCheck2, phone: PhoneCall, home: House, camera: Camera, star: Star, sun: Sun };

export function SiteIcon({ name, ...props }: { name: string; size?: number; className?: string; 'aria-hidden'?: boolean }) {
  const Icon = ICONS[name] ?? Sparkles;
  return <Icon {...props}/>;
}

/** Owner-written titles: words between *asterisks* show in gold italics. */
export function Rich({ text }: { text: string }) {
  return <>{text.split(/\*([^*]+)\*/).map((part, i) => i % 2 ? <em key={i}>{part}</em> : <Fragment key={i}>{part}</Fragment>)}</>;
}

/** Uploads a photo to /admin/website/images and resolves to its address. */
export async function uploadImage(file: File): Promise<string> {
  const token = decodeURIComponent(document.cookie.split('; ').find(c => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
  const body = new FormData();
  body.append('image', file);
  const response = await fetch('/admin/website/images', { method: 'POST', body, headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' }, credentials: 'same-origin' });
  const json = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(json?.errors?.image?.[0] || json?.message || 'Upload failed. Use a JPG, PNG or WebP photo up to 8 MB.');
  return json.url as string;
}
