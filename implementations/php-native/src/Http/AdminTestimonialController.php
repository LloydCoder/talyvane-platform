<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use PDOException;
use Talyvane\Application\Content\EditorialContentService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** MFA-gated testimonial publishing with a required private consent reference. */
final class AdminTestimonialController
{
    public function __construct(
        private AuthenticationService $authentication,
        private EditorialContentService $editorial,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        return $this->page('list',['testimonials'=>$this->editorial->adminTestimonials()]);
    }

    public function create(Request $request): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        return $this->page('edit',['testimonial'=>[
            'id'=>'','display_name'=>'','quote_text'=>'','locale'=>'en','consent_reference'=>'',
            'sort_order'=>0,'status'=>'draft','version'=>1,'published_version'=>null,
        ]]);
    }

    /** @param array<string,string> $params */
    public function edit(Request $request,array $params): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        $item=$this->editorial->testimonial((string)($params['id']??''));
        return is_array($item)?$this->page('edit',['testimonial'=>$item]):Response::html('Not Found',404);
    }

    /** @param array<string,string>|null $params */
    public function save(Request $request,?array $params=null): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->error($params,'Your session expired. Refresh and try again.',419);
        $id=isset($params['id'])?(string)$params['id']:null;
        try {
            $saved=$this->editorial->saveTestimonial(
                $principal,$id,$this->field($request,'display_name'),$this->field($request,'quote_text'),
                $this->field($request,'locale'),$this->field($request,'consent_reference'),
                (int)$this->field($request,'sort_order'),$this->field($request,'reason'),$request->requestId
            );
            return Response::redirect('/admin/testimonials/'.rawurlencode($saved));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->error($params,$error->getMessage(),422,$request);
        } catch (PDOException) {
            return $this->error($params,'The testimonial could not be saved. Refresh and check the existing record.',409,$request);
        }
    }

    /** @param array<string,string> $params */
    public function publish(Request $request,array $params): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->error($params,'Your session expired. Refresh and try again.',419,$request);
        $id=(string)($params['id']??'');
        try {
            $this->editorial->publishTestimonial($principal,$id,$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/testimonials/'.rawurlencode($id));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->error($params,$error->getMessage(),422,$request);
        } catch (PDOException) {
            return $this->error($params,'The testimonial could not be published. Refresh and try again.',409,$request);
        }
    }

    private function principal(): Principal|Response
    {
        SessionManager::start();
        $principal=$this->authentication->currentPrincipal();
        if ($principal===null) return Response::redirect('/admin/login');
        if (!Authorization::allows($principal,'site.content.manage')) return Response::html('Forbidden',403);
        return $principal;
    }

    /** @param array<string,string>|null $params */
    private function error(?array $params,string $message,int $status,?Request $request=null): Response
    {
        $id=is_array($params)?(string)($params['id']??''):'';
        $item=$id!==''?$this->editorial->testimonial($id):null;
        $item=$item??[
            'id'=>$id,'display_name'=>'','quote_text'=>'','locale'=>'en','consent_reference'=>'',
            'sort_order'=>0,'status'=>'draft','version'=>1,'published_version'=>null,
        ];
        if ($request!==null) {
            foreach (['display_name','quote_text','locale','consent_reference','sort_order'] as $key) {
                if (array_key_exists($key,$request->body)) $item[$key]=$this->field($request,$key);
            }
        }
        return $this->page('edit',['testimonial'=>$item,'error'=>$message],$status);
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?$value:(is_int($value)||is_float($value)?(string)$value:'');
    }

    /** @param array<string,mixed> $data */
    private function page(string $mode,array $data=[],int $status=200): Response
    {
        $payload=$data+[
            'mode'=>$mode,'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),'error'=>'',
            'testimonials'=>[],'testimonial'=>[],
        ];
        return new Response($this->views->render('admin/testimonials',$payload),$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','expires'=>'0','x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY','referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
