<?php
declare(strict_types=1);
namespace Talyvane\Http;
use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Safeguarding\SafeguardingCaseService;
use Talyvane\Application\Safeguarding\SafeguardingBackupPolicyService;
use Talyvane\Application\Safeguarding\SafeguardingRestoreVerificationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;
final class AdminSafeguardingController
{
    public function __construct(private AuthenticationService $authentication,private SafeguardingCaseService $cases,private SafeguardingBackupPolicyService $backupPolicies,private SafeguardingRestoreVerificationService $restoreVerification,private ViewRenderer $views,private string $brandName) {}
    public function reportForm(Request $request,string $error=''): Response
    {
        SessionManager::start();$actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        try{$specialists=$this->cases->availableSpecialists($actor);}catch(DomainException){return $this->page('report',[],'You cannot submit safeguarding reports.',403);}
        return $this->page('report',['specialists'=>$specialists],$error);
    }
    public function report(Request $request): Response
    {
        SessionManager::start();if(!Csrf::verify($this->field($request,'_csrf')))return $this->reportForm($request,'Your session expired. Refresh and retry.');
        $actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        try{
            $created=$this->cases->create($actor,$this->field($request,'category'),$this->field($request,'severity'),
                $this->field($request,'summary'),$this->field($request,'details'),$this->field($request,'subject_reference'),
                $this->field($request,'specialist_id'),$request->requestId);
            return $this->page('acknowledgement',['caseReference'=>$created['case_reference']],'');
        }catch(InvalidArgumentException|DomainException $error){return $this->reportForm($request,$error->getMessage());}
        catch(\Throwable){return $this->reportForm($request,'The report could not be recorded securely. Retry after checking the private application log.');}
    }
    public function assigned(Request $request,string $error=''): Response
    {
        SessionManager::start();$actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        try{return $this->page('assigned',['items'=>$this->cases->assignedCases($actor)],$error);}
        catch(DomainException){return $this->page('assigned',['items'=>[]],'This workspace is restricted to safeguarding specialists.',403);}
    }
    public function show(Request $request,array $params): Response
    {
        SessionManager::start();$actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        try{return $this->page('detail',['case'=>$this->cases->assignedCase($actor,(string)($params['id']??''),$request->requestId)],'');}
        catch(DomainException){return Response::html('Not found',404);}
    }
    public function transition(Request $request,array $params): Response
    {
        SessionManager::start();if(!Csrf::verify($this->field($request,'_csrf')))return $this->assigned($request,'Your session expired. Refresh and retry.');
        $actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        try{$this->cases->transition($actor,(string)($params['id']??''),$this->field($request,'status'),$this->field($request,'reason'),$request->requestId);return Response::redirect('/admin/safeguarding/cases/'.rawurlencode((string)$params['id']));}
        catch(InvalidArgumentException|DomainException $error){return $this->assigned($request,$error->getMessage());}
    }
    public function governance(Request $request,string $error=''): Response
    {
        SessionManager::start();$actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        try{return $this->page('governance',['items'=>$this->cases->governanceCases($actor),'backupPolicy'=>$this->backupPolicies->current(),'restoreVerifications'=>$this->restoreVerification->recent()],$error);}
        catch(DomainException){return $this->page('governance',['items'=>[]],'This metadata-only workspace is restricted to Owners and Super Admins.',403);}
    }
    public function hold(Request $request,array $params,bool $release=false): Response
    {
        SessionManager::start();if(!Csrf::verify($this->field($request,'_csrf')))return $this->governance($request,'Your session expired. Refresh and retry.');
        $actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        try{
            if($release)$this->cases->releaseLegalHold($actor,(string)($params['id']??''),$this->field($request,'reason'),$request->requestId);
            else $this->cases->placeLegalHold($actor,(string)($params['id']??''),$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/safeguarding/governance');
        }catch(InvalidArgumentException|DomainException $error){return $this->governance($request,$error->getMessage());}
    }
    public function approveBackupPolicy(Request $request): Response
    {
        SessionManager::start();if(!Csrf::verify($this->field($request,'_csrf')))return $this->governance($request,'Your session expired. Refresh and retry.');
        $actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        $days=filter_var($this->field($request,'expiry_days'),FILTER_VALIDATE_INT);
        try{
            if(!is_int($days))throw new InvalidArgumentException('Backup expiry days must be a whole number.');
            $this->backupPolicies->approve($actor,$days,$this->field($request,'policy_version'),$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/safeguarding/governance');
        }catch(InvalidArgumentException|DomainException $error){return $this->governance($request,$error->getMessage());}
    }
    public function verifyRestore(Request $request): Response
    {
        SessionManager::start();if(!Csrf::verify($this->field($request,'_csrf')))return $this->governance($request,'Your session expired. Refresh and retry.');
        $actor=$this->authentication->currentPrincipal();if($actor===null)return Response::redirect('/admin/login');
        try{
            $this->restoreVerification->verify($actor,$this->field($request,'backup_reference'),$this->field($request,'manifest_sha256'),$this->field($request,'policy_version'),$request->requestId);
            return Response::redirect('/admin/safeguarding/governance');
        }catch(InvalidArgumentException|DomainException $error){return $this->governance($request,$error->getMessage());}
        catch(\\Throwable){return $this->governance($request,'Restore verification failed. Inspect the private application log.');}
    }
    private function field(Request $request,string $key): string{$value=$request->body[$key]??'';return is_string($value)?trim($value):'';}
    private function page(string $mode,array $data,string $error,int $status=200): Response
    {
        return new Response($this->views->render('admin/safeguarding',$data+['mode'=>$mode,'error'=>$error,'csrf'=>Csrf::issue(),'brandName'=>$this->brandName]),$status,[
            'content-type'=>'text/html; charset=utf-8','cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','x-content-type-options'=>'nosniff','x-frame-options'=>'DENY','referrer-policy'=>'no-referrer',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
