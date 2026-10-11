<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function app_secret_key(): string {
  $cfg = app_config();
  $configured = base64_decode((string)($cfg['credential_key'] ?? ''), true);
  if (is_string($configured) && strlen($configured) === 32) {
    return $configured;
  }

  // Upgrade fallback for installations created before credential_key existed.
  $material = implode("\0", [
    (string)($cfg['db_host'] ?? ''),
    (string)($cfg['db_name'] ?? ''),
    (string)($cfg['db_user'] ?? ''),
    (string)($cfg['db_pass'] ?? ''),
  ]);
  return hash_hmac('sha256', 'COOL Rooms credential encryption v1', $material, true);
}

function secret_encrypt(string $plaintext): string {
  if ($plaintext === '') return '';
  if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
      $plaintext,
      'cool-rooms-kiosk-code',
      $nonce,
      app_secret_key()
    );
    return 'v1:' . base64_encode($nonce . $ciphertext);
  }

  if (!function_exists('openssl_encrypt')) {
    throw new RuntimeException('Für den geschützten PIN-Speicher wird Sodium oder OpenSSL benötigt.');
  }
  $nonce = random_bytes(12);
  $tag = '';
  $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', app_secret_key(), OPENSSL_RAW_DATA, $nonce, $tag, 'cool-rooms-kiosk-code');
  if ($ciphertext === false) throw new RuntimeException('PIN konnte nicht verschlüsselt werden.');
  return 'v2:' . base64_encode($nonce . $tag . $ciphertext);
}

function secret_decrypt(string $encoded): ?string {
  if ($encoded === '') return null;
  if (str_starts_with($encoded, 'v1:') && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
    $raw = base64_decode(substr($encoded, 3), true);
    $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
    if ($raw === false || strlen($raw) <= $nonceLength) return null;
    $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
      substr($raw, $nonceLength),
      'cool-rooms-kiosk-code',
      substr($raw, 0, $nonceLength),
      app_secret_key()
    );
    return $plaintext === false ? null : $plaintext;
  }

  if (str_starts_with($encoded, 'v2:') && function_exists('openssl_decrypt')) {
    $raw = base64_decode(substr($encoded, 3), true);
    if ($raw === false || strlen($raw) <= 28) return null;
    $plaintext = openssl_decrypt(
      substr($raw, 28),
      'aes-256-gcm',
      app_secret_key(),
      OPENSSL_RAW_DATA,
      substr($raw, 0, 12),
      substr($raw, 12, 16),
      'cool-rooms-kiosk-code'
    );
    return $plaintext === false ? null : $plaintext;
  }
  return null;
}
