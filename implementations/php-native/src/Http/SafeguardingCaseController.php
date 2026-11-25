<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\Safeguarding\SafeguardingCaseService;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** Confidential report intake, assigned-specialist casework and metadata-only governance. */
final class SafeguardingCaseController
{
    public function __construct(
        private AuthenticationService $authentication,
        private SafeguardingCaseService $cases,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function reportForm(Request $request,string $error=''): Response
    {
        $actor=$this->principal();
        if ($actor instanceof Response) { return $actor; }
        try {
            $specialists=$this->cases->availableSpecialists($actor);
            return $this->page('admin/safeguarding-report',[
                'csrf'=>Csrf::issue(),'brandName'=>$this->brandName,'error'=>$error,
                'specialists'=>$specialists,'submitted'=>($request->query['submitted']??null)==='1',
            ]);
        } catch (DomainException) {
            return Response::html('Forbidden',403);
        }
    }

    public function report(Request $request): Response
    {
        $actor=$this->principal();
        if ($actor instanceof Response) { return $actor; }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->reportForm($request,'Your session expired. Refresh the form and try again.');
        }
        try {
            $created=$this->cases->create(
                $actor,$this->field($request,'case_type'),$this->field($request,'severity'),
                $this->field($request,'summary'),$this->field($request,'details'),
                $this->field($request,'subject_reference'),$this->field($request,'assigned_to'),
                $request->requestId
            );
            return Response::redirect('/admin/safeguarding/report?submitted=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->reportForm($request,$error->getMessage());
        }
    }

    public function assignedIndex(Request $request,string $error=''): Response
    {
        $actor=$this->principal();
        if ($actor instanceof Response) { return $actor; }
        try {
            return $this->page('admin/safeguarding-cases',[
                'brandName'=>$this->brandName,'cases'=>$this->cases->assignedCases($actor),
                'csrf'=>Csrf::issue(),'error'=>$error,
            ]);
        } catch (DomainException) {
            return Response::html('Forbidden',403);
        }
    }

    /** @param array<string,string> $params */
    public function assignedShow(Request $request,array $params): Response
    {
        $actor=$this->principal();
        if ($actor instanceof Response) { return $actor; }
        try {
            $case=$this->cases->assignedCase($actor,(string)($params['id']??''),$request->requestId);
            return $this->page('admin/safeguarding-case',[
                'brandName'=>$this->brandName,'case'=>$case,'csrf'=>Csrf::issue(),'error'=>'',
            ]);
        } catch (DomainException) {
            return Response::html('Not Found',404);
        }
    }

    /** @param array<string,string> $params */
    public function transition(Request $request,array $params): Response
    {
        $actor=$this->principal();
        if ($actor instanceof Response) { return $actor; }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->assignedIndex($request,'Your session expired. Refresh the case and try again.');
        }
        try {
            $this->cases->transition(
                $actor,(string)($params['id']??''),$this->field($request,'status'),
                $this->field($request,'reason'),$request->requestId
            );
            return Response::redirect('/admin/safeguarding/cases/'.rawurlencode((string)($params['id']??'')));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->assignedIndex($request,$error->getMessage());
        }
    }

    public function governance(Request $request,string $error=''): Response
    {
        $actor=$this->principal();
        if ($actor instanceof Response) { return $actor; }
        try {
            return $this->page('admin/safeguarding-governance',[
                'brandName'=>$this->brandName,'cases'=>$this->cases->governanceCases($actor),
                'csrf'=>Csrf::issue(),'error'=>$error,
            ]);
        } catch (DomainException) {
            return Response::html('Forbidden',403);
        }
    }

    /** @param array<string,string> $params */
    public function placeHold(Request $request,array $params): Response
    {
        return $this->holdAction($request,$params,false);
    }

    /** @param array<string,string> $params */
    public function releaseHold(Request $request,array $params): Response
    {
        return $this->holdAction($request,$params,true);
    }

    /** @param array<string,string> $params */
    private function holdAction(Request $request,array $params,bool $release): Response
    {
        $actor=$this->principal();
        if ($actor instanceof Response) { return $actor; }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->governance($request,'Your session expired. Refresh the governance register and try again.');
        }
        try {
            if ($release) {
                $this->cases->releaseLegalHold($actor,(string)($params['id']??''),$this->field($request,'reason'),$request->requestId);
            } else {
                $this->cases->placeLegalHold($actor,(string)($params['id']??''),$this->field($request,'reason'),$request->requestId);
            }
            return Response::redirect('/admin/safeguarding/governance');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->governance($request,$error->getMessage());
        }
    }

    private function principal(): Principal|Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        return $actor ?? Response::redirect('/admin/login');
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }

    /** @param array<string,mixed> $data */
    private function page(string $view,array $data): Response
    {
        $html=$this->views->render($view,$data);
        return new Response($html,200,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','expires'=>'0',
            'x-content-type-options'=>'nosniff','x-frame-options'=>'DENY',
            'referrer-policy'=>'no-referrer',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
