<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\ModelAgency\ClientModelAgencyDistributionService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class AdminModelClientDistributionController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ClientModelAgencyDistributionService $distribution,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            return Response::html($this->views->render('admin/model-client-distribution',[
                'clients'=>$this->distribution->eligibleClients($actor),
                'calls'=>$this->distribution->adminCastingCalls($actor),
                'assignments'=>$this->distribution->adminAssignments($actor),
                'error'=>$error,'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
            ]),$status);
        } catch(DomainException) {
            return Response::html($this->views->render('admin/model-client-distribution',[
                'clients'=>[],'calls'=>[],'assignments'=>[],'error'=>'MFA-verified agency access is required.',
                'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
            ]),403);
        }
    }

    /** @param array<string,string> $params */
    public function linkCastingCall(Request $request,array $params): Response
    {
        return $this->mutate($request,fn($actor)=>$this->distribution->linkCastingCall(
            $actor,(string)($params['id']??''),$this->field($request,'client_user_id'),$request->requestId
        ));
    }

    /** @param array<string,string> $params */
    public function linkAssignment(Request $request,array $params): Response
    {
        return $this->mutate($request,fn($actor)=>$this->distribution->linkAssignmentToCastingClient(
            $actor,(string)($params['id']??''),$request->requestId
        ));
    }

    private function mutate(Request $request,callable $operation): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $operation($actor);
            return Response::redirect('/admin/model-agency/distribution');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'Client distribution could not be completed securely.',503);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }
}
