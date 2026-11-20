<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SuperAdminCanonicalIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function designatedSuperAdmin(): User
    {
        return $this->createAdminUser([
            'name' => 'Designated Deployment Super Admin',
            'email' => 'designated-root@example.test',
            'role' => 'super_admin',
        ]);
    }

    private function databaseEscalatedUser(): User
    {
        $user = $this->createAdminUser([
            'name' => 'Escalated Account',
            'email' => 'escalated@example.test',
            'role' => 'admin',
        ]);

        DB::table('users')->where('id', $user->id)->update(['role' => 'super_admin']);

        return $user->fresh();
    }

    public function test_direct_database_role_escalation_cannot_authenticate_as_super_admin(): void
    {
        $this->designatedSuperAdmin();
        $escalated = $this->databaseEscalatedUser();

        $this->post('/admin/login', [
            'email' => $escalated->email,
            'password' => 'Strong-Testing-Password-934!',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_direct_database_role_escalation_cannot_authorize_an_authenticated_super_admin_session(): void
    {
        $this->designatedSuperAdmin();
        $escalated = $this->databaseEscalatedUser();

        $this->actingAs($escalated)
            ->get('/admin/features')
            ->assertForbidden();
    }

    public function test_pending_mfa_session_cannot_complete_for_a_non_designated_super_admin(): void
    {
        $this->designatedSuperAdmin();
        $escalated = $this->databaseEscalatedUser();

        $this->withSession([
            'pending_admin_mfa_user_id' => $escalated->id,
            'pending_admin_mfa_session_version' => (int) $escalated->admin_session_version,
            'pending_admin_mfa_remember' => false,
        ])->get('/admin/mfa/challenge')->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_designated_super_admin_can_complete_password_and_mfa_authentication(): void
    {
        $superAdmin = $this->designatedSuperAdmin();

        $this->post('/admin/login', [
            'email' => $superAdmin->email,
            'password' => 'Strong-Testing-Password-934!',
        ])->assertRedirect('/admin/mfa/challenge');

        $step = intdiv(time(), 30);
        $code = app(TotpService::class)->codeForStep($superAdmin->mfa_secret, $step);

        $this->post('/admin/mfa/challenge', ['code' => $code])
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($superAdmin);
        $this->get('/admin/features')->assertOk();
    }

    public function test_designated_super_admin_remains_authorized(): void
    {
        $superAdmin = $this->designatedSuperAdmin();

        $this->actingAs($superAdmin)
            ->get('/admin/features')
            ->assertOk();
    }
}
