<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Bookings\BookingSettingsService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminBookingSettingsController
{
    public function __construct(
        private AuthenticationService $authentication,
        private BookingSettingsService $settings,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',string $notice='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$this->canManage($actor)) return Response::html('Forbidden',403);
        return $this->page($actor,$error,$notice,$status);
    }

    public function update(Request $request): Response
    {
        SessionManager::start();
        $actor=$this->actor();
        if($actor===null) return Response::redirect('/admin/login');
        if(!$this->canManage($actor)) return Response::html('Forbidden',403);
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page($actor,'Your session expired. Refresh and try again.','',419);
        $mode=$this->field($request,'booking_mode');
        $minimum=$this->field($request,'minimum_notice_hours');
        $advance=$this->field($request,'maximum_advance_days');
        $cutoff=$this->field($request,'cancellation_cutoff_hours');
        if(!in_array($mode,['manual','automatic'],true) ||
            !preg_match('/^\d{1,3}$/D',$minimum) || !preg_match('/^\d{1,3}$/D',$advance) ||
            !preg_match('/^\d{1,3}$/D',$cutoff)) {
            return $this->page($actor,'Enter valid booking mode and numeric policy limits.','',422);
        }
        try {
            $this->settings->update($actor,[
                'booking_mode'=>$mode,
                'auto_confirm_enabled'=>$this->field($request,'auto_confirm_enabled')==='1',
                'auto_requires_verified_deposit'=>$this->field($request,'auto_requires_verified_deposit')==='1',
                'minimum_notice_hours'=>(int)$minimum,
                'maximum_advance_days'=>(int)$advance,
                'client_cancellation_enabled'=>$this->field($request,'client_cancellation_enabled')==='1',
                'cancellation_cutoff_hours'=>(int)$cutoff,
            ],$request->requestId);
            return $this->page($actor,'','Booking policy settings saved and audited.');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($actor,$error->getMessage(),'',422);
        } catch(\Throwable) {
            return $this->page($actor,'Booking settings could not be saved. Please retry.','',503);
        }
    }

    private function actor(): ?Principal
    {
        return $this->authentication->currentPrincipal();
    }

    private function canManage(Principal $actor): bool
    {
        return $actor->mfaVerified &&
            ($actor->hasRole('super_admin')||$actor->hasRole('owner')||$actor->hasRole('admin'));
    }

    private function page(Principal $actor,string $error,string $notice,int $status): Response
    {
        $body=$this->views->render('admin/booking-settings',[
            'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),'error'=>$error,'notice'=>$notice,
            'settings'=>$this->settings->current($actor),
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
