@extends('admin.layout')
@section('title', 'Enquiry inbox')
@section('section', 'ENQUIRIES')
@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">CLIENT RELATIONSHIPS</p><h1>Enquiry<br><em>inbox.</em></h1><p>Review incoming messages and update their handling status. Access is restricted to administrators.</p></div>
    <div class="admin-list-toolbar"><span>{{ $enquiries->total() }} enquiries</span><a class="text-link" href="{{ route('contact') }}" target="_blank" rel="noopener noreferrer">View public contact form ↗</a></div>
    <div class="enquiry-list">
        @forelse ($enquiries as $enquiry)
            <article class="enquiry-card">
                <div class="enquiry-card-head"><div><span class="eyebrow">{{ $enquiry->created_at->format('d M Y · H:i') }}</span><h2>{{ $enquiry->subject }}</h2><p>{{ $enquiry->name }} · <a href="mailto:{{ $enquiry->email }}">{{ $enquiry->email }}</a>@if ($enquiry->phone) · {{ $enquiry->phone }}@endif</p></div><span class="status-pill {{ $enquiry->status === 'closed' ? 'status-inactive' : 'status-active' }}">{{ str_replace('_', ' ', $enquiry->status) }}</span></div>
                @if ($enquiry->destination)<p class="enquiry-destination">Destination: {{ $enquiry->destination->name }}</p>@endif
                <p class="enquiry-message">{{ $enquiry->message }}</p>
                <form method="POST" action="{{ route('admin.enquiries.update', $enquiry) }}" class="enquiry-status-form">
                    @csrf @method('PATCH')
                    <label for="status-{{ $enquiry->id }}">Status</label>
                    <select id="status-{{ $enquiry->id }}" name="status">@foreach (['new','in_progress','responded','closed','spam'] as $status)<option value="{{ $status }}" @selected($enquiry->status === $status)>{{ ucfirst(str_replace('_', ' ', $status)) }}</option>@endforeach</select>
                    <button class="button button-dark" type="submit">Update ↗</button>
                </form>
            </article>
        @empty
            <p class="empty-table">The enquiry inbox is empty.</p>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $enquiries->links() }}</div>
@endsection
