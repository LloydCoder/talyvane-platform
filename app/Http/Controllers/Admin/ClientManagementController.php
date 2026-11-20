<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientMembership;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientManagementController extends Controller
{
    public function index(): View
    {
        return view('admin.clients.index', [
            'clients' => User::query()
                ->where('role', 'client')
                ->with('latestMembership')
                ->orderBy('name')
                ->paginate(20),
        ]);
    }

    public function assignMembership(Request $request, User $client, \App\Services\FeatureRegistry $features): RedirectResponse
    {
        $features->assertOperationallyEnabled('memberships');
        abort_unless($client->role === 'client', 404);

        $validated = $request->validate([
            'tier_name' => ['required', 'string', 'max:80'],
            'benefits_summary' => ['required', 'string', 'min:10', 'max:2000'],
            'status' => ['required', Rule::in(['active', 'suspended'])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'internal_note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $client, $validated): void {
            $lockedClient = User::query()->lockForUpdate()->findOrFail($client->id);
            abort_unless($lockedClient->role === 'client', 404);

            ClientMembership::query()
                ->where('user_id', $lockedClient->id)
                ->where('status', 'active')
                ->update(['status' => 'superseded']);

            ClientMembership::query()->create([
                ...$validated,
                'user_id' => $lockedClient->id,
                'assigned_by' => $request->user()->id,
                'starts_at' => $validated['starts_at'],
                'ends_at' => $validated['ends_at'] ?? null,
            ]);
        });

        return redirect()->route('admin.clients.index')->with('status', 'Client membership updated. Previous active membership is retained in history.');
    }
}
