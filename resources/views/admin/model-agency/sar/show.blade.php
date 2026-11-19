@extends('admin.layout')

@section('title', 'Review subject access request')
@section('section', 'MODEL AGENCY / SAR')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">VERIFIED APPLICANT ACCESS REQUEST</p>
        <h1>Review & prepare<br><em>response.</em></h1>
        <p>{{ $rightsRequest->application->reference }} · {{ $rightsRequest->application->full_name }} · {{ $rightsRequest->application->email }}</p>
    </div>

    <section class="admin-section">
        <div class="portal-section-heading"><h2>Response deadline</h2><p>{{ $rightsRequest->response_status }}</p></div>
        <p>Received: {{ $rightsRequest->created_at->format('d M Y H:i') }}</p>
        <p>Due: {{ $rightsRequest->response_due_at?->format('d M Y H:i') ?? 'Not set' }}</p>
        @if($rightsRequest->response_extended_at)
            <p>Extended: {{ $rightsRequest->response_extended_at->format('d M Y H:i') }}</p>
            <p>Extension reason: {{ $rightsRequest->response_extension_reason }}</p>
        @endif
        @if($rightsRequest->response_status === 'prepared')
            <p>Reviewed by user ID {{ $rightsRequest->response_reviewed_by }} at {{ $rightsRequest->response_reviewed_at?->format('d M Y H:i') }}</p>
            <p>SHA-256: <code>{{ $rightsRequest->response_sha256 }}</code></p>
            <a class="button button-dark" href="{{ route('admin.model-applicant-sar.download', $rightsRequest) }}">Download reviewed response ↗</a>
        @elseif(in_array($rightsRequest->response_status, ['fulfilled', 'partially_fulfilled', 'refused'], true))
            <p>Decision: {{ str_replace('_', ' ', $rightsRequest->response_status) }}</p>
            <p>Delivered: {{ $rightsRequest->response_responded_at?->format('d M Y H:i') }}</p>
            <p>Delivery method: {{ $rightsRequest->response_delivery_method }}</p>
            <p>Response hash: <code>{{ $rightsRequest->response_sha256 }}</code></p>
            <p>Decision reason: {{ $rightsRequest->response_decision_reason }}</p>
        @endif
    </section>

    @if(in_array($rightsRequest->response_status, ['pending', 'prepared'], true))
        @if($rightsRequest->response_status === 'pending')
            <section class="admin-section">
                <h2>Prepare a complete response</h2>
                <p class="field-help">The generated JSON includes the application record, platform event history, prior privacy requests and the published privacy notice. Review every field, add records found in external systems, and redact third-party or exempt material before saving.</p>
                <form method="POST" action="{{ route('admin.model-applicant-sar.prepare', $rightsRequest) }}" class="review-form">
                    @csrf
                    <label for="response_content">Reviewed subject-access response (JSON)</label>
                    <textarea id="response_content" name="response_content" rows="24" required maxlength="200000" spellcheck="false">{{ old('response_content', $draftResponse) }}</textarea>
                    <label class="remember-row"><input type="checkbox" name="platform_records_searched" value="1" required> I searched all relevant platform records and reviewed the generated data.</label>
                    <label class="remember-row"><input type="checkbox" name="external_records_searched" value="1" required> I searched all relevant agency systems outside this platform and added any responsive records.</label>
                    <label class="remember-row"><input type="checkbox" name="third_party_redactions_reviewed" value="1" required> I reviewed third-party rights, confidential references, privilege and applicable exemptions, and made lawful redactions.</label>
                    <label class="remember-row"><input type="checkbox" name="supplementary_information_reviewed" value="1" required> I included or referenced the applicable supplementary information from the current privacy notice.</label>
                    <button class="button button-dark" type="submit">Encrypt and prepare response ↗</button>
                </form>
            </section>
        @else
            <section class="admin-section">
                <h2>Record delivery</h2>
                <p class="field-help">Download the reviewed response, deliver it through the chosen secure channel, then record the delivery reference. The temporary encrypted response copy is purged when delivery is recorded.</p>
                <form method="POST" action="{{ route('admin.model-applicant-sar.fulfil', $rightsRequest) }}" class="review-form">
                    @csrf
                    <label for="decision">Decision</label>
                    <select id="decision" name="decision" required>
                        <option value="fulfilled">Fulfilled</option>
                        <option value="partially_fulfilled">Partially fulfilled</option>
                        <option value="refused">Refused under a reviewed exemption</option>
                    </select>
                    <label for="delivery_method">Secure delivery method</label>
                    <select id="delivery_method" name="delivery_method" required>
                        <option value="secure_email">Secure email / encrypted attachment</option>
                        <option value="secure_portal">Secure external portal</option>
                        <option value="other">Other approved secure channel</option>
                    </select>
                    <label for="delivery_reference">Delivery ticket/reference (stored as a keyed hash)</label>
                    <input id="delivery_reference" name="delivery_reference" required minlength="8" maxlength="255" autocomplete="off">
                    <label for="decision_reason">Decision and delivery note</label>
                    <textarea id="decision_reason" name="decision_reason" rows="3" required minlength="20" maxlength="1000"></textarea>
                    <button class="button button-dark" type="submit">Record response delivery ↗</button>
                </form>
            </section>
        @endif

        @if(!$rightsRequest->response_extended_at && $rightsRequest->created_at->copy()->addMonthNoOverflow()->isFuture())
            <section class="admin-section">
                <h2>Extend response deadline</h2>
                <p class="field-help">A single extension of up to two further months may be recorded only within the initial response month and only where the request is complex or multiple requests are received. The applicant is notified by email.</p>
                <form method="POST" action="{{ route('admin.model-applicant-sar.extend', $rightsRequest) }}" class="review-form">
                    @csrf
                    <label for="extension-reason">Reason for extension</label>
                    <textarea id="extension-reason" name="reason" rows="2" required minlength="20" maxlength="1000"></textarea>
                    <button class="button button-dark" type="submit">Extend and notify applicant ↗</button>
                </form>
            </section>
        @endif
    @endif

    <section class="admin-section">
        <h2>Applicant record and event history</h2>
        <dl class="application-data-list">
            <dt>Reference</dt><dd>{{ $rightsRequest->application->reference }}</dd>
            <dt>Applicant</dt><dd>{{ $rightsRequest->application->full_name }}</dd>
            <dt>Email</dt><dd>{{ $rightsRequest->application->email }}</dd>
            <dt>Phone</dt><dd>{{ $rightsRequest->application->phone ?: 'Not supplied' }}</dd>
            <dt>Location</dt><dd>{{ $rightsRequest->application->city }}, {{ $rightsRequest->application->country_code }}</dd>
            <dt>Portfolio</dt><dd>{{ $rightsRequest->application->portfolio_url ?: 'Not supplied' }}</dd>
            <dt>Experience summary</dt><dd>{{ $rightsRequest->application->experience_summary }}</dd>
            <dt>Review note</dt><dd>{{ $rightsRequest->application->review_note ?: 'None' }}</dd>
        </dl>
        <h3>Application history</h3>
        @foreach($rightsRequest->application->events->sortBy('occurred_at') as $event)
            <p>{{ $event->occurred_at->format('d M Y H:i') }} · {{ $event->event_type }} · {{ $event->reason }}</p>
        @endforeach
    </section>
@endsection
