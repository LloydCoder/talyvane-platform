<?php

namespace App\Http\Controllers;

use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ModelAgencyApplicationController extends Controller
{
    public function create(): View
    {
        $privacyNoticeVersion = config('talyvane.model_agency.privacy_notice_version');

        abort_unless(filled($privacyNoticeVersion), 503, 'Model agency applications are not configured.');

        return view('model-agency.apply', [
            'privacyNoticeVersion' => $privacyNoticeVersion,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $privacyNoticeVersion = config('talyvane.model_agency.privacy_notice_version');

        if (! filled($privacyNoticeVersion)
            || ! config('talyvane.model_agency.privacy_notice_approved')
            || ! config('talyvane.model_agency.adult_eligibility_policy_approved')
            || ! filled(config('talyvane.model_agency.adult_eligibility_policy_version'))) {
            abort(503, 'Model agency application safeguards are not configured.');
        }

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
                    if ($value !== null && strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https') {
                        $fail('Portfolio links must use HTTPS.');
                    }
                },
            ],
                'experience_summary' => ['required', 'string', 'min:20', 'max:4000'],
            'adult_eligibility_confirmed' => ['required', 'accepted'],
                'privacy_notice_version' => ['required', 'string', 'in:'.$privacyNoticeVersion],
            'privacy_notice_accepted' => ['required', 'accepted'],
            'website' => ['prohibited'],
        ]);

        DB::transaction(function () use ($validated, $privacyNoticeVersion): void {
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
}
