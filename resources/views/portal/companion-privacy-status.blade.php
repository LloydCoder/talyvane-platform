@extends('portal.layout')

@section('title', 'Privacy controls')
@section('content')
    <p class="eyebrow">COMPANION PRIVACY CONTROLS</p>
    <h1>Your privacy<br><em>comes first.</em></h1>
    <p class="admin-muted">These controls remain available even when the companion portal is temporarily disabled. You can withdraw publication consent and remove submitted media without relying on the general portal.</p>

    @if (session('status'))
        <div class="form-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($profile)
        <section class="client-membership-panel">
            <div>
                <p class="eyebrow">PROFILE PUBLICATION</p>
                <h2>{{ $profile->display_name }}</h2>
                <p>Current state: {{ str_replace('_', ' ', $profile->publication_status) }}</p>
                @if ($profile->consent_withdrawn_at)
                    <p>Publication consent was withdrawn on {{ $profile->consent_withdrawn_at->format('d M Y H:i') }}.</p>
                @elseif ($profile->publication_consent_at)
                    <p>Publication consent is currently recorded. You may withdraw it at any time.</p>
                    <form method="POST" action="{{ route('companion-portal.profiles.withdraw-consent', $profile) }}">
                        @csrf
                        <label for="withdraw-reason">Reason (required)</label>
                        <textarea id="withdraw-reason" name="reason" minlength="5" maxlength="500" required></textarea>
                        <label><input type="checkbox" name="withdraw_confirmed" value="1" required> I confirm that I am withdrawing publication consent.</label>
                        <button class="button button-dark admin-submit" type="submit">Withdraw publication consent</button>
                    </form>
                @else
                    <p>No active publication consent is recorded for this profile.</p>
                @endif
            </div>
        </section>

        <section class="client-saved-section">
            <div class="portal-section-heading"><h2>Submitted media</h2></div>
            <div class="portal-booking-list">
                @forelse ($media as $item)
                    <article class="portal-booking-card">
                        <div>
                            <span class="eyebrow">MEDIA SUBMISSION</span>
                            <h3>{{ ucfirst($item->status) }}</h3>
                            <p>Submitted {{ $item->created_at->format('d M Y H:i') }}</p>
                        </div>
                        <form method="POST" action="{{ route('companion-portal.profiles.media.destroy', [$profile, $item]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="text-link" type="submit">Remove submission</button>
                        </form>
                    </article>
                @empty
                    <div class="portal-placeholder"><strong>No submitted media</strong><p>There are no media submissions attached to this profile.</p></div>
                @endforelse
            </div>
        </section>
    @else
        <div class="portal-placeholder"><strong>No companion profile is linked to this account</strong><p>Contact the agency if you need help with an account or privacy request.</p></div>
    @endif

    @include('portal.account-security')
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-dark admin-submit" type="submit">Sign out ↗</button></form>
@endsection
