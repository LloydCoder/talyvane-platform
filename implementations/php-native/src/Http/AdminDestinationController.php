<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Destinations\DestinationManagementService;
use Talyvane\Application\Destinations\DestinationCoverService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminDestinationController
{
    public function __construct(
        private AuthenticationService $authentication,
        private DestinationManagementService $destinations,
        private DestinationCoverService $covers,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor = $this->administrator();
        if ($actor === null) {
            return Response::redirect('/admin/login');
        }
        try {
            $items = $this->destinations->list($actor);
            $catalogs = $this->destinations->catalogs($actor);
            $notice = ($request->query['saved'] ?? '') === '1' ? 'Destination changes have been saved.' : '';
            return $this->page($items,$catalogs['countries'],$catalogs['currencies'],$this->covers->pending($actor),$error,$notice,$status);
        } catch (DomainException) {
            return $this->page([],[],[],[],'You do not have permission to manage destinations.','',403);
        }
    }

    /** @param array<string,string> $params */
    public function save(Request $request,array $params=[]): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor = $this->administrator();
        if ($actor === null) {
            return Response::redirect('/admin/login');
        }
        $destinationId = isset($params['id']) && is_string($params['id']) ? $params['id'] : null;
        $input = [];
        foreach (['slug','country_code','city_name','display_name','currency_code','locale','time_zone','status'] as $field) {
            $input[$field] = $this->field($request,$field);
        }
        try {
            $this->destinations->save($actor,$destinationId,$input,$request->requestId);
            return Response::redirect('/admin/destinations?saved=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'Destination could not be saved. Please try again.',503);
        }
    }

    /** @param array<string,string> $params */
    public function uploadCover(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->administrator();
        if ($actor===null) return Response::redirect('/admin/login');
        $upload=$request->files['cover_image']??[];
        if (!is_array($upload)) $upload=[];
        try {
            $this->covers->submit($actor,(string)($params['id']??''),$upload,
                in_array($request->body['rights_confirmed']??null,[true,1,'1','on'],true),$request->requestId);
            return Response::redirect('/admin/destinations?saved=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'Destination cover could not be submitted. Please try again.',503);
        }
    }

    /** @param array<string,string> $params */
    public function reviewCover(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->administrator();
        if ($actor===null) return Response::redirect('/admin/login');
        try {
            $this->covers->review($actor,(string)($params['id']??''),$this->field($request,'decision'),
                $this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/destinations?saved=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch (\Throwable) {
            return $this->index($request,'Destination cover review could not be completed.',503);
        }
    }

    private function administrator(): ?Principal
    {
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null || (!$principal->hasRole('super_admin') && count(array_intersect($principal->roles,['owner','admin'])) !== 1)) {
            return null;
        }
        return $principal;
    }

    private function field(Request $request,string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    /** @param list<array<string,mixed>> $items @param list<array<string,mixed>> $countries @param list<array<string,mixed>> $currencies */
    private function page(array $items,array $countries,array $currencies,array $pendingCovers,string $error,string $notice,int $status): Response
    {
        $html = $this->views->render('admin/destinations',[
            'brandName'=>$this->brandName,'items'=>$items,'countries'=>$countries,
            'currencies'=>$currencies,'pendingCovers'=>$pendingCovers,'error'=>$error,'notice'=>$notice,'csrf'=>Csrf::issue(),
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
