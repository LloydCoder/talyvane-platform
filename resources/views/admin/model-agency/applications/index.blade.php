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
                <p class="field-help">Adult eligibility self-attested {{ $application->adult_eligibility_confirmed_at?->format('d M Y') ?? 'Erased' }} · @if($application->adult_eligibility_verified_at) Manually verified {{ $application->adult_eligibility_verified_at->format('d M Y') }} by {{ $application->adultEligibilityVerifier?->name ?? 'former administrator' }} @else <strong>Not yet verified — acceptance blocked</strong> @endif · Privacy notice {{ $application->privacy_notice_version }}</p>
                @if($application->status === 'reviewing' && ! $application->adult_eligibility_verified_at && in_array(auth()->user()->role, ['super_admin', 'owner'], true))
                    <form method="POST" action="{{ route('admin.model-applications.adult-eligibility', $application) }}" class="enquiry-status-form">
                        @csrf
                        <p class="field-help">Inspect an original identity document using the approved policy. Confirm identity match, document validity and the policy's applicable adult-age threshold. Do not upload or retain document images, document numbers or dates of birth here.</p>
                        <label><input type="checkbox" name="identity_matches_applicant" value="1" required> Identity document matches the applicant</label>
                        <label><input type="checkbox" name="document_is_valid" value="1" required> Document is valid and acceptable under policy</label>
                        <label><input type="checkbox" name="meets_policy_age_threshold" value="1" required> Applicant meets the approved policy's adult-age threshold</label>
                        <input type="hidden" name="verification_method" value="manual_document_inspection">
                        <button class="button button-dark" type="submit">Record adult eligibility verification</button>
                    </form>
                @endif
                @if($application->review_note)<p class="enquiry-message">Review note: {{ $application->review_note }}</p>@endif
                @if($application->legal_hold_at)
                    <p class="field-help">Legal hold active since {{ $application->legal_hold_at->format('d M Y') }}.</p>
                    @if(in_array(auth()->user()->role, ['super_admin', 'owner'], true))<p class="field-help">Legal hold reason: {{ $application->legal_hold_reason }}</p>@endif
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
                @if($application->status === 'accepted' && $application->adult_eligibility_verified_at && ! $application->legal_hold_at)
                    <section class="enquiry-status-form">
                        <h3>Representation agreement</h3>
                        @forelse($application->representationAgreements as $agreement)
                            <div class="field-help">
                                <strong>{{ $agreement->reference }} · v{{ $agreement->version }} · {{ str_replace('_', ' ', $agreement->status) }}</strong>
                                <p>Terms policy: {{ $agreement->terms_policy_version }} · Scope: {{ $agreement->terms_snapshot['representation_scope'] ?? 'Not available' }}</p>
                                @if($agreement->status === 'draft')
                                    <form method="POST" action="{{ route('admin.model-representation-agreements.offer', $agreement) }}">@csrf
                                        <button class="button button-dark" type="submit">Issue offer</button>
                                    </form>
                                @elseif($agreement->status === 'offered')
                                    <form method="POST" action="{{ route('admin.model-representation-agreements.execute', $agreement) }}">
                                        @csrf
                                        <label>Private executed document storage reference</label>
                                        <input name="executed_document_reference" required minlength="20" maxlength="200" placeholder="private/model-agreements/signed-contract-reference.pdf">
                                        <label>Effective from</label><input type="date" name="effective_from" required>
                                        <label>Effective until (optional)</label><input type="date" name="effective_until">
                                        <label><input type="checkbox" name="confirm_externally_executed" value="1" required> All required parties have executed the agreement outside this platform and the referenced evidence is stored privately</label>
                                        <button class="button button-dark" type="submit">Record external execution</button>
                                    </form>
                                @elseif($agreement->status === 'executed')
                                    <p>Effective {{ $agreement->effective_from?->format('d M Y') }} @if($agreement->effective_until) until {{ $agreement->effective_until->format('d M Y') }} @endif. Execution evidence reference is encrypted.</p>
                                    <form method="POST" action="{{ route('admin.model-representation-agreements.terminate', $agreement) }}">@csrf
                                        <label>Termination reason</label><textarea name="reason" minlength="20" maxlength="2000" required></textarea>
                                        <label><input type="checkbox" name="confirm_termination" value="1" required> I confirm termination is authorized under the executed agreement and applicable law</label>
                                        <button class="button button-dark" type="submit">Record termination</button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <p class="field-help">No representation agreement exists. Acceptance alone does not create an agency relationship.</p>
                        @endforelse
                        @if(! $application->representationAgreements->contains(fn ($agreement) => in_array($agreement->status, ['draft','offered','executed'], true)))
                            <form method="POST" action="{{ route('admin.model-applications.agreements.store', $application) }}">
                                @csrf
                                <label>Territory (must match the legally approved scope)</label><input name="territory" maxlength="120" required>
                                <label>Representation scope</label><textarea name="representation_scope" minlength="20" maxlength="3000" required></textarea>
                                <label>Exclusivity</label><select name="exclusivity" required><option value="non_exclusive">Non-exclusive</option><option value="exclusive">Exclusive</option></select>
                                <label>Commission (%)</label><input type="number" name="commission_percent" min="0" max="100" step="0.01" required>
                                <label>Term (months)</label><input type="number" name="term_months" min="1" max="60" required>
                                <label>Notice period (days)</label><input type="number" name="notice_days" min="0" max="180" required>
                                <label>Image and usage rights scope</label><textarea name="image_usage_scope" minlength="20" maxlength="3000" required></textarea>
                                <label>Compensation and commission terms</label><textarea name="compensation_terms" minlength="20" maxlength="3000" required></textarea>
                                <label>Expenses and deductions</label><textarea name="expense_terms" minlength="20" maxlength="3000" required></textarea>
                                <label>Consent, withdrawal and safeguarding terms</label><textarea name="withdrawal_and_consent_terms" minlength="20" maxlength="3000" required></textarea>
                                <button class="button button-dark" type="submit">Create agreement draft</button>
                            </form>
                        @endif
                        <p class="field-help">Drafting and recording only. Offers are blocked until the current terms version is explicitly approved. This platform does not provide legal advice, create an electronic signature, or validate contract enforceability. Store executed documents in private storage and retain the required jurisdiction-specific evidence.</p>
                    </section>
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
