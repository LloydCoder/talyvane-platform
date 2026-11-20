<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InstallerAccessKeyDisclosureTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(storage_path('app/private/INSTALLER-ACCESS-KEY.txt'));

        parent::tearDown();
    }

    public function test_public_installer_page_never_discloses_the_private_access_key(): void
    {
        config(['talyvane.installer.enforce' => false]);

        $response = $this->get('/install');

        $response->assertOk()
            ->assertSee('name="installer_token"', false)
            ->assertSee('storage/app/private/INSTALLER-ACCESS-KEY.txt')
            ->assertDontSee((string) file_get_contents(storage_path('app/private/INSTALLER-ACCESS-KEY.txt')));
    }
}
