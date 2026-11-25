<?php
declare(strict_types=1);

namespace Talyvane\Http;

use InvalidArgumentException;
use DomainException;
use Talyvane\Application\CRM\EnquiryService;
use Talyvane\Security\Csrf;

final class PublicEnquiryController
{
    public function __construct(private EnquiryService $enquiries,private ViewRenderer $views,private string $brandName) {}

    public function create(Request $request): Response
    {
        SessionManager::start();
        return $this->render('',(string) ($request->query['sent'] ?? '') === '1');
    }

    public function store(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->render('Your form session expired. Please try again.',false,419);
        }
        try {
            $this->enquiries->submit($request->body,$request->ipAddress,$request->requestId,$request->header('referer'));
            return Response::redirect('/contact?sent=1');
        } catch (InvalidArgumentException $error) {
            return $this->render($error->getMessage(),false,422,$request->body);
        } catch (DomainException $error) {
            return $this->render($error->getMessage(),false,429,$request->body);
        } catch (\Throwable) {
            return $this->render('Your enquiry could not be submitted right now. Please try again later.',false,503,$request->body);
        }
    }

    private function render(string $error = '',bool $sent = false,int $status = 200,array $values = []): Response
    {
        $body = $this->views->render('public/contact',[
            'brandName'=>$this->brandName,
            'csrf'=>Csrf::issue(),
            'error'=>$error,
            'sent'=>$sent,
            'values'=>$values,
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
