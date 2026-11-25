<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use JsonException;
use Talyvane\Application\Identity\PortalPrivacyExportService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\SessionManager;

final class PortalPrivacyController
{
    public function __construct(
        private AuthenticationService $authentication,
        private PortalPrivacyExportService $exports
    ) {}

    public function export(Request $request): Response
    {
        SessionManager::start();
        $actor=$this->authentication->currentPrincipal();
        if ($actor===null) return Response::redirect('/portal/login');
        try {
            $payload=$this->exports->export($actor,$request->requestId);
            $json=json_encode($payload,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
            return new Response($json,200,[
                'content-type'=>'application/json; charset=utf-8',
                'content-disposition'=>'attachment; filename="talyvane-account-export.json"',
                'cache-control'=>'no-store, no-cache, must-revalidate, private',
                'pragma'=>'no-cache',
                'x-content-type-options'=>'nosniff',
                'referrer-policy'=>'no-referrer',
            ]);
        } catch (DomainException) {
            return Response::html('Privacy export is not available for this account.',403);
        } catch (JsonException) {
            return Response::html('Privacy export could not be generated.',503);
        }
    }
}
