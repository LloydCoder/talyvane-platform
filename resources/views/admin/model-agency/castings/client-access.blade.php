@extends('admin.layout')

@section('title', 'Client casting access')
@section('section', 'CLIENT CASTING ACCESS')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">MODEL AGENCY</p>
            <h1>Private client casting access</h1>
            <p class="field-help">Grant short-lived, named-client access only after confirming the relevant model licences include the client-review channel. Client feedback is advisory and never changes agency selection.</p>
        </div>
    </div>

    <section class="admin-panel">
        <h2>Grant access</h2>
        <form method="POST" action="{{ route('admin.model-casting-client-access.grant') }}" class="admin-form-grid">
            @csrf
            <label>Casting call
                <select name="call_id" required>
                    <option value="">Choose a private casting call</option>
                    @foreach($calls as $call)
                        <option value="{{ $call->id }}">{{ $call->reference }} · {{ $call->title }}</option>
                    @endforeach
                </select>
            </label>
            <label>Verified client account
                <select name="client_user_id" required>
                    <option value="">Choose a verified client</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->name }} · {{ $client->email }}</option>
                    @endforeach
                </select>
            </label>
            <label>Expires at<input type="datetime-local" name="expires_at" required></label>
            <label class="full-width">Reason / approved business purpose<textarea name="reason" minlength="20" maxlength="1000" required></textarea></label>
            <div class="full-width"><button class="button button-dark" type="submit">Grant private access</button></div>
        </form>
    </section>

    <section class="admin-panel">
        <h2>Access grants</h2>
        @forelse($accesses as $access)
            <article class="admin-record">
                <p class="eyebrow">{{ $access->call->reference }} · {{ $access->isActive() ? 'ACTIVE' : ($access->revoked_at ? 'REVOKED' : 'EXPIRED') }}</p>
                <h3>{{ $access->call->title }} — {{ $access->client->name }}</h3>
                <p>{{ $access->client->email }} · Expires {{ $access->expires_at->format('d M Y H:i') }}</p>
                <p class="field-help">{{ $access->reason }}</p>
                @if($access->revoked_at === null)
                    <form method="POST" action="{{ route('admin.model-casting-client-access.revoke', $access) }}" class="inline-form">
                        @csrf
                        <input name="reason" minlength="20" maxlength="1000" placeholder="Revocation reason" required>
                        <button class="button button-dark" type="submit">Revoke access</button>
                    </form>
                @endif
            </article>
        @empty
            <p class="field-help">No private client access grants have been created.</p>
        @endforelse
    </section>

    <section class="admin-panel">
        <h2>Client advisory feedback</h2>
        @forelse($reviews as $review)
            <article class="admin-record">
                <p class="eyebrow">{{ $review->submission->reference }} · {{ strtoupper($review->decision) }}</p>
                <h3>{{ $review->access->call->title }} — {{ $review->client->name }}</h3>
                <p>{{ $review->note }}</p>
                <p class="field-help">Submitted {{ $review->reviewed_at->format('d M Y H:i') }}. Advisory only; agency selection remains unchanged unless an administrator separately records a decision.</p>
            </article>
        @empty
            <p class="field-help">No client feedback has been recorded.</p>
        @endforelse
    </section>
@endsection
