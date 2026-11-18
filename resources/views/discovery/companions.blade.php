@extends('discovery.layout')
@section('title', 'Profiles')
@section('content')
    <section class="discovery-intro"><p class="eyebrow">INDEPENDENT PROFILES</p><h1>Discover<br><em>companions.</em></h1><p>Only profiles with verified adult status and current publication consent appear here.</p></section>
    <form class="discovery-filters" method="GET" action="{{ route('companions.index') }}">
        <label for="destination">Destination</label><select id="destination" name="destination"><option value="">All destinations</option>@foreach ($destinations as $destination)<option value="{{ $destination->slug }}" @selected(request('destination') === $destination->slug)>{{ $destination->name }}</option>@endforeach</select>
        <label for="language">Language</label><input id="language" name="language" maxlength="40" value="{{ request('language') }}" placeholder="e.g. English">
        <button class="button button-dark" type="submit">Apply filters ↗</button>
    </form>
    <section class="profile-grid" aria-label="Published companion profiles">
        @forelse ($profiles as $profile)
            <a class="profile-card" href="{{ route('companions.show', $profile->slug) }}"><span class="eyebrow">{{ $profile->destination->name }} / VERIFIED ADULT PROFILE</span><h2>{{ $profile->display_name }}</h2><p>{{ $profile->summary }}</p><span class="profile-languages">{{ implode(' · ', $profile->languages ?? []) }}</span><span class="text-link">View profile ↗</span></a>
        @empty
            <p class="empty-discovery">No profiles match these filters.</p>
        @endforelse
    </section>
    <div class="pagination-wrap">{{ $profiles->links() }}</div>
@endsection
