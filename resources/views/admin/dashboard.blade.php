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
        <a class="admin-card" href="{{ route('admin.bookings.settings') }}"><span>06 / OPERATIONS</span><h2>Booking rules</h2><p>Configure manual review, automatic eligibility, availability and deposit gates.</p><b>Configure ↗</b></a>
        <a class="admin-card" href="{{ route('admin.payment-settings') }}"><span>07 / COMMERCE</span><h2>Payment settings</h2><p>Configure independent payment switches and permitted purposes.</p><b>Configure ↗</b></a>
        <a class="admin-card" href="{{ route('admin.payment-methods') }}"><span>08 / CRYPTO</span><h2>Receiving addresses</h2><p>Manage BTC, USDT and USDC addresses by network. Settlement remains gated.</p><b>Configure ↗</b></a>
    </div>
@endsection
