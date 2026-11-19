import { Link } from '@inertiajs/react';

export type PaginatorLink = { url: string | null; label: string; active: boolean };

type Props = {
  links: PaginatorLink[];
  /** `guest` keeps the public site's own pagination styles (stays.css). */
  variant?: 'admin' | 'guest';
  className?: string;
};

/**
 * Laravel's paginator labels arrive as HTML ("&laquo; Previous"). They are turned into plain text here instead of
 * being injected as HTML, so a label can never carry markup into the page.
 */
const ENTITIES: Record<string, string> = { '&laquo;': '«', '&raquo;': '»', '&amp;': '&', '&hellip;': '…' };

function labelText(label: string): string {
  return label.replace(/&[a-z]+;/g, entity => ENTITIES[entity] ?? '').trim();
}

function accessibleLabel(text: string): string | undefined {
  if (/previous/i.test(text)) return 'Previous page';
  if (/next/i.test(text)) return 'Next page';
  if (/^\d+$/.test(text)) return `Page ${text}`;
  return undefined;
}

export default function Pagination({ links, variant = 'admin', className = '' }: Props) {
  // Laravel always sends Previous and Next; with only one page there is nothing to show.
  if (links.length <= 3) return null;
  const guest = variant === 'guest';

  return (
    <nav
      aria-label="Pagination"
      className={guest ? `sl-pagination ${className}` : `mt-4 flex flex-wrap justify-center gap-1.5 ${className}`}
    >
      {links.map((link, i) => {
        const text = labelText(link.label);
        if (!link.url) {
          return guest ? null : (
            <span key={i} aria-hidden="true" className="px-3 py-1.5 text-sm text-ink-faint">
              {text}
            </span>
          );
        }
        return (
          <Link
            key={i}
            href={link.url}
            preserveScroll
            aria-label={accessibleLabel(text)}
            aria-current={link.active ? 'page' : undefined}
            className={
              guest
                ? link.active
                  ? 'is-active'
                  : ''
                : `min-w-9 rounded-lg px-3 py-1.5 text-center text-sm font-semibold transition-colors ${
                    link.active ? 'bg-forest text-white' : 'bg-white text-ink-soft hover:bg-tint hover:text-forest'
                  }`
            }
          >
            {text}
          </Link>
        );
      })}
    </nav>
  );
}
