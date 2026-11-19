@extends('admin.layout')

@section('title', 'Globalization')
@section('section', 'GLOBALIZATION')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">GLOBAL PLATFORM CONFIGURATION</p>
        <h1>Markets &<br><em>currencies.</em></h1>
        <p>Configure country markets, deployment currency defaults, locale and time zone. A configured market is not automatically approved for operation.</p>
    </div>

    <form class="settings-form" method="POST" action="{{ route('admin.globalization.defaults.update') }}">
        @csrf
        @method('PUT')
        <div class="settings-section-title"><span>01</span><div><h2>Deployment defaults</h2><p>USD is only a starting recommendation. Choose any enabled registered ISO currency.</p></div></div>
        <label for="base_currency_code">Reporting / base currency</label>
        <select id="base_currency_code" name="base_currency_code">
            <option value="">Not configured</option>
            @foreach ($currencies->where('is_enabled', true) as $currency)
                <option value="{{ $currency->code }}" @selected(old('base_currency_code', $settings->base_currency_code) === $currency->code)>{{ $currency->code }} — {{ $currency->name }}</option>
            @endforeach
        </select>
        <label for="default_locale">Default locale</label>
        <input id="default_locale" name="default_locale" value="{{ old('default_locale', $settings->default_locale) }}" required maxlength="35" placeholder="en">
        <label for="default_timezone">Default time zone</label>
        <input id="default_timezone" name="default_timezone" value="{{ old('default_timezone', $settings->default_timezone) }}" required placeholder="UTC">
        <div class="settings-actions"><button class="button button-dark" type="submit">Save deployment defaults ↗</button></div>
    </form>

    <section class="settings-form">
        <div class="settings-section-title"><span>02</span><div><h2>Currency registry</h2><p>Currency codes must be verified against the current ISO 4217 dataset before use. Crypto assets are configured separately.</p></div></div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Currency</th><th>Code</th><th>Precision</th><th>Enabled</th></tr></thead>
                <tbody>
                @forelse ($currencies as $currency)
                    <tr><td><strong>{{ $currency->name }}</strong><small>{{ $currency->symbol }}</small></td><td>{{ $currency->code }}</td><td>{{ $currency->minor_unit }}</td><td>{{ $currency->is_enabled ? 'Yes' : 'No' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="empty-table">No currency reference records have been imported yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <form method="POST" action="{{ route('admin.globalization.currencies.store') }}">
            @csrf
            <div class="settings-section-title"><span>+</span><div><h2>Register a currency</h2><p>Registration stores metadata; it does not verify the code against an official ISO publication.</p></div></div>
            <label for="currency_code">ISO 4217 code</label><input id="currency_code" name="code" maxlength="3" minlength="3" pattern="[A-Z]{3}" required placeholder="USD">
            <label for="currency_name">Currency name</label><input id="currency_name" name="name" maxlength="100" required placeholder="US Dollar">
            <label for="currency_symbol">Symbol</label><input id="currency_symbol" name="symbol" maxlength="16" placeholder="$">
            <label for="currency_numeric">Numeric code</label><input id="currency_numeric" name="numeric_code" maxlength="3" minlength="3" pattern="[0-9]{3}" placeholder="840">
            <label for="currency_precision">Minor-unit precision</label><select id="currency_precision" name="minor_unit" required><option value="0">0 digits</option><option value="2" selected>2 digits</option><option value="3">3 digits</option></select>
            <label class="remember-row"><input type="checkbox" name="is_enabled" value="1"> Enable for display and pricing</label>
            <div class="settings-actions"><button class="button button-dark" type="submit">Add currency ↗</button></div>
        </form>
    </section>

    <section class="settings-form">
        <div class="settings-section-title"><span>03</span><div><h2>Country and territory markets</h2><p>New markets always begin in pending compliance state. Do not treat technical configuration as legal approval.</p></div></div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead><tr><th>Market</th><th>Default currency</th><th>Status</th><th>Enabled</th></tr></thead>
                <tbody>
                @forelse ($markets as $market)
                    <tr><td><strong>{{ $market->name }}</strong><small>{{ $market->country_code }}{{ $market->alpha3_code ? ' / '.$market->alpha3_code : '' }}</small></td><td>{{ $market->default_currency_code ?? '—' }}</td><td>{{ str($market->compliance_status)->headline() }}</td><td>{{ $market->is_enabled ? 'Yes' : 'No' }}</td></tr>
                @empty
                    <tr><td colspan="4" class="empty-table">No country market records configured.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination-wrap">{{ $markets->links() }}</div>
        <form method="POST" action="{{ route('admin.globalization.markets.store') }}">
            @csrf
            <div class="settings-section-title"><span>+</span><div><h2>Add country / territory</h2><p>Use ISO 3166-1 codes and IANA time zones. This form does not grant legal authorization.</p></div></div>
            <label for="market_code">ISO alpha-2 code</label><input id="market_code" name="country_code" maxlength="2" minlength="2" pattern="[A-Z]{2}" required placeholder="US">
            <label for="market_alpha3">ISO alpha-3 code</label><input id="market_alpha3" name="alpha3_code" maxlength="3" minlength="3" pattern="[A-Z]{3}" placeholder="USA">
            <label for="market_name">Country / territory name</label><input id="market_name" name="name" maxlength="120" required placeholder="United States">
            <label for="market_currency">Default currency</label><select id="market_currency" name="default_currency_code"><option value="">Not set</option>@foreach ($currencies as $currency)<option value="{{ $currency->code }}">{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select>
            <label for="market_locale">Default locale</label><input id="market_locale" name="default_locale" maxlength="35" placeholder="en-US">
            <label for="market_timezone">Default time zone</label><input id="market_timezone" name="default_timezone" placeholder="America/New_York">
            <label class="remember-row"><input type="checkbox" name="is_enabled" value="1"> Enable market configuration</label>
            <div class="form-alert" role="note">New markets are recorded as pending compliance review. A separate authorized review and evidence workflow is required before any service is permitted.</div>
            <div class="settings-actions"><button class="button button-dark" type="submit">Add country market ↗</button></div>
        </form>
    </section>
@endsection
