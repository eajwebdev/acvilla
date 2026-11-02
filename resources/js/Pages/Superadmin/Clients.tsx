import { Head, router, useForm } from '@inertiajs/react';
import { Building2, Plus, Search, X } from 'lucide-react';
import { useState } from 'react';
import DashboardShell from '../../Components/DashboardShell';
import { Avatar, Card, Empty, Status } from '../../Components/ops';
import { money } from '../../types';

type Tenant = { id: number; business_name: string; status: string; subscription_status: string; properties_count: number; subscription_ends_at: string | null };
type Plan = { id: number; name: string; billing_price: string; billing_interval: string };
const STATUSES = ['ALL', 'ACTIVE', 'LOCKED', 'SUSPENDED'] as const;

export default function Clients({ tenants, plans }: { tenants: Tenant[]; plans: Plan[] }) {
  const [onboarding, setOnboarding] = useState(tenants.length === 0);
  const [query, setQuery] = useState('');
  const [filter, setFilter] = useState<(typeof STATUSES)[number]>('ALL');
  const shown = tenants.filter(t => (filter === 'ALL' || t.status === filter) && t.business_name.toLowerCase().includes(query.trim().toLowerCase()));
  return <DashboardShell platform title="Clients & subscriptions" subtitle="Onboard businesses and manage their access."
    actions={<button type="button" className="btn btn-pink" onClick={() => setOnboarding(!onboarding)}>{onboarding ? <><X size={16}/> Close</> : <><Plus size={16}/> Onboard client</>}</button>}>
    <Head title="Clients"/>
    {onboarding && <OnboardForm onDone={() => setOnboarding(false)}/>}
    <Card title={`${tenants.length} ${tenants.length === 1 ? 'client' : 'clients'}`} subtitle="Change account status or assign a subscription plan."
      action={<div className="flex flex-wrap items-center gap-2">
        <label className="relative"><Search size={15} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[var(--muted)]"/><input className="input !min-h-9 !w-52 !pl-9" placeholder="Search clients" aria-label="Search clients" value={query} onChange={e => setQuery(e.target.value)}/></label>
        <div className="seg" role="group" aria-label="Filter by status">{STATUSES.map(s => <button key={s} type="button" aria-pressed={filter === s} onClick={() => setFilter(s)}>{s === 'ALL' ? 'All' : s.charAt(0) + s.slice(1).toLowerCase()}</button>)}</div>
      </div>}>
      {shown.length ? <ul className="divide-y divide-[var(--line)]">{shown.map(t => <ClientRow key={t.id} tenant={t} plans={plans}/>)}</ul>
        : <Empty icon={<Building2 size={20}/>} title={tenants.length ? 'No clients match' : 'No clients yet'} body={tenants.length ? 'Try another search or status filter.' : 'Onboard a business to start their 14-day trial.'}/>}
    </Card>
  </DashboardShell>;
}

function ClientRow({ tenant, plans }: { tenant: Tenant; plans: Plan[] }) {
  const [plan, setPlan] = useState(plans[0]?.id ?? 0);
  return <li className="grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
    <div className="flex min-w-0 items-center gap-3"><Avatar name={tenant.business_name} size={40}/>
      <div className="min-w-0"><p className="truncate font-bold">{tenant.business_name}</p>
        <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-[var(--muted)]"><Status value={tenant.status}/><Status value={tenant.subscription_status}/><span>{tenant.properties_count} {tenant.properties_count === 1 ? 'property' : 'properties'}</span><span>· Ends {tenant.subscription_ends_at?.slice(0, 10) || '-'}</span></div>
      </div>
    </div>
    <div className="flex flex-wrap items-center gap-2">
      <select aria-label={`Account status for ${tenant.business_name}`} className="input !min-h-9 !w-auto" value={tenant.status} onChange={e => router.put(`/superadmin/clients/${tenant.id}/status`, { status: e.target.value }, { preserveScroll: true })}>{['ACTIVE', 'LOCKED', 'SUSPENDED'].map(s => <option key={s} value={s}>{s.charAt(0) + s.slice(1).toLowerCase()}</option>)}</select>
      <select aria-label={`Subscription plan for ${tenant.business_name}`} className="input !min-h-9 !w-auto" value={plan} onChange={e => setPlan(Number(e.target.value))}>{plans.map(p => <option key={p.id} value={p.id}>{p.name} · {money(p.billing_price)} / {p.billing_interval.toLowerCase()}</option>)}</select>
      <button type="button" className="btn btn-navy btn-sm" disabled={!plan} onClick={() => router.post(`/superadmin/clients/${tenant.id}/subscription`, { subscription_plan_id: plan, starts_at: new Date().toISOString().slice(0, 10) }, { preserveScroll: true })}>Assign plan</button>
    </div>
  </li>;
}

function OnboardForm({ onDone }: { onDone: () => void }) {
  const form = useForm({ business_name: '', business_email: '', business_phone: '', admin_name: '', admin_email: '', admin_password: '' });
  const field = (key: keyof typeof form.data, label: string, props: React.InputHTMLAttributes<HTMLInputElement> = {}) => <label className="field">{label}
    <input {...props} value={form.data[key]} onChange={e => form.setData(key, e.target.value)}/>{form.errors[key] && <span className="text-xs font-semibold text-[var(--danger)]">{form.errors[key]}</span>}</label>;
  return <Card className="mb-6" title="Onboard a client" subtitle="Creates the business, its first administrator and a 14-day trial.">
    <form onSubmit={e => { e.preventDefault(); form.post('/superadmin/clients', { preserveScroll: true, onSuccess: () => { form.reset(); onDone(); } }); }} className="grid gap-4 p-5 md:grid-cols-3">
      {field('business_name', 'Business name', { required: true })}
      {field('business_email', 'Business email', { type: 'email', required: true })}
      {field('business_phone', 'Business phone', { type: 'tel' })}
      {field('admin_name', 'Administrator name', { required: true })}
      {field('admin_email', 'Administrator email', { type: 'email', required: true })}
      {field('admin_password', 'Temporary password', { type: 'password', required: true, minLength: 12, autoComplete: 'new-password' })}
      <div className="flex items-center gap-3 md:col-span-3"><button className="btn btn-pink" disabled={form.processing}>Create 14-day trial</button><p className="text-xs text-[var(--muted)]">Share the temporary password securely; they can change it in My profile.</p></div>
    </form>
  </Card>;
}
