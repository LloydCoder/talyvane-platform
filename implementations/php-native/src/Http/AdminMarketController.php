<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Globalization\MarketPolicyManagementService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminMarketController
{
    public function __construct(
        private AuthenticationService $authentication,
        private MarketPolicyManagementService $markets,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor = $this->superAdmin();
        if ($actor === null) {
            return Response::redirect('/admin/login');
        }
        try {
            $items = $this->markets->list($actor);
            $notice = ($request->query['reviewed'] ?? '') === '1' ? 'Market compliance decision recorded.' : '';
            $html = $this->views->render('admin/markets',[
                'brandName'=>$this->brandName,'items'=>$items,'error'=>$error,'notice'=>$notice,'csrf'=>Csrf::issue(),
            ]);
            return $this->privateHtml($html,$status);
        } catch (DomainException) {
            return $this->privateHtml($this->views->render('admin/markets',[
                'brandName'=>$this->brandName,'items'=>[],'error'=>'Only the verified Super Admin can manage market approvals.','notice'=>'','csrf'=>Csrf::issue(),
            ]),403);
        }
    }

    /** @param array<string,string> $params */
    public function review(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor = $this->superAdmin();
        if ($actor === null) {
            return Response::redirect('/admin/login');
        }
        if ($this->field($request,'review_confirmed') !== '1') {
            return $this->index($request,'Confirm that the evidence is genuine, current and specific to this market.',422);
        }
        try {
            $this->markets->review(
                $actor,(string) ($params['country_code'] ?? ''),
                $this->field($request,'decision'),$this->field($request,'evidence_reference'),
                $this->field($request,'review_summary'),$this->field($request,'expires_at'),
                $this->field($request,'enable_market') === '1',$request->requestId
            );
            return Response::redirect('/admin/markets?reviewed=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'Market decision could not be recorded. Please try again.',503);
        }
    }

    private function superAdmin(): ?Principal
    {
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null || !$principal->hasRole('super_admin')) {
            return null;
        }
        return $principal;
    }

    private function field(Request $request,string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function privateHtml(string $html,int $status): Response
    {
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
