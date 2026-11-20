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
                    <div class="availability-panel">
                        <strong>Profile imagery · private submission</strong>
                        <p>Uploads are converted to metadata-stripped WebP, stored privately and held for agency review. Nothing is public until approved.</p>
                        @foreach ($profile->media as $media)
                            <div class="availability-row">
                                <span>{{ strtoupper($media->status) }} · {{ $media->width }} × {{ $media->height }} · {{ str_replace('_', ' ', $media->access_level) }}</span>
                                @if ($media->members_only_consent_at && !$media->members_only_consent_withdrawn_at)
                                    <span>Separate members-only consent recorded</span>
                                    <form method="POST" action="{{ route('companion-portal.profiles.media.withdraw-members-only-consent', [$profile, $media]) }}">@csrf @method('PATCH')<button type="submit">Withdraw members-only consent</button></form>
                                @elseif ($media->members_only_consent_withdrawn_at)
                                    <span>Members-only consent withdrawn</span>
                                @endif
                                @if ($media->status === 'pending_review')
                                    <form method="POST" action="{{ route('companion-portal.profiles.media.destroy', [$profile, $media]) }}">@csrf @method('DELETE')<button type="submit">Remove image</button></form>
                                @endif
                            </div>
                        @endforeach
                        <form method="POST" enctype="multipart/form-data" action="{{ route('companion-portal.profiles.media.store', $profile) }}" class="availability-form">
                            @csrf
                            <label>Choose a profile image</label><input type="file" name="media" accept="image/jpeg,image/png,image/webp" required>
                            <label class="remember-row"><input type="checkbox" name="rights_confirmed" value="1" required> I own or have documented rights to this image and it contains no person whose identity or likeness is used without their permission</label>
                            <label class="remember-row"><input type="checkbox" name="publication_confirmed" value="1" required> I explicitly consent to this image being displayed on my profile if approved; I may withdraw consent at any time</label>
                            @if (filled(config('talyvane.privacy.private_gallery_consent_version')) && config('talyvane.privacy.private_gallery_consent_approved'))
                                <p class="field-help">Optional and separate from public profile publication.</p>
                                <label class="remember-row"><input type="checkbox" name="members_only_confirmed" value="1"> {{ \\App\\Services\\MembershipEntitlementCatalog::privateGalleryConsentStatement() }}</label>
                            @endif
                            <button class="button button-dark" type="submit">Submit image for review</button>
                        </form>
                    </div>
                    <div class="availability-panel">
                        <strong>Weekly availability · {{ $profile->destination->timezone }}</strong>
                        @forelse ($profile->availabilityRules as $rule)
                            <div class="availability-row"><span>{{ $weekdays[$rule->weekday] }} · {{ substr($rule->starts_at, 0, 5) }}–{{ substr($rule->ends_at, 0, 5) }}</span><form method="POST" action="{{ route('companion-portal.profiles.availability.destroy', [$profile, $rule]) }}">@csrf @method('DELETE')<button type="submit">Remove</button></form></div>
                        @empty
                            <p>No weekly availability windows configured. Automatic booking remains disabled until a matching window exists.</p>
                        @endforelse
                        <form method="POST" action="{{ route('companion-portal.profiles.availability.store', $profile) }}" class="availability-form">
                            @csrf
                            <label>Day</label><select name="weekday" required>@foreach ($weekdays as $day => $label)<option value="{{ $day }}">{{ $label }}</option>@endforeach</select>
                            <label>From</label><input type="time" name="starts_at" required>
                            <label>Until</label><input type="time" name="ends_at" required>
                            <button class="button button-dark" type="submit">Add window</button>
                        </form>
                    </div>
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
    @include('portal.account-security')
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-dark admin-submit" type="submit">Sign out ↗</button></form>
@endsection
