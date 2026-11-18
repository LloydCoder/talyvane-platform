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
        $response->assertSee('Discover the platform');
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

    public function test_homepage_does_not_expose_private_operator_data(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('wallet_address');
        $response->assertDontSee('identity_document');
        $response->assertDontSee('private_address');
    }
}
