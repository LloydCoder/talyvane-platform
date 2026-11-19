@extends('admin.layout')
@section('title', 'Crypto receiving methods')
@section('section', 'PAYMENTS')
@section('content')
    <div class="admin-page-heading compact"><p class="eyebrow">DIRECT-TO-WALLET CONFIGURATION</p><h1>Receiving<br><em>addresses.</em></h1><p>Configure BTC, USDT and USDC addresses per network. New methods are always disabled and require a recorded configuration review.</p></div>
    @if ($errors->any())<div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <div class="payment-method-list">
        @forelse ($methods as $method)
            <article class="payment-method-card">
                <div class="booking-admin-head"><div><span class="eyebrow">{{ $method->asset }} / {{ $method->network }}</span><h2>{{ $method->asset }} receiving address</h2><p class="payment-address">{{ $method->address }}</p>@if ($method->token_contract)<p>Token contract: {{ $method->token_contract }}</p>@endif<p>{{ $method->confirmations_required }} confirmations · {{ $method->purpose_booking ? 'Bookings ' : '' }}{{ $method->purpose_membership ? 'Membership' : '' }}</p></div><span class="status-pill {{ $method->enabled ? 'status-active' : 'status-inactive' }}">{{ $method->enabled ? 'enabled' : 'disabled' }}</span></div>
                <p class="field-help">Configuration review: {{ $method->verification_status }} @if ($method->address_reviewed_at) · {{ $method->address_reviewed_at->format('d M Y H:i') }} by {{ $method->verifier?->name ?? 'former administrator' }} @endif</p>
                @if ($method->verification_status !== 'reviewed')
                    <details class="review-details"><summary>Record address configuration review</summary>
                        <form method="POST" action="{{ route('admin.payment-methods.review', $method) }}" class="review-form">
                            @csrf
                            <label>Reference used for your independent review</label><input name="verification_reference" minlength="8" maxlength="255" required autocomplete="off">
                            <label class="remember-row"><input type="checkbox" name="reviewed" value="1" required> I independently checked the network, asset, address and token contract against trusted sources</label>
                            <button class="button button-dark" type="submit">Record review</button>
                        </form>
                    </details>
                @else
                    <form method="POST" action="{{ route('admin.payment-methods.enabled', $method) }}" class="payment-toggle-form">
                        @csrf @method('PATCH')
                        <input type="hidden" name="enabled" value="{{ $method->enabled ? '0' : '1' }}">
                        <button class="text-link" type="submit">{{ $method->enabled ? 'Disable method' : 'Enable method' }}</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="empty-table">No receiving addresses configured.</p>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $methods->links() }}</div>
    <form class="settings-form" method="POST" action="{{ route('admin.payment-methods.store') }}">
        @csrf
        <div class="settings-section-title"><span>01</span><div><h2>Add receiving address</h2><p>Network names are deployment-defined; confirm the exact chain before adding an address.</p></div></div>
        <label for="asset">Asset</label><select id="asset" name="asset" required><option value="BTC">Bitcoin (BTC)</option><option value="USDT">Tether (USDT)</option><option value="USDC">USD Coin (USDC)</option></select>
        <label for="network">Network / chain</label><input id="network" name="network" required maxlength="40" placeholder="Exact network name">
        <label for="address">Public receiving address</label><input id="address" name="address" required maxlength="255" autocomplete="off">
        <label for="token_contract">Token contract (required for USDT / USDC)</label><input id="token_contract" name="token_contract" maxlength="255" autocomplete="off">
        <label for="confirmations_required">Required confirmations</label><input id="confirmations_required" name="confirmations_required" type="number" min="1" max="100" value="3" required>
        <label class="remember-row"><input type="checkbox" name="purpose_booking" value="1"> Allow booking-related payments</label>
        <label class="remember-row"><input type="checkbox" name="purpose_membership" value="1"> Allow membership/listing payments</label>
        <div class="form-alert" role="note">Only public receiving addresses belong here. Never paste private keys, seed phrases or wallet recovery data. A reviewed address is not proof of payment receipt; server-side transaction verification is still required.</div>
        <div class="settings-actions"><button class="button button-dark" type="submit">Save disabled address ↗</button></div>
    </form>
@endsection
