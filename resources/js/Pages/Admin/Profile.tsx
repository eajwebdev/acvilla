import { Head, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
import SettingsLayout from '../../Components/SettingsLayout';

function Field({ label, hint, error, children }: { label: string; hint?: string; error?: string; children: ReactNode }) {
  return <label className="field">{label}{children}{hint && <small className="font-normal text-slate">{hint}</small>}{error && <small role="alert" className="font-normal text-red-600">{error}</small>}</label>;
}

export default function Profile({ profile, canDelete }: { profile: { name: string; email: string; role: string }; canDelete: boolean }) {
  const details = useForm({ name: profile.name, email: profile.email });
  const password = useForm({ current_password: '', password: '', password_confirmation: '' });
  const remove = useForm({ password: '' });
  const saveDetails = (ev: FormEvent) => { ev.preventDefault(); details.put('/admin/settings/profile', { preserveScroll: true, onSuccess: () => details.setDefaults() }); };
  const savePassword = (ev: FormEvent) => { ev.preventDefault(); password.put('/admin/settings/profile/password', { preserveScroll: true, onSuccess: () => password.reset() }); };
  const deleteAccount = (ev: FormEvent) => { ev.preventDefault(); if (confirm('Delete your account permanently? You will be signed out.')) remove.delete('/admin/settings/profile', { preserveScroll: true }); };
  const initial = (details.data.name.trim()[0] || '?').toUpperCase();

  return <SettingsLayout title="My profile" subtitle="Shown in the header, the audit log and booking history."><Head title="My profile · Settings"/>
    <div className="grid max-w-2xl gap-6">
      <form onSubmit={saveDetails} className="surface-card grid gap-5 p-6">
        <div className="flex items-center gap-4"><span className="grid size-16 place-items-center rounded-full bg-pink/10 text-2xl font-bold text-pink" aria-hidden>{initial}</span><div><p className="font-semibold">{details.data.name || 'Your name'}</p><p className="text-sm text-slate">{profile.role === 'ADMIN' ? 'Admin' : 'Staff'}</p></div></div>
        <Field label="Full name" hint="This is how you appear to your team." error={details.errors.name}><input required maxLength={120} autoComplete="name" value={details.data.name} onChange={ev => details.setData('name', ev.target.value)}/></Field>
        <Field label="Email address" hint="You sign in with this address." error={details.errors.email}><input type="email" required autoComplete="email" value={details.data.email} onChange={ev => details.setData('email', ev.target.value)}/></Field>
        <button disabled={details.processing || !details.isDirty} className="btn btn-pink w-fit disabled:opacity-50">Save profile</button>
      </form>

      <form onSubmit={savePassword} className="surface-card grid gap-5 p-6">
        <h3 className="text-lg font-semibold">Change password</h3>
        <Field label="Current password" error={password.errors.current_password}><input type="password" required autoComplete="current-password" value={password.data.current_password} onChange={ev => password.setData('current_password', ev.target.value)}/></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label="New password" hint="At least 8 characters." error={password.errors.password}><input type="password" required minLength={8} autoComplete="new-password" value={password.data.password} onChange={ev => password.setData('password', ev.target.value)}/></Field>
          <Field label="Confirm new password"><input type="password" required minLength={8} autoComplete="new-password" value={password.data.password_confirmation} onChange={ev => password.setData('password_confirmation', ev.target.value)}/></Field>
        </div>
        <div className="flex flex-wrap items-center gap-3"><button disabled={password.processing} className="btn btn-navy w-fit">Change password</button><p className="text-xs text-slate">You'll stay signed in here; other devices are signed out.</p></div>
      </form>

      <form id="delete-account" onSubmit={deleteAccount} className="surface-card grid gap-4 border-red-200 p-6">
        <h3 className="text-lg font-semibold text-red-700">Delete account</h3>
        <p className="text-sm text-slate">Removes your login. Bookings, payments and audit history stay with the business.</p>
        {canDelete ? <>
          <Field label="Confirm with your password" error={remove.errors.password}><input type="password" required autoComplete="current-password" value={remove.data.password} onChange={ev => remove.setData('password', ev.target.value)}/></Field>
          <button disabled={remove.processing} className="btn w-fit bg-red-600 text-white hover:bg-red-700">Delete my account</button>
        </> : <p role="status" className="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">You're the only active admin. Make another team member an admin before deleting your account.</p>}
      </form>
    </div>
  </SettingsLayout>;
}
