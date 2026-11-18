<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomePageTest extends TestCase
{
    public function test_homepage_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('TALYVANE');
        $response->assertSeeText('A more considered');
        $response->assertSee('Discover the platform');
        $response->assertSee('Coordinate');
    }

    public function test_homepage_does_not_expose_private_operator_data(): void
    {
        $response = $this->get('/');

        $response->assertDontSee('wallet_address');
        $response->assertDontSee('identity_document');
        $response->assertDontSee('private_address');
    }
}
