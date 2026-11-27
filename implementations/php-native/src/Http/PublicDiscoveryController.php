<?php
declare(strict_types=1);

namespace Talyvane\Http;

use Talyvane\Application\Discovery\DiscoveryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\SEO\PublicSitemapService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class PublicDiscoveryController
{
    public function __construct(private DiscoveryService $discovery, private ViewRenderer $views, private AuthenticationService $authentication, private PublicSitemapService $sitemap) {}

    public function destinations(Request $request): Response
    {
        return $this->render(['mode'=>'destinations','destinations'=>$this->discovery->destinations()]);
    }

    public function companions(Request $request): Response
    {
        return $this->render([
            'mode'=>'companions',
            'profiles'=>$this->discovery->profiles(null),
        ]);
    }

    /** @param array<string,string> $params */
    public function companion(Request $request,array $params): Response
    {
        return $this->profile($request,$params);
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
        SessionManager::start();
        $profile = $this->discovery->profile($params['slug'] ?? '');
        if ($profile === null) {
            return Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Not found</title><h1>This profile is not available.</h1><a href="/destinations">Explore destinations</a></html>',404);
        }
        $principal = $this->authentication->currentPrincipal();
        $canSaveFavorite = $principal instanceof Principal && $principal->hasRole('client') && count($principal->roles) === 1;
        $html = $this->views->render('public/discovery',[
            'mode'=>'profile','profile'=>$profile,'canSaveFavorite'=>$canSaveFavorite,
            'csrf'=>$canSaveFavorite ? Csrf::issue() : '','brandName'=>'Talyvane',
            'canonicalUrl'=>$this->sitemap->canonicalUrl('/profiles/' . (string) $profile['slug']),
        ]);
        if ($canSaveFavorite) {
            $headers = Response::html($html)->headers;
            $headers['cache-control'] = 'no-store, no-cache, must-revalidate, private';
            $headers['pragma'] = 'no-cache';
            return new Response($html,200,$headers);
        }
        return Response::html($html);
    }

    /** @param array<string,mixed> $data */
    private function render(array $data): Response
    {
        $mode = (string) ($data['mode'] ?? 'destinations');
        $canonicalPath = match ($mode) {
            'companions' => '/companions',
            'destination' => '/destinations/' . (string) ($data['destination']['slug'] ?? ''),
            default => '/destinations',
        };
        $html = $this->views->render('public/discovery',$data + [
            'brandName'=>'Talyvane',
            'canonicalUrl'=>$this->sitemap->canonicalUrl($canonicalPath),
        ]);
        return Response::html($html);
    }
}
