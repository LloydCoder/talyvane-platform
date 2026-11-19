<?php

namespace App\Http\Controllers;

use App\Models\ModelCastingCall;
use App\Models\ModelCastingSubmission;
use App\Services\ModelCastingClientPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class ModelCastingClientPortalController extends Controller
{
    public function index(Request $request, ModelCastingClientPortalService $portal): Response
    {
        return response()->view('client.model-castings.index', [
            'calls' => $portal->callsFor($request->user()),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function show(
        Request $request,
        ModelCastingCall $call,
        ModelCastingClientPortalService $portal,
    ): Response {
        $submissions = $portal->submissionsFor($call, $request->user());

        return response()->view('client.model-castings.show', [
            'call' => $call,
            'submissions' => $submissions,
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function media(
        Request $request,
        ModelCastingSubmission $submission,
        ModelCastingClientPortalService $portal,
    ): Response {
        $authorized = $portal->authorizeSubmission($submission, $request->user());
        $asset = $authorized->portfolioAsset;
        $bytes = Storage::disk('local')->get($asset->storage_path);

        abort_if($bytes === false, 404);

        return response($bytes, 200, [
            'Content-Type' => 'image/webp',
            'Content-Disposition' => 'inline; filename="private-model-preview.webp"',
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    public function review(
        Request $request,
        ModelCastingSubmission $submission,
        ModelCastingClientPortalService $portal,
    ): RedirectResponse {
        $validated = $request->validate([
            'decision' => ['required', 'in:shortlisted,declined'],
            'note' => ['required', 'string', 'min:20', 'max:1500'],
        ]);
        $portal->recordReview($submission, $request->user(), $validated['decision'], trim($validated['note']));

        return back()->with('status', 'Your advisory feedback has been sent to the agency. Final selection remains an agency decision.');
    }
}
