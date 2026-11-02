import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ExternalLink, Plus, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import ImageInput from '../../Components/ImageInput';
import SettingsLayout from '../../Components/SettingsLayout';
import { SiteIcon, type IconItem, type Option, type SiteContent } from '../../Components/siteContent';

type Hero = { slug: string; hero_headline: string | null; hero_description: string | null; hero_image: string | null };
type Form = SiteContent & { hero_headline: string; hero_description: string; hero_image: string };
type TextKey = { [K in keyof SiteContent]: SiteContent[K] extends string ? K : never }[keyof SiteContent];

const SECTION_LABELS: Record<keyof SiteContent['sections'], string> = { highlights: 'Highlights row under the booking bar', rooms: 'Rooms & Villas', events: 'Events & Weddings', amenities: 'Amenities',
  gallery: 'Gallery', steps: 'How booking works', contact: 'Contact & location', final: 'Closing “Ready to book” banner' };

/** Admin → Website content: every text, photo, icon and option on the guest website, grouped by page section. */
export default function Website({ content, defaults, icons, hero }: { content: SiteContent; defaults: SiteContent; icons: string[]; hero: Hero }) {
  const form = useForm<Form>({ ...content, hero_headline: hero.hero_headline ?? '', hero_description: hero.hero_description ?? '', hero_image: hero.hero_image ?? '' });
  const d = form.data;
  const errors = form.errors as Record<string, string>;
  const set = <K extends keyof Form>(key: K, value: Form[K]) => form.setData(key, value);
  const text = (key: TextKey, label: string, opts: { long?: boolean; hint?: string } = {}) => <label className="field">{label}
    {opts.long ? <textarea rows={3} value={d[key]} placeholder={defaults[key]} onChange={e => set(key, e.target.value)}/> : <input value={d[key]} placeholder={defaults[key]} onChange={e => set(key, e.target.value)}/>}
    {opts.hint && <small className="font-normal text-slate">{opts.hint}</small>}{errors[key] && <small role="alert" className="font-normal text-red-600">{errors[key]}</small>}</label>;
  const image = (key: TextKey, label: string) => <ImageInput label={label} value={d[key]} onChange={url => set(key, url)} error={errors[key]}/>;
  const submit = (e: FormEvent) => { e.preventDefault(); form.put('/admin/website', { preserveScroll: true }); };
  const errorList = Object.values(errors);

  return <SettingsLayout title="Website content" subtitle={<>Edit every text, photo and option on your guest website. In titles, put words between *asterisks* to show them in gold italics.</>}
    actions={<Link href={`/?tenant=${hero.slug}`} target="_blank" className="btn btn-light btn-sm"><ExternalLink size={14}/> View website</Link>}>
    <Head title="Website content"/>
    <form onSubmit={submit} className="grid gap-4">
      <Card title="Sections" intro="Switch off any section you don't want on the home page. Its menu link disappears too.">
        <div className="grid gap-2 sm:grid-cols-2">{(Object.keys(SECTION_LABELS) as (keyof SiteContent['sections'])[]).map(key => <label key={key} className="flex items-center gap-2 rounded-xl border border-[var(--line)] p-3 text-sm font-semibold">
          <input type="checkbox" className="size-4 accent-[var(--brand-pink)]" checked={d.sections[key]} onChange={e => set('sections', { ...d.sections, [key]: e.target.checked })}/>{SECTION_LABELS[key]}</label>)}</div>
      </Card>

      <Card title="Top banner (hero)" intro="The first thing guests see. A headline containing “in …” shows that part in gold, e.g. “Your Private Escape in Mabinay”." open>
        <div className="grid gap-4 sm:grid-cols-2">{text('hero_eyebrow', 'Small line above the headline')}
          <label className="field">Headline<input value={d.hero_headline} placeholder="Your Private Escape in Mabinay" onChange={e => set('hero_headline', e.target.value)}/></label></div>
        <label className="field">Description<textarea rows={2} value={d.hero_description} placeholder="Relax, celebrate, and create unforgettable memories." onChange={e => set('hero_description', e.target.value)}/></label>
        <ImageInput label="Banner photo" value={d.hero_image} onChange={url => set('hero_image', url)} error={errors.hero_image} hint="A wide landscape photo works best."/>
        <div className="grid gap-4 sm:grid-cols-2">{image('hero_card_image_1', 'Tilted photo card 1')}{image('hero_card_image_2', 'Tilted photo card 2')}</div>
        <div className="grid gap-4 sm:grid-cols-2">{text('hero_primary_label', 'Gold button')}{text('hero_secondary_label', 'Outline button')}</div>
        <div className="grid gap-4 sm:grid-cols-2">{text('reserve_button', 'Booking bar button')}{text('reserve_note', 'Note under the booking bar')}</div>
        <IconList label="Highlights row" items={d.highlights} icons={icons} onChange={items => set('highlights', items)}/>
      </Card>

      <Card title="Rooms & Villas" intro="Rooms, prices and their photos come from Properties & units.">
        <div className="grid gap-4 sm:grid-cols-2">{text('rooms_eyebrow', 'Small heading')}{text('rooms_title', 'Title')}</div>
        {text('rooms_text', 'Text', { long: true })}
        {text('rooms_note', 'Line under the room cards (check-in, extra person…)')}
      </Card>

      <Card title="Events & Weddings" intro="The package price comes from the unit marked “Whole-property package” in Properties & units.">
        <div className="grid gap-4 sm:grid-cols-2">{text('events_eyebrow', 'Small heading')}{text('events_title', 'Title')}</div>
        {text('events_text', 'Text', { long: true })}
        {image('events_image', 'Background photo')}
        <div className="grid gap-4 sm:grid-cols-2">{text('events_primary_label', 'Gold button')}{text('events_secondary_label', 'Outline button')}</div>
        <p className="label mt-2">Function hall rates</p>
        <div className="grid gap-4 sm:grid-cols-2">{text('venues_title', 'Title')}{text('venues_text', 'Text')}</div>
        <p className="label mt-2">Package card</p>
        <div className="grid gap-4 sm:grid-cols-3">{text('package_kicker', 'Line above')}{text('package_title', 'Big word')}{text('package_subtitle', 'Line below')}</div>
        <div className="grid gap-4 sm:grid-cols-2">{text('package_heading', 'Heading')}{text('package_note', 'Small note')}</div>
        <div className="grid gap-4 sm:grid-cols-2">{text('package_includes', 'What’s included')}{text('package_button', 'Button')}</div>
      </Card>

      <Card title="Amenities" intro="The amenity list itself is under Settings → Amenities.">
        <div className="grid gap-4 sm:grid-cols-3">{text('amenities_eyebrow', 'Small heading')}{text('amenities_title', 'Title')}{text('amenities_text', 'Text')}</div>
      </Card>

      <Card title="Gallery">
        <div className="grid gap-4 sm:grid-cols-3">{text('gallery_eyebrow', 'Small heading')}{text('gallery_title', 'Title')}{text('gallery_text', 'Text')}</div>
        <Rows label="Photos" items={d.gallery} max={30} blank={{ image: '', caption: '' }} onChange={items => set('gallery', items)}
          render={(item, update, i) => <div className="grid flex-1 gap-2 sm:grid-cols-[1fr_1fr]"><ImageInput compact value={item.image} onChange={url => update({ ...item, image: url })} error={errors[`gallery.${i}.image`]}/>
            <label className="field">Caption<input value={item.caption} onChange={e => update({ ...item, caption: e.target.value })}/></label></div>}/>
      </Card>

      <Card title="How booking works">
        <div className="grid gap-4 sm:grid-cols-3">{text('steps_eyebrow', 'Small heading')}{text('steps_title', 'Title')}{text('steps_text', 'Text')}</div>
        <Rows label="Steps" items={d.steps} max={6} blank={{ icon: 'sparkles', title: '', body: '' }} onChange={items => set('steps', items)}
          render={(item, update, i) => <div className="grid flex-1 gap-2 sm:grid-cols-[150px_1fr]"><IconSelect icons={icons} value={item.icon} onChange={icon => update({ ...item, icon })}/>
            <div className="grid gap-2"><input aria-label="Step title" placeholder="Title" value={item.title} onChange={e => update({ ...item, title: e.target.value })}/>{errors[`steps.${i}.title`] && <small className="text-red-600">{errors[`steps.${i}.title`]}</small>}
              <textarea aria-label="Step text" rows={2} placeholder="Text" value={item.body} onChange={e => update({ ...item, body: e.target.value })}/></div></div>}/>
      </Card>

      <Card title="Contact & location" intro="Address, main phone and email come from Branding. The second number and Facebook page show in the top bar, contact section and footer.">
        <div className="grid gap-4 sm:grid-cols-2">{text('phone_alt', 'Second phone number')}{text('facebook_url', 'Facebook page link')}</div>
        <div className="grid gap-4 sm:grid-cols-2">{text('contact_eyebrow', 'Small heading')}{text('contact_title', 'Title')}</div>
        <div className="grid gap-4 sm:grid-cols-2">{text('location_note', 'Landmark under the address')}{text('map_url', 'Google Maps link')}</div>
        {text('map_pin', 'Map pin: place name, address, or coordinates like 10.0712,123.1285')}
        {image('contact_image', 'Photo')}
        <div className="grid gap-4 sm:grid-cols-2">{text('contact_photo_title', 'Photo caption title')}{text('contact_photo_text', 'Photo caption text')}</div>
      </Card>

      <Card title="Closing banner & footer">
        <div className="grid gap-4 sm:grid-cols-2">{text('final_eyebrow', 'Small heading')}{text('final_title', 'Title')}</div>
        <div className="grid gap-4 sm:grid-cols-2">{text('final_text', 'Text')}{text('final_button', 'Button')}</div>
        {image('final_image', 'Background photo')}
        <div className="grid gap-4 sm:grid-cols-2">{text('footer_tagline', 'Footer tagline')}{text('footer_about', 'Footer description', { long: true })}</div>
      </Card>

      <Card title="Reservation form options" intro="What guests can choose when they send a request. The choice is saved in the booking notes so you know what to discuss on the call.">
        <OptionList label="“Event or stay” options" items={d.request_types} onChange={items => set('request_types', items)} error={errors.request_types}/>
        <OptionList label="“Best time to call” options" items={d.call_times} onChange={items => set('call_times', items)} error={errors.call_times}/>
      </Card>

      {errorList.length > 0 && <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">Please fix {errorList.length === 1 ? 'this' : 'these'}: {[...new Set(errorList)].slice(0, 4).join(' ')}</div>}
      <div className="sticky bottom-3 z-10 flex justify-end"><button disabled={form.processing} className="btn btn-pink shadow-lg">{form.processing ? 'Saving…' : 'Save website'}</button></div>
    </form>
  </SettingsLayout>;
}

function Card({ title, intro, open = false, children }: { title: string; intro?: string; open?: boolean; children: ReactNode }) {
  return <details open={open} className="surface-card group overflow-hidden">
    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 p-5 [&::-webkit-details-marker]:hidden"><span><strong className="block text-[15px]">{title}</strong>{intro && <span className="mt-0.5 block text-xs font-semibold text-[var(--muted)]">{intro}</span>}</span><ArrowDown size={16} className="shrink-0 transition group-open:rotate-180"/></summary>
    <div className="grid gap-4 border-t border-[var(--line)] p-5">{children}</div>
  </details>;
}

/** A reorderable list editor with add, move and remove. */
function Rows<T>({ label, items, max, blank, onChange, render }: { label: string; items: T[]; max: number; blank: T; onChange: (items: T[]) => void; render: (item: T, update: (item: T) => void, index: number) => ReactNode }) {
  const move = (i: number, by: number) => { const next = [...items]; [next[i], next[i + by]] = [next[i + by], next[i]]; onChange(next); };
  return <div className="grid gap-2">
    <p className="label">{label}</p>
    {items.map((item, i) => <div key={i} className="flex items-center gap-2 rounded-xl border border-[var(--line)] p-2.5">
      {render(item, next => onChange(items.map((old, j) => j === i ? next : old)), i)}
      <div className="flex shrink-0 gap-0.5">
        <button type="button" className="icon-button !size-8" aria-label="Move up" disabled={i === 0} onClick={() => move(i, -1)}><ArrowUp size={14}/></button>
        <button type="button" className="icon-button !size-8" aria-label="Move down" disabled={i === items.length - 1} onClick={() => move(i, 1)}><ArrowDown size={14}/></button>
        <button type="button" className="icon-button !size-8 text-red-600" aria-label="Remove" onClick={() => onChange(items.filter((_, j) => j !== i))}><Trash2 size={14}/></button>
      </div>
    </div>)}
    {items.length < max && <button type="button" className="btn btn-light btn-sm w-fit" onClick={() => onChange([...items, blank])}><Plus size={14}/> Add</button>}
  </div>;
}

function IconSelect({ icons, value, onChange }: { icons: string[]; value: string; onChange: (icon: string) => void }) {
  return <div className="flex items-center gap-2"><span className="grid size-9 shrink-0 place-items-center rounded-lg bg-[var(--mint)] text-[#00408C]"><SiteIcon name={value} size={18}/></span>
    <select aria-label="Icon" className="input" value={value} onChange={e => onChange(e.target.value)}>{icons.map(icon => <option key={icon} value={icon}>{icon}</option>)}</select></div>;
}

function IconList({ label, items, icons, onChange }: { label: string; items: IconItem[]; icons: string[]; onChange: (items: IconItem[]) => void }) {
  return <Rows label={label} items={items} max={6} blank={{ icon: 'sparkles', label: '' }} onChange={onChange}
    render={(item, update) => <div className="grid flex-1 gap-2 sm:grid-cols-[180px_1fr]"><IconSelect icons={icons} value={item.icon} onChange={icon => update({ ...item, icon })}/>
      <input aria-label="Label" className="input" placeholder="Label" required value={item.label} onChange={e => update({ ...item, label: e.target.value })}/></div>}/>;
}

function OptionList({ label, items, onChange, error }: { label: string; items: Option[]; onChange: (items: Option[]) => void; error?: string }) {
  return <div className="grid gap-1">
    <Rows label={label} items={items} max={15} blank={{ key: '', label: '' }} onChange={onChange}
      render={(item, update) => <input aria-label="Option" className="input flex-1" required placeholder="e.g. Debut / 18th birthday" value={item.label} onChange={e => update({ ...item, label: e.target.value })}/>}/>
    {error && <small role="alert" className="text-red-600">{error}</small>}
  </div>;
}
