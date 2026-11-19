@extends('portal.layout')
@section('title', 'Verify email')
@section('content')
    <p class="eyebrow">ONE MORE STEP</p><h1>Verify your<br><em>email.</em></h1><p class="admin-muted">We sent a verification link to {{ auth()->user()->email }}. Verify your email before using account features.</p>
    @if (session('status'))<div class="form-success" role="status">{{ session('status') }}</div>@endif
    <form method="POST" action="{{ route('verification.send') }}">@csrf<button class="button button-dark admin-submit" type="submit">Resend verification link ↗</button></form>
    <form method="POST" action="{{ route('logout') }}">@csrf<button class="admin-back" type="submit">Sign out</button></form>
@endsection
