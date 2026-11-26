<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\ModelAgency\ModelSettlementService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class AdminModelSettlementController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ModelSettlementService $settlements,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $assignments=$this->settlements->assignments($actor);
            $expenses=[];$expenseIdempotencyKeys=[];
            foreach($assignments as $assignment) {
                $expenses[$assignment['id']]=$this->settlements->expenses($actor,(string)$assignment['id']);
                $expenseIdempotencyKeys[$assignment['id']]=bin2hex(random_bytes(16));
            }
            return Response::html($this->views->render('admin/model-settlements',[
                'assignments'=>$assignments,'expenses'=>$expenses,'settlements'=>$this->settlements->list($actor),
                'expenseIdempotencyKeys'=>$expenseIdempotencyKeys,
                'error'=>$error,'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
            ]),$status);
        } catch(DomainException) {
            return Response::html('Forbidden',403);
        }
    }

    public function addExpense(Request $request,array $params): Response
    {
        return $this->mutate($request,function($actor) use($request,$params):void {
            $this->settlements->addExpense($actor,(string)($params['id']??''),$this->field($request,'amount'),
                strtoupper($this->field($request,'currency')),$this->field($request,'description'),
                $this->field($request,'evidence_reference'),$this->field($request,'idempotency_key'));
        });
    }

    public function reviewExpense(Request $request,array $params): Response
    {
        return $this->mutate($request,function($actor) use($request,$params):void {
            $this->settlements->reviewExpense($actor,(string)($params['id']??''),$this->field($request,'decision'),
                $this->field($request,'review_note'),$request->requestId);
        });
    }

    public function createDraft(Request $request,array $params): Response
    {
        return $this->mutate($request,function($actor) use($request,$params):void {
            $this->settlements->createDraft($actor,(string)($params['id']??''),$request->requestId);
        });
    }

    public function approve(Request $request,array $params): Response
    {
        return $this->mutate($request,function($actor) use($request,$params):void {
            $this->settlements->approve($actor,(string)($params['id']??''),$request->requestId);
        });
    }

    public function recordPayment(Request $request,array $params): Response
    {
        return $this->mutate($request,function($actor) use($request,$params):void {
            $this->settlements->recordPayment($actor,(string)($params['id']??''),$this->field($request,'method'),
                $this->field($request,'payment_reference'),$this->field($request,'evidence_reference'),$request->requestId);
        });
    }

    public function reverse(Request $request,array $params): Response
    {
        return $this->mutate($request,function($actor) use($request,$params):void {
            $this->settlements->reverse($actor,(string)($params['id']??''),$this->field($request,'reason'),
                $this->field($request,'evidence_reference'),$request->requestId);
        });
    }

    public function dispute(Request $request,array $params): Response
    {
        return $this->mutate($request,function($actor) use($request,$params):void {
            $this->settlements->dispute($actor,(string)($params['id']??''),$this->field($request,'reason'),
                $this->field($request,'evidence_reference'),$request->requestId);
        });
    }

    private function mutate(Request $request,callable $operation): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $operation($actor);
            return Response::redirect('/admin/model-agency/settlements');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'Settlement ledger action could not be completed securely.',503);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }
}
