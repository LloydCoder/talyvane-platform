<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_session_with_a_stale_security_version_is_invalidated(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Protected Administrator',
            'email' => 'protected-admin@example.test',
            'role' => 'admin',
        ]);
        $admin->forceFill(['admin_session_version' => 8])->save();

        $this->withSession(['admin_session_version' => 7])
            ->actingAs($admin)
            ->get('/admin')
            ->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_guest_is_redirected_from_admin_dashboard(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
    }

    public function test_owner_and_admin_retain_operations_but_cannot_access_deployment_configuration(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $user = $this->createAdminUser([
                'name' => ucfirst($role).' Account',
                'email' => $role.'-access@example.test',
                'password' => 'Strong-Testing-Password-934!',
                'role' => $role,
                'is_active' => true,
            ]);

            $this->actingAs($user)
                ->get('/admin')
                ->assertOk()
                ->assertDontSee('Brand & identity')
                ->assertDontSee('Global markets & currency')
                ->assertDontSee('Payment settings')
                ->assertDontSee('Site pages');

            $this->get('/admin/profiles')->assertOk();
            $this->get('/admin/bookings')->assertOk();
            $this->get('/admin/clients')->assertOk();
            $this->get('/admin/enquiries')->assertOk();

            foreach ([
                '/admin/features',
                '/admin/branding',
                '/admin/globalization',
                '/admin/destinations',
                '/admin/booking-settings',
                '/admin/pages',
                '/admin/payment-settings',
                '/admin/payment-methods',
            ] as $path) {
                $this->get($path)->assertForbidden();
            }
        }
    }

    public function test_owner_can_access_admin_dashboard(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Platform overview');
    }

    public function test_non_privileged_user_cannot_access_admin_dashboard(): void
    {
        $operator = User::create([
            'name' => 'Operations User',
            'email' => 'operator@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_super_admin_can_update_public_brand_settings(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->put('/admin/branding', [
                'brand_name' => 'Maison Example',
                'tagline' => 'A private world, thoughtfully managed.',
                'contact_email' => 'concierge@example.test',
                'contact_phone' => '+44 20 0000 0000',
                'contact_website' => 'https://example.test',
            ])
            ->assertRedirect('/admin/branding');

        $this->assertDatabaseHas('brand_settings', [
            'key' => 'brand_name',
            'value' => 'Maison Example',
            'updated_by' => $owner->id,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('MAISON EXAMPLE')
            ->assertSee('concierge@example.test');
    }

    public function test_super_admin_can_upload_a_public_brand_logo(): void
    {
        Storage::fake('public');

        $owner = $this->createAdminUser([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5WQAAAAASUVORK5CYII=');

        $this->actingAs($owner)
            ->put('/admin/branding', [
                'brand_name' => 'Maison Example',
                'logo' => UploadedFile::fake()->createWithContent('brand.png', $png),
            ])
            ->assertRedirect('/admin/branding');

        $logoPath = \App\Models\BrandSetting::query()
            ->where('key', 'logo_path')
            ->value('value');

        $this->assertNotEmpty($logoPath);
        Storage::disk('public')->assertExists($logoPath);
    }

    public function test_owner_cannot_update_deployment_branding(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Agency Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->put('/admin/branding', ['brand_name' => 'Unauthorized Change'])
            ->assertForbidden();
    }

    public function test_operator_cannot_update_brand_settings(): void
    {
        $operator = User::create([
            'name' => 'Operations User',
            'email' => 'operator@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->put('/admin/branding', [
                'brand_name' => 'Not Authorised',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_sign_in_and_sign_out(): void
    {
        $this->createAdminUser([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->post('/admin/login', [
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
        ])->assertRedirect('/admin/mfa/challenge');

        $this->assertGuest();
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $step = intdiv(time(), 30);
        $code = (new TotpService())->codeForStep($user->mfa_secret, $step);
        $this->post('/admin/mfa/challenge', ['code' => $code])->assertRedirect('/admin');
        $this->assertAuthenticated();

        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_super_admin_can_sign_in_through_the_privileged_mfa_flow(): void
    {
        $superAdmin = $this->createAdminUser([
            'name' => 'Deployment Super Admin',
            'email' => 'super-admin@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->assertTrue($superAdmin->isPlatformAdministrator());

        $this->post('/admin/login', [
            'email' => 'super-admin@example.test',
            'password' => 'Strong-Testing-Password-934!',
        ])->assertRedirect('/admin/mfa/challenge');

        $step = intdiv(time(), 30);
        $code = (new TotpService())->codeForStep($superAdmin->mfa_secret, $step);

        $this->post('/admin/mfa/challenge', ['code' => $code])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($superAdmin);
        $this->get('/admin')->assertOk()->assertSee('Platform overview');
    }
}
