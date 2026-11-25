<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Infrastructure\Audit\AuditQueryService;
use Talyvane\Security\Authorization;
use Talyvane\Security\SessionManager;

final class AdminAuditController
{
    public function __construct(
        private AuthenticationService $authentication,
        private AuditQueryService $queries,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request): Response
    {
        SessionManager::start();
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null) {
            return Response::redirect('/admin/login');
        }
        if (!Authorization::allows($principal, 'audit.events.view')) {
            return Response::html('<!doctype html><html lang="en"><meta charset="utf-8"><title>Forbidden</title><h1>You do not have permission to view security audit events.</h1><a href="/admin">Return to administration</a></html>', 403);
        }

        $filters = [];
        foreach (['event_key','target_type','target_id','actor_user_id','from','to'] as $field) {
            $value = $request->query[$field] ?? '';
            $filters[$field] = is_string($value) ? trim($value) : '';
        }
        $pageValue = $request->query['page'] ?? '1';
        $page = is_string($pageValue) && ctype_digit($pageValue) ? (int) $pageValue : 1;

        try {
            $items = $this->queries->search($filters, $page);
            $error = '';
            $status = 200;
        } catch (InvalidArgumentException $exception) {
            $items = [];
            $error = $exception->getMessage();
            $status = 422;
        } catch (DomainException) {
            $items = [];
            $error = 'You do not have permission to view security audit events.';
            $status = 403;
        }

        $previous = $page > 1 ? $page - 1 : null;
        $next = count($items) === 50 && $page < 10000 ? $page + 1 : null;
        $queryFor = static function (int $targetPage) use ($filters): string {
            $values = array_filter($filters, static fn(string $value): bool => $value !== '');
            $values['page'] = (string) $targetPage;
            return '/admin/audit?' . http_build_query($values, '', '&', PHP_QUERY_RFC3986);
        };

        $html = $this->views->render('admin/audit', [
            'brandName'=>$this->brandName,
            'items'=>$items,
            'filters'=>$filters,
            'page'=>$page,
            'previousUrl'=>$previous === null ? null : $queryFor($previous),
            'nextUrl'=>$next === null ? null : $queryFor($next),
            'error'=>$error,
        ]);
        return new Response($html, $status, [
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache',
            'x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }
}
