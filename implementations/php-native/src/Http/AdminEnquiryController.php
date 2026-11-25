<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\CRM\EnquiryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminEnquiryController
{
    public function __construct(private EnquiryService $enquiries,private AuthenticationService $authentication,private ViewRenderer $views,private string $brandName) {}

    public function index(Request $request): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        return $this->page('',200,false,$principal);
    }

    public function update(Request $request,?array $params = null): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page('Your session expired. Refresh and try again.',419,false,$principal);
        }
        try {
            $this->enquiries->updateStatus(
                $principal,
                (string) ($params['reference'] ?? ''),
                $this->field($request,'status'),
                $this->field($request,'reason'),
                $request->requestId
            );
            return $this->page('',200,true,$principal);
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(),422,false,$principal);
        }
    }

    private function principal(): Principal|Response
    {
        SessionManager::start();
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null) {
            return Response::redirect('/admin/login');
        }
        if (!Authorization::allows($principal,'enquiries.manage',true)) {
            return Response::html('Forbidden',403);
        }
        return $principal;
    }

    private function page(string $error = '',int $status = 200,bool $saved = false,?Principal $principal = null): Response
    {
        if ($principal === null) {
            return Response::html('Forbidden',403);
        }
        $items = $this->enquiries->list($principal,100,0);
        $body = $this->views->render('admin/enquiries',[
            'brandName'=>$this->brandName,
            'csrf'=>Csrf::issue(),
            'error'=>$error,
            'saved'=>$saved,
            'items'=>$items,
        ]);
        return new Response($body,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }

    private function field(Request $request,string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? $value : '';
    }
}
