<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The component gallery.
 *
 * Local only — the route is not registered outside a local or testing
 * environment, so it can never be reachable in production.
 */
class ComponentGalleryController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dev/Components');
    }
}
