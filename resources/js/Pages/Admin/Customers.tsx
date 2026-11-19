import { Head, Link, router } from '@inertiajs/react';
import { Search, Star, UserRound, UserX } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import DashboardShell from '../../Components/DashboardShell';
import Pagination from '../../Components/Pagination';
import { money } from '../../types';

type Customer = {
  id: number;
  name: string;
  email: string | null;
  phone: string | null;
  status: 'ACTIVE' | 'VIP' | 'BLOCKED';
  bookings_count: number;
  bookings_max_check_out: string | null;
  total_spend: string | null;
};
type Page = { data: Customer[]; links: { url: string | null; label: string; active: boolean }[]; total: number };

export default function Customers({
  customers,
  filters,
}: {
  customers: Page;
  filters: { search?: string; status?: string };
}) {
  const [search, setSearch] = useState(filters.search || '');
  const visit = (next: Record<string, string>) =>
    router.get(
      '/admin/customers',
      { search: search || undefined, status: filters.status || undefined, ...next },
      { preserveState: true, replace: true },
    );
  const submit = (event: FormEvent) => {
    event.preventDefault();
    visit({ search });
  };
  const day = (value: string | null) =>
    value
      ? new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('en-PH', { dateStyle: 'medium' })
      : 'No stay yet';

  return (
    <DashboardShell
      title="Customers"
      subtitle={`${customers.total} guest profiles with booking and spend history.`}
      actions={
        <form
          onSubmit={submit}
          className="flex items-center gap-2 rounded-xl border border-[var(--line)] bg-white px-3"
        >
          <Search size={15} />
          <input
            aria-label="Search customers"
            value={search}
            onChange={event => setSearch(event.target.value)}
            placeholder="Name, email or phone"
            className="w-48 bg-transparent py-2 text-sm outline-none"
          />
        </form>
      }
    >
      <Head title="Customers" />
      <div className="mb-4 flex flex-wrap gap-2">
        {[
          ['', 'All'],
          ['ACTIVE', 'Active'],
          ['VIP', 'VIP'],
          ['BLOCKED', 'Blocked'],
        ].map(([value, label]) => (
          <button
            key={label}
            type="button"
            aria-pressed={(filters.status || '') === value}
            onClick={() => visit({ status: value })}
            className={`rounded-lg px-3 py-2 text-sm font-semibold ${(filters.status || '') === value ? 'bg-navy text-white' : 'bg-white text-slate'}`}
          >
            {label}
          </button>
        ))}
      </div>
      <div className="overflow-hidden rounded-2xl border border-[var(--line)] bg-white">
        <div className="hidden grid-cols-[minmax(220px,1.6fr)_100px_130px_130px_90px] gap-4 border-b border-[var(--line)] bg-paper px-5 py-3 text-xs font-bold tracking-wide text-[var(--muted)] uppercase md:grid">
          <span>Customer</span>
          <span>Bookings</span>
          <span>Total paid</span>
          <span>Last stay</span>
          <span>Status</span>
        </div>
        {customers.data.map(customer => (
          <Link
            key={customer.id}
            href={`/admin/customers/${customer.id}`}
            className="grid gap-2 border-b border-[var(--line)] px-5 py-4 transition last:border-0 hover:bg-paper md:grid-cols-[minmax(220px,1.6fr)_100px_130px_130px_90px] md:items-center md:gap-4"
          >
            <span className="flex min-w-0 items-center gap-3">
              <span className="grid size-10 shrink-0 place-items-center rounded-full bg-slate-100">
                <UserRound size={18} />
              </span>
              <span className="min-w-0">
                <strong className="block truncate">{customer.name}</strong>
                <small className="block truncate text-slate">
                  {customer.email || customer.phone || 'No contact details'}
                </small>
              </span>
            </span>
            <span className="num text-sm">
              <span className="text-slate md:hidden">Bookings: </span>
              {customer.bookings_count}
            </span>
            <span className="num text-sm font-semibold">
              <span className="text-slate md:hidden">Paid: </span>
              {money(customer.total_spend || 0)}
            </span>
            <span className="text-sm">
              <span className="text-slate md:hidden">Last stay: </span>
              {day(customer.bookings_max_check_out)}
            </span>
            <span
              className={`w-fit rounded-full px-2 py-1 text-[10px] font-bold ${customer.status === 'VIP' ? 'bg-amber-100 text-amber-800' : customer.status === 'BLOCKED' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700'}`}
            >
              {customer.status === 'VIP' ? (
                <>
                  <Star className="mr-1 inline" size={10} />
                  VIP
                </>
              ) : customer.status === 'BLOCKED' ? (
                <>
                  <UserX className="mr-1 inline" size={10} />
                  BLOCKED
                </>
              ) : (
                'ACTIVE'
              )}
            </span>
          </Link>
        ))}
        {!customers.data.length && (
          <div className="p-10 text-center text-sm text-slate">No customers match these filters.</div>
        )}
      </div>
      <Pagination links={customers.links} />
    </DashboardShell>
  );
}
