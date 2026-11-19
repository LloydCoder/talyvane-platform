@extends('admin.layout')
@section('title', 'Model agency applications')
@section('section', 'MODEL AGENCY')
@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">TALENT REPRESENTATION</p><h1>Model agency<br><em>applications.</em></h1><p>Review talent applications separately from companionship profiles. Acceptance only advances an applicant to a separate representation process; it does not execute a contract.</p></div>
    <div class="admin-list-toolbar"><span>{{ $applications->total() }} applications</span><span>Personal data — restricted access</span></div>
    <div class="enquiry-list">
        @forelse ($applications as $application)
            <article class="enquiry-card">
                <div class="enquiry-card-head">
                    <div><span class="eyebrow">{{ $application->reference }} · {{ $application->submitted_at->format('d M Y · H:i') }}</span><h2>{{ $application->full_name }}</h2><p><a href="mailto:{{ $application->email }}">{{ $application->email }}</a>@if($application->phone) · {{ $application->phone }}@endif</p></div>
                    <span class="status-pill {{ in_array($application->status, ['accepted','reviewing'], true) ? 'status-active' : 'status-inactive' }}">{{ str_replace('_', ' ', $application->status) }}</span>
                </div>
                <p class="enquiry-destination">{{ $application->city }}, {{ $application->country_code }}</p>
                @if($application->portfolio_url)<p><a href="{{ $application->portfolio_url }}" target="_blank" rel="noopener noreferrer nofollow">Open applicant portfolio ↗</a></p>@endif
                <p class="enquiry-message">{{ $application->experience_summary }}</p>
                <p class="field-help">Adult eligibility self-attested {{ $application->adult_eligibility_confirmed_at?->format('d M Y') ?? 'Erased' }} · Privacy notice {{ $application->privacy_notice_version }}</p>
                @if($application->review_note)<p class="enquiry-message">Review note: {{ $application->review_note }}</p>@endif
                @if($application->legal_hold_at)
                    <p class="field-help">Legal hold active since {{ $application->legal_hold_at->format('d M Y') }}. {{ $application->legal_hold_reason }}</p>
                @endif
                @if(in_array(auth()->user()->role, ['super_admin', 'owner'], true))
                    <form method="POST" action="{{ route('admin.model-applications.legal-hold', $application) }}" class="enquiry-status-form">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ $application->legal_hold_at ? '0' : '1' }}">
                        <label for="legal-hold-reason-{{ $application->id }}">{{ $application->legal_hold_at ? 'Reason for releasing legal hold' : 'Reason for placing legal hold' }}</label>
                        <textarea id="legal-hold-reason-{{ $application->id }}" name="reason" rows="2" minlength="20" maxlength="2000" required></textarea>
                        <button class="button button-dark" type="submit">{{ $application->legal_hold_at ? 'Release legal hold' : 'Place legal hold' }}</button>
                    </form>
                @endif
                @if(in_array($application->status, ['submitted','reviewing'], true))
                    <form method="POST" action="{{ route('admin.model-applications.update', $application) }}" class="enquiry-status-form">
                        @csrf @method('PATCH')
                        <label for="status-{{ $application->id }}">Decision</label>
                        <select id="status-{{ $application->id }}" name="status" required>
                            @if($application->status === 'submitted')<option value="reviewing">Begin review</option><option value="declined">Decline</option>@else<option value="accepted">Accept for representation discussion</option><option value="declined">Decline</option>@endif
                        </select>
                        <label for="note-{{ $application->id }}">Reason / next steps (required for every transition)</label>
                        <textarea id="note-{{ $application->id }}" name="review_note" rows="2" maxlength="2000" placeholder="At least 20 characters for a decision"></textarea>
                        <button class="button button-dark" type="submit">Save review ↗</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="empty-table">No model agency applications have been received.</p>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $applications->links() }}</div>
@endsection
