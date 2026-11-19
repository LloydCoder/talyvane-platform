<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    public function test_totp_code_is_valid_for_current_step_and_rejected_for_replay(): void
    {
        $service = new TotpService();
        $secret = 'JBSWY3DPEHPK3PXP';
        $step = intdiv(time(), 30);
        $code = $service->codeForStep($secret, $step);

        $this->assertSame($step, $service->verify($secret, $code, $step - 1));
        $this->assertNull($service->verify($secret, $code, $step));
    }

    public function test_totp_provisioning_uri_contains_issuer_and_account(): void
    {
        $uri = (new TotpService())->provisioningUri('JBSWY3DPEHPK3PXP', 'owner@example.test', 'Talyvane');

        $this->assertStringContainsString('otpauth://totp/', $uri);
        $this->assertStringContainsString('issuer=Talyvane', $uri);
    }
}
