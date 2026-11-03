import { Head } from '@inertiajs/react';
import { Check, CheckCheck, Copy, Download, Minus, Plus } from 'lucide-react';
import { useEffect, useState } from 'react';
import Brand from '../../Components/Brand';
import GuestShell, { type SiteTenant } from '../../Components/GuestShell';
import PageSign from '../../Components/PageSign';
import '../../../css/quotation.css';

// Monthly PHP prices per property. Update these when EAJ confirms its rate card.
const BASE_PRICE = 3499;
// Booking Core plus every add-on comes to exactly 6,000 per property: keep the add-ons at 2,501 in total.
const ADDONS = [
  { id: 'pos', name: 'Point of sale', price: 799, description: 'Front desk sales, items, charges and transaction records.' },
  { id: 'messages', name: 'Guest messaging', price: 299, description: 'Message templates and booking communication tools.' },
  { id: 'payments', name: 'Online payment integration', price: 849, description: 'Checkout and payment review flow; live PayMongo connection requires provider setup.' },
  { id: 'face', name: 'Face recognition attendance', price: 554, description: 'Staff attendance records and manager review are ready. Camera matching requires integration and privacy review.' },
] as const;
const INCLUDED = ['Landing page', 'Booking website', 'Customer accounts', 'Property and room records', 'Availability calendar', 'Manual reservations', 'Guest and payment records', 'Advanced reports & insights', 'Staff access', 'Cloud hosting, SSL and backups'];
const CORE_MODULES = [
  { name: 'Cleaning & staff tasks', description: 'Turnover assignments, progress and task photos.' },
  { name: 'Promos & seasonal rates', description: 'Date-based rates, discount codes and offers.' },
];
// Agreed fixed price for one client, opened with /quotation?package=ac-villa. The public prices above are unchanged.
const CLIENT_PACKAGE = { id: 'ac-villa', client: 'AC Villa & Private Resort', price: 6000, addons: ['pos', 'messages'] } as const;
type AddonId = typeof ADDONS[number]['id'];
const addonIds = new Set<string>(ADDONS.map(addon => addon.id));
const peso = (amount: number) => `₱${amount.toLocaleString('en-PH')}`;
const clampCount = (value: string | null) => Math.max(1, Math.min(20, Number.parseInt(value || '', 10) || 1));

function initialSelection() {
  if (typeof window === 'undefined') return { properties: 1, addons: [] as AddonId[] };
  const params = new URLSearchParams(window.location.search);
  const addons = (params.get('addons') || '').split(',').filter((id): id is AddonId => addonIds.has(id));
  if (params.get('face') === '1' && !addons.includes('face')) addons.push('face');
  return { properties: clampCount(params.get('properties')), addons };
}

const openedPackage = () => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('package') === CLIENT_PACKAGE.id;

export default function Quotation({ tenant = null }: { tenant?: SiteTenant }) {
  const [isPackage] = useState(openedPackage);
  const [initial] = useState(initialSelection);
  const [properties, setProperties] = useState(initial.properties);
  const [selected, setSelected] = useState<AddonId[]>(initial.addons);
  const [copied, setCopied] = useState(false);
  const chosenAddons = ADDONS.filter(addon => selected.includes(addon.id));
  const perProperty = BASE_PRICE + chosenAddons.reduce((sum, addon) => sum + addon.price, 0);
  const total = perProperty * properties;
  const issued = new Intl.DateTimeFormat('en-PH', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Manila' }).format(new Date());

  useEffect(() => {
    if (isPackage) return;
    const urlAddons = ADDONS.filter(addon => selected.includes(addon.id));
    const url = new URL(window.location.href);
    ['mode', 'billing', 'face', 'website'].forEach(key => url.searchParams.delete(key));
    url.searchParams.set('properties', String(properties));
    if (urlAddons.length) url.searchParams.set('addons', urlAddons.map(addon => addon.id).join(','));
    else url.searchParams.delete('addons');
    window.history.replaceState(window.history.state, '', url);
  }, [isPackage, properties, selected]);

  function toggleAddon(id: AddonId) {
    setSelected(current => current.includes(id) ? current.filter(item => item !== id) : [...current, id]);
  }

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(window.location.href);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2500);
    } catch {
      window.prompt('Copy this quotation link', window.location.href);
    }
  }

  if (isPackage) return <PackageQuotation tenant={tenant} issued={issued} copied={copied} onCopy={copyLink}/>;

  return <GuestShell tenant={tenant}><Head title="Quotation"/><div className="eaj-quote print-area">
    <div className="eq-topline"/>
    <header className="eq-masthead">
      <Brand href="/platform"/>
      <span className="eq-document-label">COMMERCIAL QUOTATION</span>
    </header>

    <main className="eq-paper">
      <div className="eq-intro"><div><PageSign eyebrow="EAJ booking platform" title="Software for the Work *Behind Every Stay*"/><p className="eq-intro-copy">Every plan starts with Booking Core: your landing page, booking website and customer accounts. Add the modules you need; the amount updates as you go.</p></div><div className="eq-intro-side"><span>QUOTATION</span><strong>EAJ / 2026</strong><small>Prepared for review</small></div></div>

      <dl className="eq-meta"><div><dt>PREPARED FOR</dt><dd>Prospective client</dd></div><div><dt>PREPARED BY</dt><dd>EAJ Web Development Services</dd></div><div><dt>ISSUED</dt><dd>{issued}</dd></div><div><dt>BILLING</dt><dd>Monthly · per property</dd></div></dl>

      <div className="eq-layout"><div className="eq-content">
        <section className="eq-section" aria-labelledby="base-title"><SectionHeading number="01" label="Foundation" title="Booking Core" id="base-title"/><div className="eq-base"><div><p>Your landing page, booking website and customer accounts, with one place for the daily reservation workflow, from room availability to guest records and reporting.</p><ul>{INCLUDED.map(item => <li key={item}><Check size={14}/>{item}</li>)}</ul><div className="eq-core-modules">{CORE_MODULES.map(module => <div className="eq-core-module" key={module.name}><Check size={15}/><div><strong>{module.name}</strong><small>{module.description}</small><span>Included in Booking Core</span></div></div>)}</div></div><p className="eq-base-price"><strong>{peso(BASE_PRICE)}</strong><span>/ month / property</span></p></div></section>

        <section className="eq-section" aria-labelledby="modules-title"><SectionHeading number="02" label="Optional modules" title="Add only what you need" id="modules-title"/><p className="eq-section-copy">Paid modules are priced per property each month. Cleaning and promotions are already included in Booking Core.</p><div className="eq-modules">{ADDONS.map(addon => <button key={addon.id} type="button" role="switch" aria-checked={selected.includes(addon.id)} onClick={() => toggleAddon(addon.id)} className="eq-module"><span className="eq-toggle" aria-hidden="true"/><span className="eq-module-copy"><strong>{addon.name}</strong><small>{addon.description}</small></span><b>+ {peso(addon.price)}<small>/ mo</small></b></button>)}</div><p className="eq-fine">PayMongo payment links can be used for manual reservations. PayMongo transaction and message delivery fees are separate. Face recognition attendance requires supported cameras, staff consent and an agreed privacy process.</p></section>

        <section className="eq-section eq-terms" aria-labelledby="terms-title"><SectionHeading number="03" label="Commercial notes" title="Terms at a glance" id="terms-title"/><div className="eq-terms-grid"><p><strong>Monthly billing.</strong> The base subscription and selected paid modules are billed in advance per property.</p><p><strong>Included services.</strong> Hosting, SSL, routine updates and backups are part of Booking Core.</p><p><strong>External charges.</strong> Payment processing, message delivery and domain registration are separate.</p><p><strong>Final agreement.</strong> EAJ confirms scope, launch timing and final pricing before activation.</p></div></section>
      </div>

      <aside className="eq-summary" aria-labelledby="summary-title"><div className="eq-summary-head"><span>YOUR SELECTION</span><h2 id="summary-title">Monthly estimate</h2><p>All amounts in Philippine pesos.</p></div><div className="eq-summary-body"><div className="eq-property-row"><div><strong>Properties</strong><small>Price applies to each property</small></div><div className="eq-stepper no-print"><button type="button" aria-label="Remove one property" disabled={properties === 1} onClick={() => setProperties(value => Math.max(1, value - 1))}><Minus size={15}/></button><input aria-label="Number of properties" type="number" min="1" max="20" value={properties} onChange={event => setProperties(clampCount(event.target.value))}/><button type="button" aria-label="Add one property" disabled={properties === 20} onClick={() => setProperties(value => Math.min(20, value + 1))}><Plus size={15}/></button></div><span className="eq-print-count">{properties}</span></div><div className="eq-lines"><SummaryRow name="Booking Core" amount={BASE_PRICE} properties={properties}/>{chosenAddons.map(addon => <SummaryRow key={addon.id} name={addon.name} amount={addon.price} properties={properties}/>)}</div><div className="eq-total"><span>ESTIMATED MONTHLY TOTAL</span><strong>{peso(total)}</strong><small>{peso(perProperty)} per property · billed monthly</small></div><p className="eq-estimate-note">Add-on prices are indicative. EAJ will confirm the final offer with you.</p><div className="eq-actions no-print"><button type="button" className="eq-primary" onClick={() => window.print()}><Download size={16}/> Print / Save PDF</button><button type="button" className="eq-secondary" onClick={copyLink}>{copied ? <CheckCheck size={16}/> : <Copy size={16}/>} {copied ? 'Link copied' : 'Copy selection link'}</button></div></div></aside></div>

      <div className="eq-accept"><div><p className="eq-kicker">ACCEPTANCE</p><h2>Ready to proceed?</h2><p>Confirm this selection with EAJ before signing. We will provide the final service scope and launch schedule.</p></div><div className="eq-signatures"><div><span>EAJ Web Development Services</span><small>Authorized representative / Date</small></div><div><span>Client representative</span><small>Signature / Date</small></div></div></div>
      <footer className="eq-footer"><span>EAJ WEB DEVELOPMENT SERVICES</span><span>Indicative quotation · Subject to final agreement</span></footer>
    </main>
  </div></GuestShell>;
}

/** The agreed package: one fixed price, nothing to choose, so the total cannot change. */
function PackageQuotation({ tenant, issued, copied, onCopy }: { tenant: SiteTenant; issued: string; copied: boolean; onCopy: () => void }) {
  const modules = ADDONS.filter(addon => (CLIENT_PACKAGE.addons as readonly string[]).includes(addon.id));
  return <GuestShell tenant={tenant}><Head title={`Quotation | ${CLIENT_PACKAGE.client}`}/><div className="eaj-quote print-area">
    <div className="eq-topline"/>
    <header className="eq-masthead">
      <Brand href="/platform"/>
      <span className="eq-document-label">COMMERCIAL QUOTATION</span>
    </header>

    <main className="eq-paper">
      <div className="eq-intro"><div><PageSign eyebrow="Your quotation" title="Your AC Villa *Package*"/><p className="eq-intro-copy">One fixed monthly price for your landing page, booking website, customer accounts and the tools you run the resort with.</p></div><div className="eq-intro-side"><span>QUOTATION</span><strong>EAJ / 2026</strong><small>Fixed package</small></div></div>

      <dl className="eq-meta"><div><dt>PREPARED FOR</dt><dd>{CLIENT_PACKAGE.client}</dd></div><div><dt>PREPARED BY</dt><dd>EAJ Web Development Services</dd></div><div><dt>ISSUED</dt><dd>{issued}</dd></div><div><dt>BILLING</dt><dd>Monthly · one property</dd></div></dl>

      <div className="eq-layout"><div className="eq-content">
        <section className="eq-section" aria-labelledby="base-title"><SectionHeading number="01" label="Foundation" title="Booking Core" id="base-title"/><div className="eq-base"><div><p>One place for the daily reservation workflow, from room availability to guest records and reporting.</p><ul>{INCLUDED.map(item => <li key={item}><Check size={14}/>{item}</li>)}</ul><div className="eq-core-modules">{CORE_MODULES.map(module => <div className="eq-core-module" key={module.name}><Check size={15}/><div><strong>{module.name}</strong><small>{module.description}</small><span>Included in Booking Core</span></div></div>)}</div></div></div></section>

        <section className="eq-section" aria-labelledby="modules-title"><SectionHeading number="02" label="Modules" title="In your package" id="modules-title"/><div className="eq-modules">{modules.map(addon => <div key={addon.id} className="eq-module eq-module-fixed"><Check size={17} className="eq-fixed-check"/><span className="eq-module-copy"><strong>{addon.name}</strong><small>{addon.description}</small></span><b>Included</b></div>)}</div></section>

        <section className="eq-section eq-terms" aria-labelledby="terms-title"><SectionHeading number="03" label="Commercial notes" title="Terms at a glance" id="terms-title"/><div className="eq-terms-grid"><p><strong>Monthly billing.</strong> The package is billed monthly in advance for one property.</p><p><strong>Included services.</strong> Hosting, SSL, routine updates and backups are part of Booking Core.</p><p><strong>External charges.</strong> Message delivery and domain registration are separate.</p><p><strong>Before activation.</strong> EAJ confirms the service scope and launch timing.</p></div></section>
      </div>

      <aside className="eq-summary" aria-labelledby="summary-title"><div className="eq-summary-head"><span>YOUR PACKAGE</span><h2 id="summary-title">Monthly total</h2><p>All amounts in Philippine pesos.</p></div><div className="eq-summary-body"><div className="eq-lines !mt-0 !border-t-0 !pt-0">{['Booking Core', ...modules.map(addon => addon.name)].map(item => <div key={item} className="eq-line"><span>{item}</span><strong>Included</strong></div>)}</div><div className="eq-total"><span>FIXED MONTHLY TOTAL</span><strong>{peso(CLIENT_PACKAGE.price)}</strong><small>One property · billed monthly</small></div><div className="eq-actions no-print"><button type="button" className="eq-primary" onClick={() => window.print()}><Download size={16}/> Print / Save PDF</button><button type="button" className="eq-secondary" onClick={onCopy}>{copied ? <CheckCheck size={16}/> : <Copy size={16}/>} {copied ? 'Link copied' : 'Copy quotation link'}</button></div></div></aside></div>

      <div className="eq-accept"><div><p className="eq-kicker">ACCEPTANCE</p><h2>Ready to proceed?</h2><p>Sign below to accept this package. We will confirm the service scope and launch schedule.</p></div><div className="eq-signatures"><div><span>EAJ Web Development Services</span><small>Authorized representative / Date</small></div><div><span>{CLIENT_PACKAGE.client}</span><small>Signature / Date</small></div></div></div>
      <footer className="eq-footer"><span>EAJ WEB DEVELOPMENT SERVICES</span><span>Fixed package · {peso(CLIENT_PACKAGE.price)} per month</span></footer>
    </main>
  </div></GuestShell>;
}

function SectionHeading({ number, label, title, id }: { number: string; label: string; title: string; id: string }) {
  return <div className="eq-section-heading"><span>{number}</span><div><p>{label}</p><h2 id={id}>{title}</h2></div></div>;
}

function SummaryRow({ name, amount, properties }: { name: string; amount: number; properties: number }) {
  return <div className="eq-line"><span>{name}<small>× {properties}</small></span><strong>{peso(amount * properties)}</strong></div>;
}
