@extends('discovery.layout')
@section('title', $profile->display_name)
@section('robots', 'noindex,nofollow')
@section('content')
    <article class="profile-detail">
        @if (session('status'))<div class="form-success" role="status">{{ session('status') }}</div>@endif
        <p class="eyebrow">VERIFIED ADULT PROFILE / {{ $profile->destination->name }}</p>
        <h1>{{ $profile->display_name }}<br><em>by Talyvane.</em></h1>
        @if ($profile->media->where('status', 'approved')->isNotEmpty())
            <div class="profile-media-gallery" aria-label="Approved profile photographs">
                @foreach ($profile->media->where('status', 'approved') as $media)
                    <img src="{{ route('profile-media.show', $media) }}" alt="Approved profile photograph of {{ $profile->display_name }}" loading="lazy" width="{{ $media->width }}" height="{{ $media->height }}">
                @endforeach
            </div>
        @endif
        <p class="profile-summary">{{ $profile->summary }}</p>
        @if (!empty($profile->languages))
            <div class="profile-languages">Languages: {{ implode(' · ', $profile->languages) }}</div>
        @endif
        @auth
            @if (auth()->user()->role === 'client' && auth()->user()->hasVerifiedEmail())
                @if (auth()->user()->favoriteProfiles()->where('companion_profiles.id', $profile->id)->exists())
                    <form method="POST" action="{{ route('client.favorites.destroy', $profile) }}">@csrf @method('DELETE')<button class="text-link" type="submit">Remove from private shortlist</button></form>
                @else
                    <form method="POST" action="{{ route('client.favorites.store', $profile) }}">@csrf<button class="text-link" type="submit">Save to private shortlist ♡</button></form>
                @endif
                <a class="button button-dark" href="{{ route('bookings.create', $profile->slug) }}">Request a booking ↗</a>
            @endif
        @else
            <a class="text-link" href="{{ route('login') }}">Sign in to request a booking ↗</a>
        @endauth
        <p class="profile-disclaimer">This profile is displayed following age-status verification and recorded publication consent. Availability and any engagement are subject to direct confirmation and applicable local law.</p>
        <a class="text-link" href="{{ route('companions.index', ['destination' => $profile->destination->slug]) }}">← Back to companions</a>
    </article>
@endsection
