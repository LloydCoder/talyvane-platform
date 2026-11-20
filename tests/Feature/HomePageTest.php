<?php

namespace Tests\Feature;

use App\Models\BrandSetting;
use App\Models\CountryMarket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomePageTest extends TestCase
{
    use RefreshDatabase;

    private function approvePublicMarket(): void
    {
        CountryMarket::query()->firstOrCreate(['country_code' => 'GB'], [
            'country_code' => 'GB',
            'name' => 'United Kingdom',
            'is_enabled' => true,
            'compliance_status' => 'approved',
            'compliance_reviewed_at' => now(),
            'compliance_reviewed_by' => 'test-fixture',
            'compliance_evidence_reference' => 'homepage-test-market-evidence',
            'compliance_review_summary' => 'Approved test market.',
            'market_policy' => ['test_fixture' => true],
        ]);
    }


    public function test_homepage_returns_a_successful_response(): void
    {
        $this->approvePublicMarket();
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('TALYVANE');
        $response->assertSeeText('Exceptional company.');
        $response->assertSeeText('executive companionship');
        $response->assertSeeText('adults aged 18 and over');
        $response->assertSee('Explore destinations');
        $response->assertSee('Personal coordination');
        $response->assertSee('Discretion by design');
        $response->assertSee('Discover companions');
    }

    public function test_default_public_brand_does_not_leak_platform_contact_details(): void
    {
        $this->approvePublicMarket();
        $this->get('/')
            ->assertOk()
            ->assertDontSee('hello@tinlance.com')
            ->assertDontSee('tinlance.com');
    }

    public function test_homepage_uses_public_brand_settings(): void
    {
        $this->approvePublicMarket();
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
        $this->approvePublicMarket();
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
        $this->approvePublicMarket();
        $response = $this->get('/');

        $response->assertDontSee('wallet_address');
        $response->assertDontSee('identity_document');
        $response->assertDontSee('private_address');
    }
}
