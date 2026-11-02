import * as Dialog from '@radix-ui/react-dialog';
import { ChevronLeft, ChevronRight, Grid2x2, X } from 'lucide-react';
import { useState } from 'react';

export type GalleryItem = { src: string; alt: string; caption?: string };

/** Photos that open a full-screen lightbox. `mosaic` is the stay-page hero: one large photo and up to four more, swipeable on phones. */
export default function ImageGallery({ items, className = '', imageClassName = 'h-64', variant = 'grid' }: { items: GalleryItem[]; className?: string; imageClassName?: string; variant?: 'grid' | 'mosaic' }) {
  const [open, setOpen] = useState(false);
  const [index, setIndex] = useState(0);
  const [touch, setTouch] = useState<number | null>(null);
  if (!items.length) return null;
  const current = items[index];
  const change = (delta: number) => setIndex(value => (value + delta + items.length) % items.length);
  const show = (i: number) => { setIndex(i); setOpen(true); };
  const tile = (item: GalleryItem, i: number, extra = '', img = imageClassName) => <button type="button" key={`${item.src}-${i}`} className={`group relative overflow-hidden bg-slate-200 text-left ${extra}`} onClick={() => show(i)} aria-label={`View photo: ${item.alt}`}>
    <img src={item.src} alt={item.alt} loading={i === 0 ? 'eager' : 'lazy'} className={`${img} w-full object-cover transition duration-700 group-hover:scale-[1.04]`}/>
    <span className="absolute inset-0 bg-black/0 transition group-hover:bg-black/10"/>
    {item.caption && <span className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/75 to-transparent p-5 pt-12 text-lg font-semibold text-white">{item.caption}</span>}
  </button>;

  const mosaic = items.slice(0, items.length >= 5 ? 5 : items.length >= 3 ? 3 : items.length);
  return <>
    {variant === 'mosaic' ? <div className={`relative ${className}`}>
      <div className="-mx-3 flex snap-x snap-mandatory scroll-px-3 gap-2 overflow-x-auto px-3 [scrollbar-width:none] sm:hidden [&::-webkit-scrollbar]:hidden">
        {items.map((item, i) => tile(item, i, 'w-[86%] shrink-0 snap-center rounded-2xl', 'h-64'))}
      </div>
      <div className={`hidden gap-2 overflow-hidden rounded-3xl sm:grid ${mosaic.length >= 5 ? 'grid-cols-4 grid-rows-2' : mosaic.length === 3 ? 'grid-cols-3 grid-rows-2' : mosaic.length === 2 ? 'grid-cols-2' : 'grid-cols-1'} h-[380px] lg:h-[460px]`}>
        {mosaic.map((item, i) => tile(item, i, i === 0 && mosaic.length > 2 ? 'col-span-2 row-span-2' : '', 'h-full'))}
      </div>
      {items.length > 1 && <button type="button" onClick={() => show(0)} className="absolute bottom-3 right-3 inline-flex items-center gap-2 rounded-full bg-white/95 px-3.5 py-2 text-xs font-bold text-navy shadow-lg backdrop-blur transition hover:bg-white sm:bottom-4 sm:right-4"><Grid2x2 size={14}/>{items.length} photos</button>}
    </div> : <div className={className}>{items.map((item, i) => tile(item, i, 'rounded-2xl'))}</div>}

    <Dialog.Root open={open} onOpenChange={setOpen}>
      <Dialog.Portal><Dialog.Overlay className="date-overlay animate-fade"/>
        <Dialog.Content className="gallery-dialog animate-fade" aria-describedby={undefined} onKeyDown={e => { if (e.key === 'ArrowLeft') change(-1); if (e.key === 'ArrowRight') change(1); }}>
          <Dialog.Title className="sr-only">{current.alt}</Dialog.Title>
          <Dialog.Close className="absolute right-4 top-4 z-10 grid size-10 place-items-center rounded-full bg-black/60 text-white" aria-label="Close photo"><X size={20}/></Dialog.Close>
          <div className="flex h-full items-center justify-center pb-16" onTouchStart={e => setTouch(e.touches[0].clientX)} onTouchEnd={e => { if (touch === null) return; const distance = e.changedTouches[0].clientX - touch; if (Math.abs(distance) > 55) change(distance < 0 ? 1 : -1); setTouch(null); }}>
            <img key={current.src} src={current.src} alt={current.alt} className="animate-fade max-h-[78dvh] max-w-full object-contain"/>
          </div>
          {items.length > 1 && <><button type="button" className="gallery-arrow left-3" aria-label="Previous photo" onClick={() => change(-1)}><ChevronLeft/></button><button type="button" className="gallery-arrow right-3" aria-label="Next photo" onClick={() => change(1)}><ChevronRight/></button></>}
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent px-4 pb-3 pt-8">
            <p className="text-center text-sm font-semibold text-white">{current.caption || current.alt} · {index + 1} / {items.length}</p>
            {items.length > 1 && <div className="mt-2 flex justify-center gap-1.5 overflow-x-auto [scrollbar-width:none]">{items.map((item, i) => <button key={`${item.src}-${i}`} type="button" onClick={() => setIndex(i)} aria-label={`Photo ${i + 1}`} aria-current={i === index} className={`size-11 shrink-0 overflow-hidden rounded-lg border-2 transition ${i === index ? 'border-white' : 'border-transparent opacity-60 hover:opacity-100'}`}><img src={item.src} alt="" className="h-full w-full object-cover"/></button>)}</div>}
          </div>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  </>;
}
