<?php

namespace App\Console\Commands;

use App\Http\Controllers\GuestAuthController;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Run on the server before (and after) going live: every setting that would make the site unsafe or broken
 * fails here, with what to change. Exit code 1 while anything fails, so a deploy script can stop on it.
 */
class ProductionCheck extends Command
{
    protected $signature = 'app:production-check';

    protected $description = 'Check that this server is safe to run the live site';

    /** Passwords that were only ever meant for local machines and demos. */
    private const KNOWN_PASSWORDS = ['password', 'secret', 'Password123!', '12345678'];

    private int $failed = 0;

    public function handle(Migrator $migrator): int
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);

        $this->line('<options=bold>App</>');
        $this->check(app()->isProduction(), 'APP_ENV=production', 'Set APP_ENV=production.');
        $this->check(! config('app.debug'), 'APP_DEBUG=false (no error details shown to visitors)', 'Set APP_DEBUG=false: debug pages reveal code, settings and passwords.');
        $this->check(filled(config('app.key')), 'APP_KEY is set', 'Run php artisan key:generate once (never change it afterwards).');
        $this->check(str_starts_with($url, 'https://') && ! in_array($host, ['localhost', '127.0.0.1'], true), "APP_URL is the live HTTPS address ({$url})", 'Set APP_URL=https://your-domain.');
        $this->check(! config('platform.demo_mode'), 'Demo logins are off', 'Set DEMO=false.');

        $this->line('<options=bold>Sessions & cookies</>');
        $this->check((bool) config('session.secure'), 'Session cookie is HTTPS-only', 'Set SESSION_SECURE_COOKIE=true (or leave it out: it is on by default in production).');
        $this->check((bool) config('session.http_only'), 'Session cookie is hidden from scripts', 'Remove SESSION_HTTP_ONLY=false from .env.');
        $this->check(in_array(config('session.same_site'), ['lax', 'strict'], true), 'Session cookie SameSite='.config('session.same_site'), 'Set SESSION_SAME_SITE=lax.');

        $this->line('<options=bold>Accounts</>');
        try {
            DB::connection()->getPdo();
            $this->check(true, 'Database connection works ('.config('database.default').')', '');
            $migrator->setConnection(config('database.default'));
            $pending = array_diff(array_keys($migrator->getMigrationFiles($migrator->paths() + [database_path('migrations')])), $migrator->getRepository()->getRan());
            $this->check($pending === [], 'Database is up to date', 'Run php artisan migrate --force ('.count($pending).' pending).');
            $site = Tenant::where('slug', config('platform.site_tenant'))->first();
            $this->check((bool) $site, 'The resort ('.config('platform.site_tenant').') exists', 'Run php artisan db:seed --class=AcVillaSeeder --force.');
            $this->check($site && User::where('tenant_id', $site->id)->where('role', 'ADMIN')->whereNull('deactivated_at')->exists(), 'An owner account exists', 'Set ACVILLA_ADMIN_EMAIL / ACVILLA_ADMIN_PASSWORD and run the AcVillaSeeder.');
            $demo = User::where('email', 'like', '%@example.com')->whereIn('role', ['ADMIN', 'STAFF', 'SUPERADMIN'])->pluck('email');
            $this->check($demo->isEmpty(), 'No demo staff accounts', 'Remove the demo accounts: '.$demo->implode(', '));
            $weak = User::whereIn('role', ['ADMIN', 'STAFF', 'SUPERADMIN'])->get()->filter(fn (User $u) => collect(self::KNOWN_PASSWORDS)->contains(fn ($p) => Hash::check($p, $u->password)));
            $this->check($weak->isEmpty(), 'No staff account uses a known test password', 'Change the password of: '.$weak->pluck('email')->implode(', '));
        } catch (\Throwable $e) {
            $this->check(false, 'Database connection works', 'Check DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD ('.$e->getMessage().').');
        }

        $this->line('<options=bold>Guests</>');
        if (GuestAuthController::enabled()) {
            $callback = GuestAuthController::callbackUrl();
            $this->check(str_starts_with($callback, 'https://') && parse_url($callback, PHP_URL_HOST) === $host, "Google sign-in returns to {$callback}", "Set GOOGLE_REDIRECT_URI={$url}/auth/google/callback and add it in the Google console.");
        } else {
            $this->warn('  ! Google sign-in is off (GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET empty): guests sign up with email.');
        }
        $mailer = (string) config('mail.default');
        $this->check(! in_array($mailer, ['log', 'array'], true), "Emails are really sent (MAIL_MAILER={$mailer})", 'Set MAIL_MAILER=smtp and the MAIL_* settings, or guests get no booking emails.');

        $this->line('<options=bold>Files</>');
        $this->check(is_file(public_path('build/manifest.json')), 'Front-end is built (public/build)', 'Run npm ci && npm run build.');
        $this->check(! is_file(public_path('hot')), 'No Vite dev-server file (public/hot)', 'Delete public/hot.');
        $this->check(is_writable(storage_path()) && is_writable(base_path('bootstrap/cache')), 'storage/ and bootstrap/cache/ are writable', 'Make them writable by the web server user.');
        $this->check(! is_file(public_path('.env')) && ! is_dir(public_path('.git')), 'No secrets inside public/', 'The web root must be the public/ folder, with nothing secret in it.');

        $this->newLine();
        $this->line('Also set up once on the server: the cron line "* * * * * php '.base_path('artisan').' schedule:run" (expires holds, sends reminders, retries emails) and daily database backups.');
        if ($this->failed) {
            $this->error("{$this->failed} check(s) failed. Fix them before going live.");

            return self::FAILURE;
        }
        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    private function check(bool $ok, string $label, string $fix): void
    {
        if ($ok) {
            $this->line("  <fg=green>✓</> {$label}");

            return;
        }
        $this->failed++;
        $this->line("  <fg=red>✗</> {$label}");
        $this->line("      <fg=yellow>→ {$fix}</>");
    }
}
