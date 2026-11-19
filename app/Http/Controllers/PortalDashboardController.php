<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use Illuminate\View\View;

class PortalDashboardController extends Controller
{
    public function client(): View
    {
        return view('portal.client-dashboard', [
            'settings' => BookingSetting::current(),
            'bookings' => Booking::query()
                ->with(['profile', 'destination'])
                ->where('client_id', auth()->id())
                ->latest()
                ->paginate(10),
        ]);
    }

    public function companion(): View
    {
        return view('portal.companion-dashboard');
    }
}
