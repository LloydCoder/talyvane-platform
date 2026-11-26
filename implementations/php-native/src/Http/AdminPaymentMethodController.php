<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Payments\PaymentMethodManagementService;
use Talyvane\Features\FeatureAvailability;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminPaymentMethodController
{
    public function __construct(
        private AuthenticationService $authentication,
        private PaymentMethodManagementService $methods,
        private FeatureAvailability $featureAvailability,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',string $notice='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified) return Response::html('Forbidden',403);
        if(!$this->featureAvailability->enabled('payments.configuration')) return Response::html('Payment configuration is not operationally enabled.',403);
        return $this->page($actor,$error,$notice,$status);
    }

    public function create(Request $request): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified || !$this->featureAvailability->enabled('payments.configuration')) return Response::html('Forbidden',403);
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page($actor,'Your session expired. Refresh and try again.','',419);
        try {
            $contract=$this->field($request,'token_contract');
            $this->methods->create($actor,$this->field($request,'asset'),$this->field($request,'network'),
                $this->field($request,'address'),$contract===''?null:$contract,
                $this->field($request,'purpose'),$request->requestId);
            return $this->page($actor,'','Receiving method saved disabled. Obtain separate provider and legal approval before review.');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($actor,$error->getMessage(),'',422);
        } catch(\Throwable) {
            return $this->page($actor,'Receiving method could not be saved. Please retry.','',503);
        }
    }

    /** @param array<string,string> $params */
    public function review(Request $request,array $params): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified || !$this->featureAvailability->enabled('payments.configuration')) return Response::html('Forbidden',403);
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page($actor,'Your session expired. Refresh and try again.','',419);
        try {
            $this->methods->review($actor,(string)($params['id']??''),$this->field($request,'provider_reference'),
                $this->field($request,'legal_reference'),$request->requestId);
            return $this->page($actor,'','Receiving-method evidence reviewed. The method remains disabled until all operational gates pass.');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($actor,$error->getMessage(),'',422);
        } catch(\Throwable) {
            return $this->page($actor,'Receiving method review could not be recorded. Please retry.','',503);
        }
    }

    /** @param array<string,string> $params */
    public function toggle(Request $request,array $params): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified || !$this->featureAvailability->enabled('payments.configuration')) return Response::html('Forbidden',403);
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page($actor,'Your session expired. Refresh and try again.','',419);
        $enabled=$this->field($request,'enabled');
        if(!in_array($enabled,['0','1'],true)) return $this->page($actor,'Choose a valid receiving-method state.','',422);
        try {
            $this->methods->toggle($actor,(string)($params['id']??''),$enabled==='1',$request->requestId);
            return $this->page($actor,'','Receiving-method state updated.');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($actor,$error->getMessage(),'',422);
        } catch(\Throwable) {
            return $this->page($actor,'Receiving-method state could not be updated. Please retry.','',503);
        }
    }

    private function actor(): ?Principal
    {
        return $this->authentication->currentPrincipal();
    }

    private function page(Principal $actor,string $error,string $notice,int $status): Response
    {
        $body=$this->views->render('admin/payment-methods',[
            'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),'error'=>$error,'notice'=>$notice,
            'methods'=>$this->methods->list($actor),
        ]);
        return new Response($body,$status,[
            'content-type'=>'text/html; charset=utf-8','cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','expires'=>'0','x-content-type-options'=>'nosniff','x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin','permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }
}
