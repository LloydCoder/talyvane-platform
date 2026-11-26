<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Payments\PaymentSettingsService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;
use Talyvane\Features\FeatureAvailability;

final class AdminPaymentSettingsController
{
    private const FIELDS=[
        'payments_enabled','crypto_enabled','cards_enabled','manual_enabled',
        'booking_payments_enabled','membership_payments_enabled',
    ];

    public function __construct(
        private AuthenticationService $authentication,
        private PaymentSettingsService $payments,
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

    public function update(Request $request): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$actor->hasOnlyRole('super_admin') || !$actor->mfaVerified) return Response::html('Forbidden',403);
        if(!$this->featureAvailability->enabled('payments.configuration')) return Response::html('Payment configuration is not operationally enabled.',403);
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page($actor,'Your session expired. Refresh and try again.','',419);
        $settings=[];
        foreach(self::FIELDS as $field) {
            $value=$request->body[$field]??null;
            $settings[$field]=in_array($value,[true,1,'1','on','true'],true);
        }
        try {
            $this->payments->update($actor,$settings,$request->requestId);
            return $this->page($actor,'','Payment configuration saved. No checkout, custody or settlement adapter is enabled by these flags alone.');
        } catch(\Throwable $error) {
            if($error instanceof DomainException) return $this->page($actor,$error->getMessage(),'',403);
            return $this->page($actor,'Payment configuration could not be saved. Please retry.','',422);
        }
    }

    private function actor(): ?Principal
    {
        return $this->authentication->currentPrincipal();
    }

    private function page(Principal $actor,string $error,string $notice,int $status): Response
    {
        $body=$this->views->render('admin/payment-settings',[
            'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),'error'=>$error,'notice'=>$notice,
            'settings'=>$this->payments->current($actor),
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
