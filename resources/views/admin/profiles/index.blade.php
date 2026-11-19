@extends('admin.layout')

@section('title', 'Profile review')
@section('section', 'PROFILES')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">PROFILE GOVERNANCE</p>
        <h1>Profile<br><em>review.</em></h1>
        <p>Profiles remain private until an approved age-assurance provider reference and explicit publication consent are reviewed and recorded.</p>
    </div>
    <div class="admin-list-toolbar"><span>{{ $profiles->total() }} profiles</span><a class="button button-dark" href="{{ route('admin.profiles.create') }}">Create draft profile ↗</a></div>
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>Profile</th><th>Destination</th><th>Publication</th><th>Verification</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse ($profiles as $profile)
                <tr>
                    <td><strong>{{ $profile->display_name }}</strong><small>/{{ $profile->slug }}</small><small>{{ $profile->user?->email ?? 'No companion account linked' }}</small></td>
                    <td>{{ $profile->destination->name }}</td>
                    <td><span class="status-pill {{ $profile->publication_status === 'published' ? 'status-active' : 'status-inactive' }}">{{ $profile->publication_status }}</span></td>
                    <td>{{ $profile->age_verified_at ? 'Age verified' : 'Not verified' }}<small>{{ $profile->publication_consent_at ? 'Consent recorded' : 'Consent missing' }}</small></td>
                    <td>
                        @if ($profile->publication_status !== 'published' && count($approvedProviders))
                            <details class="review-details">
                                <summary>Review & publish</summary>
                                <form method="POST" action="{{ route('admin.profiles.publish', $profile) }}" class="review-form">
                                    @csrf
                                    <label>Approved verification provider</label>
                                    <select name="verification_provider" required>@foreach ($approvedProviders as $provider)<option value="{{ $provider }}">{{ $provider }}</option>@endforeach</select>
                                    <label>Provider verification reference</label><input name="verification_reference" required minlength="8" maxlength="255" autocomplete="off">
                                    <label>Consent version</label><input name="consent_version" value="{{ $consentVersion }}" readonly>
                                    <label class="remember-row"><input type="checkbox" name="consent_confirmed" value="1" required> I reviewed the current, explicit publication consent</label>
                                    <label class="remember-row"><input type="checkbox" name="automatic_booking_opt_in" value="1"> I confirm this companion separately opted into automatic booking</label>
                                    <label>Review note (optional)</label><textarea name="review_note" rows="2" maxlength="1000"></textarea>
                                    <button class="button button-dark" type="submit">Approve & publish</button>
                                </form>
                            </details>
                        @elseif ($profile->publication_status === 'published')
                            <form method="POST" action="{{ route('admin.profiles.unpublish', $profile) }}" class="inline-review-form">
                                @csrf
                                <input name="reason" required minlength="5" maxlength="500" placeholder="Reason for unpublishing">
                                <button type="submit" class="table-action">Unpublish</button>
                            </form>
                        @else
                            <span class="field-help">No approved verification provider is configured. Publication is disabled.</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty-table">No profiles have been created.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="pagination-wrap">{{ $profiles->links() }}</div>
@endsection
