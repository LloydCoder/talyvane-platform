<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** Versioned, role-scoped CMS authoring and publication endpoints. */
final class AdminContentController
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
        $query = $this->pdo->query(
            'SELECT id,slug,title,locale,status,version,updated_at
             FROM site_pages ORDER BY updated_at DESC,id DESC LIMIT 100'
        );
        return $this->page('list', ['pages'=>$query->fetchAll()]);
    }

    public function create(Request $request): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        return $this->page('edit', ['page'=>[
            'id'=>'','slug'=>'','title'=>'','locale'=>'en','content'=>'',
            'meta_title'=>'','meta_description'=>'','status'=>'draft','version'=>1,
        ]]);
    }

    public function edit(Request $request, array $params): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        $id = (string) ($params['id'] ?? '');
        if (!$this->validUuid($id)) {
            return Response::html('Not Found', 404);
        }
        $query = $this->pdo->prepare(
            'SELECT id,slug,title,locale,content,meta_title,meta_description,status,version,updated_at
             FROM site_pages WHERE id = :id LIMIT 1'
        );
        $query->execute(['id'=>$id]);
        $page = $query->fetch();
        if (!is_array($page)) {
            return Response::html('Not Found', 404);
        }
        return $this->page('edit', ['page'=>$page]);
    }

    public function save(Request $request, ?array $params = null): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->editError($params, 'Your session expired. Refresh this page and try again.', 419);
        }
        $id = isset($params['id']) ? (string) $params['id'] : null;
        if ($id !== null && !$this->validUuid($id)) {
            return Response::html('Not Found', 404);
        }

        $metaTitle = trim($this->field($request, 'meta_title'));
        $metaDescription = trim($this->field($request, 'meta_description'));
        try {
            $savedId = $this->content->savePage(
                $principal,
                $id,
                $this->field($request, 'slug'),
                $this->field($request, 'title'),
                $this->field($request, 'locale'),
                $this->field($request, 'content'),
                $metaTitle === '' ? null : $metaTitle,
                $metaDescription === '' ? null : $metaDescription,
                $this->field($request, 'reason'),
                $request->requestId
            );
            return Response::redirect('/admin/content/' . rawurlencode($savedId));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->editError($params, $error->getMessage(), 422, $request);
        } catch (PDOException $error) {
            return $this->editError($params, 'The page could not be saved. Check for an existing page with the same URL slug and locale.', 409, $request);
        }
    }

    public function publish(Request $request, array $params): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->editError($params, 'Your session expired. Refresh this page and try again.', 419, $request);
        }
        $id = (string) ($params['id'] ?? '');
        if (!$this->validUuid($id)) {
            return Response::html('Not Found', 404);
        }
        try {
            $this->content->publishPage($principal, $id, $this->field($request, 'reason'), $request->requestId);
            return Response::redirect('/admin/content/' . rawurlencode($id));
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->editError(['id'=>$id], $error->getMessage(), 422, $request);
        } catch (PDOException $error) {
            return $this->editError(['id'=>$id], 'The page could not be published. Refresh the page and try again.', 409, $request);
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

    /** @param array<string,string>|null $params */
    private function editError(?array $params, string $message, int $status, ?Request $request = null): Response
    {
        $id = is_array($params) && isset($params['id']) ? (string) $params['id'] : '';
        $page = [
            'id'=>$id,'slug'=>'','title'=>'','locale'=>'en','content'=>'',
            'meta_title'=>'','meta_description'=>'','status'=>'draft','version'=>1,
        ];
        if ($id !== '' && $this->validUuid($id)) {
            $query = $this->pdo->prepare(
                'SELECT id,slug,title,locale,content,meta_title,meta_description,status,version,updated_at
                 FROM site_pages WHERE id = :id LIMIT 1'
            );
            $query->execute(['id'=>$id]);
            $stored = $query->fetch();
            if (is_array($stored)) {
                $page = $stored;
            }
        }
        if ($request !== null && $request->method === 'POST' && $request->body !== []) {
            foreach (['slug','title','locale','content','meta_title','meta_description'] as $key) {
                if (array_key_exists($key, $request->body)) {
                    $page[$key] = $this->field($request, $key);
                }
            }
        }
        return $this->page('edit', ['page'=>$page,'error'=>$message], $status);
    }

    private function validUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    /** @param array<string,mixed> $data */
    private function page(string $mode, array $data = [], int $status = 200): Response
    {
        $payload = $data + [
            'mode'=>$mode,'csrf'=>Csrf::issue(),'error'=>'','pages'=>[],'page'=>[],
        ];
        $body = $this->views->render('admin/content', $payload);
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
}
