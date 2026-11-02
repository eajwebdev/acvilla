import * as Dialog from '@radix-ui/react-dialog';
import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, ImagePlus, Plus, Star, Trash2, X } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import type { Bed, Room } from '../types';
import { uploadImage } from './siteContent';

export type RoomEditorOptions = { amenities: string[]; bedTypes: string[]; stayDefaults: { min_nights: number; max_nights: number } };
type Blank = number | '';
const MAX_PHOTOS = 30;
type RoomForm = {
  name: string; description: string; active: boolean; whole_property: boolean; kind: 'room' | 'venue'; quantity: number; maximum_occupancy: number; base_guests: Blank;
  bedrooms: number; bathrooms: number; size_sqm: Blank; beds: Bed[];
  base_rate: Blank; weekend_rate: Blank; cleaning_fee: number; extra_guest_fee: number;
  min_nights: Blank; max_nights: Blank; amenities: string[]; photos: string[];
};

const fromRoom = (room?: Room): RoomForm => ({
  name: room?.name ?? '', description: room?.description ?? '', active: room?.active ?? true, whole_property: room?.whole_property ?? false, kind: room?.kind ?? 'room',
  quantity: room?.quantity ?? 1, maximum_occupancy: room?.maximum_occupancy ?? 2, base_guests: room?.base_guests ?? '',
  bedrooms: room?.bedrooms ?? 1, bathrooms: Number(room?.bathrooms ?? 1), size_sqm: room?.size_sqm ?? '', beds: room?.beds ?? [],
  base_rate: room ? Number(room.base_rate) : 3500, weekend_rate: room?.weekend_rate ? Number(room.weekend_rate) : '',
  cleaning_fee: Number(room?.cleaning_fee ?? 0), extra_guest_fee: Number(room?.extra_guest_fee ?? 0),
  min_nights: room?.min_nights ?? '', max_nights: room?.max_nights ?? '', amenities: room?.amenities ?? [], photos: room?.photos ?? [],
});

function Field({ label, hint, error, className = '', children }: { label: string; hint?: string; error?: string; className?: string; children: ReactNode }) {
  return <label className={`field ${className}`}>{label}{children}{hint && <small className="font-normal text-slate">{hint}</small>}{error && <small role="alert" className="font-normal text-red-600">{error}</small>}</label>;
}

function Group({ title, children }: { title: string; children: ReactNode }) {
  return <fieldset className="grid gap-4 border-t border-slate-100 pt-5"><legend className="pb-1 text-sm font-bold uppercase tracking-[.12em] text-slate">{title}</legend>{children}</fieldset>;
}

export default function RoomEditor({ propertySlug, room, options, trigger }: { propertySlug: string; room?: Room; options: RoomEditorOptions; trigger: ReactNode }) {
  const [open, setOpen] = useState(false);
  const form = useForm<RoomForm>(fromRoom(room));
  const [photo, setPhoto] = useState('');
  const d = form.data;
  const errors = form.errors as Record<string, string>;
  const num = (key: keyof RoomForm, blank = false) => ({
    type: 'number', value: String(d[key] ?? ''),
    onChange: (ev: React.ChangeEvent<HTMLInputElement>) => form.setData(key, (ev.target.value === '' && blank ? '' : Number(ev.target.value)) as never),
  });
  const setBed = (i: number, bed: Partial<Bed>) => form.setData('beds', d.beds.map((b, j) => j === i ? { ...b, ...bed } : b));
  const [uploading, setUploading] = useState('');
  const [uploadError, setUploadError] = useState('');
  const [dragging, setDragging] = useState(false);
  // Uploads one at a time so a failed file doesn't lose the others.
  const uploadPhotos = async (files: FileList | File[] | null) => {
    const picked = Array.from(files ?? []).filter(file => file.type.startsWith('image/')).slice(0, MAX_PHOTOS - d.photos.length);
    if (!picked.length) return;
    setUploadError('');
    const added: string[] = [];
    for (const [i, file] of picked.entries()) {
      setUploading(`Uploading ${i + 1} of ${picked.length}…`);
      try { added.push(await uploadImage(file)); } catch (e) { setUploadError(`${file.name}: ${(e as Error).message}`); }
    }
    form.setData(data => ({ ...data, photos: [...data.photos, ...added] }));
    setUploading('');
  };
  const movePhoto = (from: number, to: number) => { const next = [...d.photos]; next.splice(to, 0, next.splice(from, 1)[0]); form.setData('photos', next); };
  const addPhoto = () => { const url = photo.trim(); if (url && !d.photos.includes(url)) form.setData('photos', [...d.photos, url]); setPhoto(''); };

  function submit(ev: FormEvent) {
    ev.preventDefault();
    form.transform(data => Object.fromEntries(Object.entries(data).map(([k, v]) => [k, v === '' && k !== 'description' ? null : v])) as unknown as RoomForm);
    const done = { preserveScroll: true, onSuccess: () => { setOpen(false); if (!room) form.reset(); } };
    if (room) form.put(`/admin/rooms/${room.id}`, done); else form.post(`/admin/properties/${propertySlug}/rooms`, done);
  }

  return <Dialog.Root open={open} onOpenChange={next => { setOpen(next); if (next) { form.clearErrors(); if (room) { form.setDefaults(fromRoom(room)); form.setData(fromRoom(room)); } } }}>
    <Dialog.Trigger asChild>{trigger}</Dialog.Trigger>
    <Dialog.Portal><Dialog.Overlay className="date-overlay"/>
      <Dialog.Content aria-describedby={undefined} className="fixed inset-x-0 bottom-0 z-[91] max-h-[92dvh] overflow-y-auto rounded-t-3xl bg-white p-6 shadow-2xl md:inset-auto md:left-1/2 md:top-1/2 md:w-[min(calc(100vw-48px),780px)] md:-translate-x-1/2 md:-translate-y-1/2 md:rounded-3xl md:p-8">
        <div className="flex items-start justify-between gap-4"><Dialog.Title className="text-2xl font-semibold">{room ? `Edit ${room.name}` : 'Add a room'}</Dialog.Title><Dialog.Close className="icon-button -mr-2 -mt-1" aria-label="Close"><X size={20}/></Dialog.Close></div>
        <form onSubmit={submit} className="mt-5 grid gap-6">
          <div className="grid gap-4 sm:grid-cols-[1fr_auto]">
            <Field label="Room name" error={errors.name}><input required maxLength={150} value={d.name} onChange={ev => form.setData('name', ev.target.value)}/></Field>
            {room && <label className="flex items-center gap-2 self-end pb-3 text-sm font-semibold"><input type="checkbox" className="size-4 accent-[var(--brand-pink)]" checked={d.active} onChange={ev => form.setData('active', ev.target.checked)}/> Bookable</label>}
            <Field label="Type" error={errors.kind}><select value={d.kind} onChange={ev => form.setData('kind', ev.target.value as RoomForm['kind'])}><option value="room">Room (overnight stay)</option><option value="venue">Venue: function hall, priced per event</option></select></Field>
            <Field label="Description" error={errors.description} className="sm:col-span-2"><textarea maxLength={2000} className="min-h-24 rounded-xl border border-slate-200 p-3 font-normal" value={d.description} onChange={ev => form.setData('description', ev.target.value)}/></Field>
          </div>

          <Group title="Capacity & layout">
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label="Units of this type" hint="How many identical rooms you can sell per night. Set 1 to track a unit on its own, e.g. A-House #1." error={errors.quantity}><input required min={1} max={100} {...num('quantity')}/></Field>
              <label className="flex items-start gap-2 rounded-xl border border-[var(--line)] p-3 text-sm sm:col-span-3"><input type="checkbox" className="mt-0.5 size-4 accent-[var(--brand-pink)]" checked={d.whole_property} onChange={ev => form.setData('whole_property', ev.target.checked)}/><span><strong className="block">Whole-property package</strong><span className="text-xs font-normal text-slate">Booking this blocks every other unit of the property for those dates, and any booked unit blocks this package, e.g. the exclusive whole-resort rental.</span></span></label>
              <Field label="Maximum guests" error={errors.maximum_occupancy}><input required min={1} max={200} {...num('maximum_occupancy')}/></Field>
              <Field label="Guests included in rate" hint={`Blank = all ${d.maximum_occupancy || ''} guests included.`} error={errors.base_guests}><input min={1} max={d.maximum_occupancy || 200} placeholder={String(d.maximum_occupancy || '')} {...num('base_guests', true)}/></Field>
              <Field label="Bedrooms" hint="0 for a studio." error={errors.bedrooms}><input required min={0} max={50} {...num('bedrooms')}/></Field>
              <Field label="Bathrooms" error={errors.bathrooms}><input required min={0} max={50} step="0.5" {...num('bathrooms')}/></Field>
              <Field label="Size (m²)" error={errors.size_sqm}><input min={1} {...num('size_sqm', true)}/></Field>
            </div>
            <div><p className="text-sm font-semibold text-navy">Beds</p><div className="mt-2 grid gap-2">{d.beds.map((bed, i) => <div key={i} className="flex gap-2"><input type="number" min={1} max={20} aria-label="Number of beds" className="!w-20 rounded-[10px] border border-slate-200 px-3" value={bed.count} onChange={ev => setBed(i, { count: Number(ev.target.value) })}/><select aria-label="Bed type" className="min-h-11 flex-1 rounded-[10px] border border-slate-200 bg-white px-3 capitalize" value={bed.type} onChange={ev => setBed(i, { type: ev.target.value })}>{options.bedTypes.map(type => <option key={type} value={type}>{type}</option>)}</select><button type="button" className="icon-button text-slate" aria-label="Remove bed" onClick={() => form.setData('beds', d.beds.filter((_, j) => j !== i))}><Trash2 size={17}/></button></div>)}</div>{(errors.beds || Object.keys(errors).some(k => k.startsWith('beds.'))) && <p role="alert" className="mt-1 text-xs text-red-600">Check the bed rows.</p>}{d.beds.length < 10 && <button type="button" onClick={() => form.setData('beds', [...d.beds, { type: options.bedTypes[0], count: 1 }])} className="mt-2 inline-flex items-center gap-1 text-sm font-semibold text-pink"><Plus size={16}/> Add bed</button>}</div>
          </Group>

          <Group title="Rates">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={d.kind === 'venue' ? 'Rate per event (per day)' : 'Nightly rate'} error={errors.base_rate}><input required min={1} step="0.01" {...num('base_rate', true)}/></Field>
              <Field label="Weekend rate" hint="Blank = nightly rate. Weekend nights are set in Settings → Pricing." error={errors.weekend_rate}><input min={1} step="0.01" {...num('weekend_rate', true)}/></Field>
              <Field label="Cleaning fee (per stay)" error={errors.cleaning_fee}><input required min={0} step="0.01" {...num('cleaning_fee')}/></Field>
              <Field label="Extra guest fee (per guest, per night)" hint="Charged for each guest above the included count." error={errors.extra_guest_fee}><input required min={0} step="0.01" {...num('extra_guest_fee')}/></Field>
            </div>
          </Group>

          <Group title="Stay rules">
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Minimum nights" hint={`Blank = booking rule (${options.stayDefaults.min_nights}).`} error={errors.min_nights}><input min={1} max={365} placeholder={String(options.stayDefaults.min_nights)} {...num('min_nights', true)}/></Field>
              <Field label="Maximum nights" hint={`Blank = booking rule (${options.stayDefaults.max_nights}).`} error={errors.max_nights}><input min={1} max={365} placeholder={String(options.stayDefaults.max_nights)} {...num('max_nights', true)}/></Field>
            </div>
          </Group>

          <Group title="Amenities">
            {options.amenities.length ? <div className="flex flex-wrap gap-2">{options.amenities.map(name => { const on = d.amenities.includes(name); return <button type="button" key={name} aria-pressed={on} onClick={() => form.setData('amenities', on ? d.amenities.filter(a => a !== name) : [...d.amenities, name])} className={`rounded-full border px-3 py-1.5 text-sm font-semibold ${on ? 'border-pink bg-pink/10 text-pink' : 'border-slate-200 text-slate hover:border-slate-300'}`}>{name}</button>; })}</div> : <p className="text-sm text-slate">Your amenity list is empty.</p>}
            <Link href="/admin/settings/amenities" className="w-fit text-sm font-semibold text-pink">Edit amenity list →</Link>
          </Group>

          <Group title={`Photos (${d.photos.length}/${MAX_PHOTOS})`}>
            <p className="-mt-2 text-xs text-slate">Guests browse these when they open this room on the website. The first photo is the cover. Drag photos in, or use the arrows to change the order.</p>
            {d.photos.length > 0 && <ol className="grid grid-cols-2 gap-2 sm:grid-cols-4">{d.photos.map((url, i) => <li key={url} className="group relative overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200">
              <img src={url} alt={`Photo ${i + 1}`} className="aspect-[4/3] w-full object-cover"/>
              <span className={`absolute left-1.5 top-1.5 rounded-md px-1.5 py-0.5 text-[10px] font-bold uppercase ${i === 0 ? 'bg-[var(--brand-pink)] text-white' : 'bg-black/55 text-white'}`}>{i === 0 ? 'Cover' : i + 1}</span>
              <button type="button" aria-label="Remove photo" onClick={() => form.setData('photos', d.photos.filter(p => p !== url))} className="absolute right-1.5 top-1.5 grid size-7 place-items-center rounded-lg bg-white/95 text-navy shadow-sm hover:text-red-600"><Trash2 size={14}/></button>
              <div className="absolute inset-x-1.5 bottom-1.5 flex justify-between gap-1">
                <button type="button" aria-label="Move photo earlier" disabled={i === 0} onClick={() => movePhoto(i, i - 1)} className="grid size-7 place-items-center rounded-lg bg-white/95 text-navy shadow-sm disabled:invisible"><ArrowLeft size={14}/></button>
                {i > 0 && <button type="button" onClick={() => movePhoto(i, 0)} className="inline-flex items-center gap-1 rounded-lg bg-white/95 px-2 text-[11px] font-bold text-navy shadow-sm"><Star size={12}/> Cover</button>}
                <button type="button" aria-label="Move photo later" disabled={i === d.photos.length - 1} onClick={() => movePhoto(i, i + 1)} className="grid size-7 place-items-center rounded-lg bg-white/95 text-navy shadow-sm disabled:invisible"><ArrowRight size={14}/></button>
              </div>
            </li>)}</ol>}
            {d.photos.length < MAX_PHOTOS && <label onDragOver={ev => { ev.preventDefault(); setDragging(true); }} onDragLeave={() => setDragging(false)} onDrop={ev => { ev.preventDefault(); setDragging(false); uploadPhotos(ev.dataTransfer.files); }}
              className={`flex cursor-pointer flex-col items-center gap-1 rounded-2xl border-2 border-dashed p-6 text-center text-sm transition ${dragging ? 'border-[var(--brand-pink)] bg-pink/5' : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'}`}>
              <ImagePlus size={26} className="text-slate"/>
              <span className="font-semibold text-navy">{uploading || 'Upload photos from your device'}</span>
              <span className="text-xs text-slate">Choose several at once or drop them here · JPG, PNG or WebP</span>
              <input type="file" multiple accept="image/jpeg,image/png,image/webp" className="sr-only" disabled={!!uploading} onChange={ev => { uploadPhotos(ev.target.files); ev.target.value = ''; }}/>
            </label>}
            {d.photos.length < MAX_PHOTOS && <div className="flex gap-2"><input type="url" aria-label="Photo URL" placeholder="…or paste a photo link: https://example.com/room.jpg" className="min-h-11 min-w-0 flex-1 rounded-[10px] border border-slate-200 px-3" value={photo} onChange={ev => setPhoto(ev.target.value)} onKeyDown={ev => { if (ev.key === 'Enter') { ev.preventDefault(); addPhoto(); } }}/><button type="button" onClick={addPhoto} className="btn btn-light border border-slate-200">Add link</button></div>}
            {uploadError && <p role="alert" className="text-xs text-red-600">{uploadError}</p>}
            {Object.keys(errors).some(k => k.startsWith('photos')) && <p role="alert" className="text-xs text-red-600">Each photo must be an uploaded photo or a full https:// link, up to {MAX_PHOTOS} photos.</p>}
          </Group>

          {errors.amenities && <p role="alert" className="text-sm text-red-600">{errors.amenities}</p>}
          <div className="sticky bottom-0 -mx-6 -mb-6 flex items-center gap-3 border-t border-slate-100 bg-white px-6 py-4 md:-mx-8 md:-mb-8 md:px-8">
            <button disabled={form.processing} className="btn btn-pink">{room ? 'Save room' : 'Add room'}</button>
            <Dialog.Close className="text-sm font-semibold text-slate hover:text-navy">Cancel</Dialog.Close>
            {Object.keys(errors).length > 0 && <span role="alert" className="text-sm text-red-600">Fix the highlighted fields.</span>}
          </div>
        </form>
      </Dialog.Content>
    </Dialog.Portal>
  </Dialog.Root>;
}
