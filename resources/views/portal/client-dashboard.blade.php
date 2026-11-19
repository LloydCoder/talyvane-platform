@extends('portal.layout')
@section('title', 'Your account')
@section('content')
    <p class="eyebrow">CLIENT PORTAL</p><h1>Your<br><em>account.</em></h1><p class="admin-muted">Welcome, {{ auth()->user()->name }}. Your email address is verified.</p>
    <div class="portal-placeholder"><strong>Bookings and membership</strong><p>Your booking history, saved profiles, membership entitlements and support requests will appear here as those modules are released.</p></div>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-dark admin-submit" type="submit">Sign out ↗</button></form>
@endsection
