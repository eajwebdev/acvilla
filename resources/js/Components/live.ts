import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';

// While the page itself is loading (a click, a form being sent), a background refresh waits its turn.
let navigating = false;
router.on('start', () => { navigating = true; });
router.on('finish', () => { navigating = false; });

/** Refresh the current page's data in place: same scroll, open dialogs and half-typed forms kept. */
export const refreshPage = () => router.reload({ preserveScroll: true, preserveState: true, async: true } as Parameters<typeof router.reload>[0]);

/**
 * Keeps a page live without a refresh: polls a small fingerprint endpoint every few seconds while the tab is
 * visible (straight away when the guest or owner comes back to it) and calls onChange when the fingerprint moves.
 */
export function useLive<T extends { stamp: string }>(url: string | null, onChange: (data: T) => void, { interval = 5000, onTick }: { interval?: number; onTick?: (data: T) => void } = {}) {
  const handlers = useRef({ onChange, onTick });
  handlers.current = { onChange, onTick };
  useEffect(() => {
    if (!url) return;
    let last: string | null = null;
    let timer: number | undefined;
    let busy = false;
    let stopped = false;
    const schedule = () => { window.clearTimeout(timer); if (!stopped) timer = window.setTimeout(tick, interval); };
    async function tick() {
      if (stopped || busy) return;
      if (document.hidden) { schedule(); return; }
      busy = true;
      try {
        const response = await fetch(url!, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
        if (response.ok) {
          const data = await response.json() as T;
          handlers.current.onTick?.(data);
          if (last === null || data.stamp === last) last = data.stamp;
          // Try again on the next tick if the page is busy loading; the change is not lost.
          else if (!navigating) { last = data.stamp; handlers.current.onChange(data); }
        }
      } catch { /* offline for a moment: the next tick tries again */ }
      busy = false;
      schedule();
    }
    const wake = () => { if (!document.hidden && !busy) { window.clearTimeout(timer); void tick(); } };
    void tick();
    document.addEventListener('visibilitychange', wake);
    window.addEventListener('focus', wake);
    return () => { stopped = true; window.clearTimeout(timer); document.removeEventListener('visibilitychange', wake); window.removeEventListener('focus', wake); };
  }, [url, interval]);
}
