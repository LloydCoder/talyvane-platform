<?php

namespace App\Http\Controllers\Admin;

use Illuminate\View\View;

class AdminDashboardController
{
    public function __invoke(): View
    {
        return view('admin.dashboard');
    }
}
