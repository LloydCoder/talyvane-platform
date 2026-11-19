@extends('admin.layout')

@section('title', 'Feature registry')
@section('section', 'PLATFORM CONTROL')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">SUPER ADMIN / CONTROL PLANE</p>
        <h1>Feature<br><em>registry.</em></h1>
        <p>Deployment feature inventory, implementation readiness, dependencies and activation prerequisites. This release is an inspect-only registry; feature changes remain disabled until the audited control workflow is delivered.</p>
        <p class="field-help">Registry availability reflects implementation status, configured state and declared dependencies only. It does not certify jurisdictional approval, age assurance, consent, payment-provider approval or any other business prerequisite; those controls remain independently enforced.</p>
    </div>

    <div class="admin-card-grid">
        @foreach ($features as $feature)
            <article class="admin-card">
                <span>{{ strtoupper($feature->module) }} / {{ strtoupper($feature->risk_level) }} RISK</span>
                <h2>{{ $feature->name }}</h2>
                <p>{{ $feature->description }}</p>
                <p><strong>Key:</strong> <code>{{ $feature->key }}</code></p>
                <p><strong>Implementation:</strong> {{ ucfirst($feature->implementation_status) }}</p>
                <p><strong>Configured:</strong> {{ $feature->is_enabled ? 'Enabled' : 'Disabled' }}</p>
                <p><strong>Registry gate:</strong> {{ $feature->effective_enabled ? 'Pass' : 'Blocked' }}</p>
                @if (!empty($feature->dependencies))
                    <p><strong>Dependencies:</strong> {{ implode(', ', $feature->dependencies) }}</p>
                @endif
                @if (!empty($feature->activation_requirements))
                    <p><strong>Activation prerequisites:</strong> {{ implode(', ', $feature->activation_requirements) }}</p>
                @endif
            </article>
        @endforeach
    </div>
@endsection
