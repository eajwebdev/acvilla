import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import SettingsLayout from '../../Components/SettingsLayout';

const included = ['Business details, branding and every setting', 'Properties and rooms, with rates and photos', 'Seasonal rates, promo codes and add-ons',
  'Guest message templates', 'Blocked dates', 'All bookings (including deleted ones) with prices, payments and sent emails', 'Team members (names, emails and roles; never passwords)'];

export default function Backup() {
  return <SettingsLayout title="Download backup" subtitle="A complete copy of your business data as one JSON file."><Head title="Download backup · Settings"/>
    <div className="surface-card grid max-w-2xl gap-5 p-6">
      <div><h3 className="font-semibold">What's included</h3><ul className="mt-3 grid gap-1.5 text-sm text-slate">{included.map(item => <li key={item} className="flex gap-2"><span aria-hidden className="text-pink">•</span>{item}</li>)}</ul></div>
      <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">The file contains guest names, emails and phone numbers. Store it somewhere private.</p>
      <a href="/admin/settings/backup/download" className="btn btn-pink w-fit"><Download size={17}/> Download backup</a>
      <p className="text-xs text-slate">Each download is recorded in the audit log.</p>
    </div>
  </SettingsLayout>;
}
