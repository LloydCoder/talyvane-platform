@extends('discovery.layout')
@section('title', 'Companions')
@section('content')
    <section class="discovery-intro"><p class="eyebrow">PRIVATE INTRODUCTIONS</p><h1>Discover<br><em>companions.</em></h1><p>Only profiles with verified adult status and current publication consent appear here.</p></section>
    <form class="discovery-filters" method="GET" action="{{ route('companions.index') }}">
        <label for="destination">Destination</label><select id="destination" name="destination"><option value="">All destinations</option>@foreach ($destinations as $destination)<option value="{{ $destination->slug }}" @selected(request('destination') === $destination->slug)>{{ $destination->name }}</option>@endforeach</select>
        <label for="language">Language</label><input id="language" name="language" maxlength="40" value="{{ request('language') }}" placeholder="e.g. English">
        <button class="button button-dark" type="submit">Apply filters ↗</button>
    </form>
    <section class="profile-grid" aria-label="Published companion profiles">
        @forelse ($profiles as $profile)
            @include('discovery.partials.companion-card', ['profile' => $profile])
        @empty
            <p class="empty-discovery">No profiles match these filters.</p>
        @endforelse
    </section>
    <div class="pagination-wrap">{{ $profiles->links() }}</div>
@endsection
