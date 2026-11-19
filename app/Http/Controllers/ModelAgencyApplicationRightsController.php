<?php

namespace App\Http\Controllers;

use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use App\Models\ModelTalentApplicationRightsRequest;
use App\Notifications\ModelAgencyRightsVerificationCode;
use App\Services\ModelAgencyApplicationLifecycleService;
use App\Services\ModelAgencyRightsCodeGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class ModelAgencyApplicationRightsController extends Controller
{
    public function create(): View
    {
        $this->assertLifecycleSchemaReady();

        return view('model-agency.application-support');
    }

    public function submitRequest(Request $request, ModelAgencyRightsCodeGenerator $codes): RedirectResponse
    {
        $this->assertLifecycleSchemaReady();

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'request_type' => ['required', 'in:access,correction,withdrawal,erasure'],
        ]);

        $application = ModelTalentApplication::query()
            ->where('reference', strtoupper(trim($validated['reference'])))
            ->first();

        if ($application
            && $application->status !== 'erased'
            && hash_equals(Str::lower($application->email), Str::lower(trim($validated['email'])))) {
            $code = $codes->generate();
            $rightsRequest = DB::transaction(function () use ($application, $validated, $code): ModelTalentApplicationRightsRequest {
                $lockedApplication = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);

                if ($lockedApplication->status === 'erased') {
                    throw ValidationException::withMessages(['reference' => 'The application is no longer available.']);
                }

                ModelTalentApplicationRightsRequest::query()
                    ->where('application_id', $application->id)
                    ->whereIn('status', ['pending', 'verified'])
                    ->update([
                        'status' => 'superseded',
                        'code_hash' => '',
                        'updated_at' => now(),
                    ]);

                $created = ModelTalentApplicationRightsRequest::query()->create([
                    'application_id' => $lockedApplication->id,
                    'request_type' => $validated['request_type'],
                    'status' => 'pending',
                    'code_hash' => Hash::make($code),
                    'attempts' => 0,
                    'expires_at' => now()->addMinutes(10),
                ]);

                ModelTalentApplicationEvent::query()->create([
                    'application_id' => $lockedApplication->id,
                    'actor_type' => 'applicant',
                    'event_type' => 'application.privacy_request_'.$validated['request_type'],
                    'from_status' => $lockedApplication->status,
                    'to_status' => $lockedApplication->status,
                    'occurred_at' => now(),
                ]);

                return $created;
            });

            try {
                Notification::route('mail', $application->email)->notify(
                    new ModelAgencyRightsVerificationCode(
                        $code,
                        $validated['request_type'],
                        $rightsRequest->expires_at->toIso8601String(),
                    ),
                );
            } catch (Throwable) {
                $rightsRequest->forceFill([
                    'status' => 'delivery_failed',
                    'code_hash' => '',
                    'completed_at' => now(),
                ])->save();
            }
        }

        return redirect()->route('model-agency.application-support.verify')
            ->with('status', 'If the reference and email match an application, a one-time verification code has been sent. Check your inbox.');
    }

    public function verifyForm(): View
    {
        $this->assertLifecycleSchemaReady();

        return view('model-agency.application-support-verify');
    }

    public function verify(Request $request): RedirectResponse
    {
        $this->assertLifecycleSchemaReady();

        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email:rfc', 'max:254'],
            'code' => ['required', 'digits:8'],
        ]);

        $application = ModelTalentApplication::query()
            ->where('reference', strtoupper(trim($validated['reference'])))
            ->first();

        if (! $application
            || $application->status === 'erased'
            || ! hash_equals(Str::lower($application->email), Str::lower(trim($validated['email'])))) {
            throw ValidationException::withMessages([
                'code' => 'The verification details are invalid, expired or already used.',
            ]);
        }

        $rightsRequest = ModelTalentApplicationRightsRequest::query()
            ->where('application_id', $application->id)
            ->where('status', 'pending')
            ->latest('id')
            ->first();

        if (! $rightsRequest) {
            throw ValidationException::withMessages([
                'code' => 'The verification details are invalid, expired or already used.',
            ]);
        }

        $verified = DB::transaction(function () use ($rightsRequest, $validated): bool {
            $locked = ModelTalentApplicationRightsRequest::query()->lockForUpdate()->findOrFail($rightsRequest->id);

            if ($locked->status !== 'pending' || $locked->expires_at->isPast() || $locked->attempts >= 5) {
                $locked->forceFill([
                    'status' => $locked->attempts >= 5 ? 'locked' : 'expired',
                    'code_hash' => '',
                    'completed_at' => now(),
                ])->save();

                return false;
            }

            $attempts = $locked->attempts + 1;
            if (! Hash::check($validated['code'], $locked->code_hash)) {
                $locked->forceFill([
                    'attempts' => $attempts,
                    'status' => $attempts >= 5 ? 'locked' : 'pending',
                    'code_hash' => $attempts >= 5 ? '' : $locked->code_hash,
                    'completed_at' => $attempts >= 5 ? now() : null,
                ])->save();

                return false;
            }

            $locked->forceFill([
                'status' => 'verified',
                'attempts' => $attempts,
                'code_hash' => '',
                'verified_at' => now(),
                'expires_at' => now()->addMinutes(30),
            ])->save();

            return true;
        });

        if (! $verified) {
            throw ValidationException::withMessages([
                'code' => 'The verification details are invalid, expired or already used.',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->put('verified_model_application_rights_request_id', $rightsRequest->id);

        return redirect()->route('model-agency.application-support.show', $rightsRequest);
    }

    public function show(Request $request, ModelTalentApplicationRightsRequest $rightsRequest): Response
    {
        $this->assertVerified($request, $rightsRequest);

        return response()->view('model-agency.application-support-manage', [
            'rightsRequest' => $rightsRequest->load('application'),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function finish(Request $request, ModelTalentApplicationRightsRequest $rightsRequest): RedirectResponse
    {
        $this->assertVerified($request, $rightsRequest);

        $rightsRequest->forceFill([
            'status' => 'completed',
            'code_hash' => '',
            'completed_at' => now(),
        ])->save();

        $request->session()->forget('verified_model_application_rights_request_id');

        return redirect()->route('model-agency.application-support.verify')
            ->with('status', 'Your private applicant session has been closed.');
    }

    public function correct(Request $request, ModelTalentApplicationRightsRequest $rightsRequest): RedirectResponse
    {
        $this->assertVerified($request, $rightsRequest);
        abort_unless($rightsRequest->request_type === 'correction', 404);

        $validated = $request->validate([
            'full_name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^[+0-9(). -]{7,32}$/'],
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
                    if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                        || empty($parts['host'])
                        || isset($parts['user'])
                        || isset($parts['pass'])
                        || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
                        $fail('Portfolio links must use a standard HTTPS URL without embedded credentials or a custom port.');
                    }
                },
            ],
            'experience_summary' => ['required', 'string', 'min:20', 'max:4000'],
        ]);

        DB::transaction(function () use ($rightsRequest, $validated): void {
            $application = ModelTalentApplication::query()->lockForUpdate()->findOrFail($rightsRequest->application_id);
            abort_if($application->status === 'erased', 410);

            $application->forceFill([
                'full_name' => trim($validated['full_name']),
                'phone' => isset($validated['phone']) ? trim($validated['phone']) : null,
                'city' => trim($validated['city']),
                'portfolio_url' => $validated['portfolio_url'] ?? null,
                'experience_summary' => trim($validated['experience_summary']),
            ])->save();

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $application->id,
                'actor_type' => 'applicant',
                'event_type' => 'application.corrected',
                'from_status' => $application->status,
                'to_status' => $application->status,
                'occurred_at' => now(),
            ]);

            $rightsRequest->forceFill([
                'status' => 'completed',
                'code_hash' => '',
                'completed_at' => now(),
            ])->save();
        });

        $request->session()->forget('verified_model_application_rights_request_id');

        return redirect()->route('model-agency.application-support.verify')
            ->with('status', 'Your verified application corrections have been saved.');
    }

    public function withdraw(
        Request $request,
        ModelTalentApplicationRightsRequest $rightsRequest,
        ModelAgencyApplicationLifecycleService $lifecycle,
    ): RedirectResponse {
        $this->assertVerified($request, $rightsRequest);
        abort_unless($rightsRequest->request_type === 'withdrawal', 404);

        DB::transaction(function () use ($lifecycle, $rightsRequest): void {
            $lifecycle->withdraw($rightsRequest->application);

            $rightsRequest->forceFill([
                'status' => 'completed',
                'code_hash' => '',
                'completed_at' => now(),
            ])->save();
        });

        $request->session()->forget('verified_model_application_rights_request_id');

        return redirect()->route('model-agency.application-support.verify')
            ->with('status', 'Your application has been withdrawn.');
    }

    public function erase(
        Request $request,
        ModelTalentApplicationRightsRequest $rightsRequest,
        ModelAgencyApplicationLifecycleService $lifecycle,
    ): RedirectResponse {
        $this->assertVerified($request, $rightsRequest);
        abort_unless($rightsRequest->request_type === 'erasure', 404);

        if ($rightsRequest->application->legal_hold_at !== null) {
            DB::transaction(function () use ($rightsRequest): void {
                ModelTalentApplicationEvent::query()->create([
                    'application_id' => $rightsRequest->application_id,
                    'actor_type' => 'applicant',
                    'event_type' => 'application.erasure_deferred',
                    'from_status' => $rightsRequest->application->status,
                    'to_status' => $rightsRequest->application->status,
                    'occurred_at' => now(),
                ]);

                $rightsRequest->forceFill([
                    'status' => 'rejected',
                    'code_hash' => '',
                    'completed_at' => now(),
                ])->save();
            });

            $request->session()->forget('verified_model_application_rights_request_id');

            return redirect()->route('model-agency.application-support.verify')
                ->with('status', 'The application is subject to a documented legal hold. Contact the agency using the details in its privacy notice.');
        }

        $lifecycle->anonymize($rightsRequest->application, 'applicant');

        $request->session()->forget('verified_model_application_rights_request_id');

        return redirect()->route('model-agency.application-support.verify')
            ->with('status', 'The personal information in your application has been erased. A minimal non-identifying audit record is retained.');
    }

    private function assertLifecycleSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_talent_applications')
                && Schema::hasColumn('model_talent_applications', 'legal_hold_at')
                && Schema::hasTable('model_talent_application_rights_requests'),
            503,
            'Applicant privacy request services are temporarily unavailable.',
        );
    }

    private function assertVerified(Request $request, ModelTalentApplicationRightsRequest $rightsRequest): void
    {
        $valid = (int) $request->session()->get('verified_model_application_rights_request_id') === $rightsRequest->id
            && $rightsRequest->status === 'verified'
            && $rightsRequest->expires_at->isFuture();

        if (! $valid) {
            $request->session()->forget('verified_model_application_rights_request_id');
            abort(403);
        }
    }
}
