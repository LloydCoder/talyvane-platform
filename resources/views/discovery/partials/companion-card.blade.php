<a class="profile-card profile-card-editorial" href="{{ route('companions.show', $profile->slug) }}">
    <div class="profile-card-portrait">
        @if ($profile->media->isNotEmpty())
            @php($media = $profile->media->first())
            <img src="{{ route('profile-media.show', $media) }}" alt="Approved profile image for {{ $profile->display_name }}" loading="lazy" width="{{ $media->width }}" height="{{ $media->height }}">
        @else
            <span class="profile-monogram" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($profile->display_name), 0, 1)) }}</span>
        @endif
        <span class="profile-card-kicker">PRIVATE INTRODUCTIONS</span>
    </div>
    <div class="profile-card-body">
        <span class="eyebrow">{{ $profile->destination->name }} · VERIFIED ADULT</span>
        <h2>{{ $profile->display_name }}</h2>
        @if ($profile->summary)
            <p>{{ $profile->summary }}</p>
        @endif
        @if (!empty($profile->languages))
            <span class="profile-languages">{{ implode(' · ', $profile->languages) }}</span>
        @endif
        <span class="profile-card-link">Explore profile <span aria-hidden="true">↗</span></span>
    </div>
</a>
