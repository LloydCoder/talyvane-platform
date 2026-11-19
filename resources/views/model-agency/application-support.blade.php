@extends('portal.layout')
@section('title', 'Application privacy support')
@section('content')
    <p class="eyebrow">APPLICANT PRIVACY</p>
    <h1>Manage your<br><em>application.</em></h1>
    <p class="admin-muted">Request access, correction, withdrawal or erasure of a model-agency application. We will send a one-time code to the email address recorded on the application. If you no longer control that inbox or cannot receive the code, use the contact details in the model-applicant privacy notice.</p>
    @if (session('status'))<div class="form-alert" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ route('model-agency.application-support.submit') }}">
        @csrf
        <label for="reference">Application reference</label><input id="reference" name="reference" required maxlength="32" autocomplete="off" value="{{ old('reference') }}">
        <label for="email">Application email address</label><input id="email" name="email" type="email" required maxlength="254" autocomplete="email" value="{{ old('email') }}">
        <label for="request_type">What would you like to do?</label>
        <select id="request_type" name="request_type" required>
            <option value="access" @selected(old('request_type') === 'access')>Access my information</option>
            <option value="correction" @selected(old('request_type') === 'correction')>Correct my information</option>
            <option value="withdrawal" @selected(old('request_type') === 'withdrawal')>Withdraw my application</option>
            <option value="erasure" @selected(old('request_type') === 'erasure')>Request erasure</option>
        </select>
        <button class="button button-dark admin-submit" type="submit">Send verification code ↗</button>
    </form>
@endsection
