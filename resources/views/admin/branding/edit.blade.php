@extends('admin.layout')

@section('title', 'Brand & identity')
@section('section', 'BRAND & IDENTITY')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">DEPLOYMENT CONFIGURATION</p>
        <h1>Brand &<br><em>identity.</em></h1>
        <p>These settings control the public identity of this deployment. Changes are recorded against your administrator account.</p>
    </div>
    @if ($errors->any())
        <div class="form-alert" role="alert">{{ $errors->first() }}</div>
    @endif
    <form class="settings-form" method="POST" enctype="multipart/form-data" action="{{ route('admin.branding.update') }}">
        @csrf
        @method('PUT')
        <div class="settings-section-title"><span>01</span><div><h2>Brand identity</h2><p>How the business appears across its public experience.</p></div></div>
        <label for="brand_name">Brand name <span>Required</span></label>
        <input id="brand_name" name="brand_name" value="{{ old('brand_name', $brand['brand_name'] ?? '') }}" maxlength="80" required>
        <label for="tagline">Tagline</label>
        <input id="tagline" name="tagline" value="{{ old('tagline', $brand['tagline'] ?? '') }}" maxlength="160">
        <label for="logo">Brand logo</label>
        @if (!empty($brand['logo_path']))
            <div class="logo-preview"><img src="{{ \\Illuminate\\Support\\Facades\\Storage::disk('public')->url($brand['logo_path']) }}" alt="{{ $brand['brand_name'] ?? 'Current brand logo' }}"></div>
        @endif
        <input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp">
        <p class="field-help">PNG, JPEG or WebP. Maximum 2 MB and 2,000 × 2,000 pixels. This image is public brand material; never upload identity documents or private media here.</p>
        <div class="settings-section-title"><span>02</span><div><h2>Contact details</h2><p>Public contact information for this deployment.</p></div></div>
        <label for="contact_email">Contact email</label>
        <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $brand['contact_email'] ?? '') }}" maxlength="254">
        <label for="contact_phone">Contact phone</label>
        <input id="contact_phone" name="contact_phone" value="{{ old('contact_phone', $brand['contact_phone'] ?? '') }}" maxlength="40">
        <label for="contact_website">Website</label>
        <input id="contact_website" name="contact_website" type="url" value="{{ old('contact_website', $brand['contact_website'] ?? '') }}" maxlength="255" placeholder="https://example.com">
        <div class="settings-actions"><button class="button button-light" type="submit">Save brand settings <span aria-hidden="true">↗</span></button></div>
    </form>
@endsection
