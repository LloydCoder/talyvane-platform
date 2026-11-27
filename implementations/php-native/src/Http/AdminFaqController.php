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

/** MFA-gated authoring and explicit publication of locale-scoped FAQs. */
final class AdminFaqController
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
        return $this->page('list',['faqs'=>$this->editorial->adminFaqs()]);
    }

    public function create(Request $request): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        return $this->page('edit',['faq'=>[
            'id'=>'','question'=>'','answer'=>'','locale'=>'en','category_key'=>'general',
            'sort_order'=>0,'status'=>'draft','version'=>1,'published_version'=>null,
        ]]);
    }

    /** @param array<string,string> $params */
    public function edit(Request $request,array $params): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        $faq=$this->editorial->faq((string)($params['id']??''));
        return is_array($faq)?$this->page('edit',['faq'=>$faq]):Response::html('Not Found',404);
    }

    /** @param array<string,string>|null $params */
    public function save(Request $request,?array $params=null): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->error($params,'Your session expired. Refresh and try again.',419);
        $id=isset($params['id'])?(string)$params['id']:null;
        try {
            $saved=$this->editorial->saveFaq(
                $principal,$id,$this->field($request,'question'),$this->field($request,'answer'),
                $this->field($request,'locale'),$this->field($request,'category_key'),
                (int)$this->field($request,'sort_order'),$this->field($request,'reason'),$request->requestId
            );
            return Response::redirect('/admin/faqs/'.rawurlencode($saved));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->error($params,$error->getMessage(),422,$request);
        } catch (PDOException) {
            return $this->error($params,'The FAQ could not be saved. Refresh and check the existing record.',409,$request);
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
            $this->editorial->publishFaq($principal,$id,$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/faqs/'.rawurlencode($id));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->error($params,$error->getMessage(),422,$request);
        } catch (PDOException) {
            return $this->error($params,'The FAQ could not be published. Refresh and try again.',409,$request);
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
        $faq=$id!==''?$this->editorial->faq($id):null;
        $faq=$faq??[
            'id'=>$id,'question'=>'','answer'=>'','locale'=>'en','category_key'=>'general',
            'sort_order'=>0,'status'=>'draft','version'=>1,'published_version'=>null,
        ];
        if ($request!==null) {
            foreach (['question','answer','locale','category_key','sort_order'] as $key) {
                if (array_key_exists($key,$request->body)) $faq[$key]=$this->field($request,$key);
            }
        }
        return $this->page('edit',['faq'=>$faq,'error'=>$message],$status);
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?$value:(is_int($value)||is_float($value)?(string)$value:'');
    }

    /** @param array<string,mixed> $data */
    private function page(string $mode,array $data=[],int $status=200): Response
    {
        $payload=$data+['mode'=>$mode,'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),'error'=>'','faqs'=>[],'faq'=>[]];
        return new Response($this->views->render('admin/faqs',$payload),$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','expires'=>'0','x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY','referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
