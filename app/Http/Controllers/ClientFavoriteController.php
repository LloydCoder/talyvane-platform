<?php

namespace App\Http\Controllers;

use App\Models\CompanionProfile;
use Illuminate\Http\RedirectResponse;

class ClientFavoriteController extends Controller
{
    public function store(CompanionProfile $profile): RedirectResponse
    {
        $available = CompanionProfile::query()->publiclyDiscoverable()->findOrFail($profile->id);
        auth()->user()->favoriteProfiles()->syncWithoutDetaching([$available->id]);

        return redirect()->route('companions.show', $available->slug)->with('status', 'Companion saved to your private shortlist.');
    }

    public function destroy(CompanionProfile $profile): RedirectResponse
    {
        auth()->user()->favoriteProfiles()->detach($profile->id);

        $destination = $profile->publiclyDiscoverable()->exists()
            ? redirect()->route('companions.show', $profile->slug)
            : redirect()->route('portal.dashboard');

        return $destination->with('status', 'Companion removed from your private shortlist.');
    }
}
