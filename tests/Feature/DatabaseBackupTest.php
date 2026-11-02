<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** Uses DatabaseMigrations because SQLite cannot snapshot (VACUUM INTO) inside RefreshDatabase's wrapping transaction. */
class DatabaseBackupTest extends TestCase
{
    use DatabaseMigrations;

    public function test_backups_are_restorable_snapshots_and_only_the_newest_are_kept(): void
    {
        $directory = storage_path('framework/testing/backups');
        File::deleteDirectory($directory);
        config(['platform.backups.path' => $directory, 'platform.backups.keep' => 2]);
        Tenant::create(['name' => 'Bay', 'slug' => 'bay', 'business_name' => 'Bay Resort', 'status' => 'ACTIVE', 'subscription_status' => 'TRIAL']);
        try {
            foreach (range(1, 3) as $run) {
                $this->artisan('platform:backup')->expectsOutputToContain('Saved backup-')->assertSuccessful();
                $this->travel(1)->minutes();
            }
            $files = File::glob($directory.'/backup-*.sqlite.gz');
            $this->assertCount(2, $files);
            $this->assertSame([], File::glob($directory.'/*.tmp'));

            $restored = $directory.'/restored.sqlite';
            file_put_contents($restored, gzdecode(file_get_contents(end($files))));
            $pdo = new \PDO('sqlite:'.$restored);
            $this->assertSame('ok', $pdo->query('pragma integrity_check')->fetchColumn());
            $this->assertSame(1, (int) $pdo->query('select count(*) from tenants')->fetchColumn());
            $pdo = null;
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
