<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\ModelAgency\ModelPortfolioService;
use Talyvane\Application\ModelAgency\ModelImageLicenceService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class AdminModelPortfolioController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ModelPortfolioService $portfolio,
        private ModelImageLicenceService $licences,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $licenceDashboard=$this->licences->dashboard($actor);
            return Response::html($this->views->render('admin/model-portfolio',[
                'brandName'=>$this->brandName,'applications'=>$this->portfolio->eligibleApplications($actor),
                'items'=>$this->portfolio->pendingReview($actor),'approvedAssets'=>$licenceDashboard['assets'],
                'agreements'=>$licenceDashboard['agreements'],'licences'=>$licenceDashboard['licences'],
                'csrf'=>Csrf::issue(),'error'=>$error,
            ]),$status);
        } catch(DomainException) {
            return Response::html($this->views->render('admin/model-portfolio',[
                'brandName'=>$this->brandName,'applications'=>[],'items'=>[],'approvedAssets'=>[],'agreements'=>[],'licences'=>[],'csrf'=>Csrf::issue(),
                'error'=>'You do not have permission to manage private model portfolio assets.',
            ]),403);
        }
    }

    public function store(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        $upload=$request->files['media']??null;
        if(!is_array($upload)) return $this->index($request,'Choose a JPEG, PNG or WebP image.',422);
        try {
            if($this->field($request,'confirm_model_consent')!=='1' || $this->field($request,'confirm_image_rights')!=='1') {
                throw new InvalidArgumentException('Confirm that separate model consent and image rights evidence have been recorded.');
            }
            $this->portfolio->store($actor,$this->field($request,'application_id'),$upload,[
                'source_description'=>$this->field($request,'source_description'),
                'consent_scope'=>$this->field($request,'consent_scope'),
                'rights_evidence_reference'=>$this->field($request,'rights_evidence_reference'),
                'consent_evidence_reference'=>$this->field($request,'consent_evidence_reference'),
            ],$request->requestId);
            return Response::redirect('/admin/model-agency/portfolio?submitted=1');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'The private portfolio image could not be stored securely.',503);
        }
    }

    /** @param array<string,string> $params */
    public function preview(Request $request,array $params): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $image=$this->portfolio->preview($actor,(string)($params['id']??''));
            return new Response($image['bytes'],200,[
                'content-type'=>'image/webp','content-disposition'=>'inline; filename="private-model-portfolio.webp"',
                'cache-control'=>'private, no-store, max-age=0','pragma'=>'no-cache',
                'x-content-type-options'=>'nosniff','referrer-policy'=>'no-referrer',
                'content-security-policy'=>"default-src 'none'; sandbox",
            ]);
        } catch(DomainException) {
            return Response::html('Not found',404);
        }
    }

    /** @param array<string,string> $params */
    public function review(Request $request,array $params): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $this->portfolio->review($actor,(string)($params['id']??''),$this->field($request,'decision'),
                $this->field($request,'review_note'),$request->requestId);
            return Response::redirect('/admin/model-agency/portfolio?reviewed=1');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'Portfolio review could not be completed securely.',503);
        }
    }

    /** @param array<string,string> $params */
    public function withdrawConsent(Request $request,array $params): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            if($this->field($request,'confirm_withdrawal')!=='1') throw new InvalidArgumentException('Confirm the model portfolio consent withdrawal.');
            $this->portfolio->withdrawConsent($actor,(string)($params['id']??''),$this->field($request,'reason'),
                $this->field($request,'withdrawal_evidence_reference'),$request->requestId);
            return Response::redirect('/admin/model-agency/portfolio?withdrawn=1');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'Consent withdrawal could not be completed securely.',503);
        }
    }

    public function createLicence(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $channels=$request->body['channels']??null;
            if(!is_array($channels)) throw new InvalidArgumentException('Choose at least one permitted distribution channel.');
            $terms=[
                'licensee_name'=>$this->field($request,'licensee_name'),
                'licensee_reference'=>$this->field($request,'licensee_reference'),
                'purpose'=>$this->field($request,'purpose'),
                'channels'=>$channels,
                'purpose_details'=>$this->field($request,'purpose_details'),
                'country_code'=>$this->field($request,'country_code'),
                'starts_on'=>$this->field($request,'starts_on'),
                'ends_on'=>$this->field($request,'ends_on'),
                'exclusivity'=>$this->field($request,'exclusivity')==='1',
                'compensation_amount'=>$this->field($request,'compensation_amount')===''?null:$this->field($request,'compensation_amount'),
                'currency_code'=>$this->field($request,'currency_code')===''?null:$this->field($request,'currency_code'),
            ];
            $this->licences->createDraft($actor,$this->field($request,'asset_id'),$this->field($request,'agreement_id'),$terms,$request->requestId);
            return Response::redirect('/admin/model-agency/portfolio?licence=draft');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'Image licence draft could not be created securely.',503);
        }
    }

    /** @param array<string,string> $params */
    public function activateLicence(Request $request,array $params): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            if($this->field($request,'confirm_external_execution')!=='1' || $this->field($request,'confirm_model_consent')!=='1') {
                throw new InvalidArgumentException('Confirm separate external execution and model consent evidence.');
            }
            $this->licences->activate($actor,(string)($params['id']??''),$this->field($request,'execution_evidence_reference'),
                $this->field($request,'model_consent_evidence_reference'),$request->requestId);
            return Response::redirect('/admin/model-agency/portfolio?licence=active');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'Image licence activation could not be completed securely.',503);
        }
    }

    /** @param array<string,string> $params */
    public function revokeLicence(Request $request,array $params): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            if($this->field($request,'confirm_revocation')!=='1') throw new InvalidArgumentException('Confirm image licence revocation.');
            $this->licences->revoke($actor,(string)($params['id']??''),$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/model-agency/portfolio?licence=revoked');
        } catch(InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'Image licence revocation could not be completed securely.',503);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }
}
