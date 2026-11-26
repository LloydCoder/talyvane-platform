<?php
declare(strict_types=1);

namespace Talyvane\Http;

use Talyvane\Application\Destinations\DestinationCoverService;

/** Public delivery exposes only the exact independently reviewed active cover. */
final class PublicDestinationCoverController
{
    public function __construct(private DestinationCoverService $covers) {}

    /** @param array<string,string> $params */
    public function show(Request $request,array $params): Response
    {
        $slug=(string)($params['slug']??'');
        if (!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D',$slug)) return Response::html('Not Found',404);
        $cover=$this->covers->publicCover($slug);
        if ($cover===null) return Response::html('Not Found',404);
        return new Response($cover['bytes'],200,[
            'content-type'=>$cover['mime'],
            'content-length'=>(string)strlen($cover['bytes']),
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache',
            'x-content-type-options'=>'nosniff',
            'content-disposition'=>'inline',
            'cross-origin-resource-policy'=>'same-origin',
        ]);
    }
}
