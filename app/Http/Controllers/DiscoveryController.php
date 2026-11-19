<?php

namespace App\Http\Controllers;

use App\Models\CompanionProfile;
use App\Models\Destination;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiscoveryController extends Controller
{
    public function destinations(): View
    {
        $destinations = Destination::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->withCount(['companionProfiles' => fn ($query) => $query->publiclyDiscoverable()])
            ->get();

        return view('discovery.destinations', compact('destinations'));
    }

    public function destination(string $slug): View
    {
        $destination = Destination::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->withCount(['companionProfiles' => fn ($query) => $query->publiclyDiscoverable()])
            ->firstOrFail();

        $profiles = CompanionProfile::query()
            ->publiclyDiscoverable()
            ->where('destination_id', $destination->id)
            ->orderByDesc('published_at')
            ->paginate(12);

        return view('discovery.destination', compact('destination', 'profiles'));
    }

    public function companions(Request $request): View
    {
        $filters = $request->validate([
            'destination' => ['nullable', 'string', 'alpha_dash', 'max:140'],
            'language' => ['nullable', 'string', 'max:40'],
        ]);

        $destinations = Destination::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $profiles = CompanionProfile::query()
            ->publiclyDiscoverable()
            ->with('destination:id,name,slug,country_name')
            ->when(! empty($filters['destination']), function ($query) use ($filters): void {
                $query->whereHas('destination', fn ($destination) => $destination
                    ->where('slug', $filters['destination']));
            })
            ->when(! empty($filters['language']), function ($query) use ($filters): void {
                $query->whereJsonContains('languages', trim($filters['language']));
            })
            ->orderByDesc('published_at')
            ->paginate(12)
            ->withQueryString();

        return view('discovery.companions', compact('destinations', 'profiles'));
    }

    public function companion(string $slug): View
    {
        $profile = CompanionProfile::query()
            ->publiclyDiscoverable()
            ->with(['destination', 'media' => fn ($query) => $query->where('status', 'approved')->latest()])
            ->where('slug', $slug)
            ->firstOrFail();

        return view('discovery.companion', compact('profile'));
    }
}
