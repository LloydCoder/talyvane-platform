<form class="settings-form" method="POST" action="{{ $action }}">
    @csrf
    @if ($method === 'PUT') @method('PUT') @endif
    @if ($errors->any())
        <div class="form-alert" role="alert"><strong>Review the following fields:</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <div class="settings-section-title"><span>01</span><div><h2>Destination identity</h2><p>Use a clear, unique name and URL slug.</p></div></div>
    <label for="name">Display name</label><input id="name" name="name" required maxlength="120" value="{{ old('name', $destination?->name) }}">
    <label for="slug">URL slug</label><input id="slug" name="slug" required maxlength="140" pattern="[A-Za-z0-9_-]+" value="{{ old('slug', $destination?->slug) }}">
    <label for="city_name">City</label><input id="city_name" name="city_name" required maxlength="100" value="{{ old('city_name', $destination?->city_name) }}">
    <label for="country_name">Country</label><input id="country_name" name="country_name" required maxlength="100" value="{{ old('country_name', $destination?->country_name) }}">
    <label for="country_code">ISO country code</label><input id="country_code" name="country_code" required minlength="2" maxlength="2" value="{{ old('country_code', $destination?->country_code) }}">
    <div class="settings-section-title"><span>02</span><div><h2>Local configuration</h2><p>Used for formatting, calendars and market defaults.</p></div></div>
    <label for="currency_code">ISO currency code</label><input id="currency_code" name="currency_code" required minlength="3" maxlength="3" value="{{ old('currency_code', $destination?->currency_code) }}">
    <label for="locale">Locale</label><input id="locale" name="locale" required maxlength="10" value="{{ old('locale', $destination?->locale ?? 'en') }}">
    <label for="timezone">IANA timezone</label><input id="timezone" name="timezone" required maxlength="64" placeholder="Europe/London" value="{{ old('timezone', $destination?->timezone) }}">
    <label for="description">Public description</label><textarea id="description" name="description" rows="4" maxlength="2000">{{ old('description', $destination?->description) }}</textarea>
    <label for="sort_order">Display order</label><input id="sort_order" name="sort_order" type="number" min="0" max="65535" value="{{ old('sort_order', $destination?->sort_order ?? 0) }}">
    <label class="remember-row"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $destination?->is_active ?? false))> Publish this destination publicly</label>
    <div class="settings-actions"><button class="button button-dark" type="submit">{{ $destination ? 'Save destination' : 'Create destination' }} <span>↗</span></button></div>
</form>
