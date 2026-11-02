<?php

use App\Models\Booking;
use App\Models\Tenant;
use App\Services\DatabaseBackup;
use App\Services\GuestMessenger;
use App\Services\SystemHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('bookings:expire-holds', function () {
    $lapsed = Booking::whereIn('status', ['PENDING', 'AWAITING_PAYMENT'])->where('expires_at', '<=', now())
        ->whereDoesntHave('payments', fn ($payments) => $payments->where('status', 'PENDING'))->get();
    $count = Booking::whereKey($lapsed->pluck('id'))->update(['status' => 'EXPIRED']);
    // Tell the owner in the bell, once per request (facilities booked together are one line).
    $lapsed->groupBy(fn (Booking $b) => $b->group_code ?? 'b'.$b->id)->each(fn ($group) => app(App\Services\BookingNotifier::class)->record($group, 'expired'));
    $this->info("Expired {$count} booking holds.");
})->purpose('Expire checkout holds that have passed their deadline');

Artisan::command('subscriptions:expire', function () {
    $active = Tenant::where('subscription_status', 'ACTIVE')->where('subscription_ends_at', '<=', now())
        ->update(['subscription_status' => 'EXPIRED']);
    $trials = Tenant::where('subscription_status', 'TRIAL')->where('trial_ends_at', '<=', now())
        ->update(['subscription_status' => 'EXPIRED']);
    $this->info('Expired '.($active + $trials).' subscriptions.');
})->purpose('Expire ended subscriptions and trials');

Artisan::command('bookings:send-reminders', function (GuestMessenger $messenger) {
    $sent = 0;
    Tenant::where('status', 'ACTIVE')->each(function (Tenant $tenant) use ($messenger, &$sent) {
        $template = $messenger->template($tenant, 'pre_arrival');
        if (! $template['enabled']) {
            return;
        }
        // A window rather than an exact day, so a missed run still catches up; send() skips bookings already reminded.
        Booking::where('tenant_id', $tenant->id)->where('status', 'CONFIRMED')
            ->whereDate('check_in', '>=', today())->whereDate('check_in', '<=', today()->addDays((int) $template['send_days_before']))
            ->each(function (Booking $booking) use ($messenger, &$sent) {
                $sent += $messenger->send($booking, 'pre_arrival')?->status === 'SENT' ? 1 : 0;
            });
    });
    $this->info("Sent {$sent} pre-arrival reminders.");
})->purpose('Email guests before their stay');

Artisan::command('messages:retry-failed', function (GuestMessenger $messenger) {
    $results = $messenger->dueForRetry()->map(fn ($message) => $messenger->retry($message)->status)->countBy();
    $this->info('Retried '.$results->sum().' failed guest emails: '.($results->get('SENT', 0)).' sent, '
        .($results->get('SKIPPED', 0)).' no longer needed, '.($results->get('FAILED', 0)).' still failing.');
})->purpose('Resend guest emails that failed, with backoff, for up to 48 hours');

Artisan::command('platform:backup', function (DatabaseBackup $backups) {
    $backup = $backups->run();
    $this->info("Saved {$backup['name']} (".number_format($backup['size'] / 1048576, 2).' MB) to '.dirname($backup['path']));
})->purpose('Save a compressed copy of the database and keep the newest ones');

Artisan::command('platform:health {--json : Print the checks as JSON}', function (SystemHealth $health) {
    $checks = $health->checks();
    if ($this->option('json')) {
        $this->line(json_encode(['status' => SystemHealth::overall($checks), 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    } else {
        $icon = ['ok' => '<fg=green>OK</>', 'warn' => '<fg=yellow>WARN</>', 'fail' => '<fg=red>FAIL</>'];
        foreach ($checks as $check) {
            $this->line(str_pad($icon[$check['status']], 22).str_pad($check['label'], 24).$check['detail']);
            if ($check['fix']) {
                $this->line(str_repeat(' ', 32).'<fg=gray>→ '.$check['fix'].'</>');
            }
        }
    }

    // Non-zero only for failures, so uptime monitors and deploy scripts can act on it.
    return SystemHealth::overall($checks) === 'fail' ? 1 : 0;
})->purpose('Check the database, scheduler, email, backups, disk and production settings');

// Lets the health check tell whether the scheduler is actually running.
Schedule::call(fn () => Cache::forever(SystemHealth::HEARTBEAT_KEY, now()->toIso8601String()))->everyMinute()->name('platform:heartbeat');
Schedule::command('bookings:expire-holds')->everyMinute()->withoutOverlapping();
Schedule::command('subscriptions:expire')->daily()->withoutOverlapping();
Schedule::command('bookings:send-reminders')->hourly()->withoutOverlapping();
Schedule::command('messages:retry-failed')->everyTenMinutes()->withoutOverlapping();
Schedule::command('platform:backup')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('queue:prune-failed', ['--hours' => 24 * 30])->daily();
