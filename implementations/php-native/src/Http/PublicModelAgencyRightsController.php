<?php
declare(strict_types=1);

namespace Talyvane\Http;

use Talyvane\Application\ModelAgency\ModelApplicantRightsService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class PublicModelAgencyRightsController
{
    public function __construct(private ?ModelApplicantRightsService $rights, private ViewRenderer $views, private string $brandName, private ?ModelPortfolioService $portfolio=null) {}

    public function requestForm(Request $request): Response
    {
        SessionManager::start();
        return $this->page('request');
    }

    public function request(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->page('request',['error'=>'Your session expired. Refresh this page and try again.'],419);
        if ($this->rights===null) return $this->page('request',['error'=>'Applicant privacy requests are temporarily unavailable.'],503);
        $reference=strtoupper(trim($this->field($request,'reference')));
        $email=strtolower(trim($this->field($request,'email')));
        $type=$this->field($request,'request_type');
        $assetId=trim($this->field($request,'asset_id'));
        $assetId=$assetId===''?null:$assetId;
        try {
            $this->rights->request($reference,$email,$type,$request->ipAddress,$request->requestId,$assetId);
            $_SESSION['model_rights_context']=['reference'=>$reference,'email'=>$email,'type'=>$type];
            unset($_SESSION['model_rights_id']);
            return Response::redirect('/model-agency/privacy/verify');
        } catch (\Throwable) {
            // Do not reveal whether the application reference and email matched.
            $_SESSION['model_rights_context']=['reference'=>$reference,'email'=>$email,'type'=>$type];
            return Response::redirect('/model-agency/privacy/verify');
        }
    }

    public function verifyForm(Request $request): Response
    {
        SessionManager::start();
        if (!is_array($_SESSION['model_rights_context']??null)) return Response::redirect('/model-agency/privacy');
        return $this->page('verify');
    }

    public function verify(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->page('verify',['error'=>'Your session expired. Refresh this page and try again.'],419);
        if ($this->rights===null) return $this->page('verify',['error'=>'Applicant privacy requests are temporarily unavailable.'],503);
        $context=$this->context();
        if ($context===null) return Response::redirect('/model-agency/privacy');
        try {
            $id=$this->rights->verify($context['reference'],$context['email'],$this->field($request,'code'),$request->ipAddress,$request->requestId);
            if (!is_string($id)) return $this->page('verify',['error'=>'The verification details could not be accepted.'],422);
            $_SESSION['model_rights_id']=$id;
            SessionManager::rotateAfterAuthentication();
            return match ($context['type']) {
                'access'=>$this->showAccess($id,$request->requestId),
                'correction'=>$this->page('correction'),
                default=>$this->page('confirm',['requestType'=>$context['type']]),
            };
        } catch (\Throwable) {
            return $this->page('verify',['error'=>'The verification details could not be accepted.'],422);
        }
    }

    public function correct(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->page('correction',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if ($id===null || $this->rights===null) return Response::redirect('/model-agency/privacy');
        try {
            $this->rights->correct($id,[
                'applicant_name'=>$this->field($request,'applicant_name'),
                'phone'=>$this->nullable($this->field($request,'phone')),
                'city'=>$this->field($request,'city'),
                'portfolio_url'=>$this->nullable($this->field($request,'portfolio_url')),
                'experience_summary'=>$this->field($request,'experience_summary'),
            ],$request->requestId);
            $this->clearContext();
            return $this->page('complete',['notice'=>'Your verified correction request has been completed.']);
        } catch (\Throwable) {
            return $this->page('correction',['error'=>'The correction could not be completed. Check the fields and try again.'],422);
        }
    }

    public function withdraw(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->page('confirm',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if ($id===null || $this->rights===null) return Response::redirect('/model-agency/privacy');
        try {
            $this->rights->withdraw($id,$request->requestId);
            $this->clearContext();
            return $this->page('complete',['notice'=>'Your application withdrawal has been recorded.']);
        } catch (\Throwable) {
            return $this->page('confirm',['error'=>'The withdrawal request could not be completed.'],422);
        }
    }

    public function withdrawPortfolio(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->page('confirm',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if($id===null || $this->rights===null || $this->portfolio===null) return Response::redirect('/model-agency/privacy');
        try {
            $this->portfolio->withdrawByVerifiedApplicantRights($id,$this->field($request,'asset_id'),$request->requestId);
            $this->clearContext();
            return $this->page('complete',['notice'=>'Your portfolio asset withdrawal has been recorded. The private image and dependent usage permissions have been withdrawn.']);
        } catch(\\Throwable) {
            return $this->page('confirm',['error'=>'The portfolio asset could not be withdrawn. The request may have expired or require safeguarding review.'],422);
        }
    }

    public function erase(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) return $this->page('confirm',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if ($id===null || $this->rights===null) return Response::redirect('/model-agency/privacy');
        try {
            $erased=$this->rights->erase($id,$request->requestId);
            $this->clearContext();
            return $this->page('complete',['notice'=>$erased?'Your personal application details have been anonymized.':'Erasure is deferred because a documented legal hold applies.']);
        } catch (\Throwable) {
            return $this->page('confirm',['error'=>'The erasure request could not be completed.'],422);
        }
    }

    /** @param array<string,mixed> $data */
    private function showAccess(string $id,string $requestId): Response
    {
        try {
            $data=$this->rights?->read($id,$requestId)??[];
            $this->rights?->finish($id);
            $this->clearContext();
            return $this->page('access',['record'=>$data]);
        } catch (\Throwable) {
            return $this->page('verify',['error'=>'The verified access session expired. Submit a new request.'],422);
        }
    }

    /** @return array{reference:string,email:string,type:string}|null */
    private function context(): ?array
    {
        $context=$_SESSION['model_rights_context']??null;
        if (!is_array($context) || !is_string($context['reference']??null) || !is_string($context['email']??null) ||
            !is_string($context['type']??null) || !in_array($context['type'],['access','correction','withdrawal','erasure'],true)) return null;
        return ['reference'=>$context['reference'],'email'=>$context['email'],'type'=>$context['type']];
    }

    private function rightsId(): ?string
    {
        $id=$_SESSION['model_rights_id']??null;
        return is_string($id)&&preg_match('/^[a-f0-9-]{36}$/iD',$id) ? $id : null;
    }

    private function clearContext(): void
    {
        unset($_SESSION['model_rights_id'],$_SESSION['model_rights_context']);
        Csrf::rotate();
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?$value:'';
    }

    private function nullable(string $value): ?string
    {
        $value=trim($value);
        return $value===''?null:$value;
    }

    /** @param array<string,mixed> $data */
    private function page(string $screen,array $data=[],int $status=200): Response
    {
        $payload=$data+['screen'=>$screen,'csrf'=>Csrf::issue(),'error'=>'','notice'=>'','requestType'=>'','record'=>[],'brandName'=>$this->brandName];
        return Response::html($this->views->render('public/model-agency-rights',$payload),$status);
    }
}
