<?php

namespace App\Services;

use App\Models\SafeguardingCase;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SafeguardingCaseContentService
{
    public function store(SafeguardingCase $case, array $content): void
    {
        $dataKey = random_bytes(32);
        $encrypter = new Encrypter($dataKey, (string) config('app.cipher'));
        $provider = strtolower(trim((string) config('talyvane.safeguarding.key_provider', 'local')));
        $keyReference = null;

        if ($provider === 'vault') {
            $keyReference = 'case-'.Str::uuid()->toString();
            $keyCiphertext = app(SafeguardingCaseKeyVault::class)->createAndEncrypt($keyReference, $dataKey);
        } elseif ($provider === 'local') {
            $keyCiphertext = Crypt::encryptString(base64_encode($dataKey));
        } else {
            throw new RuntimeException('Unsupported safeguarding case key provider.');
        }

        DB::table('safeguarding_case_contents')->insert([
            'case_id' => $case->id,
            'key_provider' => $provider,
            'key_reference' => $keyReference,
            'key_ciphertext' => $keyCiphertext,
            'summary_ciphertext' => $this->encryptNullable($encrypter, $content['summary'] ?? null),
            'details_ciphertext' => $this->encryptNullable($encrypter, $content['details'] ?? null),
            'subject_reference_ciphertext' => $this->encryptNullable($encrypter, $content['subject_reference'] ?? null),
            'legal_hold_reason_ciphertext' => null,
            'legal_hold_release_reason_ciphertext' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function read(SafeguardingCase $case): array
    {
        $row = DB::table('safeguarding_case_contents')->where('case_id', $case->id)->first();
        if (! $row || ! $row->key_ciphertext) {
            return [
                'summary' => null,
                'details' => null,
                'subject_reference' => null,
                'legal_hold_reason' => null,
                'legal_hold_release_reason' => null,
                'content_erased' => (bool) ($row?->retention_erased_at),
            ];
        }

        $encrypter = $this->encrypterFromRow($row);

        return [
            'summary' => $this->decryptNullable($encrypter, $row->summary_ciphertext),
            'details' => $this->decryptNullable($encrypter, $row->details_ciphertext),
            'subject_reference' => $this->decryptNullable($encrypter, $row->subject_reference_ciphertext),
            'legal_hold_reason' => $this->decryptNullable($encrypter, $row->legal_hold_reason_ciphertext),
            'legal_hold_release_reason' => $this->decryptNullable($encrypter, $row->legal_hold_release_reason_ciphertext),
            'content_erased' => false,
        ];
    }

    public function encryptForCase(int $caseId, string $value): string
    {
        return $this->encrypterForCase($caseId)->encryptString($value);
    }

    public function decryptForCase(int $caseId, string $ciphertext): ?string
    {
        $row = DB::table('safeguarding_case_contents')->where('case_id', $caseId)->first();
        if (! $row || ! $row->key_ciphertext) {
            return null;
        }

        return $this->encrypterFromRow($row)->decryptString($ciphertext);
    }

    public function updateFields(int $caseId, array $values): void
    {
        $row = DB::table('safeguarding_case_contents')
            ->where('case_id', $caseId)
            ->lockForUpdate()
            ->first();

        if (! $row || ! $row->key_ciphertext) {
            throw ValidationException::withMessages(['legal_hold' => 'This case content is unavailable after retention erasure and cannot be placed under a new hold.']);
        }

        $encrypter = $this->encrypterFromRow($row);
        $allowed = [
            'legal_hold_reason' => 'legal_hold_reason_ciphertext',
            'legal_hold_release_reason' => 'legal_hold_release_reason_ciphertext',
        ];
        $updates = ['updated_at' => now()];

        foreach ($values as $field => $value) {
            if (! array_key_exists($field, $allowed)) {
                throw new RuntimeException('Unsupported safeguarding case content field.');
            }

            $updates[$allowed[$field]] = $this->encryptNullable($encrypter, $value);
        }

        DB::table('safeguarding_case_contents')->where('case_id', $caseId)->update($updates);
    }

    public function eraseKey(int $caseId, string $policyVersion, ?int $actorId = null): bool
    {
        $row = DB::table('safeguarding_case_contents')
            ->where('case_id', $caseId)
            ->lockForUpdate()
            ->first();

        if (! $row || ! $row->key_ciphertext || $row->retention_erased_at !== null) {
            return false;
        }

        if (($row->key_provider ?? 'local') === 'vault') {
            if (! is_string($row->key_reference) || $row->key_reference === '') {
                throw new RuntimeException('External safeguarding key reference is missing; retention erasure is unsafe.');
            }

            app(SafeguardingCaseKeyVault::class)->destroyKey($row->key_reference);
        }

        DB::table('safeguarding_case_contents')->where('case_id', $caseId)->update([
            'key_ciphertext' => null,
            'retention_erased_at' => now(),
            'retention_erased_by' => $actorId,
            'retention_policy_version' => $policyVersion,
            'updated_at' => now(),
        ]);

        return true;
    }

    private function encrypterForCase(int $caseId): Encrypter
    {
        $row = DB::table('safeguarding_case_contents')->where('case_id', $caseId)->first();
        if (! $row || ! $row->key_ciphertext) {
            throw new RuntimeException('Safeguarding case content key is unavailable.');
        }

        return $this->encrypterFromCiphertext($row->key_ciphertext);
    }

    private function encrypterFromRow(object $row): Encrypter
    {
        if (($row->key_provider ?? 'local') === 'vault') {
            if (! is_string($row->key_reference) || $row->key_reference === '') {
                throw new RuntimeException('External safeguarding key reference is missing.');
            }

            $dataKey = app(SafeguardingCaseKeyVault::class)->decrypt($row->key_reference, (string) $row->key_ciphertext);
        } else {
            $dataKey = base64_decode(Crypt::decryptString((string) $row->key_ciphertext), true);
        }

        if (! is_string($dataKey) || strlen($dataKey) !== 32) {
            throw new RuntimeException('Safeguarding case content key is invalid.');
        }

        return new Encrypter($dataKey, (string) config('app.cipher'));
    }

    private function encryptNullable(Encrypter $encrypter, ?string $value): ?string
    {
        return $value === null ? null : $encrypter->encryptString($value);
    }

    private function decryptNullable(Encrypter $encrypter, ?string $value): ?string
    {
        return $value === null ? null : $encrypter->decryptString($value);
    }
}
