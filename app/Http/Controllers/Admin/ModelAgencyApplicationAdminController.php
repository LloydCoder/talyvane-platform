<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ModelAgencyApplicationAdminController extends Controller
{
    private const TRANSITIONS = [
        'submitted' => ['reviewing', 'declined'],
        'reviewing' => ['accepted', 'declined'],
        'accepted' => [],
        'declined' => [],
    ];

    public function index(): \\Illuminate\\Http\\Response
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
            Schema::hasTable('model_talent_applications') && Schema::hasTable('model_talent_application_events'),
            503,
            'Model agency application records are unavailable.',
        );
    }
}
