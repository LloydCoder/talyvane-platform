@extends('discovery.layout')
@section('title', 'Destinations')
@section('content')
    <section class="discovery-intro"><p class="eyebrow">A WORLD OF POSSIBILITIES</p><h1>Explore our<br><em>destinations.</em></h1><p>Discover the cities currently served by this independent agency.</p></section>
    @php($fallbackCovers = config('stock_photography.destination_fallbacks'))
    <section class="destination-grid" aria-label="Available destinations">
        @forelse ($destinations as $destination)
            @php($hasDestinationImage = filled($destination->image_path))
            <a class="destination-card" href="{{ route('destinations.show', $destination->slug) }}">
                <img class="destination-card-image" src="{{ $hasDestinationImage ? \Illuminate\Support\Facades\Storage::disk('public')->url($destination->image_path) : $fallbackCovers[($loop->index % count($fallbackCovers))] }}" alt="{{ $hasDestinationImage ? 'Cover image selected for '.$destination->city_name : 'Illustrative hospitality photography, not a location-specific image' }}" loading="lazy" decoding="async">
                <span class="destination-card-wash" aria-hidden="true"></span>
                <span class="destination-index">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }} / {{ $destination->country_code }}</span>
                <h2>{{ $destination->city_name }}</h2><p>{{ $destination->country_name }}</p>
                <span class="destination-count">{{ $destination->companion_profiles_count }} published profiles</span>
                @unless ($hasDestinationImage)<span class="destination-image-source">ILLUSTRATIVE IMAGE</span>@endunless
                <span class="destination-arrow">↗</span>
            </a>
        @empty
            <p class="empty-discovery">Destinations will be announced soon.</p>
        @endforelse
    </section>
@endsection
