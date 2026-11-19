@extends('portal.layout')
@section('title', 'Companion workspace')
@section('content')
    <p class="eyebrow">COMPANION PORTAL</p><h1>Your<br><em>workspace.</em></h1><p class="admin-muted">Welcome, {{ auth()->user()->name }}. Your email address is verified.</p>
    <div class="portal-placeholder"><strong>Profile and safety</strong><p>Profile editing, availability, booking requests, consent controls and safety preferences will be available here after onboarding and verification.</p></div>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="button button-dark admin-submit" type="submit">Sign out ↗</button></form>
@endsection
