@extends('portal.layout')
@section('title', 'Create account')
@section('content')
    <p class="eyebrow">A PRIVATE ACCOUNT</p><h1>Create your<br><em>account.</em></h1><p class="admin-muted">Create a client account. Companion accounts are issued separately after onboarding.</p>
    @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('register.store') }}">
        @csrf
        <label for="name">Full name</label><input id="name" name="name" autocomplete="name" required maxlength="100" value="{{ old('name') }}">
        <label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="254" value="{{ old('email') }}">
        <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="14" maxlength="200" required>
        <label for="password_confirmation">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="14" maxlength="200" required>
        <label class="remember-row"><input type="checkbox" name="terms_accepted" value="1" required> I agree to the <a href="{{ route('terms') }}" target="_blank" rel="noopener noreferrer">terms</a> and <a href="{{ route('privacy') }}" target="_blank" rel="noopener noreferrer">privacy notice</a>.</label>
        <button class="button button-dark admin-submit" type="submit">Create account ↗</button>
    </form>
    <a class="admin-back" href="{{ route('login') }}">Already registered? Sign in</a>
@endsection
