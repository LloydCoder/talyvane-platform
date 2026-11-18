@extends('admin.layout')

@section('title', 'Platform overview')
@section('section', 'OVERVIEW')

@section('content')
    <div class="admin-page-heading">
        <p class="eyebrow">YOUR WORKSPACE</p>
        <h1>Platform<br><em>overview.</em></h1>
        <p>Manage the identity and operating foundation of this deployment.</p>
    </div>
    <div class="admin-card-grid">
        <a class="admin-card" href="{{ route('admin.branding.edit') }}"><span>01 / IDENTITY</span><h2>Brand & identity</h2><p>Update the name, contact details and public-facing brand settings.</p><b>Configure ↗</b></a>
        <a class="admin-card" href="{{ route('admin.destinations.index') }}"><span>02 / DISCOVERY</span><h2>Destinations</h2><p>Manage enabled markets, cities, currencies and local settings.</p><b>Manage ↗</b></a>
        <div class="admin-card admin-card-muted"><span>03 / OPERATIONS</span><h2>Bookings</h2><p>Booking workflows and operational controls are scheduled for the next build phase.</p><b>Planned</b></div>
        <div class="admin-card admin-card-muted"><span>04 / COMMERCE</span><h2>Payments</h2><p>Payment rails remain disabled until provider and jurisdiction reviews are complete.</p><b>Gated</b></div>
    </div>
@endsection
