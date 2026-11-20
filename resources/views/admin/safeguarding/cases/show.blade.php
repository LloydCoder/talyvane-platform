@extends('admin.layout')

@section('title', 'Safeguarding case '.$case->reference)
@section('section', 'SAFEGUARDING CASE')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">{{ $case->reference }} · {{ strtoupper($case->status) }}</p>
            <h1>{{ str_replace('_', ' ', ucfirst($case->case_type)) }}</h1>
            <p class="field-help">Restricted confidential record. This access has been logged. Follow approved safeguarding escalation and evidence-preservation procedures; do not download or redistribute sensitive material.</p>
        </div>
        <a class="button button-light" href="{{ route('admin.safeguarding.cases.index') }}">Back to assigned cases</a>
    </div>

    <section class="admin-panel">
        <div class="record-heading">
            <div>
                <p class="eyebrow">SEVERITY · {{ strtoupper($case->severity) }}</p>
                @if($case->content_erased)
                    <h2>Confidential case content removed under retention policy</h2>
                @else
                    <h2>{{ $case->summary }}</h2>
                @endif
                <p>Opened {{ $case->created_at->format('d M Y H:i') }} · Status {{ str_replace('_', ' ', $case->status) }}</p>
                @if($case->subject_type || $case->subject_reference)
                    <p>Related record: {{ $case->subject_type ?? 'unspecified' }} {{ $case->subject_reference ?? '' }}</p>
                @endif
            </div>
        </div>
        <div class="admin-record">
            <h3>Confidential report</h3>
            @if($case->content_erased)
                <p class="field-help">The per-case content key was removed from the primary database under the approved retention policy. Audit metadata remains. Encrypted backup copies follow their separate expiry schedule.</p>
            @else
                <p class="preserve-lines">{{ $case->details }}</p>
            @endif
        </div>
    </section>

    @if($case->status !== 'closed')
        <section class="admin-panel">
            <h2>Record next case action</h2>
            <form method="POST" action="{{ route('admin.safeguarding.cases.update', $case) }}" class="admin-form-grid">
                @csrf
                @method('PATCH')
                <label>Next status
                    <select name="status" required>
                        @if($case->status === 'open')
                            <option value="triaged">Triaged</option>
                        @elseif($case->status === 'triaged')
                            <option value="investigating">Investigating</option>
                            <option value="action_required">Action required</option>
                            <option value="resolved">Resolved</option>
                        @elseif($case->status === 'investigating')
                            <option value="action_required">Action required</option>
                            <option value="resolved">Resolved</option>
                        @elseif($case->status === 'action_required')
                            <option value="investigating">Investigating</option>
                            <option value="resolved">Resolved</option>
                        @elseif($case->status === 'resolved')
                            <option value="closed">Closed</option>
                        @endif
                    </select>
                </label>
                <label class="full-width">Decision / action note<textarea name="note" minlength="20" maxlength="1500" required></textarea></label>
                <div class="full-width"><button class="button button-dark" type="submit">Record case transition</button></div>
            </form>
        </section>
    @endif

    <section class="admin-panel">
        <h2>Append-only case history</h2>
        @forelse($case->events as $event)
            <article class="admin-record">
                <p class="eyebrow">{{ strtoupper($event->event_type) }} · {{ $event->occurred_at->format('d M Y H:i:s') }}</p>
                @if($event->note)
                    <p>{{ $event->note }}</p>
                @elseif($case->content_erased)
                    <p class="field-help">Event note unavailable after case-content retention erasure.</p>
                @endif
                <p class="field-help">Actor: {{ $event->actor?->name ?? 'System / former account' }} · {{ $event->from_status ?? '—' }} → {{ $event->to_status ?? '—' }}</p>
            </article>
        @empty
            <p class="field-help">No case events have been recorded.</p>
        @endforelse
    </section>
@endsection
