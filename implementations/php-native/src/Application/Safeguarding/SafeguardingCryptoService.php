<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use RuntimeException;
use Talyvane\Security\SecretBox;

final class SafeguardingCryptoService
{
    public function __construct(private SafeguardingCaseKeyProvider $provider) {}

    public function generateCaseKey(): string
    {
        return random_bytes(32);
    }

    public function wrapCaseKey(string $caseId,string $caseKey): string
    {
        return $this->provider->wrap($caseId,$caseKey);
    }

    public function unwrapCaseKey(string $caseId,?string $wrappedKey): string
    {
        if (!is_string($wrappedKey) || $wrappedKey==='') {
            throw new RuntimeException('Safeguarding case content key has been destroyed or is unavailable.');
        }
        return $this->provider->unwrap($caseId,$wrappedKey);
    }

    public function encryptField(string $caseId,string $field,string $plaintext,string $caseKey): string
    {
        return (new SecretBox($caseKey))->encrypt($plaintext,'safeguarding-case-content-v1:'.$caseId.':'.$field);
    }

    public function decryptField(string $caseId,string $field,string $ciphertext,string $caseKey): string
    {
        return (new SecretBox($caseKey))->decrypt($ciphertext,'safeguarding-case-content-v1:'.$caseId.':'.$field);
    }

    public function encryptEventNote(string $caseId,string $eventId,string $plaintext,string $caseKey): string
    {
        return (new SecretBox($caseKey))->encrypt($plaintext,'safeguarding-case-event-v1:'.$caseId.':'.$eventId);
    }

    public function decryptEventNote(string $caseId,string $eventId,string $ciphertext,string $caseKey): string
    {
        return (new SecretBox($caseKey))->decrypt($ciphertext,'safeguarding-case-event-v1:'.$caseId.':'.$eventId);
    }

    public function encryptHoldReason(string $caseId,string $eventId,string $plaintext,string $caseKey): string
    {
        return (new SecretBox($caseKey))->encrypt($plaintext,'safeguarding-case-hold-v1:'.$caseId.':'.$eventId);
    }

    public function decryptHoldReason(string $caseId,string $eventId,string $ciphertext,string $caseKey): string
    {
        return (new SecretBox($caseKey))->decrypt($ciphertext,'safeguarding-case-hold-v1:'.$caseId.':'.$eventId);
    }

    public function providerName(): string
    {
        return $this->provider->name();
    }

    public function supportsDestruction(): bool
    {
        return $this->provider->supportsDestruction();
    }

    public function destroyCaseKey(string $caseId,string $wrappedKey): void
    {
        $this->provider->destroy($caseId,$wrappedKey);
    }
}
