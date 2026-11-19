<?php

namespace App\Http\Controllers;

use App\Models\SitePage;
use App\Services\BrandSettings;
use Illuminate\View\View;

class PageController extends Controller
{
    public function show(string $slug, BrandSettings $settings): View
    {
        $page = SitePage::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->firstOrFail();

        return view('pages.show', [
            'page' => $page,
            'brand' => $settings->publicBrand(),
        ]);
    }
}
