@extends('portal.layout')
@section('title', 'Model representation application')
@section('content')
    <p class="eyebrow">TALENT REPRESENTATION</p>
    <h1>Model representation<br><em>application.</em></h1>
    <p class="admin-muted">Apply to be considered for independent model representation, editorial and commercial casting. This application is separate from companionship services and does not create an account or representation relationship.</p>
    <p class="admin-muted">Applicants must be adults aged 18 or over. Submitting this form is a self-attestation only; it is not identity or age verification and does not guarantee acceptance, representation or work.</p>
    @if ($errors->any())
        <div class="form-alert" role="alert"><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    <form method="POST" action="{{ route('model-agency.apply.store') }}">
        @csrf
        <div style="position:absolute;left:-10000px" aria-hidden="true"><label for="website">Website</label><input id="website" name="website" tabindex="-1" autocomplete="off"></div>
        <label for="full_name">Full name</label><input id="full_name" name="full_name" autocomplete="name" required maxlength="120" value="{{ old('full_name') }}">
        <label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required maxlength="254" value="{{ old('email') }}">
        <label for="phone">Telephone (optional)</label><input id="phone" name="phone" type="tel" autocomplete="tel" maxlength="32" value="{{ old('phone') }}">
        <label for="country_code">Country code (ISO 3166-1 alpha-2)</label><input id="country_code" name="country_code" required minlength="2" maxlength="2" pattern="[A-Z]{2}" autocomplete="country" value="{{ old('country_code') }}">
        <label for="city">City</label><input id="city" name="city" required maxlength="100" autocomplete="address-level2" value="{{ old('city') }}">
        <label for="portfolio_url">Portfolio URL (HTTPS only, optional)</label><input id="portfolio_url" name="portfolio_url" type="url" inputmode="url" maxlength="2048" placeholder="https://…" value="{{ old('portfolio_url') }}">
        <label for="experience_summary">Experience and areas of interest</label><textarea id="experience_summary" name="experience_summary" rows="6" minlength="20" maxlength="4000" required>{{ old('experience_summary') }}</textarea>
        <input type="hidden" name="privacy_notice_version" value="{{ $privacyNoticeVersion }}">
        <input type="hidden" name="adult_eligibility_policy_version" value="{{ config('talyvane.model_agency.adult_eligibility_policy_version') }}">
        <label class="remember-row"><input type="checkbox" name="adult_eligibility_confirmed" value="1" required @checked(old('adult_eligibility_confirmed'))> I confirm that I am at least 18 years old.</label>
        <label class="remember-row"><input type="checkbox" name="adult_eligibility_policy_accepted" value="1" required @checked(old('adult_eligibility_policy_accepted'))> I have read and accept the <a href="{{ route('pages.show', 'model-adult-eligibility') }}" target="_blank" rel="noopener noreferrer">adult eligibility policy</a>.</label>
        <label class="remember-row"><input type="checkbox" name="privacy_notice_accepted" value="1" required @checked(old('privacy_notice_accepted'))> I have read and accept the <a href="{{ route('pages.show', 'model-applicant-privacy') }}" target="_blank" rel="noopener noreferrer">model-applicant privacy notice</a>.</label>
        <button class="button button-dark admin-submit" type="submit">Submit application ↗</button>
    </form>
@endsection
