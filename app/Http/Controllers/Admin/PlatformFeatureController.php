<?php

namespace App\Http\Controllers\Admin;

use App\Services\FeatureRegistry;
use Illuminate\View\View;

class PlatformFeatureController
{
    public function index(FeatureRegistry $registry): View
    {
        return view('admin.features.index', [
            'features' => $registry->catalog(),
        ]);
    }
}
