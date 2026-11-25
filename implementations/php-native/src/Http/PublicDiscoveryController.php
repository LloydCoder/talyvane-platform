<?php
declare(strict_types=1);

namespace Talyvane\Http;

use Talyvane\Application\Discovery\DiscoveryService;

final class PublicDiscoveryController
{
    public function __construct(private DiscoveryService $discovery, private ViewRenderer $views) {}

    public function destinations(Request $request): Response
    {
        return $this->render(['mode'=>'destinations','destinations'=>$this->discovery->destinations()]);
    }

    /** @param array<string,string> $params */
    public function destination(Request $request, array $params): Response
    {
        $slug = $params['slug'] ?? '';
        $destinations = $this->discovery->destinations();
        $destination = null;
        foreach ($destinations as $item) {
            if (hash_equals((string) $item['slug'],(string) $slug)) { $destination = $item; break; }
        }
        if ($destination === null) {
            return Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Not found</title><h1>This destination is not available.</h1><a href="/destinations">Explore destinations</a></html>',404);
        }
        return $this->render([
            'mode'=>'destination',
            'destination'=>$destination,
            'profiles'=>$this->discovery->profiles((string) $destination['slug']),
        ]);
    }

    /** @param array<string,string> $params */
    public function profile(Request $request, array $params): Response
    {
        $profile = $this->discovery->profile($params['slug'] ?? '');
        if ($profile === null) {
            return Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Not found</title><h1>This profile is not available.</h1><a href="/destinations">Explore destinations</a></html>',404);
        }
        return $this->render(['mode'=>'profile','profile'=>$profile]);
    }

    /** @param array<string,mixed> $data */
    private function render(array $data): Response
    {
        $html = $this->views->render('public/discovery',$data + ['brandName'=>'Talyvane']);
        return Response::html($html);
    }
}
