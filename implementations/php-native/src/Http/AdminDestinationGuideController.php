<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Talyvane\Application\Content\EditorialContentService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** MFA-gated destination guide authoring and publication. */
final class AdminDestinationGuideController
{
    public function __construct(
        private PDO $pdo,
        private AuthenticationService $authentication,
        private EditorialContentService $editorial,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        return $this->page('list',['guides'=>$this->editorial->adminDestinationGuides()]);
    }

    public function create(Request $request): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        return $this->page('edit',['guide'=>[
            'id'=>'','destination_id'=>'','title'=>'','content'=>'','locale'=>'en',
            'meta_title'=>'','meta_description'=>'','status'=>'draft','version'=>1,'published_version'=>null,
        ]]);
    }

    /** @param array<string,string> $params */
    public function edit(Request $request,array $params): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        $guide=$this->editorial->destinationGuide((string)($params['id']??''));
        return is_array($guide)?$this->page('edit',['guide'=>$guide]):Response::html('Not Found',404);
    }

    /** @param array<string,string>|null $params */
    public function save(Request $request,?array $params=null): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->error($params,'Your session expired. Refresh and try again.',419);
        $id=isset($params['id'])?(string)$params['id']:null;
        $metaTitle=trim($this->field($request,'meta_title'));
        $metaDescription=trim($this->field($request,'meta_description'));
        try {
            $saved=$this->editorial->saveDestinationGuide(
                $principal,$id,$this->field($request,'destination_id'),$this->field($request,'title'),
                $this->field($request,'content'),$this->field($request,'locale'),
                $metaTitle===''?null:$metaTitle,$metaDescription===''?null:$metaDescription,
                $this->field($request,'reason'),$request->requestId
            );
            return Response::redirect('/admin/destination-guides/'.rawurlencode($saved));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->error($params,$error->getMessage(),422,$request);
        } catch (PDOException) {
            return $this->error($params,'The guide could not be saved. Check the destination and locale for an existing guide.',409,$request);
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
            $this->editorial->publishDestinationGuide($principal,$id,$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/destination-guides/'.rawurlencode($id));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->error($params,$error->getMessage(),422,$request);
        } catch (PDOException) {
            return $this->error($params,'The guide could not be published. Refresh and try again.',409,$request);
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
        $guide=$id!==''?$this->editorial->destinationGuide($id):null;
        $guide=$guide??[
            'id'=>$id,'destination_id'=>'','title'=>'','content'=>'','locale'=>'en',
            'meta_title'=>'','meta_description'=>'','status'=>'draft','version'=>1,'published_version'=>null,
        ];
        if ($request!==null) {
            foreach (['destination_id','title','content','locale','meta_title','meta_description'] as $key) {
                if (array_key_exists($key,$request->body)) $guide[$key]=$this->field($request,$key);
            }
        }
        return $this->page('edit',['guide'=>$guide,'error'=>$message],$status);
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?$value:(is_int($value)||is_float($value)?(string)$value:'');
    }

    /** @param array<string,mixed> $data */
    private function page(string $mode,array $data=[],int $status=200): Response
    {
        $destinations=$this->pdo->query('SELECT id,display_name,status FROM destinations ORDER BY display_name LIMIT 500')->fetchAll();
        $payload=$data+[
            'mode'=>$mode,'brandName'=>$this->brandName,'csrf'=>Csrf::issue(),'error'=>'',
            'guides'=>[],'guide'=>[],'destinations'=>$destinations,
        ];
        return new Response($this->views->render('admin/destination-guides',$payload),$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','expires'=>'0','x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY','referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
