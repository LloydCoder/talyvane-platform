@extends('admin.layout')
@section('title', 'Booking settings')
@section('section', 'BOOKINGS')
@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">BOOKING OPERATIONS</p><h1>Booking<br><em>rules.</em></h1><p>Choose manual review or automatic processing. Automatic confirmation remains gated by companion opt-in, availability and deposit requirements.</p></div>
    <form class="settings-form" method="POST" action="{{ route('admin.bookings.settings.update') }}">
        @csrf @method('PUT')
        @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="settings-section-title"><span>01</span><div><h2>Booking mode</h2><p>Applies to new booking requests in this deployment.</p></div></div>
        <label for="booking_mode">Workflow</label><select id="booking_mode" name="booking_mode"><option value="manual" @selected(old('booking_mode', $settings->booking_mode) === 'manual')>Manual review</option><option value="automatic" @selected(old('booking_mode', $settings->booking_mode) === 'automatic')>Automatic where eligible</option></select>
        <label class="remember-row"><input type="checkbox" name="auto_confirm_enabled" value="1" @checked(old('auto_confirm_enabled', $settings->auto_confirm_enabled))> Enable automatic confirmation where all safeguards pass</label>
        <label class="remember-row"><input type="checkbox" name="auto_requires_verified_deposit" value="1" @checked(old('auto_requires_verified_deposit', $settings->auto_requires_verified_deposit))> Require a verified deposit before automatic confirmation</label>
        <div class="settings-section-title"><span>02</span><div><h2>Scheduling limits</h2><p>Applied in the destination's local timezone.</p></div></div>
        <label for="minimum_notice_hours">Minimum booking notice (hours)</label><input id="minimum_notice_hours" type="number" name="minimum_notice_hours" min="0" max="720" required value="{{ old('minimum_notice_hours', $settings->minimum_notice_hours) }}">
        <label for="maximum_advance_days">Maximum advance booking window (days)</label><input id="maximum_advance_days" type="number" name="maximum_advance_days" min="1" max="365" required value="{{ old('maximum_advance_days', $settings->maximum_advance_days) }}">
        <div class="form-alert" role="note">Automatic confirmation is fail-closed. A profile must be published, age-verified, have current publication consent and a separate recorded automatic-booking opt-in. Overlapping confirmed bookings are rejected. A verified deposit is required while that setting is enabled.</div>
        <div class="settings-actions"><button class="button button-dark" type="submit">Save booking rules ↗</button></div>
    </form>
@endsection
