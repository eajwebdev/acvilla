<?php

namespace App\Services;

use App\Models\BookingMessage;
use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plain-language checks of everything the platform needs to keep running: database, scheduler, email, backups,
 * disk and production settings. Shown to the platform owner and by `php artisan platform:health`.
 */
class SystemHealth
{
    public const HEARTBEAT_KEY = 'platform:scheduler_heartbeat';

    public function __construct(private DatabaseBackup $backups) {}

    /** @return list<array{key: string, label: string, status: 'ok'|'warn'|'fail', detail: string, fix: ?string}> */
    public function checks(): array
    {
        $checks = [];
        foreach (['database', 'migrations', 'scheduler', 'mail', 'emailDelivery', 'backups', 'storage', 'queue', 'configuration', 'testPayments'] as $check) {
            try {
                $checks[] = ['key' => $check, ...$this->{$check}()];
            } catch (\Throwable $e) {
                $checks[] = ['key' => $check, 'label' => ucfirst($check), 'status' => 'fail', 'detail' => 'Check failed: '.$e->getMessage(), 'fix' => null];
            }
        }

        return $checks;
    }

    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');
    }

    private function database(): array
    {
        DB::select('select 1');
        $connection = DB::connection();

        return $this->result('Database', 'ok', 'Connected to '.$connection->getDriverName().' ('.basename((string) $connection->getDatabaseName()).').');
    }

    private function migrations(): array
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
        $pending = $migrator->repositoryExists() ? array_diff(array_keys($files), $migrator->getRepository()->getRan()) : array_keys($files);

        return $pending
            ? $this->result('Database updates', 'fail', count($pending).' database update(s) have not been applied.', 'Run: php artisan migrate --force')
            : $this->result('Database updates', 'ok', 'All '.count($files).' database updates are applied.');
    }

    private function scheduler(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);
        $fix = 'Run "php artisan schedule:run" every minute (cron on Linux, Task Scheduler on Windows). It expires holds, sends reminders, retries emails and makes backups.';
        if (! $last) {
            return $this->result('Scheduled jobs', $this->severe(), 'The scheduler has not run yet.', $fix);
        }
        $last = now()->parse($last);

        return $last->lt(now()->subMinutes(5))
            ? $this->result('Scheduled jobs', $this->severe(), 'The scheduler last ran '.$last->diffForHumans().'.', $fix)
            : $this->result('Scheduled jobs', 'ok', 'Running. Last run '.$last->diffForHumans().'.');
    }

    private function mail(): array
    {
        $mailer = config('mail.default');
        if (in_array($mailer, ['log', 'array'], true)) {
            return $this->result('Email sending', $this->severe(), "Guest emails are written to the log file, not sent (MAIL_MAILER={$mailer}).",
                'Set MAIL_MAILER=smtp and the MAIL_* settings from your email provider.');
        }
        $from = config('mail.from.address');
        if (! $from || str_ends_with($from, '@example.com')) {
            return $this->result('Email sending', 'warn', "Emails are sent from {$from}, which guests may see as spam.", 'Set MAIL_FROM_ADDRESS to an address on your own domain.');
        }

        return $this->result('Email sending', 'ok', "Sending through {$mailer} as {$from}.");
    }

    private function emailDelivery(): array
    {
        $retrying = BookingMessage::where('status', 'FAILED')->where('attempts', '<', GuestMessenger::MAX_ATTEMPTS)
            ->where('created_at', '>=', now()->subHours(GuestMessenger::RETRY_HOURS))->count();
        $gaveUp = BookingMessage::where('status', 'FAILED')->where('created_at', '>=', now()->subDays(7))->count() - $retrying;

        return match (true) {
            $gaveUp > 0 => $this->result('Guest email delivery', 'warn', "{$gaveUp} guest email(s) in the last 7 days could not be delivered after retrying.",
                'Check the mail settings and the latest log in storage/logs. Staff can resend from the booking drawer.'),
            $retrying > 0 => $this->result('Guest email delivery', 'warn', "{$retrying} guest email(s) failed and will be retried automatically."),
            default => $this->result('Guest email delivery', 'ok', 'No failed guest emails in the last 7 days.'),
        };
    }

    private function backups(): array
    {
        $latest = $this->backups->latest();
        $fix = 'Make sure the scheduler runs, or run: php artisan platform:backup. Copy '.config('platform.backups.path').' to another machine or cloud storage regularly.';
        if (! $latest) {
            return $this->result('Backups', $this->severe(), 'No database backup has been made yet.', $fix);
        }
        $detail = 'Latest backup '.$latest['created_at']->diffForHumans().' ('.number_format($latest['size'] / 1048576, 1).' MB, '
            .$this->backups->files()->count().' kept).';

        return $latest['created_at']->lt(now()->subHours(26))
            ? $this->result('Backups', $this->severe(), $detail.' It is more than a day old.', $fix)
            : $this->result('Backups', 'ok', $detail);
    }

    private function storage(): array
    {
        $unwritable = collect([storage_path('logs'), storage_path('app'), storage_path('framework/sessions'), base_path('bootstrap/cache')])
            ->reject(fn (string $path) => is_dir($path) && is_writable($path))->map(fn (string $path) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
        if ($unwritable->isNotEmpty()) {
            return $this->result('Disk & folders', 'fail', 'The app cannot write to: '.$unwritable->implode(', ').'.', 'Give the web server user write access to these folders.');
        }
        $free = @disk_free_space(storage_path());
        if ($free === false) {
            return $this->result('Disk & folders', 'ok', 'Folders are writable.');
        }
        $gb = round($free / 1073741824, 1);

        return match (true) {
            $free < 200 * 1048576 => $this->result('Disk & folders', 'fail', "Only {$gb} GB of disk space left.", 'Free up space or remove old backups and logs.'),
            $free < 1073741824 => $this->result('Disk & folders', 'warn', "Only {$gb} GB of disk space left.", 'Free up space soon.'),
            default => $this->result('Disk & folders', 'ok', "Folders are writable. {$gb} GB free."),
        };
    }

    private function queue(): array
    {
        $connection = config('queue.default');
        if ($connection !== 'database' || ! Schema::hasTable('jobs')) {
            return $this->result('Background jobs', 'ok', "Jobs run with the {$connection} driver.");
        }
        $stuck = DB::table('jobs')->where('available_at', '<', now()->subMinutes(10)->getTimestamp())->count();
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        return match (true) {
            $stuck > 0 => $this->result('Background jobs', 'warn', "{$stuck} job(s) have waited over 10 minutes.", 'Start a worker: php artisan queue:work --tries=3'),
            $failed > 0 => $this->result('Background jobs', 'warn', "{$failed} failed job(s) on record.", 'Review with php artisan queue:failed, then queue:retry all or queue:flush.'),
            default => $this->result('Background jobs', 'ok', 'Nothing waiting.'),
        };
    }

    private function configuration(): array
    {
        if (! app()->isProduction()) {
            return $this->result('Production settings', 'warn', 'Running in '.app()->environment().' mode. Production safety checks are relaxed.',
                'Set APP_ENV=production on the live server.');
        }
        $problems = array_filter([
            config('app.debug') ? 'APP_DEBUG is on, so error pages show internal details' : null,
            config('platform.demo_mode') ? 'DEMO is on, so the login page offers demo accounts' : null,
            ! str_starts_with((string) config('app.url'), 'https://') ? 'APP_URL does not use https' : null,
            blank(config('app.key')) ? 'APP_KEY is missing' : null,
        ]);

        return $problems
            ? $this->result('Production settings', 'fail', implode('; ', $problems).'.', 'Fix these in .env, then run php artisan config:cache.')
            : $this->result('Production settings', 'ok', 'Debug off, demo off, HTTPS on.');
    }

    private function testPayments(): array
    {
        $names = Tenant::where('status', 'ACTIVE')->get()->filter(fn (Tenant $tenant) => $tenant->setting('test_payments'))->pluck('business_name');
        if ($names->isEmpty()) {
            return $this->result('Test payments', 'ok', 'No active client offers test payments.');
        }

        return $this->result('Test payments', $this->severe(), $names->count().' active client(s) let guests confirm bookings with test payments, which collect no money: '
            .$names->take(5)->implode(', ').($names->count() > 5 ? '…' : '').'.', 'Each client turns off “Pay now (test mode)” in Settings → Payments before taking real bookings.');
    }

    /** Problems that would lose bookings or data block a live server but only warn on a local one. */
    private function severe(): string
    {
        return app()->isProduction() ? 'fail' : 'warn';
    }

    private function result(string $label, string $status, string $detail, ?string $fix = null): array
    {
        return ['label' => $label, 'status' => $status, 'detail' => $detail, 'fix' => $status === 'ok' ? null : $fix];
    }
}
