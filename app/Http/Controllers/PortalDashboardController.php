<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingSetting;
use App\Models\ClientMembership;
use App\Services\FeatureRegistry;
use Illuminate\View\View;

class PortalDashboardController extends Controller
{
    public function client(FeatureRegistry $features): View
    {
        return view('portal.client-dashboard', [
            'settings' => BookingSetting::current(),
            'membershipFeatureEnabled' => $features->isEnabled('memberships'),
            'membership' => $features->isEnabled('memberships') ? ClientMembership::query()->where('user_id', auth()->id())->current()->latest('starts_at')->first(['id', 'user_id', 'tier_name', 'benefits_summary', 'status', 'starts_at', 'ends_at']) : null,
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
