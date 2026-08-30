<?php

use App\Enums\BookingSource;
use App\Models\Customer;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Booking\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

/**
 * One side of a concurrency test.
 *
 * Bootstraps Laravel in its own process so it has its own PDO connection, then
 * waits on a barrier file so two (or more) workers actually overlap. The parent
 * is `Tests\Support\Concurrent`. Do not call this by hand.
 */
$jobFile = $argv[1] ?? null;
$outFile = $argv[2] ?? null;

if (! is_string($jobFile) || ! is_string($outFile)) {
    fwrite(STDERR, "usage: concurrent-worker.php <job.json> <out.json>\n");
    exit(2);
}

$job = json_decode((string) file_get_contents($jobFile), true);

if (! is_array($job)) {
    fwrite(STDERR, "concurrent-worker: job file is not JSON\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

file_put_contents(dirname($jobFile).'/ready-'.getmypid(), '1');

$barrier = $job['barrier'] ?? '';
$deadline = microtime(true) + 10;

while ($barrier !== '' && ! is_file($barrier) && microtime(true) < $deadline) {
    usleep(5_000);
}

if (! empty($job['now'])) {
    $frozen = CarbonImmutable::parse($job['now']);
    Carbon\Carbon::setTestNow($frozen);
    CarbonImmutable::setTestNow($frozen);
}

try {
    $result = match ($job['type'] ?? '') {
        'book' => workerBook($job),
        'http' => workerHttp($app, $job),
        default => throw new RuntimeException('unknown job type'),
    };

    file_put_contents($outFile, json_encode($result));
} catch (Throwable $e) {
    file_put_contents($outFile, json_encode([
        'ok' => false,
        'error' => $e::class,
        'message' => $e->getMessage(),
    ]));
    exit(1);
}

/**
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function workerBook(array $job): array
{
    $tenant = Tenant::query()->findOrFail($job['tenant_id']);
    $service = Service::withoutGlobalScopes()->findOrFail($job['service_id']);
    $staff = User::withoutGlobalScopes()->findOrFail($job['staff_id']);
    $customer = Customer::withoutGlobalScopes()->findOrFail($job['customer_id']);
    $startsAt = CarbonImmutable::parse($job['starts_at']);

    $booking = app(BookingService::class)->create(
        $tenant,
        $service,
        $staff,
        $customer,
        $startsAt,
        BookingSource::Online,
    );

    return ['ok' => true, 'booking_id' => $booking->id];
}

/**
 * @param  array<string, mixed>  $job
 * @return array<string, mixed>
 */
function workerHttp(Application $app, array $job): array
{
    $payload = $job['payload'] ?? [];
    $uri = $job['uri'] ?? '/';
    $method = strtoupper((string) ($job['method'] ?? 'POST'));

    $request = Request::create(
        $uri,
        $method,
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_HOST' => 'localhost',
        ],
        json_encode($payload, JSON_THROW_ON_ERROR),
    );

    $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);

    return [
        'ok' => $response->isSuccessful(),
        'status' => $response->getStatusCode(),
        'body' => $response->getContent(),
    ];
}
