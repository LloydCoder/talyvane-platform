@extends('portal.layout')
@section('title', 'Verify applicant privacy request')
@section('content')
    <p class="eyebrow">SECURE VERIFICATION</p>
    <h1>Verify your<br><em>request.</em></h1>
    @if (session('status'))<div class="form-alert" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <p class="admin-muted">Enter the application reference, the email address recorded on the application and the eight-digit code sent to that address. Codes expire after 10 minutes and are limited to five attempts.</p>
    <form method="POST" action="{{ route('model-agency.application-support.verify') }}">
        @csrf
        <label for="reference">Application reference</label><input id="reference" name="reference" required maxlength="32" autocomplete="off" value="{{ old('reference') }}">
        <label for="email">Application email address</label><input id="email" name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}">
        <label for="code">Eight-digit verification code</label><input id="code" name="code" inputmode="numeric" pattern="[0-9]{8}" minlength="8" maxlength="8" required autocomplete="one-time-code">
        <button class="button button-dark admin-submit" type="submit">Verify and continue ↗</button>
    </form>
@endsection
