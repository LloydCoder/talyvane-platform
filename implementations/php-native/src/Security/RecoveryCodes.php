<?php
declare(strict_types=1);

namespace Talyvane\Security;

final class RecoveryCodes
{
    /** @return array{plain:list<string>,hashes:list<string>} Display plain codes once; persist hashes only. */
    public static function issue(int $count = 10): array
    {
        if ($count < 5 || $count > 20) {
            throw new \InvalidArgumentException('Recovery code count must be between 5 and 20.');
        }
        $plain = [];
        $hashes = [];
        for ($i = 0; $i < $count; $i++) {
            $code = strtoupper(bin2hex(random_bytes(10)));
            $plain[] = $code;
            $hashes[] = password_hash($code, PASSWORD_DEFAULT);
        }
        return ['plain' => $plain, 'hashes' => $hashes];
    }

    /**
     * Verifies one code and returns the remaining hashes. Persist the returned list
     * in the same database transaction as the protected operation to prevent replay.
     *
     * @param list<string> $hashes
     * @return list<string>|null
     */
    public static function consume(string $submitted, array $hashes): ?array
    {
        if (!preg_match('/^[A-F0-9]{20}$/D', strtoupper($submitted))) {
            return null;
        }
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && password_verify(strtoupper($submitted), $hash)) {
                array_splice($hashes, $index, 1);
                return array_values($hashes);
            }
        }
        return null;
    }
}
