<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Companions\CompanionProfilePublicationService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class AdminCompanionController
{
    public function __construct(
        private AuthenticationService $authentication,
        private CompanionProfilePublicationService $profiles,
        private ViewRenderer $views,
        private string $brandName
    ) {}

    public function index(Request $request, string $error = '', int $status = 200): Response
    {
        SessionManager::start();
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null) { return Response::redirect('/admin/login'); }
        try {
            $items = $this->profiles->pending($principal);
            return $this->page($items,$error,$status);
        } catch (DomainException) {
            return $this->page([], 'You do not have permission to review companion profiles.', 403);
        }
    }

    /** @param array<string,string> $params */
    public function publish(Request $request, array $params): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->index($request,'Your session expired. Refresh this page and try again.',419);
        }
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null) { return Response::redirect('/admin/login'); }
        try {
            $this->profiles->publish($principal,(string) ($params['id'] ?? ''),$this->field($request,'reason'),$request->requestId);
            return Response::redirect('/admin/companions');
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->index($request,$error->getMessage(),422);
        }
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    /** @param list<array<string,mixed>> $items */
    private function page(array $items,string $error,int $status): Response
    {
        $html=$this->views->render('admin/companions',[
            'brandName'=>$this->brandName,
            'csrf'=>Csrf::issue(),
            'error'=>$error,
            'items'=>$items,
        ]);
        return Response::html($html,$status);
    }
}
