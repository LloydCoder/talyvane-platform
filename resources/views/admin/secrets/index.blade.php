@extends('admin.layout')

@section('title', 'Secret management')
@section('section', 'PLATFORM CONTROL')

@section('content')
    <div class="admin-page-heading compact">
        <p class="eyebrow">SUPER ADMIN / CONTROL PLANE</p>
        <h1>Deployment<br><em>secrets.</em></h1>
        <p>Set, rotate and permanently clear approved integration credentials in the dedicated Vault KV v2 store. Secret values are never stored in the application database and are never displayed after submission.</p>
        <p class="field-help">The Vault bootstrap token remains environment-managed. Configure a dedicated HTTPS Vault KV v2 mount and a least-privilege token before using these controls. Provider approval and integration implementation remain separate release gates.</p>
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

    @unless ($vaultConfigured)
        <div class="form-alert" role="alert">
            Secret storage is not configured. Set the dedicated SECRETS_VAULT_* environment variables and provision the required Vault KV v2 ACL before attempting secret operations.
        </div>
    @endunless

    <div class="admin-card-grid">
        @foreach ($secrets as $secret)
            <article class="admin-card">
                <span>{{ strtoupper($secret['group']) }}</span>
                <h2>{{ $secret['label'] }}</h2>
                <p>{{ $secret['description'] }}</p>
                <p><strong>Identifier:</strong> <code>{{ $secret['key'] }}</code></p>
                <p><strong>Status:</strong> {{ str_replace('_', ' ', ucfirst($secret['status'])) }}</p>
                @if ($secret['status'] === 'configured')
                    <p><strong>Vault version:</strong> {{ $secret['vault_version'] }}</p>
                    <p><strong>Last rotated:</strong> {{ $secret['last_rotated_at']?->toDayDateTimeString() ?? 'Not recorded' }}</p>
                @elseif ($secret['status'] === 'pending' || $secret['status'] === 'error')
                    <p class="field-help">The last operation did not reach a confirmed terminal state. Verify Vault state before relying on this credential.</p>
                @endif

                @if ($vaultConfigured)
                    <form class="settings-form" method="POST" action="{{ route('admin.secrets.store', $secret['key']) }}">
                        @csrf
                        <label for="secret-value-{{ str_replace('.', '-', $secret['key']) }}">New secret value</label>
                        <input id="secret-value-{{ str_replace('.', '-', $secret['key']) }}" name="secret_value" type="password" autocomplete="new-password" minlength="16" maxlength="4096" required>
                        <p class="field-help">The field is intentionally blank. Existing values cannot be retrieved through this interface.</p>

                        <label for="secret-reason-{{ str_replace('.', '-', $secret['key']) }}">Reason for setting or rotating</label>
                        <textarea id="secret-reason-{{ str_replace('.', '-', $secret['key']) }}" name="reason" minlength="20" maxlength="500" required placeholder="Explain the provider, credential purpose and rotation reason."></textarea>

                        <label for="secret-password-{{ str_replace('.', '-', $secret['key']) }}">Current Super Admin password</label>
                        <input id="secret-password-{{ str_replace('.', '-', $secret['key']) }}" name="password" type="password" autocomplete="current-password" maxlength="200" required>

                        <label for="secret-code-{{ str_replace('.', '-', $secret['key']) }}">Fresh authenticator code</label>
                        <input id="secret-code-{{ str_replace('.', '-', $secret['key']) }}" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>

                        <div class="settings-actions">
                            <button class="button button-light" type="submit">{{ $secret['status'] === 'configured' ? 'Rotate secret' : 'Store secret' }} <span aria-hidden="true">↗</span></button>
                        </div>
                    </form>

                    @if ($secret['status'] === 'configured')
                        <form class="settings-form" method="POST" action="{{ route('admin.secrets.destroy', $secret['key']) }}">
                            @csrf
                            @method('DELETE')
                            <label for="clear-reason-{{ str_replace('.', '-', $secret['key']) }}">Reason for permanent removal</label>
                            <textarea id="clear-reason-{{ str_replace('.', '-', $secret['key']) }}" name="reason" minlength="20" maxlength="500" required placeholder="Explain why this credential must be permanently removed."></textarea>

                            <label for="clear-password-{{ str_replace('.', '-', $secret['key']) }}">Current Super Admin password</label>
                            <input id="clear-password-{{ str_replace('.', '-', $secret['key']) }}" name="password" type="password" autocomplete="current-password" maxlength="200" required>

                            <label for="clear-code-{{ str_replace('.', '-', $secret['key']) }}">Fresh authenticator code</label>
                            <input id="clear-code-{{ str_replace('.', '-', $secret['key']) }}" name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required>

                            <div class="settings-actions">
                                <button class="button button-light" type="submit">Permanently clear all versions</button>
                            </div>
                        </form>
                    @endif
                @else
                    <p class="field-help">Set and rotation controls are disabled until secure external secret storage is configured.</p>
                @endif
            </article>
        @endforeach
    </div>
@endsection
