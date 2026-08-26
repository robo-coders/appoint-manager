<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--disk=s3}';

    protected $description = 'Dump the database and store it with 30-day retention.';

    public function handle(): int
    {
        $name = 'appoint-manager-'.now()->format('Y-m-d').'-'.Str::random(6).'.sql';
        $local = storage_path('app/backups/'.$name);
        @mkdir(dirname($local), 0755, true);

        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $path = config('database.connections.sqlite.database');

            if ($path === ':memory:' || $path === '') {
                file_put_contents($local, "-- sqlite memory snapshot\n");
            } else {
                copy($path, $local);
            }
        } else {
            $cfg = config('database.connections.'.$connection);
            $cmd = sprintf(
                'mysqldump --user=%s --password=%s --host=%s %s > %s',
                escapeshellarg($cfg['username']),
                escapeshellarg($cfg['password']),
                escapeshellarg($cfg['host']),
                escapeshellarg($cfg['database']),
                escapeshellarg($local),
            );
            exec($cmd, $out, $code);

            if ($code !== 0) {
                $this->error('mysqldump failed');

                return self::FAILURE;
            }
        }

        $disk = (string) $this->option('disk');

        if ($disk !== 'local' && config('filesystems.disks.'.$disk)) {
            try {
                Storage::disk($disk)->put('backups/'.$name, file_get_contents($local));
                $this->prune($disk);
            } catch (\Throwable $exception) {
                $this->warn('Remote store skipped: '.$exception->getMessage());
                Storage::disk('local')->put('backups/'.$name, file_get_contents($local));
            }
        } else {
            Storage::disk('local')->put('backups/'.$name, file_get_contents($local));
        }

        $this->info('Backup written: '.$name);

        return self::SUCCESS;
    }

    private function prune(string $disk): void
    {
        $files = collect(Storage::disk($disk)->files('backups'))
            ->filter(fn ($file) => str_ends_with($file, '.sql'))
            ->sort()
            ->values();

        $keep = $files->slice(-30);

        $files->diff($keep)->each(fn ($file) => Storage::disk($disk)->delete($file));
    }
}
