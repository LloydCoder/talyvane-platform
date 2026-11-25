<?php
declare(strict_types=1);

namespace Talyvane\Security;

final class SecretBox
{
    private string $key;

    public function __construct(string $applicationKey)
    {
        if (strlen($applicationKey) < 32) {
            throw new \InvalidArgumentException('Application encryption key must contain at least 32 bytes.');
        }
        $this->key = hash('sha256', $applicationKey, true);
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new \RuntimeException('Secret encryption failed.');
        }
        return 'v1.' . base64_encode($nonce) . '.' . base64_encode($tag) . '.' . base64_encode($ciphertext);
    }

    public function decrypt(string $envelope, string $context): string
    {
        $parts = explode('.', $envelope);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new \RuntimeException('Encrypted secret envelope is invalid.');
        }
        $nonce = base64_decode($parts[1], true);
        $tag = base64_decode($parts[2], true);
        $ciphertext = base64_decode($parts[3], true);
        if (!is_string($nonce) || strlen($nonce) !== 12 || !is_string($tag) || strlen($tag) !== 16 || !is_string($ciphertext)) {
            throw new \RuntimeException('Encrypted secret envelope is malformed.');
        }
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $context);
        if (!is_string($plaintext)) {
            throw new \RuntimeException('Encrypted secret authentication failed.');
        }
        return $plaintext;
    }
}
