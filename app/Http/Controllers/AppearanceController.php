<?php

namespace App\Http\Controllers;

use App\Enums\ThemePreference;
use App\Http\Requests\UpdateAppearanceRequest;
use Illuminate\Http\Response;

class AppearanceController extends Controller
{
    public function update(UpdateAppearanceRequest $request): Response
    {
        $request->user()->forceFill([
            'theme_preference' => ThemePreference::from($request->validated('preference')),
        ])->save();

        return response()->noContent();
    }
}
