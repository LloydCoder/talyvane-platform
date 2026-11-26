<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\ModelAgency\ModelCastingWorkflowService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class AdminModelCastingController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ModelCastingWorkflowService $casting,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            return $this->page($this->casting->list($actor),$error,$status);
        } catch(DomainException) {
            return $this->page([],'You do not have permission to view private casting operations.',403);
        }
    }

    public function create(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $terms=[
                'title'=>$this->field($request,'title'),
                'client_name'=>$this->field($request,'client_name'),
                'client_reference'=>$this->field($request,'client_reference'),
                'project_purpose'=>$this->field($request,'project_purpose'),
                'description'=>$this->field($request,'description'),
                'requirements'=>$this->field($request,'requirements'),
                'country_code'=>$this->field($request,'country_code'),
                'opens_at'=>$this->utcDate($this->field($request,'opens_at')),
                'closes_at'=>$this->utcDate($this->field($request,'closes_at')),
                'shoot_starts_at'=>$this->utcDate($this->field($request,'shoot_starts_at')),
                'shoot_ends_at'=>$this->utcDate($this->field($request,'shoot_ends_at')),
                'compensation_amount'=>$this->field($request,'compensation_amount')===''?null:$this->field($request,'compensation_amount'),
                'currency_code'=>$this->field($request,'currency_code')===''?null:$this->field($request,'currency_code'),
                'max_submissions'=>(int)$this->field($request,'max_submissions'),
            ];
            $this->casting->createDraft($actor,$terms,$request->requestId);
            return Response::redirect('/admin/model-agency/casting');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        }
    }

    /** @param array<string,string> $params */
    public function publish(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->casting->publish($actor,$id,$request->requestId));
    }

    /** @param array<string,string> $params */
    public function close(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->casting->close($actor,$id,$this->field($request,'reason'),$request->requestId));
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->casting->cancel($actor,$id,$this->field($request,'reason'),$request->requestId));
    }

    private function mutate(Request $request,array $params,callable $operation): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $operation($actor,(string)($params['id']??''));
            return Response::redirect('/admin/model-agency/casting');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }

    private function utcDate(string $value): string
    {
        if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)!==1) {
            throw new InvalidArgumentException('All casting dates must be entered as UTC date/time values.');
        }
        return $value.':00+00:00';
    }

    /** @param list<array<string,mixed>> $items */
    private function page(array $items,string $error,int $status): Response
    {
        return Response::html($this->views->render('admin/model-casting',[
            'items'=>$items,'error'=>$error,'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
        ]),$status);
    }
}
