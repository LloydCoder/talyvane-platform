<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use PDO;
use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** MFA-governed administration of public header/footer navigation. */
final class AdminNavigationController
{
    public function __construct(
        private PDO $pdo,
        private AuthenticationService $authentication,
        private ContentManagementService $content,
        private ViewRenderer $views
    ) {}

    public function index(Request $request): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        return $this->page();
    }

    public function save(Request $request, ?array $params = null): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page('Your session expired. Refresh this page and try again.', false, 419);
        }

        $id = isset($params['id']) ? (string) $params['id'] : null;
        $sortOrder = filter_var($this->field($request, 'sort_order'), FILTER_VALIDATE_INT);
        if ($sortOrder === false) {
            return $this->page('Sort order must be a whole number.', false, 422);
        }

        try {
            $this->content->saveNavigationItem(
                $principal,
                $id,
                $this->field($request, 'location'),
                $this->field($request, 'label'),
                $this->field($request, 'target_type'),
                $this->field($request, 'target_value'),
                $this->field($request, 'locale'),
                $sortOrder,
                in_array($this->field($request, 'enabled'), ['1','on','true'], true),
                $request->requestId,
                $this->field($request, 'reason')
            );
            return $this->page('', true);
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page($error->getMessage(), false, 422);
        }
    }

    private function principal(): Principal|Response
    {
        SessionManager::start();
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null) {
            return Response::redirect('/admin/login');
        }
        if (!Authorization::allows($principal, 'site.content.manage')) {
            return Response::html('Forbidden', 403);
        }
        return $principal;
    }

    private function page(string $error = '', bool $saved = false, int $status = 200): Response
    {
        $query = $this->pdo->query(
            'SELECT id,location_key,label,target_type,target_value,locale,sort_order,enabled
             FROM navigation_items ORDER BY location_key,locale,sort_order,label LIMIT 100'
        );
        $body = $this->views->render('admin/navigation', [
            'brandName'=>$this->content->publicBrand()['brand_name'],
            'csrf'=>Csrf::issue(),
            'error'=>$error,
            'saved'=>$saved,
            'items'=>$query->fetchAll(),
        ]);
        return new Response($body, $status, [
            'content-type'=>'text/html; charset=utf-8',
            'cache-control'=>'no-store, no-cache, must-revalidate, private',
            'pragma'=>'no-cache',
            'expires'=>'0',
            'x-content-type-options'=>'nosniff',
            'x-frame-options'=>'DENY',
            'referrer-policy'=>'strict-origin-when-cross-origin',
            'permissions-policy'=>'camera=(), microphone=(), geolocation=()',
            'content-security-policy'=>"default-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; style-src 'self' 'unsafe-inline'",
        ]);
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }
}
