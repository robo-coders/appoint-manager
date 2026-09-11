<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class Concurrent
{
    /**
     * @template T
     *
     * @param  callable(): T  $body
     * @return T
     */
    public static function withoutWrappingTransaction(callable $body): mixed
    {
        $depth = DB::transactionLevel();

        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        try {
            return $body();
        } finally {
            for ($i = 0; $i < $depth; $i++) {
                DB::beginTransaction();
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $jobs
     * @return list<array<string, mixed>>
     */
    public static function run(array $jobs): array
    {
        $dir = sys_get_temp_dir().'/am-race-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);

        $barrier = $dir.'/go';
        $procs = [];

        try {
            $now = now()->toIso8601String();

            foreach ($jobs as $i => $job) {
                $job['barrier'] = $barrier;
                $job['now'] ??= $now;
                $jobFile = $dir."/job-{$i}.json";
                $outFile = $dir."/out-{$i}.json";
                file_put_contents($jobFile, json_encode($job, JSON_THROW_ON_ERROR));

                $cmd = implode(' ', [
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg(base_path('tests/bin/concurrent-worker.php')),
                    escapeshellarg($jobFile),
                    escapeshellarg($outFile),
                ]);

                $pipes = [];
                $proc = proc_open($cmd, [
                    0 => ['pipe', 'r'],
                    1 => ['file', $dir."/stdout-{$i}.log", 'w'],
                    2 => ['file', $dir."/stderr-{$i}.log", 'w'],
                ], $pipes, base_path(), self::childEnv());

                if (! is_resource($proc)) {
                    throw new RuntimeException("concurrent: failed to start worker {$i}");
                }

                fclose($pipes[0]);
                $procs[] = ['proc' => $proc, 'out' => $outFile, 'err' => $dir."/stderr-{$i}.log"];
            }

            self::waitUntil(function () use ($dir, $jobs) {
                return count(glob($dir.'/ready-*') ?: []) >= count($jobs);
            }, 15, 'workers never signalled ready');

            touch($barrier);

            $deadline = microtime(true) + 20;
            foreach ($procs as $i => $p) {
                while (microtime(true) < $deadline) {
                    $status = proc_get_status($p['proc']);
                    if (! $status['running']) {
                        break;
                    }
                    usleep(20_000);
                }

                $status = proc_get_status($p['proc']);
                if ($status['running']) {
                    proc_terminate($p['proc']);
                    throw new RuntimeException("concurrent: worker {$i} timed out. stderr: ".@file_get_contents($p['err']));
                }

                proc_close($p['proc']);
            }

            $results = [];
            foreach ($procs as $i => $p) {
                $raw = @file_get_contents($p['out']);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (! is_array($decoded)) {
                    throw new RuntimeException("concurrent: worker {$i} wrote no result. stderr: ".@file_get_contents($p['err']));
                }
                $results[] = $decoded;
            }

            return $results;
        } finally {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    private static function waitUntil(callable $predicate, int $seconds, string $message): void
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($predicate()) {
                return;
            }
            usleep(5_000);
        }

        throw new RuntimeException('concurrent: '.$message);
    }

    public static function afterEach(): void
    {
        $depth = DB::transactionLevel();

        while (DB::transactionLevel() > 0) {
            DB::commit();
        }

        self::wipeApplicationTables();

        for ($i = 0; $i < $depth; $i++) {
            DB::beginTransaction();
        }
    }

    public static function wipeApplicationTables(): void
    {
        $database = (string) DB::getDatabaseName();
        $key = 'Tables_in_'.$database;
        $tables = array_map(fn (object $row) => $row->{$key}, DB::select('SHOW TABLES'));

        $keep = ['migrations', 'verticals'];

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tables as $table) {
            if (in_array($table, $keep, true)) {
                continue;
            }
            DB::table($table)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /** @return array<string, string> */
    private static function childEnv(): array
    {
        $env = [];
        foreach (getenv() as $key => $value) {
            $env[$key] = (string) $value;
        }

        $connection = (string) config('database.default');

        $env['APP_ENV'] = 'testing';
        $env['DB_CONNECTION'] = $connection;
        $env['DB_HOST'] = (string) config("database.connections.{$connection}.host");
        $env['DB_PORT'] = (string) config("database.connections.{$connection}.port");
        $env['DB_DATABASE'] = (string) config("database.connections.{$connection}.database");
        $env['DB_USERNAME'] = (string) config("database.connections.{$connection}.username");
        $env['DB_PASSWORD'] = (string) (config("database.connections.{$connection}.password") ?? '');
        $env['DB_URL'] = '';
        $env['QUEUE_CONNECTION'] = 'sync';
        $env['CACHE_STORE'] = 'array';

        return $env;
    }
}
