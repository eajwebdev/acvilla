import { Head, useForm } from '@inertiajs/react';
import { CheckCheck, Copy, Download, Percent } from 'lucide-react';
import { useState, type FormEvent, type ReactNode } from 'react';
import '../../../css/quotation.css';
import type { SiteTenant } from '../../Components/GuestShell';

/*
 * EAJ's quotation for the all-inclusive package, laid out as three A4 sheets in EAJ's own brand (navy and crimson)
 * so it prints or saves to PDF exactly as it looks. Prices, the client, the quotation number and dates come from
 * config/quotation.php; nothing here changes what anyone is charged.
 */

type PackageDiscount = { percent: number; amount: number; label: string; valid_until: string | null };
type QuotationDocument = {
  number: string;
  issued: string;
  valid_until: string;
  prepared_by: string;
  prepared_by_title: string;
};
type PackageOffer = {
  price: number;
  setup_fee: number;
  max_percent: number;
  discount: PackageDiscount | null;
  client: { name: string; business: string } | null;
  document?: QuotationDocument;
  can_discount?: boolean;
};

const FALLBACK_OFFER: PackageOffer = { price: 6000, setup_fee: 12000, max_percent: 0, discount: null, client: null };
const OFFER_DAYS = [7, 14, 30, 60] as const;
const SHEETS = 3;

const peso = (amount: number) => `₱${amount.toLocaleString('en-PH')}`;

const BENEFITS = [
  {
    title: 'Prevent double bookings',
    body: 'Every reservation, including the whole-resort package, goes through one availability check. A taken slot refuses a second booking, even if two people try at once.',
  },
  {
    title: 'Take reservations online',
    body: 'Guests see open dates on your website and send a request. They upload the downpayment receipt and you confirm it in one place, without chasing chats.',
  },
  {
    title: 'Run the front desk and your team',
    body: 'Sell food, drinks and extras and charge them to the booking. Staff get their own login for cleaning tasks and attendance.',
  },
  {
    title: 'Know where your money is',
    body: 'See what guests owe, what you received and what you spent, with occupancy and income reports ready to open.',
  },
];

const MODULE_GROUPS: { title: string; items: string[] }[] = [
  {
    title: 'Website and online booking',
    items: [
      'Your own resort website with photos, rates and details',
      'Live availability for every room, house and the function hall',
      'Reservation requests for one or several facilities at once',
      'Guest accounts, with “My bookings” to follow each request',
    ],
  },
  {
    title: 'Reservations and calendar',
    items: [
      'Calendar of every unit by date, also on a phone',
      'Double-booking protection for each unit and the whole resort',
      'Holds that expire on their own, and walk-in bookings',
      'Stay extensions and date blocks, rechecked against availability',
    ],
  },
  {
    title: 'Payments',
    items: [
      'Downpayment receipts uploaded by guests, reviewed by you',
      'Every payment and balance recorded per booking',
      'Online checkout through your own PayMongo account',
    ],
  },
  {
    title: 'Pricing and promos',
    items: [
      'Weekday and weekend rates per unit',
      'Holiday and peak-season rates with minimum stays',
      'Promo codes, add-ons and extra-guest fees',
    ],
  },
  {
    title: 'Front desk point of sale',
    items: [
      'Items and stock for food, drinks and extras',
      'Charges added to a booking and settled at check-out',
      'Daily sales records',
    ],
  },
  {
    title: 'Guest messages',
    items: [
      'Automatic confirmation, payment and reminder emails',
      'Your own wording for every message',
      'Failed emails retried automatically',
    ],
  },
  {
    title: 'Staff and operations',
    items: [
      'Cleaning tasks assigned to staff, with progress and photos',
      'Staff attendance with manager review',
      'Each staff member sees only their own workspace',
    ],
  },
  {
    title: 'Reports and records',
    items: [
      'Today’s arrivals, requests and money at a glance',
      'Occupancy, income, booking source and expense reports',
      'Customer history, reviews and a log of important actions',
    ],
  },
];

const NOT_INCLUDED = [
  'Fees charged by payment providers (PayMongo, GCash, banks) on each payment, paid directly to them with no markup from EAJ.',
  'Devices, receipt printers, cameras and internet connection.',
  'SMS or Viber messaging, if wanted later.',
  'Premium domain names other than a standard .com, at the price difference only.',
];

const GROWTH: { request: string; covers: string; cost: string; tone?: 'accent' }[] = [
  {
    request: 'Module customization',
    covers: 'Adjustments to the features, workflows and layouts of any module in this quotation.',
    cost: 'No extra cost',
    tone: 'accent',
  },
  {
    request: 'Minor feature additions',
    covers:
      'Small features outside this quotation, up to two development hours per month. Unused hours do not carry over.',
    cost: 'Included allowance',
    tone: 'accent',
  },
  {
    request: 'Major new modules',
    covers:
      'Large modules that need significant development, priced separately and approved by you before work begins.',
    cost: 'Separate quotation',
  },
];

const STEPS = [
  { title: 'Sign and pay', body: 'Sign this quotation and pay the one-time setup fee.' },
  { title: 'Set up', body: 'We set up your domain, hosting, rooms, rates, emails and staff accounts.' },
  { title: 'Train', body: 'We walk you and your staff through the system, on site or online.' },
  { title: 'Go live', body: 'Guests book on your website and your team runs the resort in the system.' },
];

export default function Quotation({
  package: offer = FALLBACK_OFFER,
}: {
  /** Sent by the server for the site menu; the quotation is a standalone EAJ document and doesn't use it. */
  tenant?: SiteTenant;
  package?: PackageOffer;
}) {
  const [copied, setCopied] = useState(false);
  const { discount, client, document: doc } = offer;
  const monthly = offer.price - (discount?.amount ?? 0);
  const firstYear = offer.setup_fee + monthly * 12;
  const perDay = Math.round(monthly / 30);
  const recipient = client ? client.name : 'Prospective client';

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(window.location.href);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2500);
    } catch {
      window.prompt('Copy this quotation link', window.location.href);
    }
  }

  const terms = [
    'All prices are in Philippine pesos.',
    `The ${peso(offer.setup_fee)} setup fee is paid once, in full, on signing. It covers setup, the domain’s first year, training and launch. If EAJ does not launch your system, it is refunded in full.`,
    `The ${peso(monthly)} monthly fee starts at go-live and covers hosting, daily backups, yearly domain renewal, guest emails, support, maintenance and security updates.`,
    'The monthly price is fixed for the first 12 months. Later changes require 30 days’ written notice.',
    'The domain is registered in your name. Your data is yours: request a full export at any time, at no cost.',
    'Support by chat, call or message, Monday to Saturday, 8 AM–6 PM, with a same-day response if the website or booking is down.',
    'Either party may cancel with 30 days’ written notice.',
    'Invoices are due within 7 days. Service is only paused, never deleted, after 30 days unpaid, with notice first.',
    ...(discount
      ? [
          `${discount.label}: ${discount.percent}% (${peso(discount.amount)}) off the monthly price${discount.valid_until ? `, when accepted by ${discount.valid_until}` : ''}.`,
        ]
      : []),
    ...(doc ? [`This quotation is valid until ${doc.valid_until}.`] : []),
  ];

  return (
    <div className="eq print-area">
      <Head title={client && doc ? `Quotation ${doc.number} · ${client.business}` : 'Pricing · EAJ Web Development'}>
        <link rel="preconnect" href="https://fonts.googleapis.com" />
        <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="" />
        <link
          rel="stylesheet"
          href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@600;700;800&display=swap"
        />
      </Head>

      <div className="eq-toolbar no-print">
        <div className="eq-toolbar-title">
          <img src="/images/eaj/eaj-mark-light.png" alt="" width={400} height={242} />
          <span>
            <strong>{doc && client ? `Quotation ${doc.number}` : 'EAJ pricing'}</strong>
            <small>{client ? client.business : 'All-inclusive package'}</small>
          </span>
        </div>
        <div className="eq-toolbar-actions">
          <button type="button" className="eq-btn eq-btn-ghost" onClick={copyLink}>
            {copied ? <CheckCheck size={16} aria-hidden="true" /> : <Copy size={16} aria-hidden="true" />}
            {copied ? 'Link copied' : 'Copy link'}
          </button>
          <button type="button" className="eq-btn eq-btn-primary" onClick={() => window.print()}>
            <Download size={16} aria-hidden="true" /> Save as PDF
          </button>
        </div>
      </div>

      {offer.can_discount && offer.max_percent > 0 && <DiscountPanel offer={offer} />}

      <main className="eq-sheets">
        {/* ── Page 1: who, what, and what it costs ── */}
        <Sheet page={1} number={doc?.number}>
          <header className="eq-head">
            <img
              className="eq-logo"
              src="/images/eaj/eaj-logo.png"
              alt="EAJ Web Development Services"
              width={880}
              height={612}
            />
            <div className="eq-head-meta">
              <h1>QUOTATION</h1>
              <dl>
                {doc && client && (
                  <div>
                    <dt>Quotation no.</dt>
                    <dd>{doc.number}</dd>
                  </div>
                )}
                {doc && (
                  <>
                    <div>
                      <dt>Date</dt>
                      <dd>{doc.issued}</dd>
                    </div>
                    <div>
                      <dt>Valid until</dt>
                      <dd>{doc.valid_until}</dd>
                    </div>
                  </>
                )}
              </dl>
            </div>
          </header>

          <section className="eq-parties" aria-label="Parties">
            <div>
              <p className="eq-eyebrow">Prepared for</p>
              <p className="eq-party-name">{recipient}</p>
              <p className="eq-party-role">{client ? client.business : 'Resort or property owner'}</p>
            </div>
            <div>
              <p className="eq-eyebrow">Prepared by</p>
              <p className="eq-party-name">{doc?.prepared_by ?? 'EAJ Web Development Services'}</p>
              <p className="eq-party-role">{doc?.prepared_by_title ?? 'Booking systems for resorts and stays'}</p>
            </div>
          </section>

          <section className="eq-hero" aria-labelledby="eq-package-title">
            <div className="eq-hero-copy">
              <p className="eq-eyebrow eq-eyebrow-light">All-inclusive package</p>
              <h2 id="eq-package-title">Resort Booking and Management System</h2>
              <p>
                One system to run the resort, from the first guest inquiry to the payment, the cleaned room and the
                monthly report.
              </p>
            </div>
            <div className="eq-hero-price">
              {discount && <s className="eq-hero-was">{peso(offer.price)}</s>}
              <p>
                <strong>{peso(monthly)}</strong>
                <span>/ month</span>
              </p>
              {discount && (
                <p className="eq-hero-line">
                  <em>
                    {discount.label} · {discount.percent}% off
                  </em>
                </p>
              )}
              <p className="eq-hero-line">Every feature included. No commission per booking.</p>
              <p className="eq-hero-line">
                <em>{peso(offer.setup_fee)}</em> one-time setup, with domain and hosting.
              </p>
            </div>
          </section>

          <section className="eq-block" aria-labelledby="eq-benefits-title">
            <h2 id="eq-benefits-title" className="eq-h2">
              What this changes for your business
            </h2>
            <div className="eq-benefits">
              {BENEFITS.map(benefit => (
                <article key={benefit.title}>
                  <h3>{benefit.title}</h3>
                  <p>{benefit.body}</p>
                </article>
              ))}
            </div>
          </section>

          <section className="eq-block" aria-labelledby="eq-cost-title">
            <h2 id="eq-cost-title" className="eq-h2">
              What it costs
            </h2>
            <div className="eq-stats">
              <div>
                <strong>{peso(perDay)}</strong>
                <span>about what the monthly package costs per day</span>
              </div>
              <div>
                <strong>Everything</strong>
                <span>every feature on page 2, nothing to unlock later</span>
              </div>
              <div>
                <strong>₱0</strong>
                <span>commission or fee per booking</span>
              </div>
            </div>
            <table className="eq-table">
              <thead>
                <tr>
                  <th scope="col">Item</th>
                  <th scope="col">Details</th>
                  <th scope="col">Cost</th>
                </tr>
              </thead>
              <tbody>
                <CostRow item="One-time setup" details="Setup, first-year domain, training and launch support">
                  {peso(offer.setup_fee)} one time
                </CostRow>
                <CostRow item="System package" details="Every module on page 2, with hosting and daily backups">
                  {peso(offer.price)} / month
                </CostRow>
                {discount && (
                  <CostRow
                    item={discount.label}
                    details={`${discount.percent}% off the monthly price${discount.valid_until ? `, accepted by ${discount.valid_until}` : ''}`}
                    tone="accent"
                  >
                    −{peso(discount.amount)} / month
                  </CostRow>
                )}
                <CostRow
                  item="Domain (.com)"
                  details="In your name. First year in the setup fee, renewed yearly in the monthly fee"
                  tone="accent"
                >
                  Included
                </CostRow>
                <CostRow
                  item="Emails, support and updates"
                  details="Guest emails, help when you need it, fixes and security updates"
                  tone="accent"
                >
                  Included
                </CostRow>
              </tbody>
            </table>
          </section>
        </Sheet>

        {/* ── Page 2: everything in the package, and what isn't ── */}
        <Sheet page={2} number={doc?.number} recipient={recipient}>
          <section aria-labelledby="eq-modules-title">
            <h2 id="eq-modules-title" className="eq-h2">
              Everything included in your package
            </h2>
            <p className="eq-lead">
              Every module below comes with the monthly price, and all of them work on a phone as well as a computer.
            </p>
            <div className="eq-modules">
              {MODULE_GROUPS.map(group => (
                <div key={group.title}>
                  <h3>{group.title}</h3>
                  <ul className="eq-bullets">
                    {group.items.map(item => (
                      <li key={item}>{item}</li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          </section>

          <section className="eq-block eq-excluded" aria-labelledby="eq-excluded-title">
            <h2 id="eq-excluded-title" className="eq-h3">
              Not included, so there are no surprises
            </h2>
            <ul>
              {NOT_INCLUDED.map(item => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </section>
        </Sheet>

        {/* ── Page 3: growth, getting started, payments, terms, signatures ── */}
        <Sheet page={3} number={doc?.number} recipient={recipient}>
          <section aria-labelledby="eq-growth-title">
            <h2 id="eq-growth-title" className="eq-h2">
              The system grows with your business
            </h2>
            <p className="eq-lead">
              Your resort will change, and your system should change with it. Customizing what you already have costs
              nothing extra.
            </p>
            <table className="eq-table eq-table-growth">
              <thead>
                <tr>
                  <th scope="col">Request</th>
                  <th scope="col">What it covers</th>
                  <th scope="col">Cost</th>
                </tr>
              </thead>
              <tbody>
                {GROWTH.map(row => (
                  <CostRow key={row.request} item={row.request} details={row.covers} tone={row.tone}>
                    {row.cost}
                  </CostRow>
                ))}
              </tbody>
            </table>
          </section>

          <section className="eq-block" aria-labelledby="eq-start-title">
            <h2 id="eq-start-title" className="eq-h2">
              How we get started
            </h2>
            <ol className="eq-steps">
              {STEPS.map((step, i) => (
                <li key={step.title}>
                  <span className="eq-step-no">{i + 1}</span>
                  <h3>{step.title}</h3>
                  <p>{step.body}</p>
                </li>
              ))}
            </ol>
            <dl className="eq-schedule">
              <div>
                <dt>On signing</dt>
                <dd>{peso(offer.setup_fee)}</dd>
              </div>
              <div>
                <dt>At go-live (first month)</dt>
                <dd>{peso(monthly)}</dd>
              </div>
              <div>
                <dt>Each month after</dt>
                <dd>{peso(monthly)}</dd>
              </div>
              <div className="eq-schedule-total">
                <dt>First year in total</dt>
                <dd>{peso(firstYear)}</dd>
              </div>
            </dl>
          </section>

          <section className="eq-block" aria-labelledby="eq-terms-title">
            <h2 id="eq-terms-title" className="eq-h2">
              Terms
            </h2>
            <ul className="eq-terms">
              {terms.map(term => (
                <li key={term}>{term}</li>
              ))}
            </ul>
          </section>

          <section className="eq-block eq-accept" aria-labelledby="eq-accept-title">
            <h2 id="eq-accept-title" className="eq-h2">
              Acceptance
            </h2>
            <p className="eq-lead">
              By signing below, the client accepts this quotation and the package described in it.
            </p>
            <div className="eq-signatures">
              <div>
                <strong>{recipient}</strong>
                <span>{client ? `${client.business} · Signature and date` : 'Client · Signature and date'}</span>
              </div>
              <div>
                <strong>{doc?.prepared_by ?? 'EAJ Web Development Services'}</strong>
                <span>Lead Developer · Signature and date</span>
              </div>
            </div>
          </section>
        </Sheet>
      </main>
    </div>
  );
}

/** One A4 page: pages after the first get the small mark and who it's for; every page gets the footer. */
function Sheet({
  page,
  number,
  recipient,
  children,
}: {
  page: number;
  number?: string;
  recipient?: string;
  children: ReactNode;
}) {
  return (
    <article className="eq-sheet" aria-label={`Page ${page} of ${SHEETS}`}>
      {page > 1 && (
        <header className="eq-sheet-head">
          <img src="/images/eaj/eaj-logo.png" alt="EAJ Web Development Services" width={880} height={612} />
          {recipient && <span>Prepared for {recipient}</span>}
        </header>
      )}
      <div className="eq-sheet-body">{children}</div>
      <footer className="eq-sheet-foot">
        <span>EAJ Web Development Services{number ? ` · Quotation ${number}` : ''}</span>
        <span>
          Page {page} of {SHEETS}
        </span>
      </footer>
    </article>
  );
}

function CostRow({
  item,
  details,
  tone,
  children,
}: {
  item: string;
  details: string;
  tone?: 'accent';
  children: ReactNode;
}) {
  return (
    <tr>
      <th scope="row">{item}</th>
      <td>{details}</td>
      <td className={tone === 'accent' ? 'eq-cost eq-cost-accent' : 'eq-cost'}>{children}</td>
    </tr>
  );
}

/**
 * The platform owner's discount controls (never printed, never shown to customers). Applying opens a signed link
 * with the discount on the monthly price; that link is what "Copy link" then copies for the customer.
 */
function DiscountPanel({ offer }: { offer: PackageOffer }) {
  const form = useForm({
    percent: offer.discount?.percent ?? 0,
    label: offer.discount?.label ?? 'Discount',
    days: 14 as number,
  });
  const percent = Math.min(Math.max(0, Math.round(Number(form.data.percent) || 0)), offer.max_percent);
  const amount = Math.round((offer.price * percent) / 100);
  const quick = [5, 10, 15, 20, 25].filter(p => p <= offer.max_percent);

  function apply(e: FormEvent) {
    e.preventDefault();
    form.transform(data => ({ ...data, percent }));
    form.post('/quotation/discount', { preserveScroll: true });
  }

  function remove() {
    form.transform(data => ({ ...data, percent: 0 }));
    form.post('/quotation/discount', { preserveScroll: true, onSuccess: () => form.setData('percent', 0) });
  }

  return (
    <form className="eq-owner no-print" onSubmit={apply} aria-labelledby="eq-owner-title">
      <p id="eq-owner-title" className="eq-owner-title">
        <Percent size={14} aria-hidden="true" /> Discount <span>Only you see this panel</span>
      </p>
      <div className="eq-owner-row">
        <div className="eq-owner-quick" role="group" aria-label="Quick discount">
          {quick.map(p => (
            <button
              key={p}
              type="button"
              aria-pressed={percent === p}
              className={percent === p ? 'is-active' : ''}
              onClick={() => form.setData('percent', p)}
            >
              {p}%
            </button>
          ))}
        </div>
        <label className="eq-owner-field eq-owner-percent">
          <span>Percent off monthly</span>
          <span className="eq-owner-input">
            <input
              type="number"
              inputMode="numeric"
              min={0}
              max={offer.max_percent}
              step={1}
              value={form.data.percent}
              onChange={e => form.setData('percent', e.target.value === '' ? 0 : Number(e.target.value))}
              aria-describedby="eq-owner-preview"
            />
            <b>%</b>
          </span>
        </label>
        <label className="eq-owner-field">
          <span>Shown as</span>
          <input maxLength={60} value={form.data.label} onChange={e => form.setData('label', e.target.value)} />
        </label>
        <label className="eq-owner-field">
          <span>Valid for</span>
          <select value={form.data.days} onChange={e => form.setData('days', Number(e.target.value))}>
            {OFFER_DAYS.map(d => (
              <option key={d} value={d}>
                {d} days
              </option>
            ))}
          </select>
        </label>
      </div>
      <div className="eq-owner-row eq-owner-foot">
        <p id="eq-owner-preview" className="eq-owner-preview" aria-live="polite">
          {peso(offer.price)} − {peso(amount)} = <strong>{peso(offer.price - amount)}</strong> / month · up to{' '}
          {offer.max_percent}%
        </p>
        <div className="eq-owner-actions">
          {offer.discount && (
            <button type="button" className="eq-btn eq-btn-ghost" disabled={form.processing} onClick={remove}>
              Remove discount
            </button>
          )}
          <button type="submit" className="eq-btn eq-btn-accent" disabled={form.processing}>
            {form.processing ? 'Applying…' : percent > 0 ? `Apply ${percent}% discount` : 'Show full price'}
          </button>
        </div>
      </div>
      {Object.values(form.errors)[0] && (
        <p role="alert" className="eq-owner-error">
          {Object.values(form.errors)[0]}
        </p>
      )}
      {offer.discount && (
        <p className="eq-owner-hint">
          {offer.discount.percent}% off is on this quotation. Use “Copy link” and send that link to the client.
        </p>
      )}
    </form>
  );
}
