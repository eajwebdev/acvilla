import * as Dialog from '@radix-ui/react-dialog';
import { router, usePage } from '@inertiajs/react';
import { Camera, ImagePlus, LoaderCircle, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { compressImage } from './ops';

export type TaskPhoto = { id: number; url: string; by: string | null; uploaded_by: number | null; at: string | null };

/** Thumbnails of a cleaning task's proof photos, with a camera button that opens the phone's rear camera. */
export default function TaskPhotos({ taskId, photos, done, max = 12, big = false, readOnly = false }: { taskId: number; photos: TaskPhoto[]; done: boolean; max?: number; big?: boolean; readOnly?: boolean }) {
  const { auth } = usePage<{ auth: { user: { id: number; role: string } } }>().props;
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [view, setView] = useState<TaskPhoto | null>(null);
  const canDelete = (p: TaskPhoto) => auth.user.role === 'ADMIN' || (p.uploaded_by === auth.user.id && !done);

  async function upload(list: FileList | null) {
    if (!list?.length) return;
    setError('');
    const room = max - photos.length;
    if (room <= 0) { setError(`Up to ${max} photos per task. Remove one first.`); return; }
    setBusy(true);
    const files = await Promise.all(Array.from(list).slice(0, Math.min(6, room)).map(f => compressImage(f)));
    router.post(`/admin/cleaning/${taskId}/photos`, { photos: files }, {
      forceFormData: true, preserveScroll: true, preserveState: true,
      onError: errs => setError((Object.values(errs)[0] as string) || 'Upload failed. Try again.'),
      onFinish: () => { setBusy(false); if (input.current) input.current.value = ''; },
    });
  }

  return <div>
    <div className="flex flex-wrap gap-2">
      {photos.map(p => <div key={p.id} className="group relative">
        <button type="button" onClick={() => setView(p)} className={`block overflow-hidden rounded-xl border border-[var(--line)] bg-[#F1F5F0] ${big ? 'size-24' : 'size-16'}`} aria-label="View photo"><img src={p.url} alt="Room after cleaning" loading="lazy" className="size-full object-cover"/></button>
        {canDelete(p) && <button type="button" aria-label="Remove photo" onClick={() => confirm('Remove this photo?') && router.delete(`/admin/cleaning/photos/${p.id}`, { preserveScroll: true, preserveState: true })} className="absolute -right-1.5 -top-1.5 grid size-6 place-items-center rounded-full border border-[var(--line)] bg-white text-[var(--muted)] shadow-sm hover:text-[var(--danger)]"><Trash2 size={12}/></button>}
      </div>)}
      {photos.length < max && !readOnly && <label className={`flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-[#7FB08A] bg-green-50 text-[11px] font-bold text-[#00408C] hover:bg-[#EBF4FB] ${big ? 'size-24' : 'size-16'} ${busy ? 'pointer-events-none opacity-60' : ''}`}>
        {busy ? <LoaderCircle size={big ? 22 : 18} className="animate-spin"/> : photos.length ? <ImagePlus size={big ? 22 : 18}/> : <Camera size={big ? 22 : 18}/>}
        {busy ? 'Uploading' : photos.length ? 'Add more' : 'Take photo'}
        <input ref={input} type="file" accept="image/*" capture="environment" multiple className="sr-only" onChange={e => upload(e.target.files)} disabled={busy}/>
      </label>}
    </div>
    {error && <p role="alert" className="mt-2 text-xs font-semibold text-[var(--danger)]">{error}</p>}
    <Dialog.Root open={view !== null} onOpenChange={o => { if (!o) setView(null); }}>
      <Dialog.Portal><Dialog.Overlay className="tapp-overlay"/><Dialog.Content className="tapp-dialog wide overflow-hidden !p-0" aria-describedby={undefined}>
        <div className="flex items-center justify-between border-b border-[var(--line)] px-4 py-2.5"><Dialog.Title className="text-sm font-bold">{view?.by ? `Photo by ${view.by}` : 'Photo'}{view?.at && <span className="ml-2 font-semibold text-[var(--muted)]">{new Date(view.at).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })}</span>}</Dialog.Title><Dialog.Close className="grid size-8 place-items-center rounded-lg border border-[var(--line)]" aria-label="Close"><X size={16}/></Dialog.Close></div>
        {view && <img src={view.url} alt="Room after cleaning" className="max-h-[75dvh] w-full bg-black object-contain"/>}
      </Dialog.Content></Dialog.Portal>
    </Dialog.Root>
  </div>;
}
