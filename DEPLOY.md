# Putting AC Villa online

This guide takes the site from this computer to a live HTTPS address, safely. Steps 1–8 are done once; step 9 is
how every later update goes out. Two kinds of hosting are covered: a **VPS** (DigitalOcean, Hostinger VPS, AWS
Lightsail…) and **shared hosting with cPanel** (Hostinger Web/Business, Namecheap…). A VPS is recommended.

## What the server needs

- PHP **8.2 or newer** with: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd`, `zip`, `curl`, `intl`
- MySQL 8 (or MariaDB 10.6+)
- Composer 2, Node.js 20+ and npm (only to build; on shared hosting you can build on your computer instead)
- An SSL certificate (Let's Encrypt is free; Hostinger/cPanel have a one-click "SSL" button)

## 1. Database

Create a database and a user **used only by this site**, with a long random password. Give that user rights on
that one database only.

## 2. Code

```bash
git clone <your repository> acvilla
cd acvilla
composer install --no-dev --optimize-autoloader
npm ci && npm run build && rm -f public/hot
```

The web server's document root must be the **`public/` folder**, never the project folder: everything outside
`public/` (the `.env`, code, uploads of payment receipts) must not be reachable from the web.

## 3. Settings (`.env`)

```bash
cp .env.production.example .env
php artisan key:generate        # once; never change APP_KEY afterwards
```

Fill in every `CHANGE_ME`. The important ones:

| Setting | Why |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false` | Error pages never show code or passwords |
| `APP_URL=https://your-domain` | Forces HTTPS for every link, cookie and redirect |
| `DB_*` | The database from step 1 |
| `ACVILLA_ADMIN_EMAIL` / `ACVILLA_ADMIN_PASSWORD` | The owner login (strong, unique password) |
| `GOOGLE_*` | Guest sign-in. In the Google console add `https://your-domain/auth/google/callback` as an *Authorised redirect URI*, and **publish** the OAuth consent screen |
| `MAIL_*` | Booking emails to guests (Gmail App Password or your host's SMTP) |

Protect the file: `chmod 640 .env` (readable by you and the web server only).

## 4. Database tables and the resort

```bash
php artisan migrate --force
php artisan db:seed --class=AcVillaSeeder --force
```

This creates the resort, its rooms, halls, prices, the InstaPay QR and the owner login. **Do not** run
`DemoSeeder` or `ConflictTestSeeder` on the live site; they are for testing only.

## 5. Folder permissions

```bash
chown -R www-data:www-data storage bootstrap/cache public/uploads   # the web server's user (cPanel: your account)
chmod -R 775 storage bootstrap/cache public/uploads
```

## 6. Web server

### VPS with nginx

```nginx
server {
    listen 80;
    server_name your-domain www.your-domain;
    return 301 https://your-domain$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain;
    root /var/www/acvilla/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/your-domain/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/your-domain/privkey.pem;

    client_max_body_size 12M;          # receipts and photos up to 10 MB
    server_tokens off;

    # Never serve hidden files (.env, .git) and never run anything uploaded.
    location ~ /\.(?!well-known) { deny all; }
    location ^~ /uploads/ { location ~ \.(php\d?|phtml|phar|html?|svg|js)$ { deny all; } }

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~* \.(js|css|jpg|jpeg|png|webp|svg|woff2?)$ { expires 30d; access_log off; try_files $uri =404; }
}
```

Free certificate: `sudo certbot --nginx -d your-domain -d www.your-domain`.

In `/etc/php/8.2/fpm/php.ini` set `expose_php = Off`, `upload_max_filesize = 12M`, `post_max_size = 14M`, then restart PHP-FPM.

### Shared hosting (cPanel / Hostinger)

1. Upload the project **above** `public_html` (e.g. `/home/you/acvilla`), then point the domain's document root to
   `/home/you/acvilla/public` (cPanel → Domains → Document Root; Hostinger → Website → Advanced → Change root).
   If the host doesn't allow that, put the contents of `public/` in `public_html` and change the two paths at the
   top of `public_html/index.php` to `../acvilla/...`.
2. The `public/.htaccess` in the project already blocks hidden files and running uploads (Apache).
3. Turn on SSL and **Force HTTPS** in the hosting panel.
4. Build the website files on your computer (`npm run build`) and upload `public/build` if the host has no Node.js.
5. PHP version: choose 8.2+ in the panel and switch `expose_php` off if the panel allows.

## 7. Background tasks (one cron line)

```cron
* * * * * cd /var/www/acvilla && php artisan schedule:run >> /dev/null 2>&1
```

(cPanel → Cron Jobs → "Once per minute".) This expires unpaid holds, sends pre-arrival reminders, retries
failed emails, prunes old data and **backs up the database every night at 2:30 AM** to `storage/app/backups`.
Copy those backups off the server regularly (download them in Admin → Settings → Backup, or sync them to
Google Drive / S3): a backup on the same server doesn't help if the server is lost.

## 8. Final checks

```bash
php artisan optimize                 # cache settings, routes and views (speed)
php artisan app:production-check     # must end with "All checks passed"
```

Then, in a browser:

- Open `https://your-domain`: the padlock shows, `http://` redirects to `https://`.
- `https://your-domain/.env` and `https://your-domain/up` → the first must be **404/403**, the second "OK".
- Sign in as the owner; change the password in *Settings → My profile* if it was ever shared.
- Make a test booking with your own Google account, approve it, upload a receipt, confirm, then delete it.
- Send a test email from *Settings → Guest messages → Send test to me*.
- Check the headers at https://securityheaders.com (aim for A).

## 9. Updating the site later

```bash
cd /var/www/acvilla
bash deploy.sh
```

It pulls the code, installs packages, builds, **backs up the database**, migrates (with a short maintenance page),
re-caches and runs the production check. If something goes wrong, the backup from that run is the newest file
in `storage/app/backups`.

## Security, built in

- **Every admin address checks the business**: changing an ID in an address (`/admin/bookings/123`) to another
  business's record answers 404, and guests only ever see their own bookings (tested for every such address).
- **Roles**: owners, staff and guests each reach only their own pages; staff can't change settings or delete.
- **Rate limits** on sign-in (5 per minute), sign-up, booking requests, receipt uploads and lookups.
- **CSRF protection** on every form, **HTTPS-only, script-proof session cookies**, sessions renewed at sign-in.
- **Content Security Policy** in production: scripts only from this site, no framing by other sites, no plugins.
- **Uploads**: payment receipts and cleaning photos are stored privately and only shown to the business's staff;
  website photos are re-named and checked to be real images, and the web server never runs anything in `/uploads`.
- **Google sign-in** only accepts verified emails; return addresses after sign-in can only be pages on this site.
- **Demo logins** are impossible in production, even if `DEMO=true` is left in the settings.
- **No error details** to visitors; the PHP version is not advertised.
- Run `composer audit` and `npm audit` now and then; both are clean at the time of writing.

**Never** run `php artisan test` on the live server (tests empty their database; they refuse to run unless they are
on their own throwaway database, but don't rely on that), and never copy your local `.env` to the server.
