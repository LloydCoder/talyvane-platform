@extends('admin.layout')

@section('title', 'Create draft profile')
@section('section', 'PROFILES / NEW')

@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">PROFILE GOVERNANCE</p><h1>Create a<br><em>draft profile.</em></h1><p>Creating a profile never publishes it. Publication requires separate age-assurance and consent review.</p></div>
    <form class="settings-form" method="POST" action="{{ route('admin.profiles.store') }}">
        @csrf
        @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="settings-section-title"><span>01</span><div><h2>Profile details</h2><p>Only non-sensitive public profile information belongs here.</p></div></div>
        <label for="destination_id">Active destination</label><select id="destination_id" name="destination_id" required><option value="">Select destination</option>@foreach ($destinations as $destination)<option value="{{ $destination->id }}" @selected(old('destination_id') == $destination->id)>{{ $destination->name }}</option>@endforeach</select>
        <label for="display_name">Display name</label><input id="display_name" name="display_name" maxlength="80" required value="{{ old('display_name') }}">
        <label for="slug">Public URL slug</label><input id="slug" name="slug" maxlength="120" pattern="[A-Za-z0-9_-]+" required value="{{ old('slug') }}">
        <label for="summary">Public introduction</label><textarea id="summary" name="summary" maxlength="500" rows="4">{{ old('summary') }}</textarea>
        <label for="languages">Languages (comma-separated)</label><input id="languages" name="languages_text" value="{{ old('languages_text') }}" placeholder="English, Spanish">
        <p class="field-help">Do not enter legal names, identity numbers, home addresses, private contact details or verification evidence in public profile fields.</p>
        <div class="settings-actions"><button class="button button-dark" type="submit">Save draft profile ↗</button></div>
    </form>
@endsection
