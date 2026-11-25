<?php
declare(strict_types=1);

namespace Talyvane\Http;

use DomainException;
use InvalidArgumentException;
use Talyvane\Application\Content\ContentManagementService;
use Talyvane\Application\Identity\AuthenticationService;
use Talyvane\Security\Authorization;
use Talyvane\Security\Csrf;
use Talyvane\Security\Principal;
use Talyvane\Security\SessionManager;

/** Super Admin-only white-label identity configuration. */
final class AdminBrandController
{
    public function __construct(
        private AuthenticationService $authentication,
        private ContentManagementService $content,
        private ViewRenderer $views
    ) {}

    public function show(Request $request): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        return $this->page($this->content->publicBrand());
    }

    public function update(Request $request): Response
    {
        $principal = $this->principal();
        if ($principal instanceof Response) {
            return $principal;
        }
        $brand = $this->content->publicBrand();
        if (!Csrf::verify($this->field($request, '_csrf'))) {
            return $this->page($this->submittedBrand($brand, $request), 'Your session expired. Refresh this page and try again.', false, 419);
        }

        try {
            $input = $this->input($request, $brand);
            $updated = $this->content->updateBrand(
                $principal,
                $input,
                $request->requestId,
                $this->field($request, 'reason')
            );
            return $this->page($updated, '', true);
        } catch (InvalidArgumentException|DomainException $error) {
            return $this->page($this->submittedBrand($brand, $request), $error->getMessage(), false, 422);
        }
    }

    private function principal(): Principal|Response
    {
        SessionManager::start();
        $principal = $this->authentication->currentPrincipal();
        if ($principal === null) {
            return Response::redirect('/admin/login');
        }
        if (!$principal->hasRole('super_admin') || !Authorization::allows($principal, 'site.content.manage')) {
            return Response::html('Forbidden', 403);
        }
        return $principal;
    }

    /** @param array<string,mixed> $brand @return array<string,mixed> */
    private function input(Request $request, array $brand): array
    {
        $email = trim($this->field($request, 'contact_email'));
        $phone = trim($this->field($request, 'contact_phone'));
        $website = trim($this->field($request, 'contact_website'));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The public contact email is invalid.');
        }
        if ($phone !== '' && (strlen($phone) > 40 || preg_match('/^[+0-9(). -]+$/D', $phone) !== 1)) {
            throw new InvalidArgumentException('The public contact phone is invalid.');
        }
        $this->assertHttpsUrl($website, 'The public website URL');
        foreach (['instagram','linkedin','facebook','x'] as $network) {
            $url = trim($this->field($request, 'social_' . $network));
            $this->assertHttpsUrl($url, ucfirst($network) . ' URL');
        }

        $robots = $this->field($request, 'seo_robots');
        if (!in_array($robots, ['index,follow','noindex,nofollow'], true)) {
            throw new InvalidArgumentException('The default robots policy is invalid.');
        }

        $contacts = is_array($brand['contact_details'] ?? null) ? $brand['contact_details'] : [];
        foreach (['email'=>$email,'phone'=>$phone,'website'=>$website] as $key=>$value) {
            if ($value === '') {
                unset($contacts[$key]);
            } else {
                $contacts[$key] = $value;
            }
        }
        $social = is_array($brand['social_links'] ?? null) ? $brand['social_links'] : [];
        foreach (['instagram','linkedin','facebook','x'] as $network) {
            $url = trim($this->field($request, 'social_' . $network));
            if ($url === '') {
                unset($social[$network]);
            } else {
                $social[$network] = $url;
            }
        }
        $colors = is_array($brand['color_tokens'] ?? null) ? $brand['color_tokens'] : [];
        $colors['primary'] = $this->field($request, 'color_primary');
        $colors['accent'] = $this->field($request, 'color_accent');
        $colors['surface'] = $this->field($request, 'color_surface');
        $typography = is_array($brand['typography'] ?? null) ? $brand['typography'] : [];
        $typography['heading'] = trim($this->field($request, 'font_heading'));
        $typography['body'] = trim($this->field($request, 'font_body'));
        $seo = is_array($brand['seo_defaults'] ?? null) ? $brand['seo_defaults'] : [];
        $seo['title_suffix'] = trim($this->field($request, 'seo_title_suffix'));
        $seo['robots'] = $robots;

        return [
            'brand_name'=>trim($this->field($request, 'brand_name')),
            'tagline'=>trim($this->field($request, 'tagline')) ?: null,
            'color_tokens'=>$colors,
            'typography'=>$typography,
            'contact_details'=>$contacts,
            'social_links'=>$social,
            'seo_defaults'=>$seo,
            // The browser never receives storage keys. Existing private assets are preserved.
            'logo_storage_key'=>$brand['logo_storage_key'] ?? null,
            'favicon_storage_key'=>$brand['favicon_storage_key'] ?? null,
        ];
    }

    private function assertHttpsUrl(string $value, string $label): void
    {
        if ($value === '') {
            return;
        }
        $parts = parse_url($value);
        if (filter_var($value, FILTER_VALIDATE_URL) === false || !is_array($parts) ||
            strtolower((string) ($parts['scheme'] ?? '')) !== 'https' ||
            !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException($label . ' must be a valid HTTPS URL.');
        }
    }

    /** @param array<string,mixed> $brand @return array<string,mixed> */
    private function submittedBrand(array $brand, Request $request): array
    {
        $brand['brand_name'] = $this->field($request, 'brand_name') ?: ($brand['brand_name'] ?? 'Talyvane');
        $brand['tagline'] = $this->field($request, 'tagline');
        $brand['color_tokens'] = [
            'primary'=>$this->field($request, 'color_primary') ?: '#17233B',
            'accent'=>$this->field($request, 'color_accent') ?: '#C58B43',
            'surface'=>$this->field($request, 'color_surface') ?: '#F7F5F0',
        ];
        $brand['typography'] = [
            'heading'=>$this->field($request, 'font_heading'),
            'body'=>$this->field($request, 'font_body'),
        ];
        $brand['contact_details'] = [
            'email'=>$this->field($request, 'contact_email'),
            'phone'=>$this->field($request, 'contact_phone'),
            'website'=>$this->field($request, 'contact_website'),
        ];
        $brand['social_links'] = [
            'instagram'=>$this->field($request, 'social_instagram'),
            'linkedin'=>$this->field($request, 'social_linkedin'),
            'facebook'=>$this->field($request, 'social_facebook'),
            'x'=>$this->field($request, 'social_x'),
        ];
        $brand['seo_defaults'] = [
            'title_suffix'=>$this->field($request, 'seo_title_suffix'),
            'robots'=>$this->field($request, 'seo_robots') ?: 'index,follow',
        ];
        return $brand;
    }

    private function field(Request $request, string $name): string
    {
        $value = $request->body[$name] ?? '';
        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : '');
    }

    /** @param array<string,mixed> $brand */
    private function page(array $brand, string $error = '', bool $saved = false, int $status = 200): Response
    {
        $body = $this->views->render('admin/brand', [
            'brand'=>$brand,
            'csrf'=>Csrf::issue(),
            'error'=>$error,
            'saved'=>$saved,
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
}
