import { Head, router, useForm } from '@inertiajs/react';
import { RotateCcw, Send } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';
import SettingsLayout from '../../Components/SettingsLayout';

type Template = { event: string; label: string; enabled: boolean; subject: string; body: string; send_days_before: number | null; customized: boolean; defaults: { subject: string; body: string } };
const when: Record<string, string> = {
  booking_received: 'When a guest chooses a manual payment method at checkout.',
  booking_confirmed: 'When a booking becomes confirmed: paid online, deposit approved, or confirmed by staff.',
  payment_received: 'When a later payment is recorded or approved, e.g. the balance.',
  booking_cancelled: 'When staff cancel a booking.',
  pre_arrival: 'Before check-in, for confirmed bookings. Checked every hour.',
  thank_you: 'When a guest is checked out.',
};
const fill = (text: string, values: Record<string, string | number>) => text.replace(/\{(\w+)\}/g, (match, key) => key in values ? String(values[key]) : match);

export default function Messages({ templates, placeholders, sample }: { templates: Template[]; placeholders: string[]; sample: Record<string, string | number> }) {
  const [event, setEvent] = useState(templates[0]?.event);
  const current = templates.find(t => t.event === event) ?? templates[0];
  return <SettingsLayout title="Guest messages" subtitle="Emails guests receive automatically. Words in {braces} are filled in for each booking."><Head title="Guest messages · Settings"/>
    <div className="grid gap-5 xl:grid-cols-[250px_minmax(0,1fr)]">
      <div className="surface-card h-fit p-2" role="tablist" aria-label="Message">{templates.map(t => <button type="button" role="tab" aria-selected={t.event === event} key={t.event} onClick={() => setEvent(t.event)} className={`flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2.5 text-left text-sm font-semibold ${t.event === event ? 'bg-pink/10 text-pink' : 'text-slate hover:bg-slate-50 hover:text-navy'}`}><span>{t.label}</span><span className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-bold uppercase ${t.enabled ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate'}`}>{t.enabled ? 'On' : 'Off'}</span></button>)}</div>
      {current && <Editor key={`${current.event}:${current.customized}`} template={current} placeholders={placeholders} sample={sample}/>}
    </div>
  </SettingsLayout>;
}

function Editor({ template, placeholders, sample }: { template: Template; placeholders: string[]; sample: Record<string, string | number> }) {
  const form = useForm({ enabled: template.enabled, subject: template.subject, body: template.body, ...(template.event === 'pre_arrival' ? { send_days_before: template.send_days_before ?? 2 } : {}) });
  const body = useRef<HTMLTextAreaElement>(null);
  const e = form.errors as Record<string, string>;
  function insert(key: string) {
    const el = body.current; const token = `{${key}}`;
    if (!el) return;
    const [start, end] = [el.selectionStart, el.selectionEnd];
    form.setData('body', form.data.body.slice(0, start) + token + form.data.body.slice(end));
    requestAnimationFrame(() => { el.focus(); el.setSelectionRange(start + token.length, start + token.length); });
  }
  const submit = (ev: FormEvent) => { ev.preventDefault(); form.put(`/admin/settings/messages/${template.event}`, { preserveScroll: true, onSuccess: () => form.setDefaults() }); };

  return <form onSubmit={submit} className="grid gap-5 2xl:grid-cols-2">
    <div className="surface-card grid h-fit gap-4 p-6">
      <div className="flex flex-wrap items-start justify-between gap-3"><div><h3 className="text-lg font-semibold">{template.label}</h3><p className="mt-1 text-xs text-slate">{when[template.event]}</p></div>
        <label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" className="size-4 accent-[var(--brand-pink)]" checked={form.data.enabled} onChange={ev => form.setData('enabled', ev.target.checked)}/> Send this email</label></div>
      {template.event === 'pre_arrival' && <label className="field">Days before check-in<input type="number" required min={0} max={30} value={String(form.data.send_days_before ?? '')} onChange={ev => form.setData('send_days_before', Number(ev.target.value))}/>{e.send_days_before && <small role="alert" className="font-normal text-red-600">{e.send_days_before}</small>}</label>}
      <label className="field">Subject<input required maxLength={150} value={form.data.subject} onChange={ev => form.setData('subject', ev.target.value)}/>{e.subject && <small role="alert" className="font-normal text-red-600">{e.subject}</small>}</label>
      <label className="field">Message<textarea ref={body} required maxLength={5000} className="min-h-72 rounded-xl border border-slate-200 p-3 font-mono text-[13px] font-normal leading-6" value={form.data.body} onChange={ev => form.setData('body', ev.target.value)}/>{e.body && <small role="alert" className="font-normal text-red-600">{e.body}</small>}</label>
      <div><p className="text-xs font-semibold text-slate">Insert a placeholder at the cursor</p><div className="mt-2 flex flex-wrap gap-1.5">{placeholders.map(key => <button type="button" key={key} onClick={() => insert(key)} className="rounded-md border border-slate-200 bg-slate-50 px-2 py-1 font-mono text-[11px] text-navy hover:border-pink hover:text-pink">{`{${key}}`}</button>)}</div></div>
      <div className="flex flex-wrap items-center gap-3 border-t border-slate-100 pt-4">
        <button disabled={form.processing || !form.isDirty} className="btn btn-pink disabled:cursor-not-allowed disabled:opacity-50">Save</button>
        <button type="button" onClick={() => router.post(`/admin/settings/messages/${template.event}/test`, { subject: form.data.subject, body: form.data.body }, { preserveScroll: true, preserveState: true })} className="btn btn-light border border-slate-200"><Send size={15}/> Send test to me</button>
        {template.customized && <button type="button" onClick={() => { if (confirm('Replace this message with the default wording?')) router.delete(`/admin/settings/messages/${template.event}`, { preserveScroll: true }); }} className="inline-flex items-center gap-1 text-sm font-semibold text-slate hover:text-navy"><RotateCcw size={14}/> Reset to default</button>}
      </div>
    </div>
    <div className="surface-card h-fit overflow-hidden"><p className="border-b border-slate-100 px-5 py-3 text-xs font-bold uppercase tracking-wide text-slate">Preview with sample booking</p>
      <div className="p-5"><p className="text-xs text-slate">Subject</p><p className="font-semibold">{fill(form.data.subject, sample)}</p>
        <div className="mt-4 whitespace-pre-line rounded-xl bg-slate-50 p-4 text-sm leading-6">{fill(form.data.body, sample)}</div>
        {!form.data.enabled && <p className="mt-3 text-xs text-amber-700">This email is off, so guests won't receive it.</p>}</div></div>
  </form>;
}
