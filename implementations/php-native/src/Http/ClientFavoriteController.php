<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use Talyvane\Application\Clients\ClientFavoriteService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class ClientFavoriteController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ClientFavoriteService $favorites,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $client = $this->client();
        if ($client === null) {
            return Response::redirect('/portal/login');
        }
        try {
            $items = $this->favorites->list($client);
            $notice = ($request->query['saved'] ?? '') === '1'
                ? 'Profile added to your private shortlist.'
                : ((($request->query['removed'] ?? '') === '1') ? 'Profile removed from your private shortlist.' : '');
            $html = $this->views->render('portal/favorites',[
                'brandName'=>$this->brandName,'items'=>$items,'error'=>$error,'notice'=>$notice,'csrf'=>Csrf::issue(),
            ]);
            return $this->privateHtml($html,$status);
        } catch (DomainException) {
            return $this->privateHtml($this->views->render('portal/favorites',[
                'brandName'=>$this->brandName,'items'=>[],'error'=>'You do not have permission to view this shortlist.','notice'=>'','csrf'=>Csrf::issue(),
            ]),403);
        }
    }

    /** @param array<string,string> $params */
    public function add(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $client = $this->client();
        if ($client === null) {
            return Response::redirect('/portal/login');
        }
        try {
            $this->favorites->add($client,(string) ($params['slug'] ?? ''),$request->requestId);
            return Response::redirect('/portal/favorites?saved=1');
        } catch (DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'The profile could not be saved. Please try again.',503);
        }
    }

    /** @param array<string,string> $params */
    public function remove(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $client = $this->client();
        if ($client === null) {
            return Response::redirect('/portal/login');
        }
        try {
            $this->favorites->remove($client,(string) ($params['slug'] ?? ''),$request->requestId);
            return Response::redirect('/portal/favorites?removed=1');
        } catch (DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'The profile could not be removed. Please try again.',503);
        }
    }

    private function client(): ?Principal
    {
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null || !$principal->hasRole('client') || count($principal->roles) !== 1) {
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
