<?php

namespace App\Http\Controllers;

use App\Services\Onboarding\BookingCsvImporter;
use App\Services\Onboarding\CustomerCsvImporter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ImportController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Imports/Index', [
            /*
             * The last run, whichever kind it was and whether or not it was
             * committed. The screen needs all three: a result with no `kind`
             * cannot be shown next to the import that produced it, and a result
             * with no `committed` cannot tell "here is what would happen" from
             * "here is what happened".
             */
            'result' => session('import_result'),
            'columns' => [
                'customers' => ['name', 'email', 'phone', 'subjects'],
                'bookings' => ['customer_email', 'service_name', 'staff_email', 'starts_at', 'subject_name'],
            ],
        ]);
    }

    public function customers(Request $request, CustomerCsvImporter $importer): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant && $tenant->hasAdminWriteAccess(), 403);

        $csv = $this->csv($request);
        $commit = $request->boolean('commit');
        $rows = $commit ? $importer->import($tenant, $csv) : $importer->preview($tenant, $csv);

        return back()
            ->with('import_result', $this->result('customers', $commit, $rows))
            ->with('toast', $commit ? 'Customers imported.' : 'Dry run finished — nothing has been saved.');
    }

    public function bookings(Request $request, BookingCsvImporter $importer): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant && $tenant->hasAdminWriteAccess(), 403);

        $csv = $this->csv($request);
        $commit = $request->boolean('commit');
        $rows = $commit ? $importer->import($tenant, $csv) : $importer->preview($tenant, $csv);

        return back()
            ->with('import_result', $this->result('bookings', $commit, $rows))
            ->with('toast', $commit ? 'Bookings imported.' : 'Dry run finished — nothing has been saved.');
    }

    /**
     * @param  list<array{row: int, ok: bool, message: string}>  $rows
     * @return array<string, mixed>
     */
    private function result(string $kind, bool $committed, array $rows): array
    {
        $ok = array_values(array_filter($rows, fn (array $row) => $row['ok']));
        $failed = array_values(array_filter($rows, fn (array $row) => ! $row['ok']));

        return [
            'kind' => $kind,
            'committed' => $committed,
            'ok' => count($ok),
            'failed' => count($failed),
            // Every failure, and a sample of the successes. A hundred rows of
            // "ok" is not something anybody reads; a hundred rows of "wrong" is.
            'rows' => array_merge($failed, array_slice($ok, 0, 20)),
            'sampled' => count($ok) > 20,
        ];
    }

    private function csv(Request $request): string
    {
        if ($request->hasFile('file')) {
            return (string) file_get_contents($request->file('file')->getRealPath());
        }

        return $request->string('csv')->toString();
    }
}
