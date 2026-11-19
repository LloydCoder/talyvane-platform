<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelTalentApplication;
use App\Models\ModelTalentApplicationEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function index(): View
    {
        return view('admin.model-agency.applications.index', [
            'applications' => ModelTalentApplication::query()
                ->with('reviewer')
                ->latest('submitted_at')
                ->paginate(20),
        ]);
    }

    public function update(Request $request, ModelTalentApplication $application): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewing', 'accepted', 'declined'])],
            'review_note' => [
                Rule::requiredIf(in_array($request->input('status'), ['accepted', 'declined'], true)),
                'nullable',
                'string',
                'min:20',
                'max:2000',
            ],
        ]);

        $changed = DB::transaction(function () use ($request, $application, $validated): bool {
            $locked = ModelTalentApplication::query()->lockForUpdate()->findOrFail($application->id);
            $target = $validated['status'];
            $allowed = self::TRANSITIONS[$locked->status] ?? [];

            if (! in_array($target, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'This application has already reached a terminal state or the requested transition is not permitted.',
                ]);
            }

            $from = $locked->status;
            $note = isset($validated['review_note']) ? trim($validated['review_note']) : null;
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

            return true;
        });

        return redirect()->route('admin.model-applications.index')->with(
            'status',
            'Application status updated. Acceptance is an invitation to the separate representation process, not a signed representation agreement.',
        );
    }
}
