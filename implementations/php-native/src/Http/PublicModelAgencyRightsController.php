<?php
declare(strict_types=1);

namespace Talyvane\Http;

use Talyvane\Application\ModelAgency\ModelApplicantRightsService;
use Talyvane\Application\ModelAgency\ModelPortfolioService;
use Talyvane\Application\ModelAgency\ModelCastingWorkflowService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class PublicModelAgencyRightsController
{
    public function __construct(private ?ModelApplicantRightsService $rights, private ViewRenderer $views, private string $brandName, private ?ModelPortfolioService $portfolio=null, private ?ModelCastingWorkflowService $casting=null) {}

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
                'portal_access'=>$this->showPortal($id,$request->requestId),
                'correction'=>$this->page('correction'),
                default=>$this->page('confirm',['requestType'=>$context['type']]),
            };
        } catch (\Throwable) {
            return $this->page('verify',['error'=>'The verification details could not be accepted.'],422);
        }
    }

    public function portal(Request $request): Response
    {
        SessionManager::start();
        $id=$this->rightsId();
        if($id===null || $this->rights===null) return Response::redirect('/model-agency/privacy');
        return $this->showPortal($id,$request->requestId);
    }

    public function portalCorrect(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('portal',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if($id===null || $this->rights===null) return Response::redirect('/model-agency/privacy');
        try {
            $this->rights->portalCorrect($id,[
                'applicant_name'=>$this->field($request,'applicant_name'),
                'phone'=>$this->nullable($this->field($request,'phone')),
                'city'=>$this->field($request,'city'),
                'portfolio_url'=>$this->nullable($this->field($request,'portfolio_url')),
                'experience_summary'=>$this->field($request,'experience_summary'),
            ],$request->requestId);
            return $this->showPortal($id,$request->requestId,'Your profile changes have been saved.');
        } catch(\Throwable) {
            return $this->showPortal($id,$request->requestId,'The profile could not be updated. Check the fields and try again.',422);
        }
    }

    public function portalUpload(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('portal',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if($id===null || $this->portfolio===null) return Response::redirect('/model-agency/privacy');
        $upload=$request->files['portfolio_image']??null;
        if($this->field($request,'applicant_attestation')!=='yes') return $this->showPortal($id,$request->requestId,'Confirm the private portfolio rights and consent attestation before uploading.',422);
        if(!is_array($upload)) return $this->showPortal($id,$request->requestId,'Choose a portfolio image before uploading.',422);
        try {
            $assetId=$this->portfolio->storeByVerifiedApplicantPortal($id,$upload,[
                'source_description'=>$this->field($request,'source_description'),
                'consent_scope'=>$this->field($request,'consent_scope'),
            ],true,$request->requestId);
            return $this->showPortal($id,$request->requestId,'Your image was uploaded privately and is awaiting human review.');
        } catch(\Throwable) {
            return $this->showPortal($id,$request->requestId,'The portfolio image could not be uploaded. Check the image and consent details.',422);
        }
    }

    /** @param array<string,string> $params */
    public function portalWithdraw(Request $request,array $params): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('portal',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if($id===null || $this->portfolio===null) return Response::redirect('/model-agency/privacy');
        try {
            $this->portfolio->withdrawByVerifiedApplicantRights($id,(string)($params['id']??''),$request->requestId);
            return $this->showPortal($id,$request->requestId,'Portfolio consent was withdrawn and dependent licences/submissions were invalidated.');
        } catch(\Throwable) {
            return $this->showPortal($id,$request->requestId,'The image could not be withdrawn. Safeguarding/legal-hold review may be required.',422);
        }
    }

    /** @param array<string,string> $params */
    public function portalSubmitCasting(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('portal',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if($id===null || $this->rights===null || $this->casting===null) return Response::redirect('/model-agency/privacy');
        if($this->field($request,'casting_attestation')!=='yes') return $this->showPortal($id,$request->requestId,'Confirm the voluntary casting submission attestation before continuing.',422);
        try {
            $applicationId=$this->rights->portalApplicationId($id);
            $this->casting->submitByApplicant(
                $applicationId,
                $this->field($request,'casting_call_id'),
                $this->field($request,'portfolio_asset_id'),
                $this->field($request,'image_licence_id'),
                $request->requestId
            );
            return $this->showPortal($id,$request->requestId,'Your casting submission was received. The agency will review it privately.');
        } catch(\\InvalidArgumentException|\\DomainException $error) {
            return $this->showPortal($id,$request->requestId,$error->getMessage(),422);
        } catch(\\Throwable) {
            return $this->showPortal($id,$request->requestId,'The casting submission could not be completed securely.',503);
        }
    }

    public function portalPreview(Request $request,array $params): Response
    {
        SessionManager::start();
        $id=$this->rightsId();
        if($id===null || $this->portfolio===null) return Response::redirect('/model-agency/privacy');
        try {
            $image=$this->portfolio->previewForVerifiedApplicantPortal($id,(string)($params['id']??''),$request->requestId);
            return new Response($image['bytes'],200,[
                'content-type'=>$image['mime'],'content-disposition'=>'inline; filename="my-private-portfolio.webp"',
                'cache-control'=>'private, no-store, max-age=0','pragma'=>'no-cache',
                'x-content-type-options'=>'nosniff','referrer-policy'=>'no-referrer',
                'content-security-policy'=>"default-src 'none'; sandbox",
            ]);
        } catch(\Throwable) {
            return Response::html('Not found',404)->withHeaders(['cache-control'=>'private, no-store','x-content-type-options'=>'nosniff']);
        }
    }

    public function portalLogout(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->page('portal',['error'=>'Your session expired. Refresh this page and try again.'],419);
        $id=$this->rightsId();
        if($id!==null && $this->rights!==null) {
            try { $this->rights->logoutPortal($id,$request->requestId); } catch(\Throwable) { /* Session is still cleared locally. */ }
        }
        $this->clearContext();
        return Response::redirect('/model-agency/privacy');
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
        } catch(\Throwable) {
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

    private function showPortal(string $id,string $requestId,string $notice='',int $status=200): Response
    {
        try {
            $record=$this->rights?->portalRead($id,$requestId)??[];
            return $this->page('portal',['record'=>$record,'notice'=>$notice],$status);
        } catch(\Throwable) {
            $this->clearContext();
            return Response::redirect('/model-agency/privacy');
        }
    }

    /** @return array{reference:string,email:string,type:string}|null */
    private function context(): ?array
    {
        $context=$_SESSION['model_rights_context']??null;
        if (!is_array($context) || !is_string($context['reference']??null) || !is_string($context['email']??null) ||
            !is_string($context['type']??null) || !in_array($context['type'],['access','correction','withdrawal','erasure','portal_access'],true)) return null;
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
        SessionManager::rotateAfterAuthentication();
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
        return Response::html($this->views->render('public/model-agency-rights',$payload),$status)->withHeaders([
            'cache-control'=>'private, no-store, max-age=0','pragma'=>'no-cache','x-content-type-options'=>'nosniff',
            'referrer-policy'=>'no-referrer','x-frame-options'=>'DENY',
        ]);
    }
}
