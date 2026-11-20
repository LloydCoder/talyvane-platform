@extends('admin.layout')

@section('title', 'Assigned safeguarding cases')
@section('section', 'SAFEGUARDING')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">RESTRICTED SPECIALIST WORKSPACE</p>
            <h1>Assigned safeguarding cases</h1>
            <p class="field-help">Only cases assigned to your account appear here. Every case detail view is recorded in the access audit trail.</p>
        </div>
    </div>

    <div class="admin-list-toolbar">
        <span>{{ $cases->total() }} assigned cases</span>
        <span>Confidential — do not export or copy outside approved procedures</span>
    </div>

    <div class="admin-panel">
        @forelse($cases as $case)
            <article class="admin-record">
                <div class="record-heading">
                    <div>
                        <p class="eyebrow">{{ $case->reference }} · {{ strtoupper($case->status) }}</p>
                        <h2>{{ str_replace('_', ' ', ucfirst($case->case_type)) }}</h2>
                        <p>Severity: {{ strtoupper($case->severity) }} · Reported {{ $case->created_at->format('d M Y H:i') }}</p>
                    </div>
                    <a class="button button-dark" href="{{ route('admin.safeguarding.cases.show', $case) }}">Open restricted case</a>
                </div>
            </article>
        @empty
            <p class="field-help">No safeguarding cases are assigned to your account.</p>
        @endforelse
        {{ $cases->links() }}
    </div>
@endsection
