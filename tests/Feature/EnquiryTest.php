<?php

namespace Tests\Feature;

use App\Models\Enquiry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnquiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_visitor_can_submit_an_enquiry(): void
    {
        $this->post('/enquiries', [
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'subject' => 'General enquiry',
            'message' => 'Please send information about your services.',
            'privacy_acknowledged' => '1',
            'website' => '',
        ])->assertRedirect('/contact')
            ->assertSessionHas('enquiry_received');

        $this->assertDatabaseHas('enquiries', [
            'email' => 'taylor@example.test',
            'status' => 'new',
            'subject' => 'General enquiry',
        ]);
    }

    public function test_honeypot_submission_is_accepted_without_creating_an_enquiry(): void
    {
        $this->post('/enquiries', [
            'name' => 'Automated bot',
            'email' => 'bot@example.test',
            'subject' => 'Spam',
            'message' => 'Automated content',
            'privacy_acknowledged' => '1',
            'website' => 'https://spam.example',
        ])->assertRedirect('/contact')
            ->assertSessionHas('enquiry_received');

        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_invalid_enquiry_email_is_rejected(): void
    {
        $this->from('/contact')
            ->post('/enquiries', [
                'name' => 'Taylor',
                'email' => 'not-an-email',
                'subject' => 'Question',
                'message' => 'Hello there.',
                'privacy_acknowledged' => '1',
                'website' => '',
            ])
            ->assertRedirect('/contact')
            ->assertSessionHasErrors('email');
    }

    public function test_enquiry_cannot_reference_an_inactive_destination(): void
    {
        $destination = \\App\\Models\\Destination::create([
            'name' => 'Hidden City',
            'slug' => 'hidden-city',
            'country_code' => 'GB',
            'country_name' => 'United Kingdom',
            'city_name' => 'Hidden City',
            'currency_code' => 'GBP',
            'locale' => 'en',
            'timezone' => 'Europe/London',
            'is_active' => false,
        ]);

        $this->from('/contact')
            ->post('/enquiries', [
                'name' => 'Taylor',
                'email' => 'taylor@example.test',
                'subject' => 'Question',
                'message' => 'Please contact me about this city.',
                'destination_id' => $destination->id,
                'privacy_acknowledged' => '1',
                'website' => '',
            ])
            ->assertRedirect('/contact')
            ->assertSessionHasErrors('destination_id');

        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_admin_can_update_enquiry_status(): void
    {
        $admin = User::create([
            'name' => 'Platform Admin',
            'email' => 'admin@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $enquiry = Enquiry::create([
            'name' => 'Taylor Example',
            'email' => 'taylor@example.test',
            'subject' => 'General enquiry',
            'message' => 'Please contact me.',
            'status' => 'new',
        ]);

        $this->actingAs($admin)
            ->patch('/admin/enquiries/'.$enquiry->id, ['status' => 'in_progress'])
            ->assertRedirect('/admin/enquiries');

        $this->assertDatabaseHas('enquiries', [
            'id' => $enquiry->id,
            'status' => 'in_progress',
        ]);
    }

    public function test_operator_cannot_access_enquiry_inbox(): void
    {
        $operator = User::create([
            'name' => 'Operations User',
            'email' => 'operator@example.test',
            'password' => Hash::make('Strong-Testing-Password-934!'),
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->get('/admin/enquiries')
            ->assertForbidden();
    }
}
