<?php
/**
 * Scheduled private-data retention cleanup.
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

if (!defined('ABSPATH')) {
    exit;
}

final class Retention {

    public const HOOK = 'mds_attention_cooperative_retention';

    public static function register() {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_action('init', [__CLASS__, 'schedule']);
    }

    public static function activate() {
        self::schedule();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function run() {
        ServiceCredentialRepository::cleanup();

        $backfill_iterations = 0;
        do {
            $backfilled = Repository::backfill_audit_pool_ids();
            $backfill_iterations++;
        } while ($backfilled > 0 && $backfill_iterations < 20);

        $page = 1;
        do {
            $pools = Repository::pools_page($page, 50);
            foreach ($pools['items'] as $pool) {
                $iterations = 0;
                do {
                    $counts = Repository::cleanup_expired_private_data(
                        absint($pool['id']),
                        max(1, absint($pool['retention_days'] ?? 365))
                    );
                    $processed = array_sum($counts);
                    $iterations++;
                } while ($processed > 0 && $iterations < 20);
            }
            $page++;
        } while ($page <= absint($pools['pages'] ?? 1));
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }
}
