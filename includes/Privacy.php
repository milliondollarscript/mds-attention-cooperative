<?php
/**
 * WordPress privacy export and erasure integration.
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

if (!defined('ABSPATH')) {
    exit;
}

final class Privacy {

    private const PAGE_SIZE = 50;

    public static function exporter($email_address, $page = 1) {
        $email = sanitize_email((string) $email_address);
        $page = max(1, absint($page));
        $partners = Repository::partners_by_email($email, $page, self::PAGE_SIZE);
        $placements = Repository::placements_by_email($email, $page, self::PAGE_SIZE);
        $items = [];

        foreach ($partners['items'] as $partner) {
            $items[] = [
                'group_id' => 'mds-attention-cooperative',
                'group_label' => __('Attention Cooperative', 'mds-attention-cooperative'),
                'item_id' => 'partner-' . absint($partner['id']),
                'data' => [
                    ['name' => __('Site name', 'mds-attention-cooperative'), 'value' => (string) $partner['site_name']],
                    ['name' => __('Contact name', 'mds-attention-cooperative'), 'value' => (string) $partner['contact_name']],
                    ['name' => __('Contact email', 'mds-attention-cooperative'), 'value' => (string) $partner['contact_email']],
                    ['name' => __('Status', 'mds-attention-cooperative'), 'value' => (string) $partner['status']],
                ],
            ];
        }

        foreach ($placements['items'] as $placement) {
            $items[] = [
                'group_id' => 'mds-attention-cooperative',
                'group_label' => __('Attention Cooperative', 'mds-attention-cooperative'),
                'item_id' => 'placement-' . absint($placement['id']),
                'data' => [
                    ['name' => __('Sponsor name', 'mds-attention-cooperative'), 'value' => (string) $placement['sponsor_name']],
                    ['name' => __('Sponsor email', 'mds-attention-cooperative'), 'value' => (string) $placement['sponsor_email']],
                    ['name' => __('Status', 'mds-attention-cooperative'), 'value' => (string) $placement['status']],
                ],
            ];
        }

        return [
            'data' => $items,
            'done' => $page >= max(absint($partners['pages']), absint($placements['pages'])),
        ];
    }

    public static function eraser($email_address, $page = 1) {
        unset($page);
        $email = sanitize_email((string) $email_address);
        $partners = Repository::partners_by_email($email, 1, self::PAGE_SIZE);
        $placements = Repository::placements_by_email($email, 1, self::PAGE_SIZE);
        $changed = 0;

        foreach ($partners['items'] as $partner) {
            if (Repository::anonymize_partner($partner['id'])) {
                $changed++;
            }
        }
        foreach ($placements['items'] as $placement) {
            if (Repository::anonymize_placement($placement['id'])) {
                $changed++;
            }
        }

        $remaining_partners = Repository::partners_by_email($email, 1, 1);
        $remaining_placements = Repository::placements_by_email($email, 1, 1);
        $done = empty($remaining_partners['items']) && empty($remaining_placements['items']);

        return [
            'items_removed' => false,
            'items_retained' => $changed > 0,
            'messages' => $changed ? [__('Attention Cooperative contact fields were anonymized while operational records were retained.', 'mds-attention-cooperative')] : [],
            'done' => $done,
        ];
    }
}
