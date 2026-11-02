# EAJ Booking Platform

A single Laravel 12 application with React, TypeScript, Inertia, Vite, and Tailwind under `resources/js`. It is an in-progress Philippine hospitality booking SaaS. The core booking path, demo access, tenant-scoped operations, and subscription gating are implemented; see **Current scope** below before using it in production.

## Local setup

Requirements: PHP 8.2+, Composer, Node 22+, and MySQL 8+ or MariaDB. This checkout was verified on PHP 8.2 and Node 22.

```powershell
Copy-Item .env.example .env
composer install
npm install
php artisan key:generate
```

Create a MySQL database named `eaj_booking`, set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`, then run:

```powershell
php artisan migrate --seed
npm run build
php artisan serve
```

On Windows PowerShell with script execution disabled, use `npm.cmd` and `npx.cmd`. For a quick local trial without MySQL, set `DB_CONNECTION=sqlite` and create `database/database.sqlite` before migrating. The repository's `.env.example` targets MySQL; the local development `.env` used during implementation targets SQLite because this workstation's MySQL account requires credentials that were not supplied.

The Laravel scheduler must run every minute in deployment (`php artisan schedule:run` via cron or Task Scheduler). See **Operations** below for what it does.

## Demo

Set `DEMO=true` in `.env` and run `php artisan db:seed`. The seed is repeatable and fills two tenants, four published properties, seven rooms, five sample bookings, price snapshots, three payment records, and a maintenance block. With `DEMO=false`, a normal seed creates only the monthly and annual plans. Demo credentials are never seeded by `DatabaseSeeder` in production.

The login page offers one-click access for:

| Role | Email | Password for development |
| --- | --- | --- |
| Administrator | `admin@example.com` | `Password123!` |
| Staff | `staff@example.com` | `Password123!` |
| Guest | `guest@example.com` | `Password123!` |

The second tenant has `admin2@example.com` with the same development password for tenant isolation checks.

Change these passwords or omit the demo seed in a real deployment. `DEMO=false` hides the cards and makes `POST /demo/login` return 404. The demo endpoint accepts only the three approved role names and is rate limited.

## Current scope

The platform quotation is an in-app page at `/quotation` (also reachable by opening `/quote` in a browser). Its monthly base price, website options, and add-on prices are defined near the top of `resources/js/Pages/Public/Quotation.tsx` for later editing. The `/quote` endpoint still returns room price calculations to the stay page when called with booking parameters.

- Public landing page, stay search, property page, guest checkout, price snapshot, manual payment request, and mock payment confirmation.
- Guest registration and login, role dashboards, tenant-scoped property and room creation, manual bookings, booking status changes, manual payment records, and superadmin client onboarding, status, and plan assignment.
- Tenant-scoped customer profiles with booking history, paid-spend totals, VIP/blocking status and private notes. Philippine mobile numbers are normalized to `+63` format.
- Verified completed-stay reviews with one review per booking, guest submission, admin moderation, and approved reviews on the public property page.
- Server-side availability with room quantity, date blocks, temporary holds, same-day turnover, row locking during booking creation, and subscription checks.
- Monthly plan at ₱3,499 and annual plan at ₱29,988 billed yearly (₱2,499 monthly equivalent, ₱12,000 annual savings). Subscription records snapshot billing values.
- Local Philippine defaults: PHP currency and Asia/Manila timezone.

The mock provider is a local test flow only and does not charge a card. Manual payments require staff verification. Public imagery currently uses remote placeholder photography; replace it with licensed property photos before launch. The supplied `eaj-primary.png` is used in platform branding. `eaj-watermark.png` was not found in the provided folder or local EAJ assets.

## Named route groups

`home`, `stays.index`, `stays.show`, `bookings.store`, `checkout.show`, `checkout.pay`, `booking.success`, `login`, `register`, `account.bookings`, `admin.*`, `superadmin.*`.

## Verification

```powershell
php artisan test
php artisan route:list
npx.cmd tsc --noEmit
npm.cmd run build
```

Tests cover repeatable seeding, the public catalog, authentication, role access, tenant isolation, booking overlap and turnover, blocked dates, pricing, mock and manual payments, booking transitions, customers, reviews, plan price snapshots, client locking, and expiry commands.

## Operations

**Is everything working?** Sign in as the superadmin and open **System health** (`/superadmin/health`), or run `php artisan platform:health` on the server. It checks the database and pending migrations, that the scheduler is running, email settings and failed guest emails, backup age, disk space and folder permissions, production settings (`APP_DEBUG`, `DEMO`, HTTPS) and clients that still offer test payments. Each problem comes with the fix. The command exits with 1 when something must be fixed, so it can gate a deploy script. `GET /up` returns 500 when the database is unreachable, for an uptime monitor.

**Scheduler.** One cron entry runs everything: `* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1` (on Windows, a Task Scheduler task every minute). `php artisan schedule:list` shows the jobs:

| Job | When | What it does |
| --- | --- | --- |
| `bookings:expire-holds` | every minute | Releases unpaid checkout holds |
| `messages:retry-failed` | every 10 minutes | Resends failed guest emails (backoff 10/20/40/80 min, up to 48 h), skipping ones the booking has outgrown |
| `bookings:send-reminders` | hourly | Pre-arrival emails |
| `subscriptions:expire` | daily | Ends lapsed subscriptions and trials |
| `platform:backup` | daily 02:30 | Gzipped database snapshot to `storage/app/backups`, newest `BACKUP_KEEP` (14) kept |
| `queue:prune-failed` | daily | Drops failed-job records older than 30 days |

**Backups.** SQLite is copied with `VACUUM INTO` (safe while the site is in use); MySQL/MariaDB uses `mysqldump` (set `BACKUP_MYSQLDUMP` if it is not on the PATH). Backups sit on the same server, so copy `storage/app/backups` somewhere else as well. To restore SQLite: stop the site, delete `database/database.sqlite-wal` and `-shm` if present, then gunzip the file over `database/database.sqlite`. For MySQL: `gunzip -c backup-….sql.gz | mysql eaj_booking`.

**Guest email.** Emails are sent during the request with a 10-second timeout (`MAIL_TIMEOUT`), so no queue worker is needed. A failed email is logged on the booking, retried automatically, and staff can press **Resend** in the booking drawer.

**Logs** rotate daily in `storage/logs` and are kept for `LOG_DAILY_DAYS` (14).

**SQLite** runs in WAL mode with immediate transactions and a 5-second busy timeout, so concurrent checkouts wait for each other instead of failing. MySQL is still recommended for production.

## Production deployment gates

The admin dashboard is for managers. Staff enter at `/admin/my-tasks` and have a smaller, phone-focused workspace for assigned cleaning, attendance, bookings, calendar, and POS. Managers can review attendance by date and correct recorded times with an audited reason. Attendance currently uses staff clock in/out; face verification is not implemented. Management reports, cleaning assignment, and exports require an administrator account.

Before taking real reservations, configure a production `.env` with `APP_ENV=production`, `APP_DEBUG=false`, `DEMO=false`, HTTPS `APP_URL`, a persistent MySQL database, `MAIL_MAILER` with a working sender, and monitored backups. Build assets, run migrations, start the scheduler and queue worker, and verify outgoing mail and restore from backup on the actual host. Do not load the demo seeder or use its credentials.

The checkout currently supports mock payments and staff-verified manual payments. `PAYMENT_PROVIDER=mock` is for testing and does not collect money. A live PayMongo checkout integration and webhook verification are still required for automatic online payment. Replace remote placeholder property imagery with licensed photos. Run the full test suite against the chosen deployment database and test the complete booking, payment, email, and staff turnover flows on the production-like host before launch.
