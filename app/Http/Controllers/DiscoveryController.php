<?php

namespace App\Http\Controllers;

use App\Models\CompanionProfile;
use App\Models\Destination;
use App\Services\FeatureRegistry;
use App\Services\MembershipEntitlementService;
use App\Services\MembershipEntitlementCatalog;
use App\Services\BrandSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DiscoveryController extends Controller
{
    public function destinations(): View
    {
        $destinations = Destination::query()
            ->operationallyAvailable()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->withCount(['companionProfiles' => fn ($query) => $query->publiclyDiscoverable()])
            ->get();

        return view('discovery.destinations', compact('destinations'));
    }

    public function destination(string $slug): View
    {
        $destination = Destination::query()
            ->operationallyAvailable()
            ->where('slug', $slug)
            ->withCount(['companionProfiles' => fn ($query) => $query->publiclyDiscoverable()])
            ->firstOrFail();

        $profiles = CompanionProfile::query()
            ->publiclyDiscoverable()
            ->where('destination_id', $destination->id)
            ->with(['destination:id,name,slug,country_name', 'media' => fn ($query) => $query
                ->where('status', 'approved')
                ->where('access_level', 'public')
                ->latest()])
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
            ->operationallyAvailable()
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);

        $profiles = CompanionProfile::query()
            ->publiclyDiscoverable()
            ->with(['destination:id,name,slug,country_name', 'media' => fn ($query) => $query
                ->where('status', 'approved')
                ->where('access_level', 'public')
                ->latest()])
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

    public function companion(
        string $slug,
        FeatureRegistry $features,
        MembershipEntitlementService $entitlements,
        BrandSettings $brandSettings,
    ): View {
        $viewer = auth()->user();
        $expectedConsentStatement = MembershipEntitlementCatalog::privateGalleryConsentStatement((string) ($brandSettings->publicBrand()['brand_name'] ?? 'Talyvane'));
        $canViewPrivateGallery = $viewer
            && $viewer->role === 'client'
            && $features->isOperationallyEnabled('memberships.entitlements')
            && config('talyvane.privacy.private_gallery_consent_approved')
            && filled(config('talyvane.privacy.private_gallery_consent_version'))
            && $entitlements->has($viewer, 'media.private_gallery');

        $profile = CompanionProfile::query()
            ->publiclyDiscoverable()
            ->with(['destination', 'media' => fn ($query) => $query
                ->where('status', 'approved')
                ->where(function ($media) use ($canViewPrivateGallery): void {
                    $media->where('access_level', 'public');
                    if ($canViewPrivateGallery) {
                        $media->orWhere(function ($privateMedia): void {
                            $privateMedia->where('access_level', 'members_only')
                                ->whereNotNull('members_only_consent_at')
                                ->whereNull('members_only_consent_withdrawn_at')
                                ->where('members_only_consent_version', config('talyvane.privacy.private_gallery_consent_version'))
                                ->whereHas('consentEvents', fn ($events) => $events
                                    ->where('action', 'members_only_consent.granted')
                                    ->where('consent_policy_version', config('talyvane.privacy.private_gallery_consent_version'))
                                    ->where('consent_statement', $expectedConsentStatement)
                                    ->where('statement_sha256', hash('sha256', $expectedConsentStatement)));
                        });
                    }
                })
                ->latest()])
            ->where('slug', $slug)
            ->firstOrFail();

        return view('discovery.companion', compact('profile'));
    }
}
