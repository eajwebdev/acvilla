import { usePage } from '@inertiajs/react';
import { CircleAlert, CircleCheck, X } from 'lucide-react';
import { useEffect, useState } from 'react';

const DURATION = 5000;

export default function FlashToast() {
  const { flash, errors } = usePage<{ flash: { success?: string | null }; errors: Record<string, string> }>().props;
  const message = flash?.success || Object.values(errors || {})[0] || '';
  const kind = flash?.success ? 'success' : 'error';
  const [visible, setVisible] = useState(false);
  const [paused, setPaused] = useState(false);
  useEffect(() => {
    if (!message) return;
    setVisible(true);
    if (paused) return;
    const timer = window.setTimeout(() => setVisible(false), DURATION);
    return () => window.clearTimeout(timer);
  }, [message, paused]);
  if (!visible || !message) return null;
  return <div role={kind === 'error' ? 'alert' : 'status'} onMouseEnter={() => setPaused(true)} onMouseLeave={() => setPaused(false)}
    className="fixed inset-x-3 bottom-[calc(1rem+env(safe-area-inset-bottom))] z-[100] mx-auto max-w-sm overflow-hidden rounded-2xl border border-[var(--border)] bg-white/95 text-sm font-semibold text-navy shadow-[0_24px_60px_rgba(0,90,28,.22)] backdrop-blur [animation:toast-in_.28s_cubic-bezier(.2,.7,.2,1)_both] sm:inset-x-auto sm:right-5 sm:bottom-5">
    <div className="flex items-start gap-3 p-4">
      <span className={`grid size-8 shrink-0 place-items-center rounded-full ${kind === 'error' ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'}`}>{kind === 'error' ? <CircleAlert size={18}/> : <CircleCheck size={18}/>}</span>
      <span className="min-w-0 flex-1 pt-1.5 leading-5">{message}</span>
      <button type="button" className="grid size-8 shrink-0 place-items-center rounded-lg text-slate hover:bg-slate-100" aria-label="Dismiss notification" onClick={() => setVisible(false)}><X size={16}/></button>
    </div>
    {!paused && <span key={message} aria-hidden="true" className={`block h-0.5 origin-left ${kind === 'error' ? 'bg-rose-500' : 'bg-emerald-500'}`} style={{ animation: `toast-timer ${DURATION}ms linear both` }}/>}
  </div>;
}
