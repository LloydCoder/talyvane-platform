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
        <a class="admin-card" href="{{ route('admin.profiles.index') }}"><span>03 / PROFILES</span><h2>Profile review</h2><p>Manage draft profiles and record publication verification and consent review.</p><b>Review ↗</b></a>
        <a class="admin-card" href="{{ route('admin.enquiries.index') }}"><span>04 / RELATIONSHIPS</span><h2>Enquiries</h2><p>Review incoming messages and manage the response pipeline.</p><b>Open inbox ↗</b></a>
        <a class="admin-card" href="{{ route('admin.bookings.index') }}"><span>05 / BOOKINGS</span><h2>Booking requests</h2><p>Review requests, verify deposits and confirm eligible bookings.</p><b>Open bookings ↗</b></a>
        <div class="admin-card admin-card-muted"><span>06 / OPERATIONS</span><h2>Bookings</h2><p>Booking workflows and operational controls are scheduled for the next build phase.</p><b>Planned</b></div>
        <div class="admin-card admin-card-muted"><span>07 / COMMERCE</span><h2>Payments</h2><p>Payment rails remain disabled until provider and jurisdiction reviews are complete.</p><b>Gated</b></div>
    </div>
@endsection
