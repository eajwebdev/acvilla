import { Head } from '@inertiajs/react';
import PageSign from '../../Components/PageSign';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';

export default function Legal({ tenant, title, body }: { tenant: SiteTenant; page: 'privacy' | 'terms'; title: string; body: string }) {
  return <GuestShell tenant={tenant}><Head title={tenant ? `${title} | ${tenant.business_name}` : title}/>
    <article className="container max-w-3xl py-14 sm:py-20">
      <PageSign eyebrow={tenant?.business_name || 'Legal'} title={title}/>
      <div className="surface-card mt-9 whitespace-pre-line p-6 leading-8 text-slate sm:p-10">{body}</div>
    </article>
  </GuestShell>;
}
