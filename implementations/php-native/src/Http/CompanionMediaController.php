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

final class CompanionMediaController
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
        $actor=$this->companion();
        if ($actor===null) { return Response::redirect('/portal/login'); }
        try {
            return $this->page($actor,$error,$status,$notice);
        } catch (DomainException) {
            return $this->page($actor,'You do not have permission to manage profile media.',403);
        }
    }

    public function submit(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor=$this->companion();
        if ($actor===null) { return Response::redirect('/portal/login'); }
        $upload=$request->files['media'] ?? null;
        if (!is_array($upload)) {
            return $this->index($request,'Choose a JPEG, PNG or WebP image to upload.',422);
        }
        try {
            $profile=$this->media->profileForCompanion($actor);
            if (!is_array($profile)) {
                return $this->index($request,'No companion profile is linked to this account.',403);
            }
            $this->media->submit(
                $actor,(string) $profile['id'],$upload,
                $this->field($request,'rights_confirmed')==='1',
                $this->field($request,'publication_confirmed')==='1',
                $request->requestId
            );
            return Response::redirect('/portal/companion/media?submitted=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'The image could not be submitted securely. Please try again.',503);
        }
    }

    /** @param array<string,string> $params */
    public function withdraw(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor=$this->companion();
        if ($actor===null) { return Response::redirect('/portal/login'); }
        try {
            $profile=$this->media->profileForCompanion($actor);
            if (!is_array($profile)) { return $this->index($request,'No companion profile is linked to this account.',403); }
            $this->media->withdraw($actor,(string) $profile['id'],(string) ($params['id'] ?? ''),$request->requestId);
            return Response::redirect('/portal/companion/media?removed=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'The image could not be removed securely. Please try again.',503);
        }
    }

    private function companion(): ?Principal
    {
        $principal=$this->authentication->currentPrincipal();
        if ($principal===null || !$principal->hasRole('companion') || count($principal->roles)!==1) { return null; }
        return $principal;
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name] ?? '';
        return is_string($value)?trim($value):'';
    }

    private function page(Principal $actor,string $error,int $status,string $notice=''): Response
    {
        $profile=$this->media->profileForCompanion($actor);
        $items=$this->media->listForCompanion($actor);
        if (($notice==='') && (($GLOBALS['__talyvane_media_notice'] ?? '')==='')) {
            $notice='';
        }
        $html=$this->views->render('portal/media',[
            'brandName'=>$this->brandName,'profile'=>$profile,'items'=>$items,
            'csrf'=>Csrf::issue(),'error'=>$error,'notice'=>$notice,
        ]);
        return new Response($html,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','x-content-type-options'=>'nosniff','x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
