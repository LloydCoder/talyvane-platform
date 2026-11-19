@extends('portal.layout')
@section('title', 'Your application information')
@section('content')
    <p class="eyebrow">VERIFIED APPLICANT ACCESS</p>
    <h1>Your application<br><em>information.</em></h1>
    <p class="admin-muted">This private session expires {{ $rightsRequest->expires_at->format('d M Y · H:i') }}. Close the browser when you finish.</p>
    <dl class="application-data-list">
        <dt>Reference</dt><dd>{{ $rightsRequest->application->reference }}</dd>
        <dt>Name</dt><dd>{{ $rightsRequest->application->full_name }}</dd>
        <dt>Email</dt><dd>{{ $rightsRequest->application->email }}</dd>
        <dt>Phone</dt><dd>{{ $rightsRequest->application->phone ?: 'Not supplied' }}</dd>
        <dt>Location</dt><dd>{{ $rightsRequest->application->city }}, {{ $rightsRequest->application->country_code }}</dd>
        <dt>Portfolio</dt><dd>@if($rightsRequest->application->portfolio_url)<a href="{{ $rightsRequest->application->portfolio_url }}" target="_blank" rel="noopener noreferrer nofollow">Open portfolio ↗</a>@else Not supplied @endif</dd>
        <dt>Experience</dt><dd>{{ $rightsRequest->application->experience_summary }}</dd>
        <dt>Status</dt><dd>{{ $rightsRequest->application->status }}</dd>
        <dt>Submitted</dt><dd>{{ $rightsRequest->application->submitted_at->format('d M Y · H:i') }}</dd>
        <dt>Privacy notice version</dt><dd>{{ $rightsRequest->application->privacy_notice_version }}</dd>
        <dt>Adult eligibility policy version</dt><dd>{{ $rightsRequest->application->adult_eligibility_policy_version }}</dd>
    </dl>

    @if($rightsRequest->request_type === 'access')
        <p class="admin-muted">Your formal access request has been recorded for operator review. Current statutory response due date: {{ $rightsRequest->response_due_at?->format('d M Y') ?? 'Not available' }}. This initial self-service view shows the application fields and current status; it is not the complete response. The agency will review platform and other relevant records, consider third-party rights and deliver the reviewed response through a secure channel.</p>
    @endif

    @if($rightsRequest->request_type === 'correction')
        <section class="portal-form-section">
            <h2>Correct your information</h2>
            <p class="admin-muted">The application email address cannot be changed through this form. Contact the agency through the privacy notice if your email address is no longer accessible.</p>
            <form method="POST" action="{{ route('model-agency.application-support.correct', $rightsRequest) }}">
                @csrf @method('PATCH')
                <label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="120" value="{{ old('full_name', $rightsRequest->application->full_name) }}">
                <label for="phone">Phone</label><input id="phone" name="phone" maxlength="32" value="{{ old('phone', $rightsRequest->application->phone) }}">
                <label for="city">City</label><input id="city" name="city" required maxlength="100" value="{{ old('city', $rightsRequest->application->city) }}">
                <label for="portfolio_url">Portfolio URL (HTTPS only)</label><input id="portfolio_url" name="portfolio_url" type="url" maxlength="2048" value="{{ old('portfolio_url', $rightsRequest->application->portfolio_url) }}">
                <label for="experience_summary">Experience summary</label><textarea id="experience_summary" name="experience_summary" rows="5" required minlength="20" maxlength="4000">{{ old('experience_summary', $rightsRequest->application->experience_summary) }}</textarea>
                <button class="button button-dark" type="submit">Save corrections ↗</button>
            </form>
        </section>
    @elseif($rightsRequest->request_type === 'withdrawal')
        <form method="POST" action="{{ route('model-agency.application-support.withdraw', $rightsRequest) }}">
            @csrf
            <p class="admin-muted">Withdrawing closes the application. It does not automatically erase information that must be retained under an applicable legal obligation or documented legal hold.</p>
            <button class="button button-dark" type="submit">Withdraw application ↗</button>
        </form>
    @elseif($rightsRequest->request_type === 'erasure')
        <form method="POST" action="{{ route('model-agency.application-support.erase', $rightsRequest) }}">
            @csrf
            <p class="admin-muted">Erasure removes identifying application information and free-text audit reasons. A documented legal hold may prevent immediate erasure.</p>
            <button class="button button-dark" type="submit">Confirm erasure request ↗</button>
        </form>
    @endif

    <form method="POST" action="{{ route('model-agency.application-support.finish', $rightsRequest) }}">
        @csrf
        <button class="text-link" type="submit">Finish and close private session</button>
    </form>
@endsection
