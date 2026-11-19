@extends('portal.layout')
@section('title', 'Your private account')
@section('content')
    <p class="eyebrow">PRIVATE CLIENT ACCESS</p>
    <h1>Your<br><em>account.</em></h1>
    <p class="admin-muted">Welcome, {{ auth()->user()->name }}. Your account and saved profiles are private to you.</p>
    @if (session('status'))<div class="form-success" role="status">{{ session('status') }}</div>@endif

    @if ($membershipFeatureEnabled)
    <section class="client-membership-panel">
        <div>
            <p class="eyebrow">YOUR MEMBERSHIP</p>
            @if ($membership)
                <h2>{{ $membership->tier_name }}</h2>
                <p>{{ $membership->benefits_summary }}</p>
                <span class="client-membership-dates">
                    Active from {{ $membership->starts_at->format('d M Y') }}
                    @if ($membership->ends_at) · Until {{ $membership->ends_at->format('d M Y') }} @endif
                </span>
            @else
                <h2>Private client access</h2>
                <p>Your account is ready for considered arrangements. Membership status and any benefits are confirmed directly by the agency.</p>
                <a class="text-link" href="{{ route('contact') }}">Speak with the concierge ↗</a>
            @endif
        </div>
        <span class="client-membership-mark" aria-hidden="true">T.</span>
    </section>
    @endif

    <section class="client-saved-section">
        <div class="portal-section-heading"><h2>Saved companions</h2><a href="{{ route('companions.index') }}">Discover companions ↗</a></div>
        <div class="client-saved-grid">
            @forelse ($favoriteProfiles as $profile)
                <article class="client-saved-card">
                    <div><span class="eyebrow">{{ $profile->destination->name }}</span><h3>{{ $profile->display_name }}</h3></div>
                    <a class="text-link" href="{{ route('companions.show', $profile->slug) }}">View profile ↗</a>
                    <form method="POST" action="{{ route('client.favorites.destroy', $profile) }}">@csrf @method('DELETE')<button class="text-link" type="submit">Remove from shortlist</button></form>
                </article>
            @empty
                <div class="portal-placeholder"><strong>Your shortlist is private</strong><p>Save profiles you would like to revisit. Only you can see your shortlist.</p></div>
            @endforelse
        </div>
    </section>

    <section class="client-bookings-section">
        <div class="portal-section-heading"><h2>Booking requests</h2><a href="{{ route('companions.index') }}">Explore companions ↗</a></div>
        <div class="portal-booking-list">
            @forelse ($bookings as $booking)
                <article class="portal-booking-card">
                    <div><span class="eyebrow">{{ $booking->reference }} / {{ strtoupper(str_replace('_', ' ', $booking->status)) }}</span><h3>{{ $booking->profile->display_name }}</h3><p>{{ $booking->destination->name }} · {{ $booking->starts_at->timezone($booking->timezone)->format('D, d M Y · H:i') }} local</p></div>
                    <span class="status-pill {{ $booking->status === 'confirmed' ? 'status-active' : 'status-inactive' }}">{{ str_replace('_', ' ', $booking->status) }}</span>
                    @if ($booking->status === 'pending_review' || ($booking->status === 'confirmed' && $settings->client_cancellation_enabled && $booking->starts_at->isAfter(now()->addHours($settings->cancellation_cutoff_hours))))
                        <form method="POST" action="{{ route('bookings.cancel', $booking) }}">@csrf<button class="text-link" type="submit">Cancel request</button></form>
                    @endif
                </article>
            @empty
                <div class="portal-placeholder"><strong>No booking requests yet</strong><p>When you request a booking, its reference and status will appear here.</p></div>
            @endforelse
        </div>
        <div class="pagination-wrap">{{ $bookings->links() }}</div>
    </section>
    @include('portal.account-security')
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-dark admin-submit" type="submit">Sign out ↗</button></form>
@endsection
