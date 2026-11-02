<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Services\DatabaseBackup;
use App\Services\GuestMessenger;
use App\Services\SystemHealth;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Platform owner's view of whether the server is healthy, with the two fixes that can safely run from the browser. */
class SuperadminHealthController extends Controller
{
    public function index(SystemHealth $health, DatabaseBackup $backups)
    {
        $checks = $health->checks();

        return Inertia::render('Superadmin/Health', [
            'checks' => $checks, 'overall' => SystemHealth::overall($checks),
            'backups' => $backups->files()->take(10)->map(fn (string $path) => [
                'name' => basename($path), 'size' => filesize($path), 'created_at' => date(DATE_ATOM, filemtime($path)),
            ]),
            'environment' => ['app' => app()->environment(), 'php' => PHP_VERSION, 'laravel' => app()->version(),
                'database' => config('database.default'), 'mailer' => config('mail.default'), 'timezone' => config('app.timezone')],
            'checkedAt' => now()->toIso8601String(),
        ]);
    }

    public function backup(Request $request, DatabaseBackup $backups)
    {
        try {
            $backup = $backups->run();
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['action' => 'Backup failed: '.$e->getMessage()]);
        }
        $this->audit($request, 'platform.backup_created', ['file' => $backup['name']]);

        return back()->with('success', "Backup saved: {$backup['name']}.");
    }

    public function retryEmails(Request $request, GuestMessenger $messenger)
    {
        $results = $messenger->dueForRetry()->map(fn ($message) => $messenger->retry($message)->status)->countBy();
        $this->audit($request, 'platform.emails_retried', $results->all());

        return back()->with('success', $results->isEmpty() ? 'No failed emails are due for a retry.'
            : $results->get('SENT', 0).' sent, '.$results->get('FAILED', 0).' still failing, '.$results->get('SKIPPED', 0).' no longer needed.');
    }

    private function audit(Request $request, string $action, array $metadata): void
    {
        AuditLog::create(['actor_user_id' => $request->user()->id, 'action' => $action, 'entity_type' => 'Platform', 'entity_id' => 0,
            'metadata' => $metadata, 'ip_address' => $request->ip()]);
    }
}
