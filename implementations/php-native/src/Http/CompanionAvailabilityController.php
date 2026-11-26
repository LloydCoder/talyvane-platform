<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Companions\CompanionAvailabilityService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class CompanionAvailabilityController
{
    public function __construct(
        private AuthenticationService $authentication,
        private CompanionAvailabilityService $availability,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor = $this->companion();
        if ($actor === null) {
            return Response::redirect('/portal/login');
        }
        try {
            $profile = $this->availability->profileForCompanion($actor);
            $items = $this->availability->listForCompanion($actor);
            $notice = ($request->query['saved'] ?? '') === '1' ? 'Your availability window has been saved.' : ((($request->query['removed'] ?? '') === '1') ? 'Your availability window has been removed.' : ((($request->query['preference'] ?? '') === 'saved') ? 'Your automatic-booking preference has been updated.' : ''));
            return $this->page($profile,$items,$error,$status,$notice);
        } catch (DomainException) {
            return $this->page(null,[],'You do not have permission to manage this availability.',403);
        }
    }

    public function save(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor = $this->companion();
        if ($actor === null) {
            return Response::redirect('/portal/login');
        }
        $weekday = $this->field($request,'weekday');
        if (!preg_match('/^[0-6]$/D',$weekday)) {
            return $this->index($request,'Choose a valid weekday.',422);
        }
        $profileId = $this->field($request,'profile_id');
        $effectiveUntil = trim($this->field($request,'effective_until'));
        try {
            $this->availability->save(
                $actor,$profileId,(int) $weekday,
                $this->field($request,'local_start'),$this->field($request,'local_end'),
                $this->field($request,'effective_from'),
                $effectiveUntil === '' ? null : $effectiveUntil,
                $request->requestId
            );
            return Response::redirect('/portal/companion/availability?saved=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'Availability could not be saved. Please try again.',503);
        }
    }

    public function automaticBookingPreference(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor=$this->companion();
        if($actor===null) return Response::redirect('/portal/login');
        $enabled=$this->field($request,'enabled');
        if(!in_array($enabled,['0','1'],true)) {
            return $this->index($request,'Choose whether automatic booking should be enabled or disabled.',422);
        }
        try {
            $this->availability->setAutomaticBookingPreference(
                $actor,$this->field($request,'profile_id'),$enabled==='1',$request->requestId
            );
            return Response::redirect('/portal/companion/availability?preference=saved');
        } catch (DomainException|InvalidArgumentException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'Your automatic-booking preference could not be updated. Please try again.',503);
        }
    }

    /** @param array<string,string> $params */
    public function delete(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor = $this->companion();
        if ($actor === null) {
            return Response::redirect('/portal/login');
        }
        try {
            $this->availability->delete(
                $actor,$this->field($request,'profile_id'),
                (string) ($params['id'] ?? ''),$request->requestId
            );
            return Response::redirect('/portal/companion/availability?removed=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'Availability could not be removed. Please try again.',503);
        }
    }

    private function companion(): ?Principal
    {
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null || !$principal->hasRole('companion') || count($principal->roles) !== 1) {
            return null;
        }
        return $principal;
    }

    private function field(Request $request,string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** @param array<string,mixed>|null $profile @param list<array<string,mixed>> $items */
    private function page(?array $profile,array $items,string $error,int $status,string $notice=''): Response
    {
        $html = $this->views->render('portal/availability',[
            'brandName'=>$this->brandName,'profile'=>$profile,'items'=>$items,
            'error'=>$error,'notice'=>$notice,'csrf'=>Csrf::issue(),
        ]);
        return new Response($html,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache',
            'x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
