<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'database' => $this->database(),
            'redis' => $this->redis(),
            'queue' => $this->queue(),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'ok' => $ok,
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    private function database(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function redis(): bool
    {
        if (! in_array(config('cache.default'), ['redis'], true) && config('queue.default') !== 'redis') {
            return true;
        }

        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function queue(): bool
    {
        try {
            return config('queue.default') !== null;
        } catch (Throwable) {
            return false;
        }
    }
}
