<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ModelCastingCall;
use App\Models\ModelCastingClientAccess;
use App\Models\ModelCastingClientReview;
use App\Models\User;
use App\Services\ModelCastingClientPortalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

class ModelCastingClientAccessAdminController extends Controller
{
    public function index(): Response
    {
        $this->assertSchemaReady();

        return response()->view('admin.model-agency.castings.client-access', [
            'calls' => ModelCastingCall::query()->whereIn('status', ['open', 'closed'])->latest()->get(),
            'clients' => User::query()->where('role', 'client')->where('is_active', true)->whereNotNull('email_verified_at')->orderBy('name')->get(['id', 'name', 'email']),
            'accesses' => ModelCastingClientAccess::query()->with(['call', 'client'])->latest()->limit(100)->get(),
            'reviews' => ModelCastingClientReview::query()->with(['submission', 'client', 'access.call'])->latest('reviewed_at')->limit(100)->get(),
        ])->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function grant(Request $request, ModelCastingClientPortalService $portal): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate([
            'call_id' => ['required', 'integer', 'exists:model_casting_calls,id'],
            'client_user_id' => ['required', 'integer', 'exists:users,id'],
            'expires_at' => ['required', 'date', 'after:now'],
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);

        $portal->grantAccess(
            ModelCastingCall::query()->findOrFail($validated['call_id']),
            User::query()->findOrFail($validated['client_user_id']),
            (int) $request->user()->id,
            Carbon::parse($validated['expires_at']),
            trim($validated['reason']),
        );

        return back()->with('status', 'Short-lived private casting access granted to the verified client.');
    }

    public function revoke(Request $request, ModelCastingClientAccess $access, ModelCastingClientPortalService $portal): RedirectResponse
    {
        $this->assertSchemaReady();
        $validated = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:1000']]);
        $portal->revokeAccess($access, (int) $request->user()->id, trim($validated['reason']));

        return back()->with('status', 'Client casting access revoked.');
    }

    private function assertSchemaReady(): void
    {
        abort_unless(
            Schema::hasTable('model_casting_client_accesses')
                && Schema::hasColumns('model_casting_client_accesses', ['call_id', 'client_user_id', 'expires_at', 'revoked_at'])
                && Schema::hasTable('model_casting_client_reviews'),
            503,
            'Private client casting access services are temporarily unavailable.',
        );
    }
}
