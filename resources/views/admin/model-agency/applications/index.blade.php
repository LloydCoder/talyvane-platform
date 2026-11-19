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
                                <label>Approved territory</label><select name="country_code" required><option value="">Choose an approved market</option>@foreach($approvedMarkets as $market)<option value="{{ $market->country_code }}">{{ $market->name }} ({{ $market->country_code }})</option>@endforeach</select>
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
                @if($application->portfolioAssets->isNotEmpty() || ($application->status === 'accepted' && $application->adult_eligibility_verified_at && ! $application->legal_hold_at))
                    <section class="enquiry-status-form">
                        <h3>Private model portfolio</h3>
                        <p class="field-help">Portfolio files are stored privately, sanitized to WebP and never published by this workflow. Approval is for internal agency curation only. Campaign, casting or public use requires a separate purpose-bound image licence.</p>
                        @foreach($application->portfolioAssets as $asset)
                            <div class="field-help">
                                <strong>Asset #{{ $asset->id }} · {{ str_replace('_', ' ', $asset->status) }}</strong>
                                <p>{{ $asset->width }} × {{ $asset->height }} · {{ number_format($asset->file_size / 1024, 1) }} KiB · Consent policy {{ $asset->consent_policy_version }}</p>
                                <p>Purpose scope: {{ $asset->consent_scope }}</p>
                                <p>Source: {{ $asset->source_description }}</p>
                                @if(in_array($asset->status, ['pending_review','approved'], true) && $asset->storage_path)
                                    <p><a href="{{ route('admin.model-portfolio-assets.preview', $asset) }}" target="_blank" rel="noopener noreferrer">Open private image preview ↗</a></p>
                                @endif
                                @if($asset->status === 'pending_review')
                                    <form method="POST" action="{{ route('admin.model-portfolio-assets.review', $asset) }}">
                                        @csrf
                                        <label>Review note</label><textarea name="review_note" minlength="20" maxlength="2000" required></textarea>
                                        <button class="button button-dark" name="decision" value="approved" type="submit">Approve for private curation</button>
                                        <button class="button button-dark" name="decision" value="rejected" type="submit">Reject and remove file</button>
                                    </form>
                                @endif
                                @if(in_array($asset->status, ['pending_review','approved'], true) && ! $asset->consent_withdrawn_at)
                                    <form method="POST" action="{{ route('admin.model-portfolio-assets.withdraw-consent', $asset) }}">
                                        @csrf
                                        <label>Verified withdrawal reason</label><textarea name="reason" minlength="20" maxlength="2000" required></textarea>
                                        <label>Private withdrawal evidence reference</label><input name="withdrawal_evidence_reference" minlength="20" maxlength="200" placeholder="private/model-consent/withdrawal-evidence.pdf" required>
                                        <label><input type="checkbox" name="confirm_withdrawal" value="1" required> The model's withdrawal request has been verified and recorded</label>
                                        <button class="button button-dark" type="submit">Withdraw consent and remove image</button>
                                    </form>
                                @endif
                                <div class="field-help">
                                    <strong>Purpose-bound image licences</strong>
                                    @foreach($asset->imageLicences as $licence)
                                        <div class="field-help">
                                            <strong>{{ $licence->reference }} · {{ str_replace('_', ' ', $licence->status) }}</strong>
                                            <p>{{ $licence->purpose }} · {{ $licence->country_code }} · {{ $licence->starts_on->format('d M Y') }} – {{ $licence->ends_on->format('d M Y') }}</p>
                                            <p>Channels: {{ implode(', ', $licence->channels ?? []) }} · Licensee: {{ $licence->licensee_name }}</p>
                                            @if($licence->compensation_amount !== null)
                                                <p>Agreed compensation: {{ $licence->compensation_amount }} {{ $licence->currency_code }}</p>
                                            @endif
                                            @if($licence->status === 'draft')
                                                <form method="POST" action="{{ route('admin.model-image-licences.activate', $licence) }}">
                                                    @csrf
                                                    <label>Private externally executed licence document reference</label><input name="execution_evidence_reference" minlength="20" maxlength="200" required placeholder="private/model-licences/signed-licence.pdf">
                                                    <label>Private purpose-specific model consent evidence reference</label><input name="model_consent_evidence_reference" minlength="20" maxlength="200" required placeholder="private/model-consent/campaign-consent.pdf">
                                                    <label><input type="checkbox" name="confirm_external_execution" value="1" required> All required parties externally executed the licence and evidence is stored privately</label>
                                                    <label><input type="checkbox" name="confirm_model_consent" value="1" required> The model gave informed consent for this exact purpose, territory, channel and term</label>
                                                    <button class="button button-dark" type="submit">Activate recorded licence</button>
                                                </form>
                                            @endif
                                            @if(in_array($licence->status, ['draft','active'], true))
                                                <form method="POST" action="{{ route('admin.model-image-licences.revoke', $licence) }}">@csrf
                                                    <label>Revocation reason</label><textarea name="reason" minlength="20" maxlength="2000" required></textarea>
                                                    <label><input type="checkbox" name="confirm_revocation" value="1" required> I confirm this licence must be revoked</label>
                                                    <button class="button button-dark" type="submit">Revoke licence</button>
                                                </form>
                                            @endif
                                        </div>
                                    @endforeach
                                    @if($asset->status === 'approved' && ! $asset->consent_withdrawn_at)
                                        @php($executedAgreements = $application->representationAgreements->where('status', 'executed')->where('country_code', $application->country_code))
                                        @if($executedAgreements->isNotEmpty())
                                            <form method="POST" action="{{ route('admin.model-portfolio-assets.licences.store', $asset) }}">
                                                @csrf
                                                <label>Executed representation agreement</label>
                                                <select name="agreement_id" required>
                                                    <option value="">Choose agreement</option>
                                                    @foreach($executedAgreements as $agreement)<option value="{{ $agreement->id }}">{{ $agreement->reference }} · v{{ $agreement->version }}</option>@endforeach
                                                </select>
                                                <label>Licensee / client entity</label><input name="licensee_name" minlength="2" maxlength="160" required>
                                                <label>Licensee contract reference (optional)</label><input name="licensee_reference" maxlength="200">
                                                <label>Usage purpose</label>
                                                <select name="purpose" required>
                                                    <option value="editorial">Editorial</option><option value="commercial_campaign">Commercial campaign</option><option value="e_commerce">E-commerce</option><option value="social_media">Social media</option><option value="advertising">Advertising</option><option value="casting_distribution">Casting distribution</option><option value="private_portfolio">Private portfolio</option><option value="other">Other (describe below)</option>
                                                </select>
                                                <label>Permitted channels</label>
                                                @foreach(['website','print','social_media','email','e_commerce','casting_platform','out_of_home','broadcast'] as $channel)
                                                    <label><input type="checkbox" name="channels[]" value="{{ $channel }}"> {{ str_replace('_', ' ', $channel) }}</label>
                                                @endforeach
                                                <label>Exact usage, campaign and restriction details</label><textarea name="purpose_details" minlength="20" maxlength="2000" required></textarea>
                                                <label>Territory</label><input name="country_code" value="{{ $application->country_code }}" maxlength="2" pattern="[A-Z]{2}" required>
                                                <label>Licence starts</label><input type="date" name="starts_on" min="{{ now()->toDateString() }}" required>
                                                <label>Licence ends</label><input type="date" name="ends_on" required>
                                                <input type="hidden" name="exclusivity" value="0">
                                                <label><input type="checkbox" name="exclusivity" value="1"> Exclusive in the specified scope</label>
                                                <label>Agreed compensation (optional)</label><input type="number" name="compensation_amount" min="0.0001" step="0.0001">
                                                <label>Currency (required with compensation)</label><input name="currency_code" maxlength="3" pattern="[A-Z]{3}">
                                                <button class="button button-dark" type="submit">Create private licence draft</button>
                                            </form>
                                        @else
                                            <p>No executed representation agreement exists for this territory. A licence cannot be drafted.</p>
                                        @endif
                                    @endif
                                    <p class="field-help">An active record means the agency has recorded an externally executed instrument and separate model consent evidence. This platform does not sign or validate the document and does not serve images publicly. Withdrawal of portfolio consent or termination of representation automatically revokes related draft/active licences.</p>
                                </div>
                            </div>
                        @endforeach
                        @if($application->status === 'accepted' && $application->adult_eligibility_verified_at && ! $application->legal_hold_at)
                            <form method="POST" action="{{ route('admin.model-applications.portfolio-assets.store', $application) }}" enctype="multipart/form-data">
                                @csrf
                                <label>Sanitized portfolio image (JPEG, PNG or WebP; max 8 MiB)</label><input type="file" name="media" accept="image/jpeg,image/png,image/webp" required>
                                <label>Source and provenance</label><textarea name="source_description" minlength="20" maxlength="1000" required placeholder="How was this image obtained and who supplied it?"></textarea>
                                <label>Exact consent scope (internal curation only at this stage)</label><textarea name="consent_scope" minlength="20" maxlength="2000" required placeholder="Specify permitted purposes, channels, territory and limits"></textarea>
                                <label>Private copyright / image-rights evidence reference</label><input name="rights_evidence_reference" minlength="20" maxlength="200" required placeholder="private/model-rights/evidence-reference.pdf">
                                <label>Private model consent evidence reference</label><input name="consent_evidence_reference" minlength="20" maxlength="200" required placeholder="private/model-consent/consent-reference.pdf">
                                <label><input type="checkbox" name="confirm_model_consent" value="1" required> The model's informed, purpose-specific consent is documented and the referenced evidence is held privately</label>
                                <label><input type="checkbox" name="confirm_image_rights" value="1" required> The agency has verified the right to possess and process this image</label>
                                <button class="button button-dark" type="submit">Add private portfolio image</button>
                            </form>
                        @endif
                        <p class="field-help">This is an administrator-recorded external evidence workflow, not an electronic signature or identity-verification service. Do not upload images without verified model consent and rights evidence. Consent withdrawal removes the private image file. Public, campaign, social, advertising and client-facing use remain disabled until a separate licence is implemented.</p>
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
