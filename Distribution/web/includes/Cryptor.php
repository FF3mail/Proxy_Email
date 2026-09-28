<?php
declare(strict_types=1);

namespace MailProxy;

use RuntimeException;

class Cryptor
{
    public const KEY_FILE = '/etc/mail-proxy/crypto.key';
    public const CIPHER_CBC = 'AES-256-CBC';
    public const CIPHER_GCM = 'aes-256-gcm';
    public const IV_LENGTH = 16;
    public const GCM_PREFIX = 'gcm:';
    public const GCM_NONCE_LENGTH = 12;
    public const GCM_TAG_LENGTH = 16;
    public const EXPECTED_KEY_HEX_LENGTH = 64;

    private string $key;

    public function __construct()
    {
        $rawKey = @file_get_contents(self::KEY_FILE);

        if ($rawKey === false || trim($rawKey) === '') {
            throw new RuntimeException(
                'Crypto key file not found or empty: ' . self::KEY_FILE
            );
        }

        $trimmed = trim($rawKey);

        if (strlen($trimmed) !== self::EXPECTED_KEY_HEX_LENGTH || !ctype_xdigit($trimmed)) {
            throw new RuntimeException(
                'crypto.key должен содержать ровно '
                . self::EXPECTED_KEY_HEX_LENGTH
                . ' шестнадцатеричных символов (256 бит). Текущая длина: '
                . strlen($trimmed)
            );
        }

        $this->key = hash('sha256', $trimmed, true);
    }

    public function encrypt(string $plainText): string
    {
        $nonce = random_bytes(self::GCM_NONCE_LENGTH);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER_GCM,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::GCM_TAG_LENGTH
        );

        if ($cipherText === false || $tag === '') {
            throw new RuntimeException('Encryption failed (AES-256-GCM)');
        }

        return self::GCM_PREFIX . base64_encode($nonce . $tag . $cipherText);
    }

    public function decrypt(string $cipherText): string
    {
        if (str_starts_with($cipherText, self::GCM_PREFIX)) {
            return $this->decryptGcm(substr($cipherText, strlen(self::GCM_PREFIX)));
        }

        return $this->decryptLegacyCbc($cipherText);
    }

    private function decryptGcm(string $payloadBase64): string
    {
        $decoded = base64_decode($payloadBase64, true);

        $minLen = self::GCM_NONCE_LENGTH + self::GCM_TAG_LENGTH + 1;

        if ($decoded === false || strlen($decoded) < $minLen) {
            throw new RuntimeException(
                'Повреждённые зашифрованные данные (GCM): некорректный base64 или слишком короткая запись'
            );
        }

        $nonce = substr($decoded, 0, self::GCM_NONCE_LENGTH);
        $tag = substr($decoded, self::GCM_NONCE_LENGTH, self::GCM_TAG_LENGTH);
        $encrypted = substr($decoded, self::GCM_NONCE_LENGTH + self::GCM_TAG_LENGTH);

        $plainText = openssl_decrypt(
            $encrypted,
            self::CIPHER_GCM,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        if ($plainText === false) {
            throw new RuntimeException(
                'Ошибка расшифровки (GCM): неверный ключ или повреждённые данные'
            );
        }

        return $plainText;
    }

    private function decryptLegacyCbc(string $cipherText): string
    {
        $decoded = base64_decode($cipherText, true);

        if ($decoded === false) {
            throw new RuntimeException(
                'Повреждённые зашифрованные данные (CBC): некорректный base64'
            );
        }

        if (strlen($decoded) < self::IV_LENGTH) {
            throw new RuntimeException(
                'Повреждённые зашифрованные данные (CBC): запись короче длины IV ('
                . strlen($decoded) . ' байт)'
            );
        }

        $encrypted = substr($decoded, self::IV_LENGTH);

        if ($encrypted === '' || (strlen($encrypted) % 16) !== 0) {
            $preview = bin2hex(substr($decoded, 0, min(8, strlen($decoded))));

            throw new RuntimeException(
                'Повреждённые зашифрованные данные (CBC): длина ciphertext не кратна 16 '
                . '(длина=' . strlen($encrypted) . ', hex=' . $preview . '...)'
            );
        }

        $iv = substr($decoded, 0, self::IV_LENGTH);

        $plainText = openssl_decrypt(
            $encrypted,
            self::CIPHER_CBC,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        if ($plainText === false) {
            throw new RuntimeException(
                'Ошибка расшифровки (CBC): неверный crypto.key или повреждённые данные'
            );
        }

        return $plainText;
    }
}
