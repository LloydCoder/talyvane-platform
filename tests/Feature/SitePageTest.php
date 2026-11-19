<?php

namespace Tests\Feature;

use App\Models\SitePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_site_only_renders_published_pages_as_escaped_text(): void
    {
        SitePage::create([
            'title' => 'Our standards',
            'slug' => 'our-standards',
            'body' => 'Respect, privacy and informed consent. <script>alert(1)</script>',
            'is_published' => true,
        ]);

        $this->get('/pages/our-standards')
            ->assertOk()
            ->assertSee('Our standards')
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_unpublished_pages_are_not_publicly_accessible(): void
    {
        SitePage::create([
            'title' => 'Internal draft',
            'slug' => 'internal-draft',
            'body' => 'Not public.',
            'is_published' => false,
        ]);

        $this->get('/pages/internal-draft')->assertNotFound();
    }

    public function test_super_admin_can_create_and_publish_a_site_page(): void
    {
        $admin = $this->createAdminUser(['role' => 'super_admin']);

        $this->actingAs($admin)
            ->post('/admin/pages', [
                'title' => 'About the agency',
                'slug' => 'about',
                'excerpt' => 'Our approach to service and privacy.',
                'body' => 'A considered approach.',
                'seo_title' => 'About',
                'seo_description' => 'Learn about our approach.',
                'is_published' => '1',
            ])
            ->assertRedirect('/admin/pages');

        $this->assertDatabaseHas('site_pages', [
            'slug' => 'about',
            'is_published' => true,
            'created_by' => $admin->id,
        ]);

        $this->get('/pages/about')->assertOk()->assertSee('A considered approach.');
    }
}
