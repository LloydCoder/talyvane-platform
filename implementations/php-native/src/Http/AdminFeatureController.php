<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Features\FeatureManagementService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminFeatureController
{
    public function __construct(
        private AuthenticationService $authentication,
        private FeatureManagementService $features,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',string $notice='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified) return Response::html('Forbidden',403);
        return $this->page($actor,$error,$notice,$status);
    }

    /** @param array<string,string> $params */
    public function update(Request $request,array $params): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified) return Response::html('Forbidden',403);
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page($actor,'Your session expired. Refresh this page and try again.','',419);
        }
        $enabled=$this->field($request,'enabled');
        if(!in_array($enabled,['0','1'],true)) {
            return $this->page($actor,'Choose a valid feature state.','',422);
        }
        try {
            $outcome=$this->features->change(
                $actor,(string)($params['key']??''),$enabled==='1',
                $this->field($request,'reason'),$this->field($request,'password'),
                $this->field($request,'code'),$request->requestId
            );
            if($outcome['blockers']!==[]) {
                return $this->page($actor,implode(' ',$outcome['blockers']),'',409);
            }
            return $this->page($actor,'',$outcome['changed']?'Feature state changed and audited.':'Feature was already in the requested state.',200);
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($actor,$error->getMessage(),'',422);
        } catch(\Throwable) {
            return $this->page($actor,'Feature state could not be changed. Please retry.','',503);
        }
    }

    private function actor(): ?Principal
    {
        return $this->authentication->currentPrincipal();
    }

    private function page(Principal $actor,string $error,string $notice,int $status): Response
    {
        $body=$this->views->render('admin/features',[
            'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),
            'error'=>$error,'notice'=>$notice,'features'=>$this->features->catalog(),
            'actorId'=>$actor->id,
        ]);
        return new Response($body,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','expires'=>'0',
            'x-content-type-options'=>'nosniff','x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?($name==='password'?$value:trim($value)):'';
    }
}
