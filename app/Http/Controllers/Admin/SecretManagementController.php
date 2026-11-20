<?php

namespace App\Http\Controllers\Admin;

use App\Models\PlatformSecret;
use App\Models\PlatformSecretAuditEvent;
use App\Models\User;
use App\Services\SecretCatalog;
use App\Services\TotpService;
use App\Services\VaultKv2SecretStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class SecretManagementController
{
    public function index(VaultKv2SecretStore $vault): View
    {
        $records = PlatformSecret::query()->get()->keyBy('secret_key');
        $secrets = collect(SecretCatalog::all())->map(function (array $definition, string $key) use ($records): array {
            $record = $records->get($key);

            return [
                'key' => $key,
                'label' => $definition['label'],
                'group' => $definition['group'],
                'description' => $definition['description'],
                'status' => $record?->status ?? 'not_configured',
                'vault_version' => (int) ($record?->vault_version ?? 0),
                'last_rotated_at' => $record?->last_rotated_at,
                'configured_by' => $record?->configured_by,
            ];
        })->values();

        return view('admin.secrets.index', [
            'secrets' => $secrets,
            'vaultConfigured' => $vault->isConfigured(),
        ]);
    }

    public function store(Request $request, string $secretKey, VaultKv2SecretStore $vault, TotpService $totp): RedirectResponse
    {
        $definition = SecretCatalog::find($secretKey);
        abort_unless($vault->isConfigured(), 503, 'Secret storage is not configured.');

        $validated = $request->validate([
            'secret_value' => ['required', 'string', 'min:16', 'max:4096', 'not_regex:/[\r\n]/'],
            'reason' => ['required', 'string', 'min:20', 'max:500'],
            'password' => ['required', 'string', 'max:200'],
            'code' => ['required', 'digits:6'],
        ]);

        if (mb_strlen(trim($validated['reason'])) < 20) {
            throw ValidationException::withMessages([
                'reason' => 'Provide at least 20 non-whitespace characters explaining the operational reason.',
            ]);
        }

        $actorId = $this->consumeStepUp($request, $validated['password'], $validated['code'], $totp);
        $vaultPath = trim((string) config('services.secrets_vault.prefix', 'talyvane'), '/').'/'.$definition['vault_path'];

        $record = PlatformSecret::query()->firstOrNew(['secret_key' => $secretKey]);
        $wasConfigured = $record->exists && $record->status === 'configured';

        $record->forceFill([
            'vault_path' => $vaultPath,
            'status' => 'pending',
            'configured_by' => $actorId,
        ])->save();

        $this->appendAudit($secretKey, $actorId, 'secret.write_requested', null, $validated['reason']);

        try {
            $expectedVersion = $vault->currentVersion($secretKey);
            $version = $vault->write($secretKey, $validated['secret_value'], $expectedVersion);
        } catch (Throwable) {
            DB::transaction(function () use ($secretKey, $actorId, $validated): void {
                PlatformSecret::query()->where('secret_key', $secretKey)->lockForUpdate()->update(['status' => 'error']);
                $this->appendAudit($secretKey, $actorId, 'secret.write_failed', null, $validated['reason']);
            });

            return back()->withErrors([
                'secret_value' => 'The secret could not be stored. No secret value was retained by the application.',
            ]);
        }

        DB::transaction(function () use ($secretKey, $actorId, $validated, $version, $wasConfigured): void {
            $record = PlatformSecret::query()->where('secret_key', $secretKey)->lockForUpdate()->firstOrFail();
            $record->forceFill([
                'status' => 'configured',
                'vault_version' => $version,
                'configured_by' => $actorId,
                'last_rotated_at' => now(),
                'cleared_at' => null,
            ])->save();

            $this->appendAudit(
                $secretKey,
                $actorId,
                $wasConfigured ? 'secret.rotated' : 'secret.set',
                $version,
                $validated['reason'],
            );
        });

        return redirect()->route('admin.secrets.index')->with('status', 'Secret stored securely. Its value will not be displayed again.');
    }

    public function destroy(Request $request, string $secretKey, VaultKv2SecretStore $vault, TotpService $totp): RedirectResponse
    {
        SecretCatalog::find($secretKey);
        abort_unless($vault->isConfigured(), 503, 'Secret storage is not configured.');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:20', 'max:500'],
            'password' => ['required', 'string', 'max:200'],
            'code' => ['required', 'digits:6'],
        ]);

        if (mb_strlen(trim($validated['reason'])) < 20) {
            throw ValidationException::withMessages([
                'reason' => 'Provide at least 20 non-whitespace characters explaining the operational reason.',
            ]);
        }

        $actorId = $this->consumeStepUp($request, $validated['password'], $validated['code'], $totp);
        $record = PlatformSecret::query()->where('secret_key', $secretKey)->firstOrFail();

        $record->forceFill(['status' => 'pending'])->save();
        $this->appendAudit($secretKey, $actorId, 'secret.clear_requested', null, $validated['reason']);

        try {
            $vault->clear($secretKey);
        } catch (Throwable) {
            DB::transaction(function () use ($secretKey, $actorId, $validated): void {
                PlatformSecret::query()->where('secret_key', $secretKey)->lockForUpdate()->update(['status' => 'error']);
                $this->appendAudit($secretKey, $actorId, 'secret.clear_failed', null, $validated['reason']);
            });

            return back()->withErrors([
                'secret' => 'The secret could not be confirmed as permanently removed from the secret store.',
            ]);
        }

        DB::transaction(function () use ($secretKey, $actorId, $validated): void {
            $record = PlatformSecret::query()->where('secret_key', $secretKey)->lockForUpdate()->firstOrFail();
            $record->forceFill([
                'status' => 'cleared',
                'vault_version' => 0,
                'cleared_at' => now(),
            ])->save();

            $this->appendAudit($secretKey, $actorId, 'secret.cleared', null, $validated['reason']);
        });

        return redirect()->route('admin.secrets.index')->with('status', 'Secret versions permanently removed from the configured Vault path.');
    }

    private function consumeStepUp(Request $request, string $password, string $code, TotpService $totp): int
    {
        return DB::transaction(function () use ($request, $password, $code, $totp): int {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            if (! $actor->is_active
                || $actor->role !== 'super_admin'
                || ! $actor->isDesignatedDeploymentSuperAdmin()) {
                abort(403);
            }

            if (! Hash::check($password, $actor->password)) {
                throw ValidationException::withMessages([
                    'password' => 'The current password could not be verified.',
                ]);
            }

            if (! $actor->mfa_enabled_at || ! $actor->mfa_secret) {
                throw ValidationException::withMessages([
                    'code' => 'Complete MFA setup before managing deployment secrets.',
                ]);
            }

            $step = $totp->verify($actor->mfa_secret, $code, $actor->mfa_last_used_step);
            if ($step === null) {
                throw ValidationException::withMessages([
                    'code' => 'The authenticator code is invalid, expired or already used.',
                ]);
            }

            $actor->forceFill(['mfa_last_used_step' => $step])->save();

            return (int) $actor->id;
        });
    }

    private function appendAudit(string $secretKey, int $actorId, string $action, ?int $version, string $reason): void
    {
        $actor = User::query()->find($actorId);

        PlatformSecretAuditEvent::query()->create([
            'secret_key' => $secretKey,
            'actor_id' => $actorId,
            'actor_email' => $actor?->email,
            'action' => $action,
            'vault_version' => $version,
            'reason' => trim($reason),
            'correlation_id' => (string) Str::uuid(),
            'created_at' => now(),
        ]);
    }
}
