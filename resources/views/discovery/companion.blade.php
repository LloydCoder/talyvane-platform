@extends('discovery.layout')
@section('title', $profile->display_name)
@section('content')
    <article class="profile-detail">
        <p class="eyebrow">VERIFIED ADULT PROFILE / {{ $profile->destination->name }}</p>
        <h1>{{ $profile->display_name }}<br><em>by Talyvane.</em></h1>
        <p class="profile-summary">{{ $profile->summary }}</p>
        @if (!empty($profile->languages))
            <div class="profile-languages">Languages: {{ implode(' · ', $profile->languages) }}</div>
        @endif
        <p class="profile-disclaimer">This profile is displayed following age-status verification and recorded publication consent. Availability and any engagement are subject to direct confirmation and applicable local law.</p>
        <a class="text-link" href="{{ route('companions.index', ['destination' => $profile->destination->slug]) }}">← Back to profiles</a>
    </article>
@endsection
