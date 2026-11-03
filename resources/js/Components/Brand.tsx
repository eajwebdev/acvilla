import { Link } from '@inertiajs/react';

/** The AC Villa sign: the blue and white monogram with the ship's wheel on yellow wood. Reads on light and dark backgrounds. */
export default function Brand({ href = '/', compact = false, plank = false }: { href?: string; compact?: boolean; plank?: boolean }) {
  if (plank) return <Link href={href} aria-label="AC Villa & Private Resort home" className="group inline-flex min-w-0 items-center"><img src="/images/acvilla/ui/nav-logo.webp" alt="AC Villa & Private Resort" width={708} height={264} className="ac-plank-logo w-auto max-w-full transition group-hover:opacity-90"/></Link>;
  return <Link href={href} aria-label="AC Villa & Private Resort home" className="group inline-flex min-w-0 items-center">
    <img src="/branding/ac-villa-logo.png" alt="AC Villa & Private Resort" width={720} height={365} className={`${compact ? 'h-14' : 'h-20 sm:h-24'} w-auto max-w-full transition group-hover:opacity-90`}/>
  </Link>;
}

/** The sign at a set height, for the owner and staff screens and their sign-in pages. */
export function BrandIcon({ href = '/', height = 48 }: { href?: string; height?: number }) {
  return <Link href={href} aria-label="AC Villa & Private Resort home" className="group inline-flex min-w-0 items-center">
    <img src="/branding/ac-villa-logo.png" alt="AC Villa & Private Resort" width={Math.round(height * 720 / 365)} height={height} className="shrink-0 transition group-hover:opacity-90"/>
  </Link>;
}

/** The ship's wheel on its own, for small spaces and decorations. */
export function BrandMark({ className = '' }: { className?: string }) {
  return <img src="/branding/ac-villa-wheel.png" alt="" aria-hidden="true" width={240} height={235} className={`w-auto ${className}`}/>;
}
