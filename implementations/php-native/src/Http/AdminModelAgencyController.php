<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\ModelAgency\ModelAgencyApplicationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class AdminModelAgencyController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ModelAgencyApplicationService $applications,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if ($actor===null) return Response::redirect('/admin/login');
        try {
            return $this->page('applications',$this->applications->pendingForReview($actor),$error,$status);
        } catch (DomainException) {
            return $this->page('applications',[],'You do not have permission to manage model-agency applications.',403);
        }
    }

    public function verificationQueue(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if ($actor===null) return Response::redirect('/admin/login');
        try {
            return $this->page('verifications',$this->applications->verificationQueue($actor),$error,$status);
        } catch (DomainException) {
            return $this->page('verifications',[],'You do not have permission to verify adult eligibility.',403);
        }
    }

    /** @param array<string,string> $params */
    public function review(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if ($actor===null) return Response::redirect('/admin/login');
        try {
            $this->applications->review($actor,(string)($params['id']??''),$this->field($request,'status'),$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/model-agency');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        }
    }

    /** @param array<string,string> $params */
    public function verify(Request $request,array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->verificationQueue($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if ($actor===null) return Response::redirect('/admin/login');
        try {
            $this->applications->verifyAdultEligibility(
                $actor,(string)($params['id']??''),$this->field($request,'method'),
                $this->field($request,'provider_reference'),$this->field($request,'outcome'),
                (int)$this->field($request,'validity_days'),$request->requestId
            );
            return Response::redirect('/admin/model-agency/verifications');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->verificationQueue($request,$error->getMessage(),422);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?$value:'';
    }

    /** @param list<array<string,mixed>> $items */
    private function page(string $mode,array $items,string $error,int $status): Response
    {
        return Response::html($this->views->render('admin/model-agency',[
            'mode'=>$mode,'items'=>$items,'error'=>$error,'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
        ]),$status);
    }
}
