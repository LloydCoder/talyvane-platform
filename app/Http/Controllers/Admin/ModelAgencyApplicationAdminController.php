<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ModelAgencyApplicationAdminController extends Controller
{
    private const TRANSITIONS = [
        'submitted' => ['reviewing', 'declined'],
        'reviewing' => ['accepted', 'declined'],
        'accepted' => [],
        'declined' => [],
    ];

    public function index(): Response
    {
        $this->assertSchemaReady();

        return response()->view('admin.model-agency.applications.index', [
            'applications' => ModelTalentApplication::query()
                ->with('reviewer')
                ->latest('submitted_at')
                ->paginate(20),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function update(
        Request $request,
        ModelTalentApplication $application,
    ): RedirectResponse {
        $this->assertSchemaReady();

        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewing', 'accepted', 'declined'])],
            'review_note' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $note = trim($validated['review_note']);

        if (mb_strlen($note) < 20) {
            throw ValidationException::withMessages([
                'review_note' => 'Provide at least 20 non-whitespace characters explaining this decision.',
            ]);
        }

        DB::transaction(function () use ($request, $application, $validated, $note): void {
            $locked = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);
            $target = $validated['status'];
            $allowed = self::TRANSITIONS[$locked->status] ?? [];

            if ($target === 'accepted' && $locked->adult_eligibility_verified_at === null) {
                throw ValidationException::withMessages([
                    'status' => 'Adult eligibility must be verified by an authorized owner or Super Admin before acceptance.',
                ]);
            }

            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'This application has already reached a terminal state or the requested transition is not permitted.',
                ]);
            }

            $from = $locked->status;
            $locked->forceFill([
                'status' => $target,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $note,
            ])->save();

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $locked->id,
                'actor_id' => $request->user()->id,
                'actor_type' => 'administrator',
                'event_type' => 'application.'.$target,
                'from_status' => $from,
                'to_status' => $target,
                'reason' => $note,
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('admin.model-applications.index')->with(
            'status',
            'Application status updated. Acceptance is an invitation to the separate representation process, not a signed representation agreement.',
        );
    }


    public function verifyAdultEligibility(Request $request, ModelTalentApplication $application): RedirectResponse
    {
        $this->assertSchemaReady();
        abort_unless(in_array($request->user()->role, ['super_admin', 'owner'], true), 403);

        abort_unless(
            config('talyvane.model_agency.adult_eligibility_policy_approved')
                && filled(config('talyvane.model_agency.adult_eligibility_policy_version')),
            503,
            'The current adult-eligibility policy is not approved.',
        );

        $validated = $request->validate([
            'identity_matches_applicant' => ['required', 'accepted'],
            'document_is_valid' => ['required', 'accepted'],
            'meets_policy_age_threshold' => ['required', 'accepted'],
            'verification_method' => ['required', Rule::in(['manual_document_inspection'])],
        ]);

        DB::transaction(function () use ($request, $application, $validated): void {
            $locked = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);
            abort_if($locked->status === 'erased', 410);

            if ($locked->status !== 'reviewing') {
                throw ValidationException::withMessages([
                    'verification_method' => 'Move the application into review before verifying adult eligibility.',
                ]);
            }
            if ($locked->adult_eligibility_verified_at !== null) {
                throw ValidationException::withMessages([
                    'verification_method' => 'Adult eligibility has already been verified. A second verification is not permitted.',
                ]);
            }
            if ($locked->adult_eligibility_policy_version !== config('talyvane.model_agency.adult_eligibility_policy_version')) {
                throw ValidationException::withMessages([
                    'verification_method' => 'The applicant accepted an outdated adult-eligibility policy. Re-consent is required before verification.',
                ]);
            }

            $locked->forceFill([
                'adult_eligibility_verified_at' => now(),
                'adult_eligibility_verified_by' => $request->user()->id,
                'adult_eligibility_verification_method' => $validated['verification_method'],
            ])->save();

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $locked->id,
                'actor_id' => $request->user()->id,
                'actor_type' => 'administrator',
                'event_type' => 'application.adult_eligibility_verified',
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'reason' => 'Manual document inspection completed against the approved adult-eligibility policy; no document image, number or date of birth retained.',
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('admin.model-applications.index')->with('status', 'Adult eligibility verification recorded.');
    }

    public function updateLegalHold(Request $request, ModelTalentApplication $application): RedirectResponse
    {
        $this->assertSchemaReady();
        abort_unless(in_array($request->user()->role, ['super_admin', 'owner'], true), 403);

        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
        ]);

        $reason = trim($validated['reason']);
        if (mb_strlen($reason) < 20) {
            throw ValidationException::withMessages([
                'reason' => 'Provide at least 20 non-whitespace characters.',
            ]);
        }

        DB::transaction(function () use ($request, $application, $validated, $reason): void {
            $locked = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);
            $placing = (bool) $validated['enabled'];

            abort_if($locked->status === 'erased', 410, 'Erased application records cannot be placed on legal hold.');

            if ($placing && $locked->legal_hold_at !== null) {
                throw ValidationException::withMessages(['enabled' => 'A legal hold is already active.']);
            }

            if (! $placing && $locked->legal_hold_at === null) {
                throw ValidationException::withMessages(['enabled' => 'No legal hold is active.']);
            }

            $locked->forceFill($placing ? [
                'legal_hold_at' => now(),
                'legal_hold_by' => $request->user()->id,
                'legal_hold_reason' => $reason,
            ] : [
                'legal_hold_at' => null,
                'legal_hold_by' => null,
                'legal_hold_reason' => null,
            ])->save();

            ModelTalentApplicationEvent::query()->create([
                'application_id' => $locked->id,
                'actor_id' => $request->user()->id,
                'actor_type' => 'administrator',
                'event_type' => $placing ? 'application.legal_hold_placed' : 'application.legal_hold_released',
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'reason' => $reason,
                'occurred_at' => now(),
            ]);
        });

        return redirect()->route('admin.model-applications.index')->with('status', 'Applicant legal hold updated.');
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_talent_applications')
                && Schema::hasColumns('model_talent_applications', ['reference', 'full_name', 'email', 'status', 'legal_hold_at', 'adult_eligibility_verified_at', 'adult_eligibility_verified_by', 'adult_eligibility_verification_method'])
                && Schema::hasTable('model_talent_application_events')
                && Schema::hasColumns('model_talent_application_events', ['application_id', 'actor_type', 'event_type', 'occurred_at']),
            503,
            'Model agency application records are unavailable.',
        );
    }
}
