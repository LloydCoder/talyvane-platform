<?php

/*
|--------------------------------------------------------------------------
| Curated Unsplash photography
|--------------------------------------------------------------------------
|
| Images are illustrative hospitality and destination atmosphere. They are
| not companion profile images and do not claim to depict a named destination.
| Keep source-page provenance in docs/IMAGE-LICENSING.md.
|
*/

return [
    'hero' => [
        'url' => 'https://images.unsplash.com/photo-1776763018821-8feeaeeee0a5?auto=format&fit=crop&w=1600&q=80',
        'alt' => 'A softly lit hotel suite lounge with elegant seating and a writing desk',
    ],
    'gallery' => [
        [
            'url' => 'https://images.unsplash.com/photo-1776761731066-c89caa8d25e6?auto=format&fit=crop&w=1400&q=80',
            'alt' => 'Aerial view of a landscaped luxury resort and swimming pool',
            'caption' => 'A sense of escape',
            'number' => '01',
        ],
        [
            'url' => 'https://images.unsplash.com/photo-1681912609128-3a05a7bdce64?auto=format&fit=crop&w=1200&q=80',
            'alt' => 'A city skyline at night with lights reflecting across the water',
            'caption' => 'Cities after dark',
            'number' => '02',
        ],
        [
            'url' => 'https://images.unsplash.com/photo-1768397003905-a202ea6325f5?auto=format&fit=crop&w=1200&q=80',
            'alt' => 'An elegant private dining room with a round table and refined furnishings',
            'caption' => 'The art of detail',
            'number' => '03',
        ],
    ],
    'destination_fallbacks' => [
        'https://images.unsplash.com/photo-1681912609128-3a05a7bdce64?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1776761731066-c89caa8d25e6?auto=format&fit=crop&w=1200&q=80',
        'https://images.unsplash.com/photo-1776763018821-8feeaeeee0a5?auto=format&fit=crop&w=1200&q=80',
    ],
];
