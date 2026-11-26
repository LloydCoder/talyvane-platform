<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\CRM\EnquiryService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class AdminEnquiryController
{
    public function __construct(private EnquiryService $enquiries,private AuthenticationService $authentication,private ViewRenderer $views,private string $brandName) {}

    public function index(Request $request): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        return $this->page('',200,false,$principal,$this->pageNumber($request));
    }

    public function update(Request $request,?array $params = null): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page('Your session expired. Refresh and try again.',419,false,$principal,$this->pageNumber($request));
        }
        try {
            $this->enquiries->updateStatus(
                $principal,
                (string) ($params['reference'] ?? ''),
                $this->field($request,'status'),
                $this->field($request,'reason'),
                $request->requestId
            );
            return $this->page('',200,true,$principal,$this->pageNumber($request));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(),422,false,$principal,$this->pageNumber($request));
        }
    }

    public function assign(Request $request,?array $params = null): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) return $principal;
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page('Your session expired. Refresh and try again.',419,false,$principal,$this->pageNumber($request));
        }
        try {
            $assignee=$this->field($request,'assignee_id');
            $this->enquiries->assign($principal,(string)($params['reference'] ?? ''),$assignee === '' ? null : $assignee,
                $this->field($request,'reason'),$request->requestId);
            return $this->page('',200,true,$principal,$this->pageNumber($request));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(),422,false,$principal,$this->pageNumber($request));
        }
    }

    public function saveResponseTemplate(Request $request): Response
    {
        $principal=$this->principal();
        if($principal instanceof Response) return $principal;
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page('Your session expired. Refresh and try again.',419,false,$principal,$this->pageNumber($request));
        }
        try {
            $version=$this->enquiries->saveResponseTemplate(
                $principal,$this->field($request,'locale'),$this->field($request,'subject_template'),
                $this->field($request,'body_template'),$this->field($request,'reason'),$request->requestId
            );
            return $this->page('',200,true,$principal,$this->pageNumber($request));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(),422,false,$principal,$this->pageNumber($request));
        }
    }

    public function reply(Request $request,?array $params = null): Response
    {
        $principal=$this->principal();
        if($principal instanceof Response) return $principal;
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page('Your session expired. Refresh and try again.',419,false,$principal,$this->pageNumber($request));
        }
        try {
            $queued=$this->enquiries->queueResponse(
                $principal,(string)($params['reference']??''),$this->field($request,'reply_text'),
                $this->field($request,'locale')?:'en',$this->brandName,$request->requestId
            );
            return $this->page('',200,true,$principal,$this->pageNumber($request));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(),422,false,$principal,$this->pageNumber($request));
        }
    }

    public function addNote(Request $request,?array $params = null): Response
    {
        $principal=$this->principal();
        if($principal instanceof Response) return $principal;
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page('Your session expired. Refresh and try again.',419,false,$principal,$this->pageNumber($request));
        }
        try {
            $this->enquiries->addInternalNote($principal,(string)($params['reference']??''),
                $this->field($request,'note'),$request->requestId);
            return $this->page('',200,true,$principal,$this->pageNumber($request));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(),422,false,$principal,$this->pageNumber($request));
        }
    }

    public function scheduleReminder(Request $request,?array $params=null): Response
    {
        $principal=$this->principal();
        if($principal instanceof Response) return $principal;
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('Your session expired. Refresh and try again.',419,false,$principal,$this->pageNumber($request));
        try {
            $this->enquiries->scheduleReminder($principal,(string)($params['reference']??''),$this->field($request,'due_at'),
                $this->field($request,'reason'),$request->requestId);
            return $this->page('',200,true,$principal,$this->pageNumber($request));
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(),422,false,$principal,$this->pageNumber($request));
        }
    }

    public function completeReminder(Request $request,?array $params=null): Response
    {
        $principal=$this->principal();
        if($principal instanceof Response) return $principal;
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('Your session expired. Refresh and try again.',419,false,$principal,$this->pageNumber($request));
        try {
            $this->enquiries->completeReminder($principal,(string)($params['reminder']??''),$this->field($request,'reason'),$request->requestId);
            return $this->page('',200,true,$principal,$this->pageNumber($request));
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(),422,false,$principal,$this->pageNumber($request));
        }
    }

    private function principal(): Principal|Response
    {
        SessionManager::start();
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null) {
            return Response::redirect('/admin/login');
        }
        if (!Authorization::allows($principal,'enquiries.manage',true)) {
            return Response::html('Forbidden',403);
        }
        return $principal;
    }

    private function page(string $error = '',int $status = 200,bool $saved = false,?Principal $principal = null,int $page = 1): Response
    {
        if ($principal === null) {
            return Response::html('Forbidden',403);
        }
        $page = max(1,min(5000,$page));
        $pageSize = 20;
        $totalCount = $this->enquiries->count($principal);
        $totalPages = max(1,(int)ceil($totalCount / $pageSize));
        $page = min($page,$totalPages);
        $items = $this->enquiries->list($principal,$pageSize + 1,($page - 1) * $pageSize);
        $hasNext = count($items) > $pageSize;
        if ($hasNext) { $items = array_slice($items,0,$pageSize); }
        $assignees = $this->enquiries->eligibleAssignees($principal);
        $body = $this->views->render('admin/enquiries',[
            'brandName'=>$this->brandName,
            'csrf'=>Csrf::issue(),
            'error'=>$error,
            'saved'=>$saved,
            'items'=>$items,
            'assignees'=>$assignees,
            'templates'=>$this->enquiries->responseTemplates($principal),
            'currentPage'=>$page,
            'totalPages'=>$totalPages,
            'totalCount'=>$totalCount,
            'hasPrevious'=>$page > 1,
            'hasNext'=>$hasNext,
        ]);
        return new Response($body,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }

    private function pageNumber(Request $request): int
    {
        $value = $request->query['page'] ?? $request->body['page'] ?? '1';
        if (is_int($value)) { return max(1,min(5000,$value)); }
        if (!is_string($value) || !ctype_digit($value)) { return 1; }
        return max(1,min(5000,(int)$value));
    }

    private function field(Request $request,string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? $value : '';
    }
}
