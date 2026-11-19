@extends('portal.layout')
@section('title', 'Companion workspace')
@section('content')
    <p class="eyebrow">COMPANION PORTAL</p><h1>Your<br><em>workspace.</em></h1><p class="admin-muted">Welcome, {{ auth()->user()->name }}. Your email address is verified.</p>
    @if (session('status'))<div class="form-success" role="status">{{ session('status') }}</div>@endif
    <div class="portal-section-heading"><h2>Your profiles and consent</h2></div>
    <div class="portal-booking-list">
        @forelse ($profiles as $profile)
            <article class="companion-profile-card">
                <div><span class="eyebrow">{{ $profile->destination->name }} / {{ strtoupper($profile->publication_status) }}</span><h3>{{ $profile->display_name }}</h3><p>Publication consent: {{ $profile->consent_withdrawn_at ? 'Withdrawn' : ($profile->publication_consent_at ? 'Active' : 'Not recorded') }}</p></div>
                @if ($profile->publication_status === 'published' && !$profile->consent_withdrawn_at)
                    <div class="companion-controls">
                        @if ($profile->accepts_automatic_bookings)
                            <form method="POST" action="{{ route('companion-portal.profiles.automatic-booking', $profile) }}">@csrf<input type="hidden" name="enabled" value="0"><button class="text-link" type="submit">Disable automatic booking</button></form>
                        @else
                            <form method="POST" action="{{ route('companion-portal.profiles.automatic-booking', $profile) }}">@csrf<input type="hidden" name="enabled" value="1"><label class="remember-row"><input type="checkbox" name="consent_confirmed" value="1" required> I explicitly opt in to automatic booking for this profile</label><button class="button button-dark" type="submit">Enable automatic booking</button></form>
                        @endif
                        <details class="withdraw-details"><summary>Withdraw publication consent</summary>
                            <form method="POST" action="{{ route('companion-portal.profiles.withdraw-consent', $profile) }}">@csrf
                                <label for="withdraw-reason-{{ $profile->id }}">Reason (optional)</label><input id="withdraw-reason-{{ $profile->id }}" name="reason" minlength="5" maxlength="500" required>
                                <label class="remember-row"><input type="checkbox" name="withdraw_confirmed" value="1" required> I understand this will immediately hide my profile</label>
                                <button class="text-link" type="submit">Confirm withdrawal</button>
                            </form>
                        </details>
                    </div>
                @endif
            </article>
        @empty
            <div class="portal-placeholder"><strong>No profile linked</strong><p>Your profile will appear here after the agency completes onboarding and links it to your account.</p></div>
        @endforelse
    </div>
    <div class="portal-section-heading"><h2>Booking requests</h2></div>
    <div class="portal-booking-list">
        @forelse ($bookings as $booking)
            <article class="portal-booking-card">
                <div><span class="eyebrow">{{ $booking->reference }} / {{ strtoupper(str_replace('_', ' ', $booking->status)) }}</span><h3>{{ $booking->profile->display_name }} · {{ $booking->destination->name }}</h3><p>{{ $booking->starts_at->timezone($booking->timezone)->format('D, d M Y · H:i') }} local · {{ $booking->duration_minutes }} minutes</p><p>Deposit: {{ str_replace('_', ' ', $booking->deposit_status) }}</p><p>Client: {{ $booking->client->name }}</p></div>
                @if ($booking->status === 'pending_review')
                    <div class="companion-booking-actions">
                        <form method="POST" action="{{ route('companion-portal.bookings.update', $booking) }}">@csrf @method('PATCH')<input type="hidden" name="decision" value="accept"><button class="button button-dark" type="submit">Accept</button></form>
                        <form method="POST" action="{{ route('companion-portal.bookings.update', $booking) }}">@csrf @method('PATCH')<input type="hidden" name="decision" value="decline"><button class="text-link" type="submit">Decline</button></form>
                    </div>
                @endif
            </article>
        @empty
            <div class="portal-placeholder"><strong>No booking requests</strong><p>Requests for your linked profiles will appear here.</p></div>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $bookings->links() }}</div>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-dark admin-submit" type="submit">Sign out ↗</button></form>
@endsection
