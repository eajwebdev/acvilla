<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/** Gzipped snapshots of the whole database, newest kept, written to config('platform.backups.path'). */
class DatabaseBackup
{
    public function run(): array
    {
        $directory = config('platform.backups.path');
        File::ensureDirectoryExists($directory);
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $stamp = now()->format('Y-m-d_His');
        $raw = $directory.DIRECTORY_SEPARATOR."partial-{$stamp}.tmp";
        $target = $directory.DIRECTORY_SEPARATOR."backup-{$stamp}.".($driver === 'sqlite' ? 'sqlite' : 'sql').'.gz';
        try {
            match ($driver) {
                // VACUUM INTO writes a consistent copy even while guests are booking.
                'sqlite' => $connection->statement('VACUUM INTO ?', [$raw]),
                'mysql', 'mariadb' => $this->mysqldump($connection->getConfig(), $raw),
                default => throw new RuntimeException("Backups are not set up for the {$driver} database driver."),
            };
            $this->gzip($raw, $target.'.tmp');
            // Renamed only once complete, so a crash mid-backup never looks like a good backup.
            File::move($target.'.tmp', $target);
        } finally {
            File::delete([$raw, $target.'.tmp']);
        }
        $this->prune();

        return $this->describe($target);
    }

    /** @return array{name: string, path: string, size: int, created_at: Carbon}|null */
    public function latest(): ?array
    {
        $file = $this->files()->first();

        return $file ? $this->describe($file) : null;
    }

    public function files(): Collection
    {
        $directory = config('platform.backups.path');

        return collect(is_dir($directory) ? File::glob($directory.DIRECTORY_SEPARATOR.'backup-*.gz') : [])
            ->sortByDesc(fn (string $path) => basename($path))->values();
    }

    private function prune(): void
    {
        File::delete($this->files()->slice(max(1, config('platform.backups.keep')))->all());
    }

    private function mysqldump(array $config, string $raw): void
    {
        // The password goes through the environment so it never shows in the process list.
        $result = Process::env(['MYSQL_PWD' => (string) ($config['password'] ?? '')])->timeout(900)->run([
            config('platform.backups.mysqldump'), '--single-transaction', '--quick', '--routines', '--no-tablespaces',
            '--host='.$config['host'], '--port='.$config['port'], '--user='.$config['username'], '--result-file='.$raw, $config['database'],
        ]);
        if ($result->failed()) {
            throw new RuntimeException('mysqldump failed: '.(trim($result->errorOutput()) ?: 'exit code '.$result->exitCode()));
        }
    }

    private function gzip(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');
        try {
            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }
        } finally {
            fclose($in);
            gzclose($out);
        }
    }

    private function describe(string $path): array
    {
        return ['name' => basename($path), 'path' => $path, 'size' => filesize($path),
            'created_at' => Carbon::createFromTimestamp(filemtime($path), config('app.timezone'))];
    }
}
