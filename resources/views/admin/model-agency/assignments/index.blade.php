@extends('admin.layout')

@section('title', 'Model assignments')
@section('section', 'MODEL ASSIGNMENTS')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">MODEL AGENCY</p>
            <h1>Assignment register</h1>
            <p class="field-help">Assignments can only originate from a human-selected casting submission. Offers, model acceptance and external execution are recorded separately. This register does not create bookings or settle money.</p>
        </div>
    </div>

    @if($canCreateAssignments)
    <section class="admin-panel">
        <h2>Create assignment draft</h2>
        @if($selectedSubmissions->isEmpty())
            <p class="field-help">No selected casting submissions are available for assignment creation.</p>
        @else
            <form method="POST" action="{{ route('admin.model-assignments.store') }}" class="admin-form-grid">
                @csrf
                <label class="full-width">Selected casting submission
                    <select name="submission_id" required>
                        <option value="">Choose a human-selected model</option>
                        @foreach($selectedSubmissions as $submission)
                            <option value="{{ $submission->id }}">{{ $submission->reference }} · {{ $submission->application->full_name }} · {{ $submission->call->title }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Project purpose
                    <select name="project_purpose" required>
                        <option value="editorial">Editorial</option>
                        <option value="commercial_campaign">Commercial campaign</option>
                        <option value="e_commerce">E-commerce</option>
                        <option value="social_media">Social media</option>
                        <option value="advertising">Advertising</option>
                        <option value="other">Other</option>
                    </select>
                </label>
                <label>Client / commissioning entity<input name="client_name" minlength="2" maxlength="160" required></label>
                <label>Client reference<input name="client_reference" maxlength="200"></label>
                <label>City / shoot location<input name="location_city" maxlength="100"></label>
                <label>Shoot starts<input type="datetime-local" name="shoot_starts_at" required></label>
                <label>Shoot ends<input type="datetime-local" name="shoot_ends_at" required></label>
                <label>Agreed compensation<input type="number" name="compensation_amount" min="0.0001" step="0.0001"></label>
                <label>Expense cap<input type="number" name="expense_cap_amount" min="0" step="0.0001"></label>
                <label>Currency<input name="currency_code" maxlength="3" pattern="[A-Z]{3}" placeholder="GBP"></label>
                <label class="full-width">Assignment brief<textarea name="brief" minlength="20" maxlength="3000" required></textarea></label>
                <label class="full-width">Deliverables and service scope<textarea name="deliverables" minlength="20" maxlength="3000" required></textarea></label>
                <div class="full-width"><button class="button button-dark" type="submit">Save assignment draft</button></div>
            </form>
        @endif
    </section>
    @else
        <section class="admin-panel"><h2>New assignments paused</h2><p class="field-help">New assignment creation is disabled by the platform feature control. Existing assignments remain available for acceptance, completion, cancellation and dispute handling.</p></section>
    @endif

    <section class="admin-panel">
        <h2>Assignment history</h2>
        @forelse($assignments as $assignment)
            <article class="admin-record">
                <div class="record-heading">
                    <div>
                        <p class="eyebrow">{{ $assignment->reference }} · {{ strtoupper($assignment->status) }}</p>
                        <h3>{{ $assignment->application->full_name }} — {{ $assignment->client_name }}</h3>
                        <p>{{ $assignment->project_purpose }} · {{ $assignment->country_code }} · {{ $assignment->shoot_starts_at->format('d M Y H:i') }}–{{ $assignment->shoot_ends_at->format('d M Y H:i') }}</p>
                        @if($assignment->compensation_amount !== null)
                            <p>Agreed compensation: {{ $assignment->compensation_amount }} {{ $assignment->currency_code }} · Expense cap: {{ $assignment->expense_cap_amount ?? 'Not set' }} · Commission snapshot: {{ $assignment->commission_percent }}%</p>
                        @endif
                        <p class="field-help">Casting source: {{ $assignment->submission->reference }} · Agreement: {{ $assignment->representationAgreement->reference }} · Policy: {{ $assignment->assignment_policy_version }}</p>
                    </div>
                    <div class="record-actions">
                        @if($assignment->status === 'draft' && $canCreateAssignments)
                            <form method="POST" action="{{ route('admin.model-assignments.offer', $assignment) }}">@csrf<button class="button button-dark" type="submit">Issue offer externally</button></form>
                        @elseif($assignment->status === 'offered')
                            <form method="POST" action="{{ route('admin.model-assignments.accept', $assignment) }}" class="admin-form-grid">
                                @csrf
                                <label>Executed contract evidence reference<input name="executed_document_reference" minlength="20" maxlength="200" placeholder="private/model-assignments/signed-contract.pdf" required></label>
                                <label>Model acceptance evidence reference<input name="model_acceptance_evidence_reference" minlength="20" maxlength="200" placeholder="private/model-consent/model-acceptance.pdf" required></label>
                                <button class="button button-dark" type="submit">Record external acceptance</button>
                            </form>
                            <form method="POST" action="{{ route('admin.model-assignments.decline', $assignment) }}" class="admin-form-grid">
                                @csrf
                                <label>Decline reason<input name="reason" minlength="20" maxlength="1000" required></label>
                                <label>Model decline evidence<input name="evidence_reference" minlength="20" maxlength="200" placeholder="private/model-consent/model-decline.pdf" required></label>
                                <button class="button button-dark" type="submit">Record decline</button>
                            </form>
                        @elseif($assignment->status === 'accepted')
                            <form method="POST" action="{{ route('admin.model-assignments.confirm', $assignment) }}">@csrf<button class="button button-dark" type="submit">Confirm assignment</button></form>
                        @elseif($assignment->status === 'confirmed')
                            <form method="POST" action="{{ route('admin.model-assignments.start', $assignment) }}">@csrf<button class="button button-dark" type="submit">Mark in progress</button></form>
                        @elseif($assignment->status === 'in_progress')
                            <form method="POST" action="{{ route('admin.model-assignments.complete', $assignment) }}" class="admin-form-grid">
                                @csrf<label>Completion note<input name="completion_note" minlength="20" maxlength="1500" required></label>
                                <button class="button button-dark" type="submit">Record completion</button>
                            </form>
                        @endif
                        @if(in_array($assignment->status, ['draft','offered','accepted','confirmed','in_progress'], true))
                            <form method="POST" action="{{ route('admin.model-assignments.cancel', $assignment) }}" class="admin-form-grid">
                                @csrf<label>Cancellation reason<input name="reason" minlength="20" maxlength="1000" required></label>
                                <button class="button button-dark" type="submit">Cancel assignment</button>
                            </form>
                        @endif
                        @if(in_array($assignment->status, ['accepted','confirmed','in_progress','completed'], true))
                            <form method="POST" action="{{ route('admin.model-assignments.dispute', $assignment) }}" class="admin-form-grid">
                                @csrf<label>Dispute reason<input name="reason" minlength="20" maxlength="1500" required></label>
                                <button class="button button-dark" type="submit">Record dispute</button>
                            </form>
                        @endif
                    </div>
                </div>
                <p class="field-help">{{ $assignment->brief }}</p>
                <p class="field-help">Deliverables: {{ $assignment->deliverables }}</p>
            </article>
        @empty
            <p class="field-help">No model assignments have been recorded.</p>
        @endforelse
        {{ $assignments->links() }}
    </section>

    <p class="field-help">Evidence fields are private storage-key references, not file uploads or signature verification. The platform does not send offers, execute contracts, confirm identity, create bookings, calculate tax, process compensation or settle commissions. Campaign image use remains governed by separate active purpose-specific image licences.</p>
@endsection
