<?php

namespace Tests\Feature;

use App\Models\ClientMembership;
use App\Models\CompanionProfile;
use App\Models\Destination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function client(string $email = 'client@example.test'): User
    {
        $client = User::create([
            'name' => 'Taylor Client',
            'email' => $email,
            'password' => 'Strong-Client-Password-934!',
            'role' => 'client',
            'is_active' => true,
        ]);
        $client->forceFill(['email_verified_at' => now()])->save();

        return $client;
    }

    private function profile(bool $approved = true): CompanionProfile
    {
        $destination = Destination::create([
            'name' => 'London',
            'slug' => 'london',
            'country_code' => 'GB',
            'country_name' => 'United Kingdom',
            'city_name' => 'London',
            'currency_code' => 'GBP',
            'locale' => 'en',
            'timezone' => 'Europe/London',
            'is_active' => true,
        ]);
        if ($approved) {
            $this->approveCountryMarket($destination);
        }

        return CompanionProfile::create([
            'destination_id' => $destination->id,
            'display_name' => 'Alex',
            'slug' => 'alex-london',
            'summary' => 'A considered profile introduction.',
            'publication_status' => 'published',
            'age_verified_at' => now(),
            'publication_consent_at' => now(),
        ]);
    }

    public function test_client_can_save_and_remove_only_a_publicly_discoverable_companion(): void
    {
        $client = $this->client();
        $profile = $this->profile();

        $this->actingAs($client)
            ->post(route('client.favorites.store', $profile))
            ->assertRedirect(route('companions.show', $profile->slug));

        $this->assertDatabaseHas('client_favorites', [
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
        ]);

        $this->get('/portal')->assertOk()->assertSee('Alex')->assertSee('Saved companions');

        $this->delete(route('client.favorites.destroy', $profile))
            ->assertRedirect(route('companions.show', $profile->slug));

        $this->assertDatabaseMissing('client_favorites', [
            'client_id' => $client->id,
            'companion_profile_id' => $profile->id,
        ]);
    }

    public function test_client_cannot_save_a_profile_in_a_pending_market(): void
    {
        $client = $this->client();
        $profile = $this->profile(false);

        $this->actingAs($client)
            ->post(route('client.favorites.store', $profile))
            ->assertNotFound();

        $this->assertDatabaseCount('client_favorites', 0);
    }

    public function test_client_portal_shows_active_membership_and_never_exposes_internal_notes(): void
    {
        $client = $this->client();
        $profile = $this->profile();
        $admin = $this->createAdminUser(['name' => 'Agency Owner', 'email' => 'owner@example.test', 'role' => 'owner']);
        $client->favoriteProfiles()->syncWithoutDetaching([$profile->id]);

        ClientMembership::create([
            'user_id' => $client->id,
            'tier_name' => 'Signature',
            'benefits_summary' => 'A dedicated concierge contact and priority coordination.',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addYear(),
            'assigned_by' => $admin->id,
            'internal_note' => 'PRIVATE INTERNAL NOTE — NEVER SHOW TO CLIENT',
        ]);

        $this->actingAs($client)
            ->get('/portal')
            ->assertOk()
            ->assertSee('Signature')
            ->assertSee('dedicated concierge contact')
            ->assertSee('Alex')
            ->assertDontSee('PRIVATE INTERNAL NOTE');
    }

    public function test_admin_can_assign_membership_and_previous_active_membership_is_superseded(): void
    {
        $client = $this->client();
        $admin = $this->createAdminUser(['name' => 'Agency Owner', 'email' => 'owner@example.test', 'role' => 'owner']);
        $previous = ClientMembership::create([
            'user_id' => $client->id,
            'tier_name' => 'Private',
            'benefits_summary' => 'Existing benefits.',
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'assigned_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.clients.membership', $client), [
                'tier_name' => 'Signature',
                'benefits_summary' => 'Dedicated concierge contact and priority coordination.',
                'status' => 'active',
                'starts_at' => now()->format('Y-m-d'),
                'ends_at' => now()->addYear()->format('Y-m-d'),
                'internal_note' => 'Renewed after a private consultation.',
            ])
            ->assertRedirect(route('admin.clients.index'));

        $this->assertDatabaseHas('client_memberships', [
            'id' => $previous->id,
            'status' => 'superseded',
        ]);
        $this->assertDatabaseHas('client_memberships', [
            'user_id' => $client->id,
            'tier_name' => 'Signature',
            'status' => 'active',
            'assigned_by' => $admin->id,
        ]);
    }

    public function test_admin_can_view_client_membership_management(): void
    {
        $this->client();
        $admin = $this->createAdminUser(['name' => 'Agency Owner', 'email' => 'owner@example.test', 'role' => 'owner']);

        $this->actingAs($admin)
            ->get(route('admin.clients.index'))
            ->assertOk()
            ->assertSee('membership.')
            ->assertSee('Taylor Client')
            ->assertSee('Assign or update membership');
    }

    public function test_clients_cannot_assign_their_own_membership(): void
    {
        $client = $this->client();

        $this->actingAs($client)
            ->post(route('admin.clients.membership', $client), [
                'tier_name' => 'Black',
                'benefits_summary' => 'Unapproved self-assigned membership.',
                'status' => 'active',
                'starts_at' => now()->format('Y-m-d'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('client_memberships', 0);
    }

    public function test_membership_assignment_records_append_only_lifecycle_events(): void
    {
        $client = $this->client();
        $admin = $this->createAdminUser(['name' => 'Agency Owner', 'email' => 'owner@example.test', 'role' => 'owner']);
        $previous = ClientMembership::create([
            'user_id' => $client->id,
            'tier_name' => 'Private',
            'benefits_summary' => 'Existing benefits.',
            'status' => 'active',
            'starts_at' => now()->subMonth(),
            'assigned_by' => $admin->id,
        ]);

        $this->actingAs($admin)->post(route('admin.clients.membership', $client), [
            'tier_name' => 'Signature',
            'benefits_summary' => 'Dedicated concierge contact and priority coordination.',
            'status' => 'active',
            'starts_at' => now()->format('Y-m-d'),
        ])->assertRedirect(route('admin.clients.index'));

        $this->assertDatabaseHas('client_membership_events', [
            'membership_id' => $previous->id,
            'actor_id' => $admin->id,
            'event_type' => 'membership.superseded',
        ]);
        $this->assertDatabaseHas('client_membership_events', [
            'actor_id' => $admin->id,
            'event_type' => 'membership.assigned',
        ]);
        $this->assertSame(2, DB::table('client_membership_events')->count());
    }

    public function test_membership_lifecycle_events_reject_eloquent_mutation_and_deletion(): void
    {
        $client = $this->client();
        $admin = $this->createAdminUser(['name' => 'Agency Owner', 'email' => 'owner@example.test', 'role' => 'owner']);
        $membership = ClientMembership::create([
            'user_id' => $client->id,
            'tier_name' => 'Signature',
            'benefits_summary' => 'A dedicated concierge contact.',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'assigned_by' => $admin->id,
        ]);
        $eventId = DB::table('client_membership_events')->insertGetId([
            'membership_id' => $membership->id,
            'client_id' => $client->id,
            'actor_id' => $admin->id,
            'event_type' => 'membership.assigned',
            'before_state' => null,
            'after_state' => json_encode(['status' => 'active']),
            'reason' => 'Initial assignment',
            'created_at' => now(),
        ]);

        $event = \App\Models\ClientMembershipEvent::query()->findOrFail($eventId);
        try {
            $event->reason = 'tampered';
            $event->save();
            $this->fail('Membership events must reject updates.');
        } catch (\LogicException $exception) {
            $this->assertSame('Membership lifecycle events are append-only.', $exception->getMessage());
        }

        try {
            $event->delete();
            $this->fail('Membership events must reject deletion.');
        } catch (\LogicException $exception) {
            $this->assertSame('Membership lifecycle events are append-only.', $exception->getMessage());
        }
    }
}
