<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use Talyvane\Application\Media\ProfileMediaService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class ClientPrivateGalleryController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ProfileMediaService $media,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request): Response
    {
        SessionManager::start();
        $client=$this->client();
        if ($client===null) { return Response::redirect('/portal/login'); }
        try {
            $items=$this->media->privateGalleryForClient($client);
            $html=$this->views->render('portal/private-gallery',[
                'brandName'=>$this->brandName,'items'=>$items,'error'=>'',
            ]);
            return $this->privateHtml($html,200);
        } catch (DomainException) {
            $html=$this->views->render('portal/private-gallery',[
                'brandName'=>$this->brandName,'items'=>[],'error'=>'An active membership with the private-gallery entitlement is required.',
            ]);
            return $this->privateHtml($html,403);
        }
    }

    /** @param array<string,string> $params */
    public function show(Request $request,array $params): Response
    {
        SessionManager::start();
        $client=$this->client();
        if ($client===null) { return Response::redirect('/portal/login'); }
        try {
            $image=$this->media->privateMediaForClient($client,(string)($params['id']??''));
            return new Response($image['bytes'],200,[
                'content-type'=>'image/webp',
                'content-disposition'=>'inline; filename="members-only-profile-image.webp"',
                'cache-control'=>'private, no-store, max-age=0',
                'pragma'=>'no-cache','x-content-type-options'=>'nosniff',
                'referrer-policy'=>'no-referrer','content-security-policy'=>"default-src 'none'; sandbox",
            ]);
        } catch (DomainException) {
            return Response::html('Not found',404)->withHeaders([
                'cache-control'=>'private, no-store, max-age=0','x-content-type-options'=>'nosniff',
                'referrer-policy'=>'no-referrer',
            ]);
        } catch (\Throwable) {
            return Response::html('Media unavailable',503)->withHeaders([
                'cache-control'=>'private, no-store, max-age=0','x-content-type-options'=>'nosniff',
            ]);
        }
    }

    private function client(): ?Principal
    {
        $principal=$this->authentication->currentPrincipal();
        return $principal!==null && $principal->hasRole('client') && count($principal->roles)===1 ? $principal : null;
    }

    private function privateHtml(string $html,int $status): Response
    {
        return new Response($html,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','x-content-type-options'=>'nosniff','x-frame-options'=>'DENY',
            'referrer-policy'=>'no-referrer','permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
