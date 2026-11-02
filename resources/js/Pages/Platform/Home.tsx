import { Head, Link } from '@inertiajs/react';
import { ArrowRight, BadgePercent, BedDouble, CalendarDays, ChartLine, Check, Clock3, CreditCard, Globe, Mail, ShoppingBasket, SprayCan, Star, Users } from 'lucide-react';
import Shell from '../../Components/Shell';

const features = [
  [Globe, 'Your own booking site', 'A branded guest site with live availability, add-ons, promo codes and a clear price before payment.', 'lg:col-span-2'],
  [CalendarDays, 'Availability calendar', 'Every room, booking and blocked date on one timeline. No double bookings, ever.', ''],
  [CreditCard, 'Flexible payments', 'GCash, Maya, bank transfer or cash, with deposits, balance reminders and staff verification.', ''],
  [SprayCan, 'Housekeeping', 'Turnover tasks with checklists and photo proof, assigned straight to staff phones.', ''],
  [ChartLine, 'Insights that matter', 'Occupancy, ADR, revenue by channel, expenses and targets, exportable in one click.', 'lg:col-span-2'],
  [Clock3, 'Staff attendance', 'Clock in and out from the staff workspace, with audited manager corrections.', ''],
  [ShoppingBasket, 'Point of sale', 'Sell snacks, tours and extras, then charge them to a room or settle on the spot.', 'lg:col-span-2'],
  [Users, 'Guest CRM', 'Profiles, stay history, VIP flags and private notes for every repeat guest.', 'lg:col-span-2'],
] as const;

const workspaces = [
  ['Owners & managers', 'The full picture: today’s arrivals, revenue, reports, team and settings.', ['Dashboard & insights', 'Rates, seasons and promos', 'Team and audit log']],
  ['Front desk & staff', 'A phone-first workspace focused on the shift ahead.', ['Assigned cleaning tasks', 'Clock in / out', 'Bookings and POS']],
  ['Guests', 'A fast, trustworthy way to book direct and keep plans close.', ['Live availability', 'Clear pricing & policies', 'Reviews after the stay']],
] as const;

const faqs = [
  ['Do I need my own website?', 'No. Every client gets a branded booking site with their logo and colours. You can also link to it from Facebook, Instagram or an existing website.'],
  ['Which payment methods are supported?', 'GCash, Maya, bank transfer, cash on arrival and manual payment requests, with optional deposits. Staff verify each payment before the booking is confirmed.'],
  ['Can my staff use it on their phones?', 'Yes. Staff get a dedicated mobile workspace for cleaning tasks, attendance, bookings and POS.'],
  ['Is my data safe?', 'Each business is fully isolated, access is role-based, every change is audit-logged and the database is backed up nightly.'],
] as const;

export default function Home() {
  return <Shell>
    <Head title="Hospitality software"/>
    <section className="relative isolate overflow-hidden bg-navy text-white">
      <div className="absolute -left-40 top-20 -z-10 size-[36rem] rounded-full bg-pink/25 blur-[120px]"/>
      <div className="absolute -right-40 -top-40 -z-10 size-[30rem] rounded-full bg-indigo-500/20 blur-[110px]"/>
      <div className="absolute inset-0 -z-10 bg-[radial-gradient(rgba(255,255,255,.07)_1px,transparent_1px)] [background-size:22px_22px] [mask-image:linear-gradient(180deg,#000,transparent_85%)]"/>
      <div className="container grid items-center gap-14 py-20 md:py-28 lg:grid-cols-[1.05fr_.95fr]">
        <div>
          <p className="eaj-kicker">StaySuite by EAJ</p>
          <h1 className="mt-6 max-w-3xl font-serif text-5xl font-medium leading-[1.02] tracking-[-.035em] md:text-7xl">Your hospitality business, <em className="text-[#ff6b9f]">beautifully</em> connected.</h1>
          <p className="mt-7 max-w-xl text-lg leading-8 text-white/70">Direct bookings, payments, housekeeping and insights for resorts, inns and vacation rentals across the Philippines, in one calm workspace.</p>
          <div className="mt-9 flex flex-wrap gap-3"><Link href="/quotation" className="btn btn-pink">Build your quote <ArrowRight size={18}/></Link><Link href="/login" className="btn border-white/20 bg-white/10 text-white backdrop-blur hover:bg-white/15">Try the live demo</Link></div>
          <ul className="mt-9 flex flex-wrap gap-x-6 gap-y-2 text-sm text-white/65">{['Peso pricing & local payments', 'Phone-ready staff workspace', 'Nightly backups'].map(item => <li key={item} className="inline-flex items-center gap-2"><Check size={16} className="text-[#ff6b9f]"/>{item}</li>)}</ul>
        </div>
        <ProductPreview/>
      </div>
    </section>

    <section className="container py-20 md:py-28" aria-labelledby="features">
      <div className="max-w-2xl"><p className="section-eyebrow">Everything in one place</p><h2 id="features" className="section-title mt-3">Built for how Filipino properties actually run.</h2><p className="mt-4 text-lg leading-8 text-slate">From the first enquiry on Messenger to the last towel folded, StaySuite covers the whole stay.</p></div>
      <div className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {features.map(([Icon, title, body, span]) => <article key={title} className={`surface-card interactive p-7 ${span}`}>
          <span className="grid size-11 place-items-center rounded-xl bg-pink/10 text-pink"><Icon size={21}/></span>
          <h3 className="mt-6 text-lg font-bold tracking-[-.01em]">{title}</h3><p className="mt-2 text-[15px] leading-7 text-slate">{body}</p>
        </article>)}
      </div>
    </section>

    <section className="border-y border-[var(--border)] bg-white py-20 md:py-28" aria-labelledby="workspaces">
      <div className="container">
        <div className="flex flex-wrap items-end justify-between gap-6"><div className="max-w-2xl"><p className="section-eyebrow">One platform, three workspaces</p><h2 id="workspaces" className="section-title mt-3">The right tools for every role.</h2></div><Link href="/login" className="btn btn-light">See each workspace <ArrowRight size={17}/></Link></div>
        <div className="mt-12 grid gap-5 lg:grid-cols-3">
          {workspaces.map(([title, body, points], index) => <article key={title} className="rounded-[var(--radius-card)] border border-[var(--border)] bg-[var(--background)] p-7">
            <span className="font-mono text-xs font-semibold text-pink">0{index + 1}</span>
            <h3 className="mt-3 text-xl font-bold">{title}</h3><p className="mt-2 leading-7 text-slate">{body}</p>
            <ul className="mt-6 grid gap-2.5 border-t border-[var(--border)] pt-5">{points.map(point => <li key={point} className="flex items-center gap-2.5 text-sm font-semibold"><span className="grid size-5 place-items-center rounded-full bg-emerald-50 text-emerald-600"><Check size={13}/></span>{point}</li>)}</ul>
          </article>)}
        </div>
      </div>
    </section>

    <section className="container grid gap-12 py-20 md:py-28 lg:grid-cols-[.85fr_1.15fr]" aria-labelledby="faq">
      <div><p className="section-eyebrow">Questions</p><h2 id="faq" className="section-title mt-3">Good to know.</h2><p className="mt-4 leading-7 text-slate">Still deciding? Build a quotation to see exactly what your setup costs each month.</p><Link href="/quotation" className="btn btn-navy mt-7">Open the quotation builder <ArrowRight size={17}/></Link></div>
      <div className="grid gap-3">{faqs.map(([q, a]) => <details key={q} className="surface-card group p-5 [&_summary::-webkit-details-marker]:hidden"><summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold">{q}<span className="grid size-7 shrink-0 place-items-center rounded-full bg-[var(--surface-muted)] text-lg leading-none transition group-open:rotate-45">+</span></summary><p className="mt-3 text-[15px] leading-7 text-slate">{a}</p></details>)}</div>
    </section>

    <section className="container pb-20 md:pb-28">
      <div className="relative isolate overflow-hidden rounded-[28px] bg-navy px-7 py-14 text-white sm:px-14">
        <div className="absolute -right-20 -top-20 -z-10 size-80 rounded-full bg-pink/35 blur-[90px]"/>
        <div className="flex flex-wrap items-center justify-between gap-8"><div className="max-w-xl"><h2 className="font-serif text-4xl font-medium tracking-[-.03em] md:text-5xl">Ready for more direct bookings?</h2><p className="mt-4 text-white/70">Set up your property, share your booking link and start taking reservations this week.</p></div><div className="flex flex-wrap gap-3"><Link href="/quotation" className="btn btn-pink">Get started <ArrowRight size={18}/></Link><Link href="/stays" className="btn border-white/20 bg-white/10 text-white hover:bg-white/15">View a guest site</Link></div></div>
      </div>
    </section>
  </Shell>;
}

/** A static, decorative glimpse of the operations dashboard for the hero. */
function ProductPreview() {
  const bars = [42, 58, 51, 74, 66, 88, 79];
  return <div className="relative mx-auto w-full max-w-lg lg:max-w-none" aria-hidden="true">
    <div className="rounded-[26px] border border-white/10 bg-white/[.06] p-3 shadow-[0_40px_120px_rgba(0,0,0,.45)] backdrop-blur-xl">
      <div className="rounded-[18px] bg-white p-5 text-navy">
        <div className="flex items-center justify-between"><div><p className="text-[11px] font-bold uppercase tracking-[.14em] text-slate">Today</p><p className="mt-1 text-lg font-bold">Good morning, Andrea</p></div><span className="pill border-emerald-200 bg-emerald-50 text-emerald-800">● Live</span></div>
        <div className="mt-5 grid grid-cols-3 gap-2.5">{[['Occupancy', '86%', BedDouble], ['Revenue', '₱184K', ChartLine], ['Rating', '4.9', Star]].map(([label, value, Icon]) => { const I = Icon as typeof Star; return <div key={label as string} className="rounded-xl border border-[var(--border)] p-3"><I size={15} className="text-pink"/><p className="mt-2 text-[10px] font-bold uppercase tracking-[.1em] text-slate">{label as string}</p><p className="font-mono text-lg font-semibold">{value as string}</p></div>; })}</div>
        <div className="mt-3 rounded-xl border border-[var(--border)] p-4"><div className="flex items-center justify-between text-xs font-bold"><span>Bookings this week</span><span className="text-emerald-600">+18%</span></div><div className="mt-4 flex h-24 items-end gap-2">{bars.map((h, i) => <span key={i} className={`flex-1 rounded-t-md ${i === 5 ? 'bg-pink' : 'bg-navy/15'}`} style={{ height: `${h}%` }}/>)}</div></div>
        <div className="mt-3 grid gap-2">{[['Maria Santos', 'Deluxe King · 3 nights', 'CONFIRMED'], ['John Reyes', 'Family Suite · 2 nights', 'CHECKED IN']].map(([name, room, status]) => <div key={name} className="flex items-center gap-3 rounded-xl bg-[var(--surface-muted)] px-3 py-2.5"><span className="grid size-8 place-items-center rounded-full bg-pink/15 text-xs font-bold text-pink">{name[0]}</span><div className="min-w-0 flex-1"><p className="truncate text-sm font-bold">{name}</p><p className="truncate text-xs text-slate">{room}</p></div><span className="pill border-emerald-200 bg-emerald-50 text-emerald-800">{status}</span></div>)}</div>
      </div>
    </div>
    <div className="absolute -bottom-6 -left-4 hidden items-center gap-3 rounded-2xl bg-white p-3.5 pr-5 text-navy shadow-2xl sm:flex"><span className="grid size-10 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><BadgePercent size={19}/></span><div><p className="text-xs font-semibold text-slate">New direct booking</p><p className="text-sm font-bold">₱12,450 · GCash</p></div></div>
    <div className="absolute -right-3 -top-5 hidden items-center gap-2.5 rounded-2xl bg-white p-3 pr-4 text-navy shadow-2xl sm:flex"><span className="grid size-8 place-items-center rounded-lg bg-pink/10 text-pink"><Mail size={16}/></span><p className="text-xs font-bold">Pre-arrival email sent</p></div>
  </div>;
}
