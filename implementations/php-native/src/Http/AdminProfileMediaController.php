<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Media\ProfileMediaService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminProfileMediaController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ProfileMediaService $media,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200,string $notice=''): Response
    {
        SessionManager::start();
        $actor=$this->reviewer();
        if ($actor===null) { return Response::redirect('/admin/login'); }
        try {
            $items=$this->media->pendingReview($actor);
            $html=$this->views->render('admin/profile-media',[
                'brandName'=>$this->brandName,'items'=>$items,'csrf'=>Csrf::issue(),
                'error'=>$error,'notice'=>$notice,
            ]);
            return new Response($html,$status,[
                'content-type'=>'text/html; charset=utf-8',
                'cache-control'=>'no-store, no-cache, must-revalidate, private',
                'pragma'=>'no-cache','x-content-type-options'=>'nosniff','x-frame-options'=>'DENY',
                'referrer-policy'=>'no-referrer',
                'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
            ]);
        } catch (DomainException) {
            return $this->index($request,'You do not have permission to review profile media.',403);
        }
    }

    /** @param array<string,string> $params */
    public function preview(Request $request,array $params): Response
    {
        SessionManager::start();
        $actor=$this->reviewer();
        if ($actor===null) { return Response::redirect('/admin/login'); }
        try {
            $image=$this->media->preview($actor,(string) ($params['id'] ?? ''));
            return new Response($image['bytes'],200,[
                'content-type'=>'image/webp','content-disposition'=>'inline; filename="pending-profile-image.webp"',
                'cache-control'=>'private, no-store, max-age=0','x-content-type-options'=>'nosniff',
                'referrer-policy'=>'no-referrer','content-security-policy'=>"default-src 'none'; sandbox",
            ]);
        } catch (DomainException) {
            return Response::html('Not found',404);
        }
    }

    /** @param array<string,string> $params */
    public function review(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor=$this->reviewer();
        if ($actor===null) { return Response::redirect('/admin/login'); }
        try {
            $this->media->review(
                $actor,(string) ($params['id'] ?? ''),$this->field($request,'decision'),
                $this->field($request,'reason'),$request->requestId
            );
            return Response::redirect('/admin/profile-media?reviewed=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'Media review could not be completed. Please retry.',503);
        }
    }

    private function reviewer(): ?Principal
    {
        $principal=$this->authentication->currentPrincipal();
        if ($principal===null || !$principal->mfaVerified ||
            (! $principal->hasRole('admin') && ! $principal->hasRole('owner') && ! $principal->hasRole('super_admin'))) {
            return null;
        }
        return $principal;
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name] ?? '';
        return is_string($value)?trim($value):'';
    }
}
