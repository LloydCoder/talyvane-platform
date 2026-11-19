<?php

namespace Tests\Feature;

use App\Models\PaymentMethod;
use App\Models\PaymentSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_methods_are_disabled_by_default(): void
    {
        $settings = PaymentSetting::current();

        $this->assertFalse($settings->payments_enabled);
        $this->assertFalse($settings->crypto_enabled);
        $this->assertFalse($settings->cards_enabled);
        $this->assertFalse($settings->manual_enabled);
        $this->assertDatabaseCount('payment_methods', 0);
    }

    public function test_super_admin_can_configure_independent_payment_switches(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Platform Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-Admin-Password-934!',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->put('/admin/payment-settings', [
                'payments_enabled' => '1',
                'crypto_enabled' => '1',
                'cards_enabled' => '0',
                'manual_enabled' => '1',
                'booking_payments_enabled' => '1',
                'membership_payments_enabled' => '0',
            ])
            ->assertRedirect('/admin/payment-settings');

        $this->assertDatabaseHas('payment_settings', [
            'payments_enabled' => true,
            'crypto_enabled' => true,
            'cards_enabled' => false,
            'manual_enabled' => true,
            'booking_payments_enabled' => true,
            'membership_payments_enabled' => false,
        ]);
    }

    public function test_operator_cannot_change_payment_configuration(): void
    {
        $operator = User::create([
            'name' => 'Operations User',
            'email' => 'operator@example.test',
            'password' => 'Strong-Testing-Password-934!',
            'role' => 'operator',
            'is_active' => true,
        ]);

        $this->actingAs($operator)
            ->put('/admin/payment-settings', ['payments_enabled' => '1'])
            ->assertForbidden();
    }

    public function test_crypto_receiving_method_is_created_disabled_until_address_review(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Platform Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-Admin-Password-934!',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post('/admin/payment-methods', [
                'asset' => 'BTC',
                'network' => 'Bitcoin',
                'address' => 'bc1qexampleaddressforconfigurationonly000000000000000000000',
                'confirmations_required' => 3,
                'purpose_booking' => '1',
            ])
            ->assertRedirect('/admin/payment-methods');

        $this->assertDatabaseHas('payment_methods', [
            'asset' => 'BTC',
            'network' => 'Bitcoin',
            'enabled' => false,
            'verification_status' => 'pending',
        ]);
    }

    public function test_usdt_and_usdc_methods_require_a_token_contract(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Platform Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-Admin-Password-934!',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->from('/admin/payment-methods')
            ->post('/admin/payment-methods', [
                'asset' => 'USDT',
                'network' => 'Example Network',
                'address' => 'TExampleReceivingAddress123456789',
                'confirmations_required' => 12,
                'purpose_booking' => '1',
            ])
            ->assertRedirect('/admin/payment-methods')
            ->assertSessionHasErrors('token_contract');
    }
    public function test_receiving_method_cannot_be_enabled_before_review_and_matching_purpose_switches(): void
    {
        $admin = $this->createAdminUser([
            'name' => 'Platform Admin',
            'email' => 'admin@example.test',
            'password' => 'Strong-Admin-Password-934!',
            'role' => 'admin',
            'is_active' => true,
        ]);
        PaymentSetting::create([
            'payments_enabled' => true,
            'crypto_enabled' => true,
            'booking_payments_enabled' => true,
        ]);
        $method = PaymentMethod::create([
            'asset' => 'BTC',
            'network' => 'Bitcoin',
            'address' => 'bc1qexampleaddressforconfigurationonly000000000000000000000',
            'confirmations_required' => 3,
            'purpose_booking' => true,
            'enabled' => false,
            'verification_status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->patch('/admin/payment-methods/'.$method->id.'/enabled', ['enabled' => '1'])
            ->assertSessionHasErrors('enabled');

        $this->post('/admin/payment-methods/'.$method->id.'/review', [
            'verification_reference' => 'network-review-reference-123',
            'reviewed' => '1',
        ])->assertRedirect('/admin/payment-methods');

        $this->patch('/admin/payment-methods/'.$method->id.'/enabled', ['enabled' => '1'])
            ->assertRedirect('/admin/payment-methods');

        $this->assertDatabaseHas('payment_methods', [
            'id' => $method->id,
            'enabled' => true,
            'verification_status' => 'reviewed',
        ]);
    }

}
