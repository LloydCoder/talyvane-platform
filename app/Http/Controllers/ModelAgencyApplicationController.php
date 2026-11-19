<?php

namespace App\Http\Controllers;

use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use App\Models\PlatformFeature;
use App\Services\FeatureActivationEvaluator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModelAgencyApplicationController extends Controller
{
    public function create(FeatureActivationEvaluator $evaluator): Response
    {
        $this->assertFeatureReady($evaluator);

        return response()->view('model-agency.apply', [
            'privacyNoticeVersion' => config('talyvane.model_agency.privacy_notice_version'),
            'adultEligibilityPolicyVersion' => config('talyvane.model_agency.adult_eligibility_policy_version'),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function store(Request $request, FeatureActivationEvaluator $evaluator): RedirectResponse
    {
        $this->assertFeatureReady($evaluator);

        $privacyNoticeVersion = (string) config('talyvane.model_agency.privacy_notice_version');
        $adultEligibilityPolicyVersion = (string) config('talyvane.model_agency.adult_eligibility_policy_version');

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9(). -]{7,32}$/'],
            'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'city' => ['required', 'string', 'min:2', 'max:100'],
            'portfolio_url' => [
                'nullable',
                'url',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $parts = parse_url($value);
                    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                    $host = (string) ($parts['host'] ?? '');

                    if ($scheme !== 'https'
                        || $host === ''
                        || isset($parts['user'])
                        || isset($parts['pass'])
                        || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
                        $fail('Portfolio links must use a standard HTTPS URL without embedded credentials or a custom port.');
                    }
                },
            ],
            'experience_summary' => ['required', 'string', 'min:20', 'max:4000'],
            'adult_eligibility_confirmed' => ['required', 'accepted'],
            'adult_eligibility_policy_version' => ['required', 'string', 'in:'.$adultEligibilityPolicyVersion],
            'adult_eligibility_policy_accepted' => ['required', 'accepted'],
            'privacy_notice_version' => ['required', 'string', 'in:'.$privacyNoticeVersion],
            'privacy_notice_accepted' => ['required', 'accepted'],
            'website' => ['prohibited'],
        ]);

        DB::transaction(function () use ($validated, $privacyNoticeVersion, $adultEligibilityPolicyVersion): void {
            $application = ModelTalentApplication::query()->create([
                'reference' => 'MTA-'.now()->format('Y').'-'.Str::upper(Str::random(10)),
                'full_name' => trim($validated['full_name']),
                'email' => Str::lower(trim($validated['email'])),
                'phone' => isset($validated['phone']) ? trim($validated['phone']) : null,
                'country_code' => $validated['country_code'],
                'city' => trim($validated['city']),
                'portfolio_url' => $validated['portfolio_url'] ?? null,
                'experience_summary' => trim($validated['experience_summary']),
                'status' => 'submitted',
                'adult_eligibility_confirmed_at' => now(),
                'adult_eligibility_policy_version' => $adultEligibilityPolicyVersion,
                'adult_eligibility_policy_accepted_at' => now(),
                'privacy_notice_version' => $privacyNoticeVersion,
                'privacy_notice_accepted_at' => now(),
                'submitted_at' => now(),
            ]);

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $application->id,
                'actor_type' => 'applicant',
                'event_type' => 'application.submitted',
                'to_status' => 'submitted',
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('model-agency.apply.received');
    }

    public function received(): View
    {
        return view('model-agency.received');
    }

    private function assertFeatureReady(FeatureActivationEvaluator $evaluator): void
    {
        $feature = PlatformFeature::query()->findOrFail('model-agency.applications');

        abort_if($evaluator->blockers($feature) !== [], 503, 'Model agency application safeguards are not configured.');
    }
}
