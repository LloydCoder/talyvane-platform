@extends('admin.layout')

@section('title', 'Model casting')
@section('section', 'MODEL CASTING')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">MODEL AGENCY</p>
            <h1>Private casting calls</h1>
            <p class="field-help">Internal agency workflow only. Calls are not publicly listed or distributed to clients. A casting selection is not an assignment or booking.</p>
        </div>
    </div>

    @if($canCreateCasting)
    <section class="admin-panel">
        <h2>Create casting brief</h2>
        <form method="POST" action="{{ route('admin.model-castings.store') }}" class="admin-form-grid">
            @csrf
            <label>Call title<input name="title" minlength="3" maxlength="180" required></label>
            <label>Client / commissioning entity<input name="client_name" minlength="2" maxlength="160" required></label>
            <label>Client reference<input name="client_reference" maxlength="200"></label>
            <label>Project purpose
                <select name="project_purpose" required>
                    <option value="editorial">Editorial</option><option value="commercial_campaign">Commercial campaign</option><option value="e_commerce">E-commerce</option><option value="social_media">Social media</option><option value="advertising">Advertising</option><option value="other">Other</option>
                </select>
            </label>
            <label>Approved territory
                <select name="country_code" required><option value="">Choose market</option>@foreach($approvedMarkets as $market)<option value="{{ $market->country_code }}">{{ $market->name }} ({{ $market->country_code }})</option>@endforeach</select>
            </label>
            <label>Maximum submissions<input type="number" name="max_submissions" min="1" max="500" value="20" required></label>
            <label>Call opens<input type="datetime-local" name="opens_at" required></label>
            <label>Submission deadline<input type="datetime-local" name="closes_at" required></label>
            <label>Shoot starts<input type="datetime-local" name="shoot_starts_at" required></label>
            <label>Shoot ends<input type="datetime-local" name="shoot_ends_at" required></label>
            <label>Agreed compensation<input type="number" name="compensation_amount" min="0.0001" step="0.0001"></label>
            <label>Currency<input name="currency_code" maxlength="3" pattern="[A-Z]{3}" placeholder="GBP"></label>
            <label class="full-width">Project description<textarea name="description" minlength="20" maxlength="3000" required></textarea></label>
            <label class="full-width">Model requirements and restrictions<textarea name="requirements" minlength="20" maxlength="3000" required></textarea></label>
            <div class="full-width"><button class="button button-dark" type="submit">Save private casting draft</button></div>
        </form>
    </section>
    @else
        <section class="admin-panel"><h2>New casting paused</h2><p class="field-help">New casting calls, submissions and positive selection decisions are disabled. Existing calls can still be closed or cancelled, and submissions can still be declined or withdrawn.</p></section>
    @endif

    <section class="admin-panel">
        <h2>Casting register</h2>
        @forelse($calls as $call)
            <article class="admin-record">
                <div class="record-heading">
                    <div>
                        <p class="eyebrow">{{ $call->reference }} · {{ strtoupper($call->status) }}</p>
                        <h3>{{ $call->title }}</h3>
                        <p>{{ $call->client_name }} · {{ $call->project_purpose }} · {{ $call->country_code }}</p>
                        <p class="field-help">Submissions close {{ $call->closes_at->format('d M Y H:i') }} · Shoot {{ $call->shoot_starts_at->format('d M') }}–{{ $call->shoot_ends_at->format('d M Y') }}</p>
                        @if($call->compensation_amount !== null)<p>Compensation: {{ $call->compensation_amount }} {{ $call->currency_code }}</p>@endif
                    </div>
                    <div class="record-actions">
                        @if($call->status === 'draft' && $canCreateCasting)
                            <form method="POST" action="{{ route('admin.model-castings.publish', $call) }}">@csrf<button class="button button-dark" type="submit">Open call</button></form>
                        @elseif($call->status === 'open')
                            <form method="POST" action="{{ route('admin.model-castings.close', $call) }}">@csrf<label>Close reason<input name="reason" minlength="20" maxlength="1000" required></label><button class="button button-dark" type="submit">Close to submissions</button></form>
                        @endif
                        @if(in_array($call->status, ['draft','open'], true))
                            <form method="POST" action="{{ route('admin.model-castings.cancel', $call) }}">@csrf<label>Cancellation reason<input name="reason" minlength="20" maxlength="1000" required></label><button class="button button-dark" type="submit">Cancel call</button></form>
                        @endif
                    </div>
                </div>

                @if($call->status === 'open' && $canCreateCasting)
                    <details class="admin-details">
                        <summary>Submit an eligible model</summary>
                        <p class="field-help">Only approved private portfolio assets with an active casting-distribution licence covering this territory and shoot dates can be submitted. The model's per-use consent must already be recorded.</p>
                        @foreach($eligibleApplications as $candidate)
                            @foreach($candidate->portfolioAssets->where('status', 'approved') as $asset)
                                @foreach($asset->imageLicences->where('status', 'active')->where('purpose', 'casting_distribution') as $licence)
                                    @if(in_array('casting_platform', $licence->channels ?? [], true) && $licence->country_code === $call->country_code && $licence->starts_on->format('Y-m-d') <= $call->shoot_starts_at->format('Y-m-d') && $licence->ends_on->format('Y-m-d') >= $call->shoot_ends_at->format('Y-m-d'))
                                        <form method="POST" action="{{ route('admin.model-castings.submissions.store', $call) }}" class="inline-form">
                                            @csrf
                                            <input type="hidden" name="application_id" value="{{ $candidate->id }}">
                                            <input type="hidden" name="portfolio_asset_id" value="{{ $asset->id }}">
                                            <input type="hidden" name="image_licence_id" value="{{ $licence->id }}">
                                            <span>{{ $candidate->full_name }} · {{ $asset->id }} · {{ $licence->reference }}</span>
                                            <button class="button button-dark" type="submit">Submit eligible model</button>
                                        </form>
                                    @endif
                                @endforeach
                            @endforeach
                        @endforeach
                    </details>
                @endif

                <h4>Submissions ({{ $call->submissions->count() }})</h4>
                @forelse($call->submissions as $submission)
                    <div class="field-help">
                        <strong>{{ $submission->reference }} · {{ strtoupper($submission->status) }}</strong>
                        <p>{{ $submission->application->full_name }} · Asset #{{ $submission->portfolio_asset_id }} · Licence {{ $submission->imageLicence->reference }}</p>
                        @if($submission->decision_note)<p>{{ $submission->decision_note }}</p>@endif
                        @if(in_array($submission->status, ['submitted','shortlisted'], true))
                            <form method="POST" action="{{ route('admin.model-casting-submissions.decision', $submission) }}" class="inline-form">
                                @csrf
                                <select name="decision" required>@if($canCreateCasting)<option value="shortlisted">Shortlist</option><option value="selected">Select</option>@endif<option value="declined">Decline</option></select>
                                <input name="note" minlength="20" maxlength="1500" placeholder="Human review rationale" required>
                                <button class="button button-dark" type="submit">Record decision</button>
                            </form>
                        @endif
                        @if(in_array($submission->status, ['submitted','shortlisted','selected'], true))
                            <form method="POST" action="{{ route('admin.model-casting-submissions.withdraw', $submission) }}" class="inline-form">
                                @csrf<input name="reason" minlength="20" maxlength="1500" placeholder="Verified model withdrawal reason" required><input name="withdrawal_evidence_reference" minlength="20" maxlength="200" placeholder="private/model-consent/withdrawal-evidence.pdf" required>
                                <button class="button button-dark" type="submit">Withdraw submission</button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="field-help">No submissions have been recorded.</p>
                @endforelse
            </article>
        @empty
            <p class="field-help">No casting calls have been created.</p>
        @endforelse
        {{ $calls->links() }}
    </section>

    <p class="field-help">This is a private agency register. It does not expose a public casting board, transfer portfolio images to clients, automatically rank/select models, or create assignments, bookings, payments or settlements. Revoked or expired image licences invalidate linked submissions.</p>
@endsection
