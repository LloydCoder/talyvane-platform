<?php

namespace App\Http\Controllers\Admin;

use App\Models\PlatformFeature;
use App\Models\PlatformFeatureAuditEvent;
use App\Models\User;
use App\Services\FeatureActivationEvaluator;
use App\Services\FeatureRegistry;
use App\Services\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PlatformFeatureController
{
    public function index(FeatureRegistry $registry, FeatureActivationEvaluator $evaluator): View
    {
        $features = $registry->catalog();

        $features->each(function (PlatformFeature $feature) use ($evaluator): void {
            $feature->setAttribute('hard_safeguard', HardSafeguards::contains($feature->key));
            $feature->setAttribute('activation_blockers', $evaluator->blockers($feature));
            $feature->setAttribute('impact_summary', $evaluator->impactSummary($feature));
        });

        return view('admin.features.index', ['features' => $features]);
    }

    public function update(
        Request $request,
        PlatformFeature $feature,
        FeatureActivationEvaluator $evaluator,
        TotpService $totp,
    ): RedirectResponse {
        abort_if(HardSafeguards::contains($feature->key), 403, HardSafeguards::blocker());

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:20', 'max:500'],
            'password' => ['required', 'string', 'max:200'],
            'code' => ['required', 'digits:6'],
        ]);

        if (mb_strlen(trim($validated['reason'])) < 20) {
            throw ValidationException::withMessages([
                'reason' => 'Provide at least 20 non-whitespace characters explaining the operational reason.',
            ]);
        }

        $desiredState = (bool) $validated['enabled'];
        $outcome = DB::transaction(function () use ($request, $feature, $evaluator, $totp, $validated, $desiredState): array {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);

            if (! $actor->is_active || $actor->role !== 'super_admin') {
                abort(403);
            }

            if (! Hash::check($validated['password'], $actor->password)) {
                throw ValidationException::withMessages([
                    'password' => 'The current password could not be verified.',
                ]);
            }

            if (! $actor->mfa_enabled_at || ! $actor->mfa_secret) {
                throw ValidationException::withMessages([
                    'code' => 'Complete MFA setup before changing platform features.',
                ]);
            }

            $step = $totp->verify($actor->mfa_secret, $validated['code'], $actor->mfa_last_used_step);
            if ($step === null) {
                throw ValidationException::withMessages([
                    'code' => 'The authenticator code is invalid, expired or already used.',
                ]);
            }

            // Consume the TOTP step even when activation is blocked, preventing cross-action replay.
            $actor->forceFill(['mfa_last_used_step' => $step])->save();

            $lockedFeature = PlatformFeature::query()->lockForUpdate()->findOrFail($feature->getKey());

            if ($lockedFeature->is_enabled === $desiredState) {
                return ['changed' => false, 'blockers' => []];
            }

            if ($desiredState) {
                $blockers = $evaluator->blockers($lockedFeature);
                if ($blockers !== []) {
                    return ['changed' => false, 'blockers' => $blockers];
                }
            }

            $before = [
                'is_enabled' => $lockedFeature->is_enabled,
                'implementation_status' => $lockedFeature->implementation_status,
            ];

            $lockedFeature->forceFill(['is_enabled' => $desiredState])->save();

            PlatformFeatureAuditEvent::query()->create([
                'feature_key' => $lockedFeature->key,
                'actor_id' => $actor->id,
                'actor_email' => $actor->email,
                'action' => $desiredState ? 'feature.enabled' : 'feature.disabled',
                'before_state' => $before,
                'after_state' => [
                    'is_enabled' => $lockedFeature->is_enabled,
                    'implementation_status' => $lockedFeature->implementation_status,
                ],
                'reason' => trim($validated['reason']),
                'correlation_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);

            return ['changed' => true, 'blockers' => []];
        });

        if ($outcome['blockers'] !== []) {
            return back()->withErrors(['enabled' => implode(' ', $outcome['blockers'])]);
        }

        if (! $outcome['changed']) {
            return redirect()->route('admin.features.index')->with('status', 'The feature was already in the requested state.');
        }

        return redirect()->route('admin.features.index')->with(
            'status',
            $desiredState ? 'Feature enabled and audited.' : 'Feature disabled and audited.',
        );
    }
}
