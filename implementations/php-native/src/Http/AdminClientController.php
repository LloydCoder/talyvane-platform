<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Application\Clients\ClientMembershipService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** MFA-protected client directory and membership assignment workspace. */
final class AdminClientController
{
    public function __construct(
        private PDO $pdo,
        private AuthenticationService $authentication,
        private ClientMembershipService $memberships,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request,string $error='',int $status=200): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) { return $principal; }
        $query=$this->pdo->query(
            'SELECT u.id,u.email,u.email_verified_at,m.id AS membership_id,m.plan_key,m.tier_name,
                    m.status AS membership_status,m.starts_at,m.ends_at
             FROM users u JOIN user_roles r ON r.user_id=u.id AND r.role_key="client"
             LEFT JOIN memberships m ON m.client_user_id=u.id AND m.status="active"
             ORDER BY u.created_at DESC LIMIT 200'
        );
        $noticeKey=$request->query['membership_assigned']??null;
        $noticeKey=is_string($noticeKey)?$noticeKey:'';
        if ($noticeKey==='') {
            $noticeKey=$request->query['membership_cancelled']??$request->query['membership_suspended']??null;
            $noticeKey=is_string($noticeKey)?$noticeKey:'';
        }
        $notice=match($noticeKey) {
            '1' => 'Membership update saved.',
            default => '',
        };
        return $this->page($query->fetchAll(PDO::FETCH_ASSOC),$error,$status,$notice);
    }

    /** @param array<string,string> $params */
    public function assign(Request $request,array $params): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) { return $principal; }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        try {
            $start=$this->date($this->field($request,'starts_at'));
            $endValue=$this->field($request,'ends_at');
            $end=$endValue===''?null:$this->date($endValue);
            $rawEntitlements=$request->body['entitlements'] ?? [];
            if (!is_array($rawEntitlements)) { throw new InvalidArgumentException('Membership entitlement selection is invalid.'); }
            $entitlements=[];
            foreach ($rawEntitlements as $key) {
                if (is_string($key)) { $entitlements[]=$key; }
            }
            $this->memberships->assign(
                $principal,
                (string)($params['id'] ?? ''),
                $this->field($request,'plan_key'),
                $this->field($request,'tier_name'),
                $this->field($request,'benefits_summary'),
                $start,
                $end,
                $entitlements,
                $this->field($request,'reason'),
                $request->requestId
            );
            return Response::redirect('/admin/clients?membership_assigned=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        }
    }

    /** @param array<string,string> $params */
    public function cancel(Request $request,array $params): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) { return $principal; }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        try {
            $this->memberships->cancel(
                $principal,
                (string)($params['id'] ?? ''),
                $this->field($request,'reason'),
                $request->requestId
            );
            return Response::redirect('/admin/clients?membership_cancelled=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        }
    }

    /** @param array<string,string> $params */
    public function suspend(Request $request,array $params): Response
    {
        $principal=$this->principal();
        if ($principal instanceof Response) { return $principal; }
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        try {
            $this->memberships->suspend(
                $principal,
                (string)($params['id'] ?? ''),
                $this->field($request,'reason'),
                $request->requestId
            );
            return Response::redirect('/admin/clients?membership_suspended=1');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        }
    }

    private function principal(): Principal|Response
    {
        SessionManager::start();
        $principal=$this->authentication->currentPrincipal();
        if ($principal===null) { return Response::redirect('/admin/login'); }
        if (!Authorization::allows($principal,'clients.manage',true)) {
            return Response::html('Forbidden',403);
        }
        return $principal;
    }

    /** @param list<array<string,mixed>> $clients */
    private function page(array $clients,string $error,int $status,string $notice=''): Response
    {
        $html=$this->views->render('admin/clients',[
            'brandName'=>$this->brandName,
            'csrf'=>Csrf::issue(),
            'error'=>$error,
            'notice'=>$notice,
            'clients'=>$clients,
            'entitlements'=>['media.private_gallery'=>'Private members-only gallery'],
        ]);
        return new Response($html,$status,[
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache',
            'expires'=>'0',
            'x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name]??'';
        return is_string($value)?trim($value):'';
    }

    private function date(string $value): DateTimeImmutable
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,new DateTimeZone('UTC'));
        $errors=DateTimeImmutable::getLastErrors();
        if (!$date || ($errors!==false && ($errors['warning_count']>0 || $errors['error_count']>0)) || $date->format('Y-m-d\TH:i')!==$value) {
            throw new InvalidArgumentException('Enter a valid UTC membership date and time.');
        }
        return $date;
    }
}
