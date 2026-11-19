@extends('portal.layout')
@section('title', 'Your account')
@section('content')
    <p class="eyebrow">CLIENT PORTAL</p><h1>Your<br><em>account.</em></h1><p class="admin-muted">Welcome, {{ auth()->user()->name }}. Your email address is verified.</p>
    @if (session('status'))<div class="form-success" role="status">{{ session('status') }}</div>@endif
    <div class="portal-section-heading"><h2>Booking requests</h2><a href="{{ route('companions.index') }}">Discover profiles ↗</a></div>
    <div class="portal-booking-list">
        @forelse ($bookings as $booking)
            <article class="portal-booking-card">
                <div><span class="eyebrow">{{ $booking->reference }} / {{ strtoupper(str_replace('_', ' ', $booking->status)) }}</span><h3>{{ $booking->profile->display_name }}</h3><p>{{ $booking->destination->name }} · {{ $booking->starts_at->timezone($booking->timezone)->format('D, d M Y · H:i') }} local</p></div>
                <span class="status-pill {{ $booking->status === 'confirmed' ? 'status-active' : 'status-inactive' }}">{{ str_replace('_', ' ', $booking->status) }}</span>
            </article>
        @empty
            <div class="portal-placeholder"><strong>No booking requests yet</strong><p>When you request a booking, its reference and status will appear here.</p></div>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $bookings->links() }}</div>
    @include('portal.account-security')
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-dark admin-submit" type="submit">Sign out ↗</button></form>
@endsection
