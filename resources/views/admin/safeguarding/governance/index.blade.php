@extends('admin.layout')

@section('title', 'Safeguarding governance')
@section('section', 'SAFEGUARDING GOVERNANCE')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">METADATA-ONLY GOVERNANCE</p>
            <h1>Legal hold register</h1>
            <p class="field-help">This workspace exposes case references, workflow metadata and hold state only. It does not expose case summaries, narratives, related-record references or evidence. Hold release requires a different privileged actor from the one who placed the hold.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="admin-alert error">{{ $errors->first() }}</div>
    @endif

    <div class="admin-list-toolbar">
        <span>{{ $cases->total() }} safeguarding cases</span>
        <span>Restricted metadata — case contents remain segregated</span>
    </div>

    <div class="admin-panel">
        @forelse($cases as $case)
            <article class="admin-record">
                <div class="record-heading">
                    <div>
                        <p class="eyebrow">{{ $case->reference }} · {{ strtoupper($case->status) }}</p>
                        <h2>{{ str_replace('_', ' ', ucfirst($case->case_type)) }}</h2>
                        <p>Severity: {{ strtoupper($case->severity) }} · Opened {{ $case->created_at->format('d M Y H:i') }}</p>
                        @if($case->legal_hold_at)
                            <p class="field-help">LEGAL HOLD ACTIVE · Placed {{ $case->legal_hold_at->format('d M Y H:i') }} · Custodian user ID {{ $case->legal_hold_by }}</p>
                        @elseif($case->legal_hold_released_at)
                            <p class="field-help">Previous hold released {{ $case->legal_hold_released_at->format('d M Y H:i') }} · Reviewer user ID {{ $case->legal_hold_released_by }}</p>
                        @else
                            <p class="field-help">No active legal hold recorded.</p>
                        @endif
                    </div>
                </div>

                @if(!$case->legal_hold_at)
                    <form method="POST" action="{{ route('admin.safeguarding.governance.place-hold', $case) }}" class="admin-form-grid">
                        @csrf
                        <label class="full-width">Reason to place legal hold<textarea name="reason" minlength="20" maxlength="1000" required></textarea></label>
                        <div class="full-width"><button class="button button-dark" type="submit">Place legal hold</button></div>
                    </form>
                @elseif((int) $case->legal_hold_by !== (int) auth()->id())
                    <form method="POST" action="{{ route('admin.safeguarding.governance.release-hold', $case) }}" class="admin-form-grid">
                        @csrf
                        <label class="full-width">Independent release rationale<textarea name="reason" minlength="20" maxlength="1000" required></textarea></label>
                        <div class="full-width"><button class="button button-dark" type="submit">Release legal hold</button></div>
                    </form>
                @else
                    <p class="field-help">You placed this hold. A different Super Admin or Owner must review and release it.</p>
                @endif
            </article>
        @empty
            <p class="field-help">No safeguarding cases are available for governance review.</p>
        @endforelse
        {{ $cases->links() }}
    </div>
@endsection
