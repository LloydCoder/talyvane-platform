<?php
declare(strict_types=1);

namespace Talyvane\Application\ModelAgency;

use InvalidArgumentException;
use Talyvane\Security\SecretBox;

/** Field-scoped encryption and non-reversible lookup for applicant personal data. */
final class ModelApplicantPiiCipher
{
    public function __construct(private SecretBox $box,private string $fingerprintKey)
    {
        if (strlen($fingerprintKey)<32) { throw new InvalidArgumentException('Applicant lookup key must contain at least 256 bits.'); }
    }

    public function encrypt(string $applicationId,string $field,?string $value): ?string
    {
        if ($value===null) { return null; }
        return $this->box->encrypt($value,$this->context($applicationId,$field));
    }

    public function decrypt(string $applicationId,string $field,?string $envelope): ?string
    {
        if ($envelope===null || $envelope==='') { return null; }
        return $this->box->decrypt($envelope,$this->context($applicationId,$field));
    }

    public function emailFingerprint(string $email): string
    {
        return hash_hmac('sha256',strtolower(trim($email)),$this->fingerprintKey);
    }

    private function context(string $applicationId,string $field): string
    {
        if (!preg_match('/^[0-9a-f-]{36}$/iD',$applicationId) ||
            !in_array($field,['applicant_name','email','phone','city','portfolio_url','experience_summary'],true)) {
            throw new InvalidArgumentException('Applicant personal-data encryption context is invalid.');
        }
        return 'model-applicant-pii-v1:'.$applicationId.':'.$field;
    }
}
