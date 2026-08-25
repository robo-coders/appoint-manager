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
            'preview' => session('import_preview'),
        ]);
    }

    public function customers(Request $request, CustomerCsvImporter $importer): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant && $tenant->hasAdminWriteAccess(), 403);

        $csv = $this->csv($request);
        $commit = $request->boolean('commit');
        $result = $commit ? $importer->import($tenant, $csv) : $importer->preview($tenant, $csv);

        return back()->with('import_preview', $result)->with('toast', $commit ? 'Customers imported.' : 'Preview ready.');
    }

    public function bookings(Request $request, BookingCsvImporter $importer): RedirectResponse
    {
        $tenant = current_tenant();
        abort_unless($tenant && $tenant->hasAdminWriteAccess(), 403);

        $csv = $this->csv($request);
        $commit = $request->boolean('commit');
        $result = $commit ? $importer->import($tenant, $csv) : $importer->preview($tenant, $csv);

        return back()->with('import_preview', $result)->with('toast', $commit ? 'Bookings imported.' : 'Preview ready.');
    }

    private function csv(Request $request): string
    {
        if ($request->hasFile('file')) {
            return (string) file_get_contents($request->file('file')->getRealPath());
        }

        return $request->string('csv')->toString();
    }
}
