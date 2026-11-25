<?php
declare(strict_types=1);

namespace Talyvane\Application\Safeguarding;

use RuntimeException;
use Talyvane\Security\SecretBox;

/** Local envelope encryption: unique case key wrapped by the application key. */
final class LocalSafeguardingCaseKeyProvider implements SafeguardingCaseKeyProvider
{
    private SecretBox $applicationBox;

    public function __construct(string $applicationKey)
    {
        $this->applicationBox=new SecretBox($applicationKey);
    }

    public function name(): string
    {
        return 'local';
    }

    public function supportsDestruction(): bool
    {
        return false;
    }

    public function destroy(string $caseId,string $wrappedKey): void
    {
        throw new RuntimeException('Local application-key custody cannot guarantee cryptographic erasure across retained database and configuration backups.');
    }

    public function wrap(string $caseId,string $caseKey): string
    {
        if (strlen($caseKey)!==32) {
            throw new RuntimeException('Safeguarding case keys must contain 256 bits.');
        }
        return $this->applicationBox->encrypt($caseKey,'safeguarding-case-key-v1:'.$caseId);
    }

    public function unwrap(string $caseId,string $wrappedKey): string
    {
        $key=$this->applicationBox->decrypt($wrappedKey,'safeguarding-case-key-v1:'.$caseId);
        if (strlen($key)!==32) {
            throw new RuntimeException('Wrapped safeguarding case key is invalid.');
        }
        return $key;
    }
}
