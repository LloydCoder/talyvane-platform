@extends('admin.layout')
@section('title', 'Booking requests')
@section('section', 'BOOKINGS')
@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">BOOKING OPERATIONS</p><h1>Booking<br><em>requests.</em></h1><p>Review requests, independently verify deposits and confirm only eligible bookings.</p></div>
    <div class="admin-list-toolbar"><span>{{ $bookings->total() }} booking requests</span><a class="text-link" href="{{ route('admin.bookings.settings') }}">Booking rules ↗</a></div>
    <div class="booking-admin-list">
        @forelse ($bookings as $booking)
            <article class="booking-admin-card">
                <div class="booking-admin-head"><div><span class="eyebrow">{{ $booking->reference }} / {{ strtoupper($booking->booking_mode) }}</span><h2>{{ $booking->profile->display_name }} · {{ $booking->destination->name }}</h2><p>{{ $booking->starts_at->timezone($booking->timezone)->format('D, d M Y · H:i') }} local · {{ $booking->duration_minutes }} minutes</p></div><span class="status-pill {{ $booking->status === 'confirmed' ? 'status-active' : 'status-inactive' }}">{{ str_replace('_', ' ', $booking->status) }}</span></div>
                <p class="booking-client">Client: {{ $booking->client->name }} · <a href="mailto:{{ $booking->client->email }}">{{ $booking->client->email }}</a></p>
                @if ($booking->client_note)<p class="booking-note">{{ $booking->client_note }}</p>@endif
                <div class="booking-admin-actions">
                    <div>
                        <strong>Deposit: {{ str_replace('_', ' ', $booking->deposit_status) }}</strong>
                        @if ($booking->deposit_status !== 'verified' && $settings->auto_requires_verified_deposit && !in_array($booking->status, ['cancelled','declined','completed'], true))
                            <details class="review-details"><summary>Record verified deposit</summary>
                                <form method="POST" action="{{ route('admin.bookings.deposit', $booking) }}" class="review-form">
                                    @csrf
                                    <label>Provider or transaction reference</label><input name="transaction_reference" minlength="8" maxlength="255" required autocomplete="off">
                                    <label class="remember-row"><input type="checkbox" name="verified" value="1" required> I independently verified this transaction on the relevant provider or blockchain explorer</label>
                                    <button class="button button-dark" type="submit">Record verification</button>
                                </form>
                            </details>
                        @elseif ($booking->deposit_verified_at)
                            <small>Verified {{ $booking->deposit_verified_at->format('d M Y H:i') }} by {{ $booking->depositVerifier?->name ?? 'former administrator' }}</small>
                        @endif
                    </div>
                    @if (!in_array($booking->status, ['cancelled','declined','completed'], true))
                        <form method="POST" action="{{ route('admin.bookings.update', $booking) }}" class="booking-status-form">
                            @csrf @method('PATCH')
                            <label for="booking-status-{{ $booking->id }}">Update status</label>
                            <select id="booking-status-{{ $booking->id }}" name="status">
                                @foreach (['confirmed','declined','cancelled','completed'] as $status)
                                    <option value="{{ $status }}" @selected($booking->status === $status)>{{ ucfirst($status) }}</option>
                                @endforeach
                            </select>
                            <button class="button button-dark" type="submit">Save ↗</button>
                        </form>
                    @endif
                </div>
            </article>
        @empty
            <p class="empty-table">No booking requests yet.</p>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $bookings->links() }}</div>
@endsection
