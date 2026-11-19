<?php

/*
|--------------------------------------------------------------------------
| Curated stock photography
|--------------------------------------------------------------------------
|
| These are illustrative editorial images, not companion profile images and
| not a claim that a photograph depicts the destination beside it. Replace
| destination fallbacks with location-specific, cleared photography when an
| operator supplies it.
|
*/

return [
    'hero' => [
        'url' => 'https://images.pexels.com/photos/26729545/pexels-photo-26729545.jpeg?auto=compress&cs=tinysrgb&w=1600',
        'alt' => 'An elegant contemporary hotel lounge with warm architectural lighting',
    ],
    'gallery' => [
        [
            'url' => 'https://images.pexels.com/photos/12389384/pexels-photo-12389384.jpeg?auto=compress&cs=tinysrgb&w=1400',
            'alt' => 'Aerial view of a tropical resort with an infinity pool and ocean',
            'caption' => 'A sense of escape',
            'number' => '01',
        ],
        [
            'url' => 'https://images.pexels.com/photos/6774097/pexels-photo-6774097.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'alt' => 'A tranquil infinity pool overlooking the sea',
            'caption' => 'Space to unwind',
            'number' => '02',
        ],
        [
            'url' => 'https://images.pexels.com/photos/9143471/pexels-photo-9143471.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'alt' => 'An elegant restaurant interior with warm lighting and refined furnishings',
            'caption' => 'The art of detail',
            'number' => '03',
        ],
    ],
    'destination_fallbacks' => [
        'https://images.pexels.com/photos/9164056/pexels-photo-9164056.jpeg?auto=compress&cs=tinysrgb&w=1200',
        'https://images.pexels.com/photos/12389384/pexels-photo-12389384.jpeg?auto=compress&cs=tinysrgb&w=1200',
        'https://images.pexels.com/photos/26729545/pexels-photo-26729545.jpeg?auto=compress&cs=tinysrgb&w=1200',
    ],
];
