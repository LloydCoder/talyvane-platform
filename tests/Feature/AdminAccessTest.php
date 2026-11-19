<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_admin_dashboard(): void
    {
        $this->get('/admin')
            ->assertRedirect('/admin/login');
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

    public function test_owner_can_update_public_brand_settings(): void
    {
        $owner = $this->createAdminUser([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
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

    public function test_owner_can_upload_a_public_brand_logo(): void
    {
        Storage::fake('public');

        $owner = $this->createAdminUser([
            'name' => 'Platform Owner',
            'email' => 'owner@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'owner',
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
        ])->assertRedirect('/admin');

        $this->assertAuthenticated();

        $this->post('/admin/logout')->assertRedirect('/admin/login');

        $this->assertGuest();
    }
}
