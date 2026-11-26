<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\PortalPrivacyRightsAdminService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class AdminPrivacyRightsController
{
    public function __construct(
        private AuthenticationService $authentication,
        private PortalPrivacyRightsAdminService $rights,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $notice='',string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $items=$this->rights->queue($actor,$request->requestId);
            return Response::html($this->views->render('admin/privacy-rights',[
                'items'=>$items,'notice'=>$notice,'error'=>$error,
                'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
            ]),$status);
        } catch(DomainException) {
            return Response::html('You do not have permission to review privacy requests.',403);
        }
    }

    public function review(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'','Your session expired. Refresh and try again.',419);
        }
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $this->rights->review($actor,$this->field($request,'request_id'),
                $this->field($request,'decision'),$this->field($request,'reason'),$request->requestId);
            return $this->index($request,'Privacy request review decision recorded. No data was erased or corrected automatically.');
        } catch(DomainException) {
            return $this->index($request,'','The privacy request is no longer awaiting review or you lack permission.',403);
        } catch(\InvalidArgumentException) {
            return $this->index($request,'','Provide a valid review decision and a reason of at least 10 characters.',422);
        } catch(\Throwable) {
            return $this->index($request,'','The privacy review could not be recorded.',503);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?$value:'';
    }
}
