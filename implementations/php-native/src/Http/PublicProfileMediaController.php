<?php
declare(strict_types=1);

namespace Talyvane\Http;

use Talyvane\Application\Media\ProfileMediaService;

final class PublicProfileMediaController
{
    public function __construct(private ProfileMediaService $media) {}

    /** @param array<string,string> $params */
    public function show(Request $request,array $params): Response
    {
        try {
            $image=$this->media->publicMedia((string) ($params['id'] ?? ''));
            return new Response($image['bytes'],200,[
                'content-type'=>'image/webp','content-disposition'=>'inline; filename="profile-image.webp"',
                'cache-control'=>'private, no-store, max-age=0','x-content-type-options'=>'nosniff',
                'referrer-policy'=>'no-referrer','content-security-policy'=>"default-src 'none'; sandbox",
            ]);
        } catch (\Throwable) {
            return Response::html('Not found',404);
        }
    }
}
