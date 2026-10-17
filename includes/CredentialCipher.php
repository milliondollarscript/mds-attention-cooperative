<?php
/**
 * Authenticated encryption for Attention Cooperative service secrets.
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

if (!defined('ABSPATH')) {
    exit;
}

final class CredentialCipher {

    private const CONTEXT = 'mds-attention-cooperative/service-secret/v1';

    public static function available(): bool {
        return function_exists('hash_hkdf')
            && (
                function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
                || (
                    function_exists('openssl_encrypt')
                    && in_array('aes-256-gcm', array_map('strtolower', openssl_get_cipher_methods()), true)
                )
            );
    }

    /**
     * @return string|\WP_Error
     */
    public static function encrypt($secret, $service_id, $partner_id, $pool_id) {
        if (!self::available()) {
            return new \WP_Error(
                'mds_attention_cooperative_cipher_unavailable',
                __('Authenticated secret encryption is unavailable on this server.', 'mds-attention-cooperative')
            );
        }

        $salt = random_bytes(16);
        $source = self::key_source();
        $key = self::derive_key($salt, $source);
        $aad = self::aad($service_id, $partner_id, $pool_id);
        $envelope = [
            'v' => 1,
            'salt' => self::base64url_encode($salt),
            'source' => $source,
        ];

        if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt((string) $secret, $aad, $nonce, $key);
            $envelope['alg'] = 'xchacha20poly1305';
            $envelope['nonce'] = self::base64url_encode($nonce);
            $envelope['ciphertext'] = self::base64url_encode($ciphertext);
        } else {
            $nonce = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt(
                (string) $secret,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $nonce,
                $tag,
                $aad,
                16
            );
            if (false === $ciphertext || 16 !== strlen($tag)) {
                self::wipe($key);
                return new \WP_Error(
                    'mds_attention_cooperative_encrypt_failed',
                    __('The service secret could not be encrypted.', 'mds-attention-cooperative')
                );
            }
            $envelope['alg'] = 'aes-256-gcm';
            $envelope['nonce'] = self::base64url_encode($nonce);
            $envelope['ciphertext'] = self::base64url_encode($ciphertext);
            $envelope['tag'] = self::base64url_encode($tag);
        }

        self::wipe($key);

        return (string) wp_json_encode($envelope, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return string|\WP_Error
     */
    public static function decrypt($encoded, $service_id, $partner_id, $pool_id) {
        $envelope = json_decode((string) $encoded, true);
        if (!is_array($envelope) || 1 !== absint($envelope['v'] ?? 0)) {
            return self::invalid_envelope();
        }

        $source = sanitize_key((string) ($envelope['source'] ?? ''));
        if (!in_array($source, ['constant', 'wordpress_salts'], true)) {
            return self::invalid_envelope();
        }

        $salt = self::base64url_decode((string) ($envelope['salt'] ?? ''));
        $nonce = self::base64url_decode((string) ($envelope['nonce'] ?? ''));
        $ciphertext = self::base64url_decode((string) ($envelope['ciphertext'] ?? ''));
        if (16 !== strlen($salt) || '' === $nonce || '' === $ciphertext) {
            return self::invalid_envelope();
        }

        $key = self::derive_key($salt, $source);
        if (is_wp_error($key)) {
            return $key;
        }
        $aad = self::aad($service_id, $partner_id, $pool_id);
        $algorithm = sanitize_key((string) ($envelope['alg'] ?? ''));
        $plaintext = false;

        if ('xchacha20poly1305' === $algorithm && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
            if (SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES === strlen($nonce)) {
                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
            }
        } elseif ('aes-256-gcm' === $algorithm && function_exists('openssl_decrypt')) {
            $tag = self::base64url_decode((string) ($envelope['tag'] ?? ''));
            if (12 === strlen($nonce) && 16 === strlen($tag)) {
                $plaintext = openssl_decrypt(
                    $ciphertext,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    $aad
                );
            }
        }

        self::wipe($key);

        return false === $plaintext ? self::invalid_envelope() : (string) $plaintext;
    }

    public static function wipe(&$value): void {
        if (is_string($value) && function_exists('sodium_memzero')) {
            try {
                sodium_memzero($value);
            } catch (\Throwable $exception) {
                // WordPress's sodium_compat shim exposes this function but cannot
                // securely wipe PHP-managed memory. Cleanup must not break the
                // credential workflow when native libsodium is unavailable.
            }
        }
        $value = '';
    }

    /**
     * @return string|\WP_Error
     */
    private static function derive_key($salt, $source) {
        $root = self::root_key($source);
        if (is_wp_error($root)) {
            return $root;
        }

        return hash_hkdf('sha256', $root, 32, self::CONTEXT, (string) $salt);
    }

    /**
     * @return string|\WP_Error
     */
    private static function root_key($source) {
        if ('constant' === $source) {
            if (defined('MDS_ATTENTION_COOPERATIVE_MASTER_KEY') && strlen((string) MDS_ATTENTION_COOPERATIVE_MASTER_KEY) >= 32) {
                return (string) MDS_ATTENTION_COOPERATIVE_MASTER_KEY;
            }

            return self::invalid_envelope();
        }

        $secure = function_exists('wp_salt') ? wp_salt('secure_auth') : (defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '');
        $nonce = function_exists('wp_salt') ? wp_salt('nonce') : (defined('NONCE_KEY') ? NONCE_KEY : '');

        return (string) $secure . "\0" . (string) $nonce;
    }

    private static function key_source(): string {
        return defined('MDS_ATTENTION_COOPERATIVE_MASTER_KEY') && strlen((string) MDS_ATTENTION_COOPERATIVE_MASTER_KEY) >= 32
            ? 'constant'
            : 'wordpress_salts';
    }

    private static function aad($service_id, $partner_id, $pool_id): string {
        return implode("\n", ['v1', (string) $service_id, (string) absint($partner_id), (string) absint($pool_id)]);
    }

    private static function invalid_envelope(): \WP_Error {
        return new \WP_Error(
            'mds_attention_cooperative_secret_unavailable',
            __('The service credential is unavailable and must be rotated.', 'mds-attention-cooperative')
        );
    }

    private static function base64url_encode($value): string {
        return rtrim(strtr(base64_encode((string) $value), '+/', '-_'), '=');
    }

    private static function base64url_decode($value): string {
        $value = (string) $value;
        if ('' === $value || preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return '';
        }

        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);

        return false === $decoded ? '' : $decoded;
    }
}
