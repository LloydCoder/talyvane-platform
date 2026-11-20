@extends('admin.layout')
@section('title', 'Adult eligibility verification')
@section('section', 'MODEL AGENCY')
@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">RESTRICTED REVIEW WORKSPACE</p>
        <h1>Adult eligibility<br><em>verification.</em></h1>
        <p>Review applications already in the agency review state. Inspect the applicant's identity document through the approved offline procedure. Do not upload, photograph, copy or retain document details in this platform.</p>
    </div>

    @if (session('status'))
        <div class="admin-alert success">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="admin-alert error">{{ $errors->first() }}</div>
    @endif

    <div class="admin-list-toolbar">
        <span>{{ $applications->total() }} pending eligibility checks</span>
        <span>Personal data — minimum necessary</span>
    </div>

    <div class="enquiry-list">
        @forelse ($applications as $application)
            <article class="enquiry-card">
                <div class="enquiry-card-head">
                    <div>
                        <span class="eyebrow">{{ $application->reference }}</span>
                        <h2>{{ $application->full_name }}</h2>
                        <p>Accepted policy version: {{ $application->adult_eligibility_policy_version }}</p>
                    </div>
                    <span class="status-pill">Awaiting verification</span>
                </div>
                <p class="muted">Applicant email, contact details, city, portfolio links, experience notes, agreements and media are intentionally excluded from this workspace.</p>
                <form method="POST" action="{{ route('admin.adult-eligibility.verify', $application) }}" class="admin-form">
                    @csrf
                    <label><input required type="checkbox" name="identity_matches_applicant" value="1"> Identity document matches the applicant</label>
                    <label><input required type="checkbox" name="document_is_valid" value="1"> Document appears valid under the approved policy</label>
                    <label><input required type="checkbox" name="meets_policy_age_threshold" value="1"> Applicant meets the policy's adult age threshold</label>
                    <input type="hidden" name="verification_method" value="manual_document_inspection">
                    <button type="submit" class="button button-primary">Record eligibility verification</button>
                </form>
            </article>
        @empty
            <div class="admin-empty-state">No applications are awaiting adult eligibility verification.</div>
        @endforelse
    </div>

    {{ $applications->links() }}
@endsection
