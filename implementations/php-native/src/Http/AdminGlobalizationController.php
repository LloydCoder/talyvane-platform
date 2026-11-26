<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Globalization\GlobalizationSettingsService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminGlobalizationController
{
    public function __construct(
        private AuthenticationService $authentication,
        private GlobalizationSettingsService $globalization,
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

    public function updateDefaults(Request $request): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified) return Response::html('Forbidden',403);
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page($actor,'Your session expired. Refresh and try again.','',419);
        $currency=$this->field($request,'base_currency_code');
        try {
            $this->globalization->updateDefaults(
                $actor,$currency===''?null:$currency,$this->field($request,'default_locale'),
                $this->field($request,'default_timezone'),$this->field($request,'reason'),$request->requestId
            );
            return $this->page($actor,'','Deployment globalization defaults saved.');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($actor,$error->getMessage(),'',422);
        } catch(\Throwable) {
            return $this->page($actor,'Globalization defaults could not be saved. Please retry.','',503);
        }
    }

    /** @param array<string,string> $params */
    public function toggleCurrency(Request $request,array $params): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified) return Response::html('Forbidden',403);
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page($actor,'Your session expired. Refresh and try again.','',419);
        $enabled=$this->field($request,'enabled');
        if(!in_array($enabled,['0','1'],true)) return $this->page($actor,'Choose a valid currency state.','',422);
        try {
            $this->globalization->toggleCurrency($actor,(string)($params['code']??''),$enabled==='1',
                $this->field($request,'reason'),$request->requestId);
            return $this->page($actor,'','Currency availability updated.');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($actor,$error->getMessage(),'',$error instanceof DomainException?409:422);
        } catch(\Throwable) {
            return $this->page($actor,'Currency availability could not be updated. Please retry.','',503);
        }
    }

    private function actor(): ?Principal
    {
        return $this->authentication->currentPrincipal();
    }

    private function page(Principal $actor,string $error,string $notice,int $status): Response
    {
        $data=$this->globalization->catalog($actor);
        $body=$this->views->render('admin/globalization',[
            'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),'error'=>$error,'notice'=>$notice,
            'settings'=>$data['settings'],'currencies'=>$data['currencies'],
        ]);
        return new Response($body,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','expires'=>'0','x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY','referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }
}
