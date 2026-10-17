<?php
/**
 * Attention Cooperative service-signature registration and verification.
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

use MillionDollarScript\Core\ApiAccess;
use MillionDollarScript\Core\ServiceSignatureRequest;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceSignature {

    public const ENDPOINT_ID = 'cooperative-placement-create';
    public const SCOPE = 'cooperative.placement.write';
    public const ROUTE = '/million-dollar-script/v1/cooperative/placements';

    public static function register(): void {
        if (!class_exists(ApiAccess::class) || !CredentialCipher::available()) {
            return;
        }

        foreach (ServiceCredentialRepository::registrable() as $credential) {
            ApiAccess::register_service_signature_verifier(
                self::ENDPOINT_ID,
                self::SCOPE,
                (string) $credential['service_id'],
                [__CLASS__, 'verify'],
                ['v1']
            );
        }
    }

    /**
     * @return bool|\WP_Error
     */
    public static function verify(ServiceSignatureRequest $signature, \WP_REST_Request $request) {
        if (
            'v1' !== $signature->version()
            || 'POST' !== $signature->method()
            || self::ROUTE !== $signature->route()
            || self::SCOPE !== $signature->scope()
            || '' === $signature->idempotency_key()
        ) {
            return ApiAccess::service_signature_error('invalid');
        }

        $credential = ServiceCredentialRepository::find_by_service_id($signature->service_id());
        if (!$credential || !ServiceCredentialRepository::usable($credential, self::SCOPE)) {
            return ApiAccess::service_signature_error('invalid');
        }

        $secret = CredentialCipher::decrypt(
            (string) $credential['secret_envelope'],
            (string) $credential['service_id'],
            absint($credential['partner_id']),
            absint($credential['pool_id'])
        );
        if (is_wp_error($secret)) {
            return ApiAccess::service_signature_error('invalid');
        }

        $expected = hash_hmac('sha256', $signature->canonical_string(), $secret);
        CredentialCipher::wipe($secret);
        if (!hash_equals($expected, $signature->signature())) {
            return ApiAccess::service_signature_error('invalid');
        }

        $claimed = ServiceCredentialRepository::claim_nonce($credential, $signature->nonce());

        return is_wp_error($claimed) ? $claimed : true;
    }

    public static function credential_for_request(\WP_REST_Request $request): ?array {
        if (!class_exists(ApiAccess::class)) {
            return null;
        }

        $identity = ApiAccess::service_identity($request);
        if (!is_array($identity) || 'service_signature' !== (string) ($identity['authentication'] ?? '')) {
            return null;
        }

        $credential = ServiceCredentialRepository::find_by_service_id((string) ($identity['service_id'] ?? ''));

        return $credential && ServiceCredentialRepository::usable($credential, self::SCOPE) ? $credential : null;
    }
}
