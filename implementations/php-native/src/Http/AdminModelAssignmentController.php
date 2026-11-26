<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\ModelAgency\ModelAssignmentService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class AdminModelAssignmentController
{
    public function __construct(private AuthenticationService $authentication,private ModelAssignmentService $assignments,private ViewRenderer $views,private string $brandName) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();$actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            return $this->page($this->assignments->list($actor),$this->assignments->eligibleSubmissions($actor),$error,$status);
        } catch(DomainException) {
            return $this->page([],[],'You do not have permission to manage private assignments.',403);
        }
    }

    public function create(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh and try again.',419);
        $actor=$this->authentication->currentPrincipal();if($actor===null) return Response::redirect('/admin/login');
        try {
            $terms=[
                'project_purpose'=>$this->field($request,'project_purpose'),
                'client_name'=>$this->field($request,'client_name'),
                'client_reference'=>$this->field($request,'client_reference')===''?null:$this->field($request,'client_reference'),
                'brief'=>$this->field($request,'brief'),
                'location_city'=>$this->field($request,'location_city'),
                'shoot_starts_at'=>$this->utcDate($this->field($request,'shoot_starts_at')),
                'shoot_ends_at'=>$this->utcDate($this->field($request,'shoot_ends_at')),
                'deliverables'=>$this->field($request,'deliverables'),
                'compensation_amount'=>$this->field($request,'compensation_amount')===''?null:$this->field($request,'compensation_amount'),
                'currency_code'=>$this->field($request,'currency_code')===''?null:strtoupper($this->field($request,'currency_code')),
                'expense_cap_amount'=>$this->field($request,'expense_cap_amount')===''?null:$this->field($request,'expense_cap_amount'),
            ];
            $this->assignments->createDraft($actor,$this->field($request,'submission_id'),$terms,$request->requestId);
            return Response::redirect('/admin/model-agency/assignments');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'Assignment draft could not be created securely.',503);
        }
    }

    /** @param array<string,string> $params */
    public function offer(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->offer($actor,$id,$request->requestId));
    }

    /** @param array<string,string> $params */
    public function executionEvidence(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->recordExecutionEvidence(
            $actor,$id,$this->field($request,'executed_document_reference'),$request->requestId));
    }

    /** @param array<string,string> $params */
    public function accept(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->recordAcceptance(
            $actor,$id,$this->field($request,'executed_document_reference'),$this->field($request,'model_consent_evidence_reference'),$request->requestId));
    }

    /** @param array<string,string> $params */
    public function confirm(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->confirm($actor,$id,$request->requestId));
    }

    /** @param array<string,string> $params */
    public function start(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->start($actor,$id,$request->requestId));
    }

    /** @param array<string,string> $params */
    public function complete(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->complete($actor,$id,$this->field($request,'completion_evidence_reference'),$request->requestId));
    }

    /** @param array<string,string> $params */
    public function decline(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->decline(
            $actor,$id,$this->field($request,'reason'),$this->field($request,'model_evidence_reference'),$request->requestId));
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->cancel($actor,$id,$this->field($request,'reason'),$request->requestId));
    }

    /** @param array<string,string> $params */
    public function dispute(Request $request,array $params): Response
    {
        return $this->mutate($request,$params,fn($actor,$id)=>$this->assignments->dispute($actor,$id,$this->field($request,'reason'),$request->requestId));
    }

    private function mutate(Request $request,array $params,callable $operation): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh and try again.',419);
        $actor=$this->authentication->currentPrincipal();if($actor===null) return Response::redirect('/admin/login');
        try {$operation($actor,(string)($params['id']??''));return Response::redirect('/admin/model-agency/assignments');}
        catch(InvalidArgumentException|DomainException $error) {return $this->index($request,$error->getMessage(),422);}
        catch(\Throwable) {return $this->index($request,'Assignment workflow action could not be completed securely.',503);}
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';return is_string($value)?trim($value):'';
    }

    private function utcDate(string $value): string
    {
        if(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D',$value)!==1) throw new InvalidArgumentException('Assignment dates must be entered in UTC.');
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new \DateTimeZone('UTC'));
        if(!$date || $date->format('Y-m-d\TH:i')!==$value) throw new InvalidArgumentException('Assignment date/time is invalid.');
        return $date->format('Y-m-d H:i:s');
    }

    private function page(array $items,array $eligible,string $error,int $status): Response
    {
        return Response::html($this->views->render('admin/model-assignments',[
            'items'=>$items,'eligible'=>$eligible,'error'=>$error,'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
        ]),$status);
    }
}
