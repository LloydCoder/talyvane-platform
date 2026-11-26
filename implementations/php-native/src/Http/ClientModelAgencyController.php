<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Application\ModelAgency\ClientModelAgencyDistributionService;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

final class ClientModelAgencyController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ClientModelAgencyDistributionService $distribution,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request): Response
    {
        SessionManager::start();
        $client=$this->client();
        if($client===null) return Response::redirect('/portal/login');
        try {
            $calls=$this->distribution->castingCalls($client);
            $candidates=[];
            foreach($calls as $call) $candidates[(string)$call['id']]=$this->distribution->candidates($client,(string)$call['id']);
            $html=$this->views->render('portal/model-agency',[
                'brandName'=>$this->brandName,'calls'=>$calls,'candidates'=>$candidates,
                'assignments'=>$this->distribution->assignments($client),'error'=>'',
            ]);
            return $this->privateHtml($html,200);
        } catch(DomainException) {
            $html=$this->views->render('portal/model-agency',[
                'brandName'=>$this->brandName,'calls'=>[],'candidates'=>[],'assignments'=>[],
                'error'=>'The client model-agency workspace is unavailable for this account.',
            ]);
            return $this->privateHtml($html,403);
        } catch(\Throwable) {
            $html=$this->views->render('portal/model-agency',[
                'brandName'=>$this->brandName,'calls'=>[],'candidates'=>[],'assignments'=>[],
                'error'=>'The client model-agency workspace could not be loaded securely.',
            ]);
            return $this->privateHtml($html,503);
        }
    }

    /** @param array<string,string> $params */
    public function candidateImage(Request $request,array $params): Response
    {
        SessionManager::start();
        $client=$this->client();
        if($client===null) return Response::redirect('/portal/login');
        try {
            $image=$this->distribution->candidateImage($client,(string)($params['call']??''),(string)($params['submission']??''),$request->requestId);
            return new Response($image['bytes'],200,[
                'content-type'=>$image['mime'],
                'content-disposition'=>'inline; filename="casting-portfolio-preview.webp"',
                'cache-control'=>'private, no-store, max-age=0','pragma'=>'no-cache',
                'x-content-type-options'=>'nosniff','referrer-policy'=>'no-referrer',
                'content-security-policy'=>"default-src 'none'; sandbox",
            ]);
        } catch(DomainException) {
            return Response::html('Not found',404)->withHeaders([
                'cache-control'=>'private, no-store, max-age=0','x-content-type-options'=>'nosniff',
                'referrer-policy'=>'no-referrer',
            ]);
        } catch(\Throwable) {
            return Response::html('Media unavailable',503)->withHeaders([
                'cache-control'=>'private, no-store, max-age=0','x-content-type-options'=>'nosniff',
            ]);
        }
    }

    private function client(): ?Principal
    {
        $principal=$this->authentication->currentPrincipal();
        return $principal!==null && $principal->hasRole('client') && count($principal->roles)===1 ? $principal : null;
    }

    private function privateHtml(string $html,int $status): Response
    {
        return new Response($html,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache','x-content-type-options'=>'nosniff','x-frame-options'=>'DENY',
            'referrer-policy'=>'no-referrer','permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
