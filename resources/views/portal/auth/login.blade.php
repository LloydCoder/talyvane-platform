@extends('portal.layout')
@section('title', 'Sign in')
@section('content')
    <p class="eyebrow">WELCOME BACK</p><h1>Sign<br><em>in.</em></h1><p class="admin-muted">Access your client or companion account.</p>
    @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('login.store') }}">
        @csrf
        <label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" required maxlength="254" value="{{ old('email') }}">
        <label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="200">
        <label class="remember-row"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button class="button button-dark admin-submit" type="submit">Sign in ↗</button>
    </form>
    <a class="admin-back" href="{{ route('register') }}">Create a client account</a>
@endsection
