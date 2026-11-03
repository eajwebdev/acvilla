import { ImagePlus, Loader2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import { uploadImage } from './siteContent';

/** An image field for the admin: preview, upload from the device, or paste a link. */
export default function ImageInput({ label, value, onChange, hint, error, compact = false }: { label?: string; value: string; onChange: (url: string) => void; hint?: string; error?: string; compact?: boolean }) {
  const file = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState('');
  async function pick(files: FileList | null) {
    if (!files?.[0]) return;
    setBusy(true); setFailed('');
    try { onChange(await uploadImage(files[0])); } catch (e) { setFailed((e as Error).message); } finally { setBusy(false); if (file.current) file.current.value = ''; }
  }
  return <div className="field min-w-0 grid-cols-[minmax(0,1fr)]">
    {label && <span>{label}</span>}
    <div className="flex min-w-0 flex-wrap items-center gap-3">
      <div className={`${compact ? 'size-14' : 'h-20 w-28'} grid shrink-0 place-items-center overflow-hidden rounded-xl border border-[var(--line)] bg-[#F7F6F3]`}>{value ? <img src={value} alt="" className="h-full w-full object-cover"/> : <ImagePlus size={20} className="text-[var(--muted)]"/>}</div>
      <div className="grid min-w-[150px] flex-1 gap-2">
        <div className="flex flex-wrap gap-2">
          <button type="button" className="btn btn-light btn-sm" disabled={busy} onClick={() => file.current?.click()}>{busy ? <Loader2 size={14} className="animate-spin"/> : <ImagePlus size={14}/>}{busy ? 'Uploading…' : 'Upload photo'}</button>
          {value && <button type="button" className="btn btn-light btn-sm" onClick={() => onChange('')} aria-label="Remove photo"><X size={14}/></button>}
        </div>
        <input aria-label={`${label ?? 'Image'} link`} placeholder="…or paste an image link" value={value} onChange={e => onChange(e.target.value)} className="!min-h-9 min-w-0 !text-xs"/>
      </div>
      <input ref={file} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={e => pick(e.target.files)}/>
    </div>
    {hint && <small className="font-normal text-slate">{hint}</small>}
    {(failed || error) && <small role="alert" className="font-normal text-red-600">{failed || error}</small>}
  </div>;
}
