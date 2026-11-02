import { Head, Link, usePage } from '@inertiajs/react';
import SettingsLayout from '../../Components/SettingsLayout';

type Guide = { title: string; steps: string[]; link?: [string, string]; adminOnly?: boolean };
const guides: Guide[] = [
  { title: 'Get ready to take bookings', adminOnly: true, link: ['/admin/properties', 'Open properties'], steps: [
    'Add a property under Properties, then add each room type with its rates, beds, amenities and photos.',
    'Publish the property. Drafts are hidden from guests.',
    'Set your currency, check-in times and booking reference under Settings → General.',
    'Choose how guests pay under Settings → Payments, and turn off test mode before going live.'] },
  { title: 'Change prices for holidays or long stays', adminOnly: true, link: ['/admin/settings/seasons', 'Seasonal rates'], steps: [
    'Use Seasonal rates for date ranges like Holy Week or Christmas: raise or lower by %, or set a fixed nightly price.',
    'Weekly and monthly stay discounts live under Pricing & taxes.',
    'Promo codes give a discount when a guest enters the code on the stay page.'] },
  { title: 'Confirm a GCash, Maya or bank payment', link: ['/admin/bookings', 'Open reservations'], steps: [
    'Guests who pay outside the app appear in Reservations with a yellow “payment to verify” box.',
    'Check your GCash, Maya or bank app for the amount and reference, then press Approve.',
    'Approving the deposit confirms the booking and emails the guest. Reject it if the money never arrived.'] },
  { title: 'Take a walk-in or phone booking', link: ['/admin/bookings', 'Open reservations'], steps: [
    'Use “New manual booking” in Reservations, then record the payment you received.',
    'Staff bookings skip the advance-notice rule, so same-day stays are fine.'] },
  { title: 'Day-of-stay workflow', link: ['/admin/bookings', 'Open reservations'], steps: [
    'Press Check in when the guest arrives and Check out when they leave.',
    'Checking out sends the thank-you email if it is turned on under Guest messages.',
    'Cancel bookings that will not happen; you can delete cancelled ones later to tidy the list and restore them from Deleted bookings.'] },
  { title: 'Emails guests receive', adminOnly: true, link: ['/admin/settings/messages', 'Guest messages'], steps: [
    'Each email can be turned off or reworded. Words in {braces} are filled in automatically.',
    'Use “Send test to me” to see the real email in your inbox before guests do.'] },
  { title: 'Add your team', adminOnly: true, link: ['/admin/settings/team', 'Users'], steps: [
    'Admins can change everything; staff handle reservations, payments and the calendar.',
    'Turn off access when someone leaves. Their past actions stay in the audit log.'] },
];

export default function Help() {
  const { auth } = usePage<{ auth: { user: { role: string } } }>().props;
  const visible = guides.filter(g => !g.adminOnly || auth.user.role === 'ADMIN');
  return <SettingsLayout title="Help & guides" subtitle="Short how-tos for the things you'll do most."><Head title="Help & guides · Settings"/>
    <div className="grid gap-4 xl:grid-cols-2">{visible.map(g => <section key={g.title} className="surface-card p-6">
      <h3 className="text-lg font-semibold">{g.title}</h3>
      <ol className="mt-3 grid list-decimal gap-2 pl-5 text-sm leading-6 text-slate">{g.steps.map(step => <li key={step}>{step}</li>)}</ol>
      {g.link && <Link href={g.link[0]} className="mt-4 inline-block text-sm font-semibold text-pink">{g.link[1]} →</Link>}
    </section>)}</div>
  </SettingsLayout>;
}
