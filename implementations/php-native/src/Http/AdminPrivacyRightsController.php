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
            $externalEvidence=[];
            foreach($items as $item) {
                if(($item['request_type']??'')==='erasure') {
                    $externalEvidence[(string)$item['id']]=$this->rights->externalErasureEvidence($actor,(string)$item['id']);
                }
            }
            return Response::html($this->views->render('admin/privacy-rights',[
                'items'=>$items,'notice'=>$notice,'error'=>$error,'externalEvidence'=>$externalEvidence,
                'canAttestExternalEvidence'=>$actor->mfaVerified && ($actor->hasRole('owner')||$actor->hasRole('super_admin')),
                'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,
            ]),$status);
        } catch(DomainException) {
            return Response::html('You do not have permission to review privacy requests.',403);
        }
    }

    public function recordExternalEvidence(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'','Your session expired. Refresh and try again.',419);
        }
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/admin/login');
        try {
            $retention=$this->field($request,'retention_until');
            $this->rights->recordExternalErasureEvidence(
                $actor,$this->field($request,'request_id'),$this->field($request,'system_key'),
                $this->field($request,'decision'),$this->field($request,'evidence_reference'),
                $this->field($request,'legal_basis_reference'),$retention!==''?$retention:null,
                $this->field($request,'reason'),$request->requestId
            );
            return $this->index($request,'Owner-reviewed external evidence recorded as an operator attestation. It is not independent proof of provider deletion.');
        } catch(DomainException $error) {
            return $this->index($request,'',$error->getMessage(),403);
        } catch(\InvalidArgumentException $error) {
            return $this->index($request,'',$error->getMessage(),422);
        } catch(\Throwable) {
            return $this->index($request,'','External erasure evidence could not be recorded.',503);
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
            if ($this->field($request,'decision')==='fulfill_client_correction') {
                $this->rights->fulfillClientCorrection($actor,$this->field($request,'request_id'),
                    $this->field($request,'client_name'),$this->field($request,'reason'),$request->requestId);
                return $this->index($request,'Verified client account name correction applied. Contact email was not changed.');
            }
            if ($this->field($request,'decision')==='fulfill_client_erasure') {
                if ($this->field($request,'confirm_erasure')!=='yes') {
                    throw new \InvalidArgumentException('Explicit erasure confirmation is required.');
                }
                $this->rights->fulfillClientErasure($actor,$this->field($request,'request_id'),
                    $this->field($request,'reason'),$request->requestId);
                return $this->index($request,'Client account anonymized after all automated dependency checks passed. Backup expiry and third-party propagation remain separate obligations.');
            }
            if ($this->field($request,'decision')==='fulfill_companion_erasure') {
                if ($this->field($request,'confirm_erasure')!=='yes') {
                    throw new \InvalidArgumentException('Explicit erasure confirmation is required.');
                }
                $this->rights->fulfillCompanionErasure($actor,$this->field($request,'request_id'),
                    $this->field($request,'reason'),$request->requestId);
                return $this->index($request,'Companion account anonymized and profile withdrawn after all automated dependency checks passed. Backup expiry and third-party propagation remain separate obligations.');
            }
            if ($this->field($request,'decision')==='fulfill_companion_correction') {
                $rawLanguages=$this->field($request,'languages');
                $languages=array_values(array_filter(array_map('trim',explode(',',$rawLanguages)),static fn(string $value):bool=>$value!==''));
                $this->rights->fulfillCompanionCorrection($actor,$this->field($request,'request_id'),
                    $this->field($request,'display_name'),$this->field($request,'biography'),$languages,
                    $this->field($request,'reason'),$request->requestId);
                return $this->index($request,'Verified companion profile correction applied. The profile is now unpublished pending a fresh publication review.');
            }
            $this->rights->review($actor,$this->field($request,'request_id'),
                $this->field($request,'decision'),$this->field($request,'reason'),$request->requestId);
            return $this->index($request,'Privacy request review decision recorded. No data was erased or corrected automatically.');
        } catch(DomainException $error) {
            if (str_contains($error->getMessage(),'external processor evidence')) {
                return $this->index($request,'',
                    'Erasure is locked until an MFA-verified Owner or Super Admin records evidence for every external processor, backup and key-custody gate.',
                    409);
            }
            if (str_contains($error->getMessage(),'notification outbox') ||
                str_contains($error->getMessage(),'verified notification destination')) {
                return $this->index($request,'',
                    'Privacy fulfilment is unavailable because the verified notification channel is not ready. No data was changed; restore the private mail configuration or verify the current account address, then retry.',
                    503);
            }
            $message=str_contains($error->getMessage(),'blocked by retained') || str_contains($error->getMessage(),'Only an active account') ||
                str_contains($error->getMessage(),'profile is unavailable')
                ? 'Erasure is blocked by retained records or unresolved account dependencies. No data was changed; complete a documented retention review.'
                : 'The privacy request is no longer awaiting review or you lack permission.';
            return $this->index($request,'',$message,403);
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
