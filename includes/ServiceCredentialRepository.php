<?php
/**
 * Service credential, replay, rate, and idempotency storage.
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

if (!defined('ABSPATH')) {
    exit;
}

// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.PreparedSQL.NotPrepared -- Table identifiers are extension constants; values use $wpdb->prepare() or wpdb write helpers.

final class ServiceCredentialRepository {

    public const DB_VERSION = '0.3.0';
    private const SCOPES = ['cooperative.placement.write'];

    private const TABLES = [
        'credentials' => 'mds_ac_service_credentials',
        'nonces' => 'mds_ac_signature_nonces',
        'idempotency' => 'mds_ac_placement_idempotency',
    ];

    public static function maybe_install(): void {
        if (get_option('mds_attention_cooperative_db_version') !== self::DB_VERSION || !self::tables_exist()) {
            self::install();
        }
    }

    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $credentials = self::table('credentials');
        $nonces = self::table('nonces');
        $idempotency = self::table('idempotency');

        dbDelta("CREATE TABLE {$credentials} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            service_id varchar(128) NOT NULL,
            partner_id bigint(20) unsigned NOT NULL,
            pool_id bigint(20) unsigned NOT NULL,
            label varchar(191) NOT NULL,
            secret_envelope longtext NULL,
            scopes longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            expires_at datetime NOT NULL,
            retire_at datetime NULL,
            last_used_at datetime NULL,
            revealed_at datetime NULL,
            created_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            revoked_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY service_id (service_id),
            KEY partner_status (partner_id,status),
            KEY pool_id (pool_id),
            KEY expires_at (expires_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$nonces} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            credential_id bigint(20) unsigned NOT NULL,
            partner_id bigint(20) unsigned NOT NULL,
            pool_id bigint(20) unsigned NOT NULL,
            nonce_hash char(64) NOT NULL,
            decision varchar(20) NOT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY credential_nonce (credential_id,nonce_hash),
            KEY partner_created (partner_id,created_at),
            KEY expires_at (expires_at)
        ) {$charset};");

        dbDelta("CREATE TABLE {$idempotency} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            partner_id bigint(20) unsigned NOT NULL,
            pool_id bigint(20) unsigned NOT NULL,
            key_hash char(64) NOT NULL,
            request_fingerprint char(64) NOT NULL,
            state varchar(20) NOT NULL DEFAULT 'processing',
            placement_id bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY partner_key (partner_id,key_hash),
            KEY placement_id (placement_id),
            KEY state_updated (state,updated_at)
        ) {$charset};");

        update_option('mds_attention_cooperative_db_version', self::DB_VERSION, false);
    }

    /**
     * @return array|\WP_Error
     */
    public static function create($partner_id, $label, $days = 90) {
        global $wpdb;

        $partner = Repository::partner(absint($partner_id));
        $pool = $partner ? Repository::pool(absint($partner['pool_id'] ?? 0)) : null;
        if (
            !$partner
            || 'approved' !== (string) ($partner['status'] ?? '')
            || !$pool
            || 'active' !== (string) ($pool['status'] ?? '')
            || empty($pool['legal_review_completed'])
        ) {
            return new \WP_Error(
                'mds_attention_cooperative_credential_partner_invalid',
                __('Create credentials only for an approved partner in an active reviewed cooperative.', 'mds-attention-cooperative')
            );
        }
        if (!CredentialCipher::available()) {
            return new \WP_Error(
                'mds_attention_cooperative_cipher_unavailable',
                __('Authenticated secret encryption is unavailable on this server.', 'mds-attention-cooperative')
            );
        }

        $days = min(365, max(1, absint($days ?: 90)));
        $service_id = 'acsvc_' . self::base64url(random_bytes(24));
        $secret = self::base64url(random_bytes(32));
        $envelope = CredentialCipher::encrypt($secret, $service_id, absint($partner['id']), absint($pool['id']));
        if (is_wp_error($envelope)) {
            CredentialCipher::wipe($secret);
            return $envelope;
        }

        $now = self::now();
        $inserted = $wpdb->insert(self::table('credentials'), [
            'service_id' => $service_id,
            'partner_id' => absint($partner['id']),
            'pool_id' => absint($pool['id']),
            'label' => self::bounded_label($label),
            'secret_envelope' => $envelope,
            'scopes' => wp_json_encode(self::SCOPES),
            'status' => 'active',
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($days * DAY_IN_SECONDS)),
            'retire_at' => null,
            'last_used_at' => null,
            'revealed_at' => $now,
            'created_by' => get_current_user_id(),
            'created_at' => $now,
            'updated_at' => $now,
            'revoked_at' => null,
        ], ['%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s']);

        if (false === $inserted) {
            CredentialCipher::wipe($secret);
            return new \WP_Error(
                'mds_attention_cooperative_credential_create_failed',
                __('The service credential could not be created.', 'mds-attention-cooperative')
            );
        }

        $credential = self::find(absint($wpdb->insert_id));
        Repository::audit(
            'service_credential_created',
            'partner',
            absint($partner['id']),
            __('A service credential was created.', 'mds-attention-cooperative'),
            ['pool_id' => absint($pool['id']), 'credential_ref' => self::reference($service_id)]
        );

        return ['secret' => $secret, 'credential' => $credential];
    }

    /**
     * @return array|\WP_Error
     */
    public static function rotate($credential_id) {
        global $wpdb;

        $existing = self::find(absint($credential_id));
        if (!$existing || !in_array((string) $existing['status'], ['active', 'retiring'], true)) {
            return new \WP_Error('mds_attention_cooperative_credential_not_found', __('Active service credential not found.', 'mds-attention-cooperative'));
        }

        if (false === $wpdb->query('START TRANSACTION')) {
            return new \WP_Error('mds_attention_cooperative_rotation_failed', __('The service credential could not be rotated.', 'mds-attention-cooperative'));
        }
        $created = self::create(
            absint($existing['partner_id']),
            sprintf(__('%s replacement', 'mds-attention-cooperative'), (string) $existing['label']),
            90
        );
        if (is_wp_error($created)) {
            $wpdb->query('ROLLBACK');
            return $created;
        }

        $retire_at = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
        $updated = $wpdb->update(self::table('credentials'), [
            'status' => 'retiring',
            'retire_at' => $retire_at,
            'updated_at' => self::now(),
        ], ['id' => absint($existing['id'])], ['%s', '%s', '%s'], ['%d']);

        if (false === $updated) {
            self::revoke(absint($created['credential']['id'] ?? 0));
            CredentialCipher::wipe($created['secret']);
            $wpdb->query('ROLLBACK');
            return new \WP_Error('mds_attention_cooperative_rotation_failed', __('The service credential could not be rotated.', 'mds-attention-cooperative'));
        }

        if (false === $wpdb->query('COMMIT')) {
            CredentialCipher::wipe($created['secret']);
            return new \WP_Error('mds_attention_cooperative_rotation_failed', __('The service credential could not be rotated.', 'mds-attention-cooperative'));
        }
        Repository::audit(
            'service_credential_rotated',
            'partner',
            absint($existing['partner_id']),
            __('A service credential was rotated with a 24-hour overlap.', 'mds-attention-cooperative'),
            ['pool_id' => absint($existing['pool_id']), 'credential_ref' => self::reference((string) $existing['service_id'])]
        );

        return $created;
    }

    public static function revoke($credential_id): bool {
        global $wpdb;

        $credential = self::find(absint($credential_id));
        if (!$credential) {
            return false;
        }

        $updated = $wpdb->update(self::table('credentials'), [
            'status' => 'revoked',
            'secret_envelope' => null,
            'updated_at' => self::now(),
            'revoked_at' => self::now(),
        ], ['id' => absint($credential['id'])], ['%s', '%s', '%s', '%s'], ['%d']);

        if (false !== $updated) {
            Repository::audit(
                'service_credential_revoked',
                'partner',
                absint($credential['partner_id']),
                __('A service credential was revoked.', 'mds-attention-cooperative'),
                ['pool_id' => absint($credential['pool_id']), 'credential_ref' => self::reference((string) $credential['service_id'])]
            );
        }

        return false !== $updated;
    }

    public static function revoke_for_partner($partner_id): void {
        foreach (self::for_partner(absint($partner_id)) as $credential) {
            if (in_array((string) $credential['status'], ['active', 'retiring'], true)) {
                self::revoke(absint($credential['id']));
            }
        }
    }

    public static function find($credential_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table('credentials') . ' WHERE id = %d', absint($credential_id)),
            ARRAY_A
        );

        return is_array($row) ? self::normalize($row) : null;
    }

    public static function find_by_service_id($service_id): ?array {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table('credentials') . ' WHERE service_id = %s', (string) $service_id),
            ARRAY_A
        );

        return is_array($row) ? self::normalize($row) : null;
    }

    public static function for_partner($partner_id): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table('credentials') . ' WHERE partner_id = %d ORDER BY created_at DESC',
                absint($partner_id)
            ),
            ARRAY_A
        );

        return array_map([__CLASS__, 'normalize'], is_array($rows) ? $rows : []);
    }

    public static function registrable(): array {
        global $wpdb;

        $now = self::now();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::table('credentials') . "
                 WHERE status IN ('active','retiring')
                   AND expires_at > %s
                   AND (retire_at IS NULL OR retire_at > %s)
                   AND secret_envelope IS NOT NULL",
                $now,
                $now
            ),
            ARRAY_A
        );

        return array_map([__CLASS__, 'normalize'], is_array($rows) ? $rows : []);
    }

    public static function usable(array $credential, $required_scope): bool {
        $partner = Repository::partner(absint($credential['partner_id'] ?? 0));
        $pool = Repository::pool(absint($credential['pool_id'] ?? 0));
        $now = time();

        return in_array((string) ($credential['status'] ?? ''), ['active', 'retiring'], true)
            && !empty($credential['secret_envelope'])
            && strtotime((string) ($credential['expires_at'] ?? '')) > $now
            && (
                empty($credential['retire_at'])
                || strtotime((string) $credential['retire_at']) > $now
            )
            && in_array((string) $required_scope, (array) ($credential['scopes'] ?? []), true)
            && $partner
            && 'approved' === (string) ($partner['status'] ?? '')
            && absint($partner['pool_id'] ?? 0) === absint($credential['pool_id'] ?? 0)
            && $pool
            && 'active' === (string) ($pool['status'] ?? '')
            && !empty($pool['legal_review_completed']);
    }

    /**
     * Atomically claim a valid nonce and enforce the partner's hourly quota.
     *
     * @return bool|\WP_Error
     */
    public static function claim_nonce(array $credential, $nonce) {
        global $wpdb;

        $credential_id = absint($credential['id'] ?? 0);
        $partner_id = absint($credential['partner_id'] ?? 0);
        $pool_id = absint($credential['pool_id'] ?? 0);
        $partner = Repository::partner($partner_id);
        if (!$credential_id || !$partner_id || !$pool_id || !$partner) {
            return \MillionDollarScript\Core\ApiAccess::service_signature_error('invalid');
        }
        $limit = max(1, absint($partner['rate_limit_per_hour'] ?? 10));
        $nonce_hash = hash_hmac('sha256', (string) $nonce, self::pepper());
        $now = self::now();

        if (false === $wpdb->query('START TRANSACTION')) {
            return \MillionDollarScript\Core\ApiAccess::service_signature_error('unavailable');
        }
        $locked = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d FOR UPDATE", $partner_id));
        if ($partner_id !== absint($locked)) {
            $wpdb->query('ROLLBACK');
            return \MillionDollarScript\Core\ApiAccess::service_signature_error('invalid');
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . self::table('nonces') . ' WHERE credential_id = %d AND nonce_hash = %s',
                $credential_id,
                $nonce_hash
            )
        );
        if ($existing) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('mds_attention_cooperative_replay', __('The signed service request could not be authenticated.', 'mds-attention-cooperative'));
        }

        $used = absint($wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::table('nonces') . "
                 WHERE partner_id = %d AND decision = 'allowed' AND created_at >= %s",
                $partner_id,
                gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)
            )
        ));
        $decision = $used >= $limit ? 'limited' : 'allowed';
        $inserted = $wpdb->insert(self::table('nonces'), [
            'credential_id' => $credential_id,
            'partner_id' => $partner_id,
            'pool_id' => $pool_id,
            'nonce_hash' => $nonce_hash,
            'decision' => $decision,
            'created_at' => $now,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + HOUR_IN_SECONDS + 300),
        ], ['%d', '%d', '%d', '%s', '%s', '%s', '%s']);

        if (false === $inserted) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('mds_attention_cooperative_replay', __('The signed service request could not be authenticated.', 'mds-attention-cooperative'));
        }

        if ('limited' === $decision) {
            $wpdb->query('COMMIT');
            return \MillionDollarScript\Core\ApiAccess::service_signature_error('rate_limited', HOUR_IN_SECONDS);
        }

        $wpdb->update(self::table('credentials'), [
            'last_used_at' => $now,
            'updated_at' => $now,
        ], ['id' => $credential_id], ['%s', '%s'], ['%d']);
        if (false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            return \MillionDollarScript\Core\ApiAccess::service_signature_error('unavailable');
        }

        return true;
    }

    /**
     * @return array|\WP_Error
     */
    public static function begin_idempotency($partner_id, $pool_id, $key, $fingerprint) {
        global $wpdb;

        $key_hash = hash_hmac('sha256', (string) $key, self::pepper());
        $now = self::now();
        $inserted = $wpdb->query(
            $wpdb->prepare(
                'INSERT IGNORE INTO ' . self::table('idempotency') . '
                    (partner_id, pool_id, key_hash, request_fingerprint, state, placement_id, created_at, updated_at)
                 VALUES (%d, %d, %s, %s, %s, NULL, %s, %s)',
                absint($partner_id),
                absint($pool_id),
                $key_hash,
                (string) $fingerprint,
                'processing',
                $now,
                $now
            )
        );

        if (1 === $inserted) {
            return ['state' => 'new', 'id' => absint($wpdb->insert_id)];
        }
        if (false === $inserted) {
            return new \WP_Error(
                'mds_attention_cooperative_idempotency_unavailable',
                __('Placement idempotency is temporarily unavailable.', 'mds-attention-cooperative'),
                ['status' => 503]
            );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table('idempotency') . ' WHERE partner_id = %d AND key_hash = %s',
                absint($partner_id),
                $key_hash
            ),
            ARRAY_A
        );
        if (!$row || !hash_equals((string) ($row['request_fingerprint'] ?? ''), (string) $fingerprint)) {
            return new \WP_Error(
                'mds_attention_cooperative_idempotency_conflict',
                __('That idempotency key was already used for a different placement request.', 'mds-attention-cooperative'),
                ['status' => 409]
            );
        }

        if ('complete' === (string) ($row['state'] ?? '') && absint($row['placement_id'] ?? 0)) {
            return ['state' => 'replay', 'id' => absint($row['id']), 'placement_id' => absint($row['placement_id'])];
        }
        if ('failed' === (string) ($row['state'] ?? '')) {
            $claimed = $wpdb->update(self::table('idempotency'), [
                'state' => 'processing',
                'updated_at' => $now,
            ], ['id' => absint($row['id']), 'state' => 'failed'], ['%s', '%s'], ['%d', '%s']);
            if (1 === $claimed) {
                return ['state' => 'new', 'id' => absint($row['id'])];
            }
        }
        if (
            'processing' === (string) ($row['state'] ?? '')
            && strtotime((string) ($row['updated_at'] ?? '')) <= time() - (10 * MINUTE_IN_SECONDS)
        ) {
            $claimed = $wpdb->update(self::table('idempotency'), [
                'updated_at' => $now,
            ], [
                'id' => absint($row['id']),
                'state' => 'processing',
                'updated_at' => (string) $row['updated_at'],
            ], ['%s'], ['%d', '%s', '%s']);
            if (1 === $claimed) {
                return ['state' => 'new', 'id' => absint($row['id'])];
            }
        }

        return new \WP_Error(
            'mds_attention_cooperative_idempotency_in_progress',
            __('A placement request with that idempotency key is already processing.', 'mds-attention-cooperative'),
            ['status' => 409, 'retry_after' => 5]
        );
    }

    public static function complete_idempotency($id, $placement_id): void {
        global $wpdb;

        $wpdb->update(self::table('idempotency'), [
            'state' => 'complete',
            'placement_id' => absint($placement_id),
            'updated_at' => self::now(),
        ], ['id' => absint($id), 'state' => 'processing'], ['%s', '%d', '%s'], ['%d', '%s']);
    }

    public static function fail_idempotency($id): void {
        global $wpdb;

        $wpdb->update(self::table('idempotency'), [
            'state' => 'failed',
            'updated_at' => self::now(),
        ], ['id' => absint($id), 'state' => 'processing'], ['%s', '%s'], ['%d', '%s']);
    }

    public static function cleanup(): void {
        global $wpdb;

        $now = self::now();
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table('nonces') . ' WHERE expires_at < %s', $now));
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE " . self::table('credentials') . "
                 SET status = 'expired', secret_envelope = NULL, updated_at = %s
                 WHERE status IN ('active','retiring')
                   AND (expires_at <= %s OR (retire_at IS NOT NULL AND retire_at <= %s))",
                $now,
                $now,
                $now
            )
        );
    }

    public static function reference($service_id): string {
        return substr(hash_hmac('sha256', (string) $service_id, self::pepper()), 0, 16);
    }

    public static function table($key): string {
        global $wpdb;

        return $wpdb->prefix . (self::TABLES[sanitize_key((string) $key)] ?? '');
    }

    private static function normalize(array $row): array {
        $scopes = json_decode((string) ($row['scopes'] ?? '[]'), true);
        $row['id'] = absint($row['id'] ?? 0);
        $row['partner_id'] = absint($row['partner_id'] ?? 0);
        $row['pool_id'] = absint($row['pool_id'] ?? 0);
        $row['created_by'] = absint($row['created_by'] ?? 0);
        $row['scopes'] = is_array($scopes) ? array_values(array_map('sanitize_text_field', $scopes)) : [];

        return $row;
    }

    private static function tables_exist(): bool {
        global $wpdb;

        foreach (array_keys(self::TABLES) as $key) {
            $table = self::table($key);
            if ($table !== $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
                return false;
            }
        }

        return true;
    }

    private static function base64url($bytes): string {
        return rtrim(strtr(base64_encode((string) $bytes), '+/', '-_'), '=');
    }

    private static function bounded_label($label): string {
        $label = sanitize_text_field((string) $label);
        if ('' === $label) {
            $label = __('Partner placement service', 'mds-attention-cooperative');
        }

        return function_exists('mb_substr') ? mb_substr($label, 0, 191) : substr($label, 0, 191);
    }

    private static function pepper(): string {
        return function_exists('wp_salt') ? wp_salt('auth') : (defined('AUTH_KEY') ? AUTH_KEY : 'mds-attention-cooperative');
    }

    private static function now(): string {
        return current_time('mysql', true);
    }
}
