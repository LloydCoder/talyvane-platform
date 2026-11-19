@extends('discovery.layout')
@section('title', $destination->city_name)
@section('content')
    <section class="discovery-intro"><p class="eyebrow">{{ $destination->country_name }} / {{ $destination->country_code }}</p><h1>{{ $destination->city_name }}<br><em>destination.</em></h1><p>{{ $destination->description }}</p><a class="text-link" href="{{ route('companions.index', ['destination' => $destination->slug]) }}">Explore published profiles ↗</a></section>
    <figure class="destination-feature-image">
        <img src="{{ $destination->image_path ? \Illuminate\Support\Facades\Storage::disk('public')->url($destination->image_path) : config('stock_photography.destination_fallbacks.0') }}" alt="{{ $destination->image_path ? 'Cover image selected for '.$destination->city_name : 'Illustrative luxury hospitality image' }}" decoding="async">
        @unless ($destination->image_path)<figcaption>Illustrative hospitality photography</figcaption>@endunless
    </figure>
    <section class="profile-grid">
        @forelse ($profiles as $profile)
            @include('discovery.partials.companion-card', ['profile' => $profile])
        @empty
            <p class="empty-discovery">No profiles are currently published for this destination.</p>
        @endforelse
    </section>
    {{ $profiles->links() }}
@endsection
