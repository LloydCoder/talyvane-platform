<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ClientMembershipService;
use App\Services\MembershipEntitlementCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientManagementController extends Controller
{
    public function index(): View
    {
        return view('admin.clients.index', [
            'clients' => User::query()
                ->where('role', 'client')
                ->with('latestMembership.entitlements')
                ->orderBy('name')
                ->paginate(20),
            'entitlementCatalog' => MembershipEntitlementCatalog::all(),
        ]);
    }

    public function assignMembership(Request $request, User $client, ClientMembershipService $memberships): RedirectResponse
    {
        abort_unless($client->role === 'client', 404);

        $validated = $request->validate([
            'tier_name' => ['required', 'string', 'max:80'],
            'benefits_summary' => ['required', 'string', 'min:10', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
            'entitlements' => ['sometimes', 'array', 'max:1'],
            'entitlements.*' => ['string', Rule::in(MembershipEntitlementCatalog::keys())],
        ]);

        $memberships->assign($client, $request->user(), $validated);

        return redirect()->route('admin.clients.index')->with('status', 'Client membership updated. Previous active membership is retained in history.');
    }
}
