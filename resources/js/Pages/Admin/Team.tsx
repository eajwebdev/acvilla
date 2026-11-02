import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import SettingsLayout from '../../Components/SettingsLayout';

type Member = { id: number; name: string; email: string; role: 'ADMIN' | 'STAFF'; deactivated_at: string | null; created_at: string };
const roles = { ADMIN: 'Admin: operations, attendance, reports, settings and team', STAFF: 'Staff: assigned tasks, attendance, reservations, calendar and POS' };

export default function Team({ members, me }: { members: Member[]; me: number }) {
  const form = useForm({ name: '', email: '', role: 'STAFF' as Member['role'], password: '' });
  const e = form.errors as Record<string, string>;
  const submit = (ev: FormEvent) => { ev.preventDefault(); form.post('/admin/settings/team', { preserveScroll: true, onSuccess: () => form.reset() }); };
  const put = (member: Member, url: string, data: Record<string, string | number | boolean | null>) => router.put(`/admin/settings/team/${member.id}${url}`, data, { preserveScroll: true });

  return <SettingsLayout title="Users" subtitle="People who can sign in to manage this business."><Head title="Users · Settings"/>
    <div className="grid gap-3">{members.map(m => { const self = m.id === me; return <div key={m.id} className={`surface-card flex flex-wrap items-center justify-between gap-4 p-5 ${m.deactivated_at ? 'opacity-60' : ''}`}>
      <div className="flex min-w-0 items-center gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-full bg-slate-100 font-bold text-navy" aria-hidden>{m.name[0]?.toUpperCase()}</span><div className="min-w-0"><p className="font-semibold">{m.name}{self && <span className="ml-2 text-xs font-normal text-slate">(you)</span>}{m.deactivated_at && <span className="ml-2 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-bold uppercase text-slate">No access</span>}</p><p className="truncate text-sm text-slate">{m.email}</p></div></div>
      {self ? <span className="text-sm font-semibold text-slate">{m.role === 'ADMIN' ? 'Admin' : 'Staff'} · manage in My profile</span> : <div className="flex flex-wrap items-center gap-2">
        <select aria-label={`Role for ${m.name}`} value={m.role} disabled={Boolean(m.deactivated_at)} onChange={ev => put(m, '', { role: ev.target.value })} className="min-h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm"><option value="ADMIN">Admin</option><option value="STAFF">Staff</option></select>
        <button type="button" onClick={() => { if (m.deactivated_at || confirm(`Turn off access for ${m.name}? They'll be signed out right away.`)) put(m, '/status', { active: Boolean(m.deactivated_at) }); }} className="btn btn-light min-h-0 border border-slate-200 px-3 py-2 text-xs">{m.deactivated_at ? 'Restore access' : 'Turn off access'}</button>
      </div>}
    </div>; })}</div>
    {e.member && <p role="alert" className="mt-3 text-sm text-red-600">{e.member}</p>}

    <form onSubmit={submit} className="surface-card mt-6 grid gap-5 p-6">
      <h3 className="text-lg font-semibold">Add a team member</h3>
      <div className="grid gap-4 sm:grid-cols-2">
        <label className="field">Full name<input required maxLength={120} value={form.data.name} onChange={ev => form.setData('name', ev.target.value)}/>{e.name && <small role="alert" className="font-normal text-red-600">{e.name}</small>}</label>
        <label className="field">Email<input type="email" required value={form.data.email} onChange={ev => form.setData('email', ev.target.value)}/>{e.email && <small role="alert" className="font-normal text-red-600">{e.email}</small>}</label>
        <label className="field">Temporary password<input type="text" required minLength={8} autoComplete="off" value={form.data.password} onChange={ev => form.setData('password', ev.target.value)}/><small className="font-normal text-slate">Share it privately; they can change it in My profile.</small>{e.password && <small role="alert" className="font-normal text-red-600">{e.password}</small>}</label>
        <fieldset><legend className="text-sm font-semibold text-navy">Role</legend><div className="mt-2 grid gap-2">{(Object.keys(roles) as Member['role'][]).map(role => <label key={role} className="flex items-start gap-2 text-sm"><input type="radio" name="role" className="mt-0.5 accent-[var(--brand-pink)]" checked={form.data.role === role} onChange={() => form.setData('role', role)}/>{roles[role]}</label>)}</div></fieldset>
      </div>
      <button disabled={form.processing} className="btn btn-pink w-fit">Add member</button>
    </form>
  </SettingsLayout>;
}
