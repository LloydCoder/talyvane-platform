@extends('admin.layout')

@section('title', 'Feature registry')
@section('section', 'PLATFORM CONTROL')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">SUPER ADMIN / CONTROL PLANE</p>
        <h1>Feature<br><em>registry.</em></h1>
        <p>Control optional deployment modules with explicit impact review, a recorded reason, current-password confirmation and a fresh single-use authenticator code.</p>
        <p class="field-help">The registry never overrides jurisdictional approval, age assurance, consent, safeguarding, payment integrity or other hard-coded policy gates. A configured feature may still be unavailable at runtime when those independent checks fail.</p>
    </div>

    @if (session('status'))
        <div class="form-alert" role="status">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="form-alert" role="alert">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

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

                <p><strong>Impact preview:</strong></p>
                <ul>
                    @foreach ($feature->impact_summary as $impact)
                        <li>{{ $impact }}</li>
                    @endforeach
                </ul>

                @if (!$feature->is_enabled && $feature->activation_blockers !== [])
                    <p><strong>Activation blockers:</strong></p>
                    <ul>
                        @foreach ($feature->activation_blockers as $blocker)
                            <li>{{ $blocker }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($feature->implementation_status === 'available')
                    <form class="settings-form" method="POST" action="{{ route('admin.features.update', $feature->key) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="enabled" value="{{ $feature->is_enabled ? '0' : '1' }}">

                        <label for="reason-{{ str_replace('.', '-', $feature->key) }}">Reason for this change</label>
                        <textarea id="reason-{{ str_replace('.', '-', $feature->key) }}" name="reason" minlength="20" maxlength="500" required placeholder="Explain the operational reason and expected impact."></textarea>

                        <label for="password-{{ str_replace('.', '-', $feature->key) }}">Current Super Admin password</label>
                        <input id="password-{{ str_replace('.', '-', $feature->key) }}" name="password" type="password" autocomplete="current-password" maxlength="200" required>

                        <label for="code-{{ str_replace('.', '-', $feature->key) }}">Fresh authenticator code</label>
                        <input id="code-{{ str_replace('.', '-', $feature->key) }}" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>

                        <div class="settings-actions">
                            <button class="button button-light" type="submit" @disabled(!$feature->is_enabled && $feature->activation_blockers !== [])>
                                {{ $feature->is_enabled ? 'Disable feature' : 'Enable feature' }}
                                <span aria-hidden="true">↗</span>
                            </button>
                        </div>
                    </form>
                @else
                    <p class="field-help">Controls are unavailable because this feature has not reached an available implementation state.</p>
                @endif
            </article>
        @endforeach
    </div>
@endsection
