@extends('admin.layout')

@section('title', 'Applicant subject access requests')
@section('section', 'MODEL AGENCY / PRIVACY')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">PRIVACY OPERATIONS</p>
        <h1>Subject access<br><em>requests.</em></h1>
        <p>Owner-only workflow for applicant data searches, third-party review, secure response preparation and delivery records.</p>
    </div>
    <div class="admin-list-toolbar">
        <span>{{ $requests->total() }} access requests</span>
        <span>Restricted personal data · no-store</span>
    </div>
    <div class="enquiry-list">
        @forelse ($requests as $item)
            <article class="enquiry-card">
                <div class="enquiry-card-head">
                    <div>
                        <span class="eyebrow">{{ $item->application->reference }} · received {{ $item->created_at->format('d M Y') }}</span>
                        <h2>{{ $item->application->full_name }}</h2>
                        <p>{{ $item->application->email }}</p>
                    </div>
                    <span class="status-pill {{ $item->response_status === 'fulfilled' ? 'status-active' : 'status-inactive' }}">{{ str_replace('_', ' ', $item->response_status) }}</span>
                </div>
                <p class="enquiry-destination">
                    Due {{ $item->response_due_at?->format('d M Y') ?? 'Not set' }}
                    @if ($item->response_due_at && $item->response_due_at->isPast() && ! in_array($item->response_status, ['fulfilled', 'partially_fulfilled', 'refused'], true))
                        · OVERDUE
                    @endif
                    · {{ $item->status }}
                </p>
                <a class="button button-dark" href="{{ route('admin.model-applicant-sar.show', $item) }}">Review request ↗</a>
            </article>
        @empty
            <p class="empty-table">No verified applicant access requests are awaiting operator review.</p>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $requests->links() }}</div>
@endsection
