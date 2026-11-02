import { Link } from '@inertiajs/react';

/**
 * The AC Villa & Private Resort logo artwork: the house-and-leaf mark beside the green and blue wordmark.
 * `tone="light"` is the all-white version for dark backgrounds.
 */
export default function Brand({ href = '/', tone = 'dark', compact = false }: { href?: string; tone?: 'dark' | 'light'; compact?: boolean }) {
  const src = tone === 'light' ? '/branding/acvilla-logo-horizontal-white.png' : '/branding/acvilla-logo-horizontal.png';
  return <Link href={href} aria-label="AC Villa & Private Resort home" className="group inline-flex min-w-0 items-center">
    <img src={src} alt="AC Villa & Private Resort" width={1390} height={300} className={`${compact ? 'w-[214px]' : 'w-[250px] sm:w-[340px]'} h-auto max-w-full transition group-hover:opacity-90`}/>
  </Link>;
}

/**
 * The real logo (the artwork of favicon.ico, cropped from its 512px master so it stays sharp and fills its space) with
 * the resort's name beside it. For the owner and staff screens and their sign-in pages, on white.
 */
export function BrandIcon({ href = '/', height = 48 }: { href?: string; height?: number }) {
  return <Link href={href} aria-label="AC Villa & Private Resort home" className="group inline-flex min-w-0 items-center gap-2.5">
    <img src="/branding/acvilla-logo-mark.png" alt="" width={Math.round(height * 328 / 238)} height={height} className="shrink-0 transition group-hover:opacity-90"/>
    <span className="min-w-0 leading-none"><span className="block truncate font-serif text-[20px] font-semibold text-forest">AC Villa</span><span className="mt-1 block truncate text-[8.5px] font-semibold uppercase tracking-[.1em] text-[var(--brand-slate)]">Private Resort</span></span>
  </Link>;
}

/** The house-and-leaf mark on its own, for small spaces and decorations. */
export function BrandMark({ className = '' }: { className?: string }) {
  return <img src="/branding/acvilla-mark.png" alt="" aria-hidden="true" width={646} height={460} className={`w-auto ${className}`}/>;
}
