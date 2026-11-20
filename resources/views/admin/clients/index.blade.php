@extends('admin.layout')
@section('title', 'Clients & membership')
@section('section', 'CLIENTS')
@section('content')
    <div class="admin-page-heading">
        <div><p class="eyebrow">CLIENT RELATIONSHIPS</p><h1>Clients & <em>membership.</em></h1><p class="admin-muted">Membership is assigned by the agency. Tier names and benefits remain configurable; no benefits are implied until recorded here.</p></div>
    </div>

    <div class="admin-list-toolbar"><span>{{ $clients->total() }} client accounts</span></div>
    <div class="admin-client-list">
        @forelse ($clients as $client)
            <article class="admin-client-card">
                <div class="admin-client-summary">
                    <div><span class="eyebrow">CLIENT ACCOUNT</span><h2>{{ $client->name }}</h2><p>{{ $client->email }}</p></div>
                    @if ($client->latestMembership)
                        <div class="admin-client-current"><span class="eyebrow">LATEST MEMBERSHIP</span><strong>{{ $client->latestMembership->tier_name }}</strong><small>{{ strtoupper($client->latestMembership->status) }}</small></div>
                    @else
                        <div class="admin-client-current"><span class="eyebrow">MEMBERSHIP</span><strong>Not assigned</strong></div>
                    @endif
                </div>
                @if ($client->latestMembership)
                    <p class="admin-client-benefits">{{ $client->latestMembership->benefits_summary }}</p>
                @endif
                <details class="admin-membership-editor">
                    <summary>Assign or update membership</summary>
                    <form method="POST" action="{{ route('admin.clients.membership', $client) }}">
                        @csrf
                        <div class="admin-form-grid">
                            <label>Tier name<input name="tier_name" maxlength="80" required value="{{ old('tier_name', $client->latestMembership?->tier_name) }}" placeholder="Private / Signature / Bespoke"></label>
                            <label>Status<select name="status" required><option value="active">Active</option><option value="suspended">Suspended</option></select></label>
                            <label>Starts<input type="date" name="starts_at" required value="{{ old('starts_at', now()->format('Y-m-d')) }}"></label>
                            <label>Ends (optional)<input type="date" name="ends_at" value="{{ old('ends_at') }}"></label>
                        </div>
                        <label>Client-facing benefits summary<textarea name="benefits_summary" rows="3" maxlength="2000" required placeholder="Describe only benefits the agency has agreed to provide.">{{ old('benefits_summary', $client->latestMembership?->benefits_summary) }}</textarea></label>
                        <label>Internal note (never shown to client)<textarea name="internal_note" rows="2" maxlength="2000" placeholder="Private operational note"></textarea></label>
                        @if ($entitlementsEnabled)
                            <fieldset class="admin-form-fieldset">
                                <legend>Protected membership entitlements</legend>
                                @php($currentEntitlements = $client->latestMembership?->entitlements->pluck('entitlement_key')->all() ?? [])
                                @foreach ($entitlementCatalog as $key => $entitlement)
                                    <label class="remember-row">
                                        <input type="checkbox" name="entitlements[]" value="{{ $key }}" @checked(in_array($key, old('entitlements', $currentEntitlements), true))>
                                        <span><strong>{{ $entitlement['name'] }}</strong><small>{{ $entitlement['description'] }}</small></span>
                                    </label>
                                @endforeach
                            </fieldset>
                        @else
                            <p class="field-help">Protected membership entitlements are disabled until the Super Admin completes the feature activation review.</p>
                        @endif
                        <button class="button button-dark" type="submit">Save membership ↗</button>
                    </form>
                </details>
            </article>
        @empty
            <div class="portal-placeholder"><strong>No client accounts yet</strong><p>Verified client accounts will appear here.</p></div>
        @endforelse
    </div>
    <div class="pagination-wrap">{{ $clients->links() }}</div>
@endsection
