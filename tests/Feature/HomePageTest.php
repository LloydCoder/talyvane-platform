<?php

namespace Tests\Feature;

use App\Models\BrandSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('TALYVANE');
        $response->assertSeeText('A more considered');
        $response->assertSee('Explore destinations');
        $response->assertSee('Coordinate');
    }

    public function test_homepage_uses_public_brand_settings(): void
    {
        BrandSetting::create([
            'key' => 'brand_name',
            'value' => 'Maison Example',
            'group' => 'branding',
            'is_public' => true,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('MAISON EXAMPLE');
    }

    public function test_blank_public_contact_email_is_not_replaced_with_platform_email(): void
    {
        BrandSetting::create([
            'key' => 'contact_email',
            'value' => null,
            'group' => 'branding',
            'is_public' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertDontSee('mailto:hello@tinlance.com');
    }

    public function test_homepage_does_not_expose_private_operator_data(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('wallet_address');
        $response->assertDontSee('identity_document');
        $response->assertDontSee('private_address');
    }
}
