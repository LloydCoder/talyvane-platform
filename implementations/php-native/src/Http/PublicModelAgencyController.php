<?php
declare(strict_types=1);

namespace Talyvane\Http;

use PDO;
use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\ModelAgency\ModelAgencyApplicationService;
use Talyvane\Application\ModelAgency\ModelApplicantRightsService;
use Talyvane\Infrastructure\Security\RateLimiter;
use Talyvane\Security\Csrf;
use Talyvane\Security\SessionManager;

final class PublicModelAgencyController
{
    public function __construct(
        private PDO $pdo,
        private ModelAgencyApplicationService $applications,
        private ContentManagementService $content,
        private RateLimiter $limiter,
        private ?ModelApplicantRightsService $rights,
        private ViewRenderer $views
    ) {}

    public function form(Request $request): Response
    {
        SessionManager::start();
        return $this->page('form',$this->intakeContext());
    }

    public function submit(Request $request): Response
    {
        SessionManager::start();
        if (!Csrf::verify($this->field($request,'_csrf'))) {
            return $this->page('form',array_replace($this->intakeContext(),['error'=>'Your session expired. Refresh this page and try again.']),419);
        }
        $context=$this->intakeContext();
        if (!$context['available']) {
            return $this->page('form',array_replace($context,['error'=>'Applications are temporarily unavailable.']),503);
        }
        $email=strtolower(trim($this->field($request,'email')));
        $ip=$request->ipAddress;
        if (!$this->limiter->consume('model-application.ip',$ip,3,3600,3600) ||
            !$this->limiter->consume('model-application.email-ip',$email.'|'.$ip,2,86400,86400)) {
            return $this->page('form',array_replace($context,['error'=>'Please wait before submitting another application.']),429);
        }
        $adult=$this->field($request,'adult_attested')==='1';
        $privacy=$this->field($request,'privacy_acknowledged')==='1';
        try {
            $id=$this->applications->submit(
                $this->field($request,'applicant_name'),
                $email,
                $this->nullable($this->field($request,'phone')),
                $this->field($request,'country_code'),
                $this->field($request,'city'),
                $this->nullable($this->field($request,'portfolio_url')),
                $this->nullable($this->field($request,'experience_summary')),
                $adult,
                $privacy,
                (string) $context['privacyVersion'],
                $request->requestId
            );
            $query=$this->pdo->prepare('SELECT application_reference FROM model_talent_applications WHERE id = :id');
            $query->execute(['id'=>$id]);
            $reference=(string) $query->fetchColumn();
            return $this->page('success',['reference'=>$reference,'notice'=>'Your application has been received. It will be reviewed separately from companionship profiles.']);
        } catch (\InvalidArgumentException|\DomainException $error) {
            return $this->page('form',$context+['error'=>$error instanceof \DomainException ? $error->getMessage() : 'Check the application fields and required acknowledgements.'],422);
        } catch (\Throwable) {
            return $this->page('form',array_replace($context,['error'=>'The application could not be submitted. Please try again later.']),503);
        }
    }

    /** @return array<string,mixed> */
    private function intakeContext(): array
    {
        $privacyPage=$this->content->publicPage('privacy','en');
        $query=$this->pdo->prepare('SELECT setting_json FROM deployment_settings WHERE setting_key = :key LIMIT 1');
        $query->execute(['key'=>'model_agency.intake']);
        $raw=$query->fetchColumn();
        $policy=is_string($raw) ? json_decode($raw,true) : null;
        $configuredVersion=is_array($policy) && is_string($policy['privacy_notice_version'] ?? null) ? $policy['privacy_notice_version'] : '';
        $publishedVersion=is_array($privacyPage) ? 'privacy-v'.(int)($privacyPage['version'] ?? 0) : '';
        $version=$configuredVersion;
        $available=is_array($policy) && ($policy['enabled'] ?? false) === true && $version !== '' &&
            $publishedVersion !== 'privacy-v0' && hash_equals($publishedVersion,$configuredVersion) && $this->rights !== null;
        return ['available'=>$available,'privacyVersion'=>$version,'privacyPage'=>$privacyPage,'error'=>'','values'=>[],'brandName'=>(string)($this->content->publicBrand()['brand_name']??'Talyvane')];
    }

    private function field(Request $request,string $name): string
    {
        $value=$request->body[$name] ?? '';
        return is_string($value) ? $value : '';
    }

    private function nullable(string $value): ?string
    {
        $value=trim($value);
        return $value==='' ? null : $value;
    }

    /** @param array<string,mixed> $data */
    private function page(string $screen,array $data=[],int $status=200): Response
    {
        $payload=$data+['screen'=>$screen,'csrf'=>Csrf::issue(),'error'=>'','notice'=>'','reference'=>'','values'=>[]];
        return Response::html($this->views->render('public/model-agency',$payload),$status);
    }
}
