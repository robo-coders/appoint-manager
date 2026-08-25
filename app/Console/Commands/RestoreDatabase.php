<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class RestoreDatabase extends Command
{
    protected $signature = 'db:restore {path : Absolute path to a .sql dump} {--force}';

    protected $description = 'Restore a dump written by db:backup. Always test this after a backup.';

    public function handle(): int
    {
        if (! $this->option('force') && app()->environment('production')) {
            $this->error('Pass --force in production.');

            return self::FAILURE;
        }

        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->error('Dump not found.');

            return self::FAILURE;
        }

        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $db = config('database.connections.sqlite.database');
            copy($path, $db);
            $this->info('SQLite file replaced.');

            return self::SUCCESS;
        }

        $cfg = config('database.connections.'.$connection);
        $cmd = sprintf(
            'mysql --user=%s --password=%s --host=%s %s < %s',
            escapeshellarg($cfg['username']),
            escapeshellarg($cfg['password']),
            escapeshellarg($cfg['host']),
            escapeshellarg($cfg['database']),
            escapeshellarg($path),
        );
        exec($cmd, $out, $code);

        if ($code !== 0) {
            $this->error('mysql restore failed');

            return self::FAILURE;
        }

        Artisan::call('migrate', ['--force' => true]);
        $this->info('Restore complete.');

        return self::SUCCESS;
    }
}
