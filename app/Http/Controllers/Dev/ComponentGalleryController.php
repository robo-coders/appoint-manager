<?php

namespace App\Http\Controllers\Dev;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

class ComponentGalleryController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dev/Components');
    }
}
