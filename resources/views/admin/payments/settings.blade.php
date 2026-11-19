@extends('admin.layout')
@section('title', 'Payment configuration')
@section('section', 'PAYMENTS')
@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">PAYMENT CONTROL CENTRE</p><h1>Payment<br><em>settings.</em></h1><p>Enable only the methods and purposes approved for this deployment. All methods are disabled by default.</p></div>
    <form class="settings-form" method="POST" action="{{ route('admin.payment-settings.update') }}">
        @csrf @method('PUT')
        <div class="settings-section-title"><span>01</span><div><h2>Global controls</h2><p>One switch can disable all payment methods.</p></div></div>
        <label class="remember-row"><input type="checkbox" name="payments_enabled" value="1" @checked(old('payments_enabled', $settings->payments_enabled))> Enable payments globally</label>
        <div class="settings-section-title"><span>02</span><div><h2>Payment rails</h2><p>Independent switches for each method family.</p></div></div>
        <label class="remember-row"><input type="checkbox" name="crypto_enabled" value="1" @checked(old('crypto_enabled', $settings->crypto_enabled))> Cryptocurrency receiving methods</label>
        <label class="remember-row"><input type="checkbox" name="cards_enabled" value="1" @checked(old('cards_enabled', $settings->cards_enabled))> Approved card processor adapters</label>
        <label class="remember-row"><input type="checkbox" name="manual_enabled" value="1" @checked(old('manual_enabled', $settings->manual_enabled))> Manual payment instructions</label>
        <div class="settings-section-title"><span>03</span><div><h2>Payment purposes</h2><p>Booking and membership payment purposes can be enabled independently.</p></div></div>
        <label class="remember-row"><input type="checkbox" name="booking_payments_enabled" value="1" @checked(old('booking_payments_enabled', $settings->booking_payments_enabled))> Booking-related payments</label>
        <label class="remember-row"><input type="checkbox" name="membership_payments_enabled" value="1" @checked(old('membership_payments_enabled', $settings->membership_payments_enabled))> Membership and listing payments</label>
        <div class="form-alert" role="note">This is configuration only. No public checkout, blockchain transaction watcher, invoice settlement, refund engine or card processor is connected yet. Keep payments disabled until those adapters, underwriting and jurisdictional approvals are complete. Never enter wallet seed phrases or private keys.</div>
        <div class="settings-actions"><button class="button button-dark" type="submit">Save payment settings ↗</button></div>
    </form>
    <p><a class="text-link" href="{{ route('admin.payment-methods') }}">Configure crypto receiving addresses ↗</a></p>
@endsection
