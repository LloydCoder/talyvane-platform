<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use RuntimeException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Identity\PortalPrivacyRightsService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class PortalPrivacyRightsController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ?PortalPrivacyRightsService $rights,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $notice='',string $error='',?string $pendingRequestId=null,int $status=200): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/portal/login');
        try {
            $history=$this->rights?->history($actor)??[];
            return Response::html($this->views->render('portal/privacy-rights',[
                'history'=>$history,'notice'=>$notice,'error'=>$error,
                'pendingRequestId'=>$pendingRequestId,'brandName'=>$this->brandName,
                'csrf'=>Csrf::issue(),'mailAvailable'=>$this->rights!==null,
            ]),$status);
        } catch(DomainException) {
            return Response::html('Privacy rights are not available for this account.',403);
        }
    }

    public function request(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'','Your session expired. Refresh this page and try again.',null,419);
        }
        if($this->rights===null) return $this->index($request,'','Privacy request email delivery is unavailable.',null,503);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/portal/login');
        try {
            $id=$this->rights->request($actor,$this->field($request,'request_type'),
                $this->field($request,'summary')===''?null:$this->field($request,'summary'),
                $request->ipAddress,$request->requestId);
            return $this->index($request,'A verification code has been sent to your verified account email. It expires in 10 minutes.','',$id);
        } catch(DomainException $error) {
            $limited=str_contains(strtolower($error->getMessage()),'rate limited');
            return $this->index($request,'',$limited?'Privacy requests are temporarily rate limited. Try again later.':'Privacy requests are not available for this account.',null,$limited?429:403);
        } catch(\InvalidArgumentException $error) {
            return $this->index($request,'','Check the request details and try again.',null,422);
        } catch(RuntimeException) {
            return $this->index($request,'','The verification email could not be delivered. No request was submitted.',null,503);
        } catch(\Throwable) {
            return $this->index($request,'','The privacy request could not be submitted.',null,503);
        }
    }

    public function verify(Request $request): Response
    {
        SessionManager::start();
        if(!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'','Your session expired. Refresh this page and try again.',null,419);
        }
        if($this->rights===null) return $this->index($request,'','Privacy request email delivery is unavailable.',null,503);
        $actor=$this->authentication->currentPrincipal();
        if($actor===null) return Response::redirect('/portal/login');
        try {
            $verified=$this->rights->verify($actor,$this->field($request,'request_id'),
                $this->field($request,'code'),$request->ipAddress,$request->requestId);
            return $this->index($request,$verified
                ? 'Your verified privacy request has entered the restricted review queue. Erasure is not automatic and may be deferred for legal, safeguarding, booking, financial or retention obligations.'
                : 'The verification details could not be accepted. Check the code or request a new one.','',null,$verified?200:422);
        } catch(DomainException) {
            return $this->index($request,'','The privacy request is not available for this account.',null,403);
        } catch(\Throwable) {
            return $this->index($request,'','The privacy request could not be verified.',null,503);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?$value:'';
    }
}
