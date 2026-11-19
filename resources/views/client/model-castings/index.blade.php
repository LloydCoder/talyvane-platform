@extends('portal.layout')

@section('title', 'Private model castings')

@section('content')
    <div class="private-casting-page">
        <section class="page-heading">
            <p class="eyebrow">PRIVATE CLIENT PORTAL</p>
            <h1>Model casting invitations</h1>
            <p>Only casting calls specifically shared with your verified account appear here. Access expires automatically and can be revoked by the agency.</p>
        </section>

        <div class="private-casting-list">
        @forelse($calls as $call)
            <article class="private-casting-card">
                <p class="eyebrow">{{ $call->reference }} · {{ strtoupper($call->status) }}</p>
                <h2>{{ $call->title }}</h2>
                <p>{{ $call->description }}</p>
                <p>{{ $call->requirements }}</p>
                <p>{{ $call->country_code }} · {{ $call->project_purpose }} · {{ $call->shoot_starts_at->format('d M Y') }}–{{ $call->shoot_ends_at->format('d M Y') }}</p>
                <a href="{{ route('client.model-castings.show', $call) }}">Review private casting submissions</a>
            </article>
        @empty
            <p>No active private casting invitations are assigned to this account.</p>
        @endforelse
        </div>
    </div>
@endsection
