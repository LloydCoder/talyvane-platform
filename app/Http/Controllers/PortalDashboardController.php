<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\ClientMembership;
use Illuminate\View\View;

class PortalDashboardController extends Controller
{
    public function client(): View
    {
        return view('portal.client-dashboard', [
            'settings' => BookingSetting::current(),
            'membership' => ClientMembership::query()->where('user_id', auth()->id())->current()->latest('starts_at')->first(['id', 'user_id', 'tier_name', 'benefits_summary', 'status', 'starts_at', 'ends_at']),
            'favoriteProfiles' => auth()->user()->favoriteProfiles()->publiclyDiscoverable()->with('destination')->orderBy('display_name')->get(),
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
