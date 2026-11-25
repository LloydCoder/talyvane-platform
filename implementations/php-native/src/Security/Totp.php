<?php
declare(strict_types=1);

namespace Talyvane\Security;

final class Totp
{
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        if ($bytes < 16 || $bytes > 64) {
            throw new \InvalidArgumentException('TOTP secret size must be between 16 and 64 bytes.');
        }
        return self::base32Encode(random_bytes($bytes));
    }

    public static function codeAt(string $base32Secret, int $timestamp, int $digits = 6, string $algorithm = 'sha1'): string
    {
        if ($digits < 6 || $digits > 8 || !in_array($algorithm, ['sha1', 'sha256', 'sha512'], true)) {
            throw new \InvalidArgumentException('Unsupported TOTP parameters.');
        }
        $key = self::base32Decode($base32Secret);
        $counter = intdiv($timestamp, 30);
        $high = intdiv($counter, 0x100000000);
        $low = $counter % 0x100000000;
        $digest = hash_hmac($algorithm, pack('N2', $high, $low), $key, true);
        $offset = ord($digest[strlen($digest) - 1]) & 0x0f;
        $binary = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;
        $modulus = 10 ** $digits;
        return str_pad((string) ($binary % $modulus), $digits, '0', STR_PAD_LEFT);
    }

    /** Returns the matched time counter, or null. Caller must persist it atomically to prevent replay. */
    public static function verify(string $base32Secret, string $submitted, int $now, int $lastAcceptedCounter = -1, int $window = 1): ?int
    {
        if (!preg_match('/^[0-9]{6}$/D', $submitted) || $window < 0 || $window > 2) {
            return null;
        }
        $current = intdiv($now, 30);
        for ($counter = $current - $window; $counter <= $current + $window; $counter++) {
            if ($counter <= $lastAcceptedCounter || $counter < 0) {
                continue;
            }
            if (hash_equals(self::codeAt($base32Secret, $counter * 30), $submitted)) {
                return $counter;
            }
        }
        return null;
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0');
            }
            $encoded .= self::BASE32[bindec($chunk)];
        }
        return $encoded;
    }

    private static function base32Decode(string $value): string
    {
        $value = strtoupper(rtrim($value, '='));
        if ($value === '' || !preg_match('/^[A-Z2-7]+$/D', $value)) {
            throw new \InvalidArgumentException('Invalid Base32 TOTP secret.');
        }
        $bits = '';
        foreach (str_split($value) as $char) {
            $position = strpos(self::BASE32, $char);
            if ($position === false) {
                throw new \InvalidArgumentException('Invalid Base32 TOTP secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $binary = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }
        if (strlen($binary) < 10) {
            throw new \InvalidArgumentException('TOTP secret is too short.');
        }
        return $binary;
    }
}
