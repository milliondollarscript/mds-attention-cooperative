<?php
/**
 * Attention Cooperative local repository.
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

if (!defined('ABSPATH')) {
    exit;
}

final class Repository {

    public const POOL_POST_TYPE = 'mds_ac_pool';
    public const PARTNER_POST_TYPE = 'mds_ac_partner';
    public const INVENTORY_POST_TYPE = 'mds_ac_inventory';
    public const PLACEMENT_POST_TYPE = 'mds_ac_placement';
    public const AUDIT_POST_TYPE = 'mds_ac_audit';

    public static function migrate() {
        $current_version = (string) get_option('mds_attention_cooperative_data_version', '0.1.0');
        ServiceCredentialRepository::maybe_install();

        if (version_compare($current_version, '0.2.0', '<')) {
            $candidate_ids = get_posts([
                'post_type' => self::PARTNER_POST_TYPE,
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => 20,
                'no_found_rows' => true,
                'meta_query' => [
                    'relation' => 'AND',
                    ['key' => '_mds_ac_partner_type', 'value' => 'invite', 'compare' => '='],
                    ['key' => '_mds_ac_partner_status', 'value' => 'invited', 'compare' => '='],
                    ['key' => '_mds_ac_terms_accepted', 'value' => '0', 'compare' => '='],
                ],
            ]);
            foreach ($candidate_ids as $candidate_id) {
                $candidate = self::partner($candidate_id);
                if (!$candidate) {
                    continue;
                }
                $untouched = __('Example partner', 'mds-attention-cooperative') === (string) $candidate['site_name']
                    && __('Replace this example with a real invite or application when the cooperative is ready.', 'mds-attention-cooperative') === (string) $candidate['notes']
                    && untrailingslashit(home_url('/')) === untrailingslashit((string) $candidate['site_url'])
                    && sanitize_email((string) get_option('admin_email')) === sanitize_email((string) $candidate['contact_email'])
                    && empty($candidate['service_endpoint'])
                    && empty($candidate['api_key_label']);
                if ($untouched) {
                    wp_delete_post(absint($candidate_id), true);
                }
            }

            $iterations = 0;
            do {
                $backfilled = self::backfill_audit_pool_ids();
                $iterations++;
            } while ($backfilled > 0 && $iterations < 20);
        }

        update_option('mds_attention_cooperative_data_version', '0.3.0', false);
    }

    public static function maybe_seed() {
        if (self::pools(1)) {
            return;
        }

        $pool_id = self::save_pool([
            'title' => __('Local Attention Cooperative', 'mds-attention-cooperative'),
            'slug' => 'local-attention-cooperative',
            'status' => 'active',
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url('/'),
            'contact_email' => get_option('admin_email'),
            'takedown_url' => home_url('/contact/'),
            'terms_url' => '',
            'privacy_url' => function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '',
            'disclosure_label' => __('Sponsored placement', 'mds-attention-cooperative'),
            'terms_summary' => __('Partners may request access to exposed inventory, but each site owner approves their own partners and placements before publication.', 'mds-attention-cooperative'),
            'prohibited_content' => __('Illegal content, misleading offers, malware, hate or harassment, adult content, deceptive financial claims, and any placement that conflicts with the site owner terms.', 'mds-attention-cooperative'),
            'rate_limit_per_hour' => 20,
            'retention_days' => 365,
            'public_applications_enabled' => '1',
            'source_approval_required' => '1',
            'destination_approval_required' => '1',
            'legal_review_completed' => '0',
        ]);

        if ($pool_id) {
            self::save_inventory([
                'pool_id' => $pool_id,
                'type' => 'custom',
                'label' => __('Featured sponsor inventory', 'mds-attention-cooperative'),
                'description' => __('A manually curated inventory record that can be exposed to approved partners after local review.', 'mds-attention-cooperative'),
                'status' => 'draft',
                'scope' => 'local',
                'capacity' => 4,
                'available' => 4,
                'currency' => self::default_currency(),
                'price_from' => 0,
                'approval_required' => '1',
                'disclosure_label' => __('Sponsored placement', 'mds-attention-cooperative'),
                'source_ref' => '',
            ]);

        }
    }

    public static function register_post_types() {
        register_post_type(self::POOL_POST_TYPE, [
            'label' => __('Attention Cooperatives', 'mds-attention-cooperative'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(self::PARTNER_POST_TYPE, [
            'label' => __('Cooperative Partners', 'mds-attention-cooperative'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(self::INVENTORY_POST_TYPE, [
            'label' => __('Cooperative Inventory', 'mds-attention-cooperative'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(self::PLACEMENT_POST_TYPE, [
            'label' => __('Cooperative Placements', 'mds-attention-cooperative'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(self::AUDIT_POST_TYPE, [
            'label' => __('Cooperative Audit Records', 'mds-attention-cooperative'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public static function save_pool(array $data, $pool_id = 0) {
        $pool_id = absint($pool_id);
        $title = self::bounded_text($data['title'] ?? '', 160);
        $site_url = self::http_url($data['site_url'] ?? home_url('/'));
        $contact_email = sanitize_email((string) ($data['contact_email'] ?? get_option('admin_email')));
        if ('' === $title || !$site_url || !is_email($contact_email) || ($pool_id && !self::pool($pool_id))) {
            return 0;
        }

        $result = $pool_id
            ? wp_update_post([
                'ID' => $pool_id,
                'post_type' => self::POOL_POST_TYPE,
                'post_title' => $title,
                'post_content' => self::bounded_textarea($data['terms_summary'] ?? '', 10000),
                'post_status' => 'publish',
            ], true)
            : wp_insert_post([
                'post_type' => self::POOL_POST_TYPE,
                'post_title' => $title,
                'post_content' => self::bounded_textarea($data['terms_summary'] ?? '', 10000),
                'post_status' => 'publish',
            ], true);

        if (is_wp_error($result) || !$result) {
            return 0;
        }

        $pool_id = absint($result);
        $status = self::one_of($data['status'] ?? 'draft', ['draft', 'active', 'paused', 'archived'], 'draft');
        $meta = [
            '_mds_ac_slug' => sanitize_title((string) ($data['slug'] ?? $title)) ?: 'cooperative-' . $pool_id,
            '_mds_ac_status' => $status,
            '_mds_ac_site_name' => self::bounded_text($data['site_name'] ?? get_bloginfo('name'), 160),
            '_mds_ac_site_url' => $site_url,
            '_mds_ac_contact_email' => $contact_email,
            '_mds_ac_takedown_url' => self::http_url($data['takedown_url'] ?? ''),
            '_mds_ac_terms_url' => self::http_url($data['terms_url'] ?? ''),
            '_mds_ac_privacy_url' => self::http_url($data['privacy_url'] ?? ''),
            '_mds_ac_disclosure_label' => self::bounded_text($data['disclosure_label'] ?? __('Sponsored placement', 'mds-attention-cooperative'), 120),
            '_mds_ac_prohibited_content' => self::bounded_textarea($data['prohibited_content'] ?? '', 10000),
            '_mds_ac_rate_limit_per_hour' => max(1, absint($data['rate_limit_per_hour'] ?? 20)),
            '_mds_ac_retention_days' => max(1, absint($data['retention_days'] ?? 365)),
            '_mds_ac_public_applications_enabled' => !empty($data['public_applications_enabled']) ? '1' : '0',
            '_mds_ac_source_approval_required' => !empty($data['source_approval_required']) ? '1' : '0',
            '_mds_ac_destination_approval_required' => !empty($data['destination_approval_required']) ? '1' : '0',
            '_mds_ac_legal_review_completed' => !empty($data['legal_review_completed']) ? '1' : '0',
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($pool_id, $key, $value);
        }

        self::audit('pool_saved', 'pool', $pool_id, __('Cooperative settings saved.', 'mds-attention-cooperative'), ['pool_id' => $pool_id]);

        return $pool_id;
    }

    public static function pools($limit = 50) {
        return self::post_payloads(self::POOL_POST_TYPE, [__CLASS__, 'pool_payload'], $limit);
    }

    public static function pool($pool_id) {
        $post = get_post(absint($pool_id));
        if (!$post || self::POOL_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::pool_payload($post);
    }

    public static function pool_by_slug($slug) {
        $slug = sanitize_title((string) $slug);
        if (!$slug) {
            return null;
        }

        $posts = get_posts([
            'post_type' => self::POOL_POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => true,
            'meta_key' => '_mds_ac_slug',
            'meta_value' => $slug,
        ]);

        return $posts ? self::pool(absint($posts[0])) : null;
    }

    public static function save_partner(array $data, $partner_id = 0) {
        $partner_id = absint($partner_id);
        $existing_partner = $partner_id ? self::partner($partner_id) : null;
        $pool_id = absint($data['pool_id'] ?? 0);
        $site_name = self::bounded_text($data['site_name'] ?? '', 120);
        if ('' === $site_name || !self::pool($pool_id) || ($partner_id && !self::partner($partner_id))) {
            return 0;
        }

        $service_endpoint = self::https_url($data['service_endpoint'] ?? '');
        if ('' !== trim((string) ($data['service_endpoint'] ?? '')) && '' === $service_endpoint) {
            return 0;
        }

        $result = $partner_id
            ? wp_update_post([
                'ID' => $partner_id,
                'post_type' => self::PARTNER_POST_TYPE,
                'post_title' => $site_name,
                'post_content' => self::bounded_textarea($data['notes'] ?? '', 4000),
                'post_status' => 'publish',
            ], true)
            : wp_insert_post([
                'post_type' => self::PARTNER_POST_TYPE,
                'post_title' => $site_name,
                'post_content' => self::bounded_textarea($data['notes'] ?? '', 4000),
                'post_status' => 'publish',
            ], true);

        if (is_wp_error($result) || !$result) {
            return 0;
        }

        $partner_id = absint($result);
        $status = self::one_of($data['status'] ?? 'applied', self::partner_statuses(), 'applied');
        $type = self::one_of($data['type'] ?? 'application', ['invite', 'application'], 'application');
        $terms_snapshot = self::bounded_textarea($data['terms_snapshot'] ?? '', 10000);
        if ('' === $terms_snapshot && $pool_id) {
            $pool = self::pool($pool_id);
            $terms_snapshot = sanitize_textarea_field((string) ($pool['terms_summary'] ?? ''));
        }

        $meta = [
            '_mds_ac_pool_id' => $pool_id,
            '_mds_ac_partner_type' => $type,
            '_mds_ac_partner_status' => $status,
            '_mds_ac_site_url' => self::http_url($data['site_url'] ?? ''),
            '_mds_ac_contact_name' => self::bounded_text($data['contact_name'] ?? '', 120),
            '_mds_ac_contact_email' => sanitize_email((string) ($data['contact_email'] ?? '')),
            '_mds_ac_requested_scope' => self::json(self::sanitize_inventory_types($data['requested_scope'] ?? [])),
            '_mds_ac_allowed_inventory' => self::json(self::sanitize_inventory_types($data['allowed_inventory'] ?? [])),
            '_mds_ac_rate_limit_per_hour' => max(1, absint($data['rate_limit_per_hour'] ?? 10)),
            '_mds_ac_terms_accepted' => !empty($data['terms_accepted']) ? '1' : '0',
            '_mds_ac_terms_snapshot' => $terms_snapshot,
            '_mds_ac_service_endpoint' => $service_endpoint,
            '_mds_ac_api_key_label' => self::bounded_text($data['api_key_label'] ?? '', 120),
            '_mds_ac_private_data_updated_at' => time(),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($partner_id, $key, $value);
        }

        if (
            $existing_partner
            && (
                absint($existing_partner['pool_id'] ?? 0) !== $pool_id
                || 'approved' !== $status
            )
        ) {
            ServiceCredentialRepository::revoke_for_partner($partner_id);
        }

        self::audit('partner_saved', 'partner', $partner_id, sprintf(__('Partner saved with status %s.', 'mds-attention-cooperative'), $status), ['pool_id' => $pool_id]);

        return $partner_id;
    }

    public static function partners($pool_id = 0, $limit = 100) {
        return self::meta_filtered_posts(self::PARTNER_POST_TYPE, [__CLASS__, 'partner_payload'], $pool_id, $limit);
    }

    public static function partner($partner_id) {
        $post = get_post(absint($partner_id));
        if (!$post || self::PARTNER_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::partner_payload($post);
    }

    public static function update_partner_status($partner_id, $status) {
        $partner = self::partner($partner_id);
        if (!$partner) {
            return false;
        }

        $status = self::one_of($status, self::partner_statuses(), '');
        if (!$status) {
            return false;
        }

        update_post_meta(absint($partner_id), '_mds_ac_partner_status', $status);
        update_post_meta(absint($partner_id), '_mds_ac_private_data_updated_at', time());
        if ('approved' === $status && empty($partner['terms_snapshot'])) {
            $pool = self::pool(absint($partner['pool_id'] ?? 0));
            update_post_meta(absint($partner_id), '_mds_ac_terms_snapshot', sanitize_textarea_field((string) ($pool['terms_summary'] ?? '')));
        }
        if ('approved' === $status && empty($partner['allowed_inventory']) && !empty($partner['requested_scope'])) {
            update_post_meta(absint($partner_id), '_mds_ac_allowed_inventory', self::json(self::sanitize_inventory_types($partner['requested_scope'])));
        }
        if ('approved' !== $status) {
            ServiceCredentialRepository::revoke_for_partner(absint($partner_id));
        }
        self::audit('partner_' . $status, 'partner', absint($partner_id), sprintf(__('Partner status changed to %s.', 'mds-attention-cooperative'), $status), ['pool_id' => absint($partner['pool_id'] ?? 0)]);

        return true;
    }

    public static function save_inventory(array $data, $inventory_id = 0) {
        $inventory_id = absint($inventory_id);
        $pool_id = absint($data['pool_id'] ?? 0);
        $label = self::bounded_text($data['label'] ?? '', 160);
        if ('' === $label || !self::pool($pool_id) || ($inventory_id && !self::inventory($inventory_id))) {
            return 0;
        }

        $capacity = max(0, absint($data['capacity'] ?? 0));
        $available = min($capacity, max(0, absint($data['available'] ?? 0)));

        $result = $inventory_id
            ? wp_update_post([
                'ID' => $inventory_id,
                'post_type' => self::INVENTORY_POST_TYPE,
                'post_title' => $label,
                'post_content' => self::bounded_textarea($data['description'] ?? '', 4000),
                'post_status' => 'publish',
            ], true)
            : wp_insert_post([
                'post_type' => self::INVENTORY_POST_TYPE,
                'post_title' => $label,
                'post_content' => self::bounded_textarea($data['description'] ?? '', 4000),
                'post_status' => 'publish',
            ], true);

        if (is_wp_error($result) || !$result) {
            return 0;
        }

        $inventory_id = absint($result);
        $meta = [
            '_mds_ac_pool_id' => $pool_id,
            '_mds_ac_inventory_type' => self::one_of($data['type'] ?? 'custom', self::inventory_types(), 'custom'),
            '_mds_ac_inventory_status' => self::one_of($data['status'] ?? 'draft', ['draft', 'exposed', 'paused', 'retired'], 'draft'),
            '_mds_ac_inventory_scope' => self::one_of($data['scope'] ?? 'local', ['local', 'federated'], 'local'),
            '_mds_ac_capacity' => $capacity,
            '_mds_ac_available' => $available,
            '_mds_ac_currency' => self::effective_currency($data['currency'] ?? self::default_currency()),
            '_mds_ac_price_from' => self::money($data['price_from'] ?? 0),
            '_mds_ac_approval_required' => !empty($data['approval_required']) ? '1' : '0',
            '_mds_ac_disclosure_label' => self::bounded_text($data['disclosure_label'] ?? __('Sponsored placement', 'mds-attention-cooperative'), 120),
            '_mds_ac_source_ref' => self::bounded_text($data['source_ref'] ?? '', 160),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($inventory_id, $key, $value);
        }

        self::audit('inventory_saved', 'inventory', $inventory_id, __('Inventory record saved.', 'mds-attention-cooperative'), ['pool_id' => $pool_id]);

        return $inventory_id;
    }

    public static function inventory_items($pool_id = 0, $public_only = false, $limit = 100) {
        $items = self::meta_filtered_posts(self::INVENTORY_POST_TYPE, [__CLASS__, 'inventory_payload'], $pool_id, $limit);
        if ($public_only) {
            $items = array_values(array_filter($items, static function ($item) {
                return 'exposed' === (string) ($item['status'] ?? '');
            }));
        }

        return $items;
    }

    public static function inventory($inventory_id) {
        $post = get_post(absint($inventory_id));
        if (!$post || self::INVENTORY_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::inventory_payload($post);
    }

    public static function create_placement(array $data) {
        $pool_id = absint($data['pool_id'] ?? 0);
        $partner_id = absint($data['partner_id'] ?? 0);
        $inventory_id = absint($data['inventory_id'] ?? 0);
        $sponsor_name = self::bounded_text($data['sponsor_name'] ?? '', 160);
        $validation = self::validate_placement_request($data);
        if (is_wp_error($validation)) {
            return 0;
        }

        $pool = self::pool($pool_id);
        $source_approved = !empty($data['source_approved']);
        $status = !empty($pool['source_approval_required']) && !$source_approved
            ? 'pending_source'
            : 'pending_destination';

        $post_id = wp_insert_post([
            'post_type' => self::PLACEMENT_POST_TYPE,
            'post_title' => $sponsor_name,
            'post_content' => self::bounded_textarea($data['placement_copy'] ?? '', 4000),
            'post_status' => 'pending',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        $meta = [
            '_mds_ac_pool_id' => $pool_id,
            '_mds_ac_partner_id' => $partner_id,
            '_mds_ac_inventory_id' => $inventory_id,
            '_mds_ac_placement_status' => $status,
            '_mds_ac_sponsor_url' => self::http_url($data['sponsor_url'] ?? ''),
            '_mds_ac_sponsor_email' => sanitize_email((string) ($data['sponsor_email'] ?? '')),
            '_mds_ac_disclosure_label' => self::bounded_text($data['disclosure_label'] ?? ($pool['disclosure_label'] ?? __('Sponsored placement', 'mds-attention-cooperative')), 120),
            '_mds_ac_source_approved' => $source_approved ? '1' : '0',
            '_mds_ac_destination_approved' => '0',
            '_mds_ac_terms_accepted' => !empty($data['terms_accepted']) ? '1' : '0',
            '_mds_ac_notes' => self::bounded_textarea($data['notes'] ?? '', 4000),
            '_mds_ac_inventory_consumed' => '0',
            '_mds_ac_private_data_updated_at' => time(),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        self::audit('placement_requested', 'placement', absint($post_id), __('Placement request created.', 'mds-attention-cooperative'), ['pool_id' => $pool_id, 'partner_id' => $partner_id, 'inventory_id' => $inventory_id]);

        return absint($post_id);
    }

    public static function placements($pool_id = 0, $limit = 100) {
        return self::meta_filtered_posts(self::PLACEMENT_POST_TYPE, [__CLASS__, 'placement_payload'], $pool_id, $limit);
    }

    public static function placement($placement_id) {
        $post = get_post(absint($placement_id));
        if (!$post || self::PLACEMENT_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::placement_payload($post);
    }

    public static function update_placement_status($placement_id, $status) {
        $placement = self::placement($placement_id);
        if (!$placement) {
            return false;
        }

        $status = self::one_of($status, self::placement_statuses(), '');
        if (!$status) {
            return false;
        }

        if ('approved' === $status) {
            $validation = self::validate_placement_request($placement, true);
            if (is_wp_error($validation) || !self::consume_inventory($placement)) {
                return false;
            }

            $updated = wp_update_post(['ID' => absint($placement_id), 'post_status' => 'publish'], true);
            if (is_wp_error($updated)) {
                self::restore_inventory($placement);
                return false;
            }
            update_post_meta(absint($placement_id), '_mds_ac_destination_approved', '1');
        } elseif (in_array($status, ['rejected', 'revoked', 'takedown'], true)) {
            self::restore_inventory($placement);
            wp_update_post(['ID' => absint($placement_id), 'post_status' => 'draft']);
            update_post_meta(absint($placement_id), '_mds_ac_destination_approved', '0');
        } else {
            wp_update_post(['ID' => absint($placement_id), 'post_status' => 'pending']);
        }

        update_post_meta(absint($placement_id), '_mds_ac_placement_status', $status);
        update_post_meta(absint($placement_id), '_mds_ac_private_data_updated_at', time());

        self::audit('placement_' . $status, 'placement', absint($placement_id), sprintf(__('Placement status changed to %s.', 'mds-attention-cooperative'), $status), ['pool_id' => absint($placement['pool_id'] ?? 0)]);

        return true;
    }

    public static function approve_placement_source($placement_id) {
        $placement = self::placement($placement_id);
        if (!$placement) {
            return false;
        }

        update_post_meta(absint($placement_id), '_mds_ac_source_approved', '1');
        update_post_meta(absint($placement_id), '_mds_ac_private_data_updated_at', time());
        if ('pending_source' === (string) ($placement['status'] ?? '')) {
            update_post_meta(absint($placement_id), '_mds_ac_placement_status', 'pending_destination');
        }
        self::audit(
            'placement_source_approved',
            'placement',
            absint($placement_id),
            __('Source-site approval recorded.', 'mds-attention-cooperative'),
            ['pool_id' => absint($placement['pool_id'] ?? 0)]
        );

        return true;
    }

    /**
     * Validate the relationships and release gates for a placement request.
     *
     * @return true|\WP_Error
     */
    public static function validate_placement_request(array $data, $for_approval = false) {
        $pool_id = absint($data['pool_id'] ?? 0);
        $partner_id = absint($data['partner_id'] ?? 0);
        $inventory_id = absint($data['inventory_id'] ?? 0);
        $pool = self::pool($pool_id);
        $partner = self::partner($partner_id);
        $inventory = self::inventory($inventory_id);
        $sponsor_name = self::bounded_text($data['sponsor_name'] ?? '', 160);

        if (!$pool || 'active' !== (string) ($pool['status'] ?? '') || empty($pool['legal_review_completed'])) {
            return new \WP_Error('mds_attention_cooperative_pool_unavailable', __('The cooperative must be active and have its review gate confirmed.', 'mds-attention-cooperative'));
        }
        if ('' === $sponsor_name || empty($data['terms_accepted'])) {
            return new \WP_Error('mds_attention_cooperative_placement_terms_required', __('Add a sponsor name and confirm the placement terms.', 'mds-attention-cooperative'));
        }
        if (!$partner || 'approved' !== (string) ($partner['status'] ?? '') || $pool_id !== absint($partner['pool_id'] ?? 0)) {
            return new \WP_Error('mds_attention_cooperative_partner_invalid', __('Choose an approved partner from this cooperative.', 'mds-attention-cooperative'));
        }
        if (!$inventory || $pool_id !== absint($inventory['pool_id'] ?? 0) || 'exposed' !== (string) ($inventory['status'] ?? '') || 'federated' !== (string) ($inventory['scope'] ?? '')) {
            return new \WP_Error('mds_attention_cooperative_inventory_invalid', __('Choose exposed approved-partner inventory from this cooperative.', 'mds-attention-cooperative'));
        }
        if (!in_array((string) ($inventory['type'] ?? ''), (array) ($partner['allowed_inventory'] ?? []), true)) {
            return new \WP_Error('mds_attention_cooperative_inventory_not_allowed', __('The selected partner is not approved for this inventory type.', 'mds-attention-cooperative'));
        }
        if ($for_approval && !empty($pool['source_approval_required']) && empty($data['source_approved'])) {
            return new \WP_Error('mds_attention_cooperative_source_approval_required', __('Record source-site approval before approving this placement.', 'mds-attention-cooperative'));
        }
        if (empty($data['inventory_consumed']) && absint($inventory['available'] ?? 0) < 1) {
            return new \WP_Error('mds_attention_cooperative_inventory_exhausted', __('The selected inventory has no remaining capacity.', 'mds-attention-cooperative'));
        }

        return true;
    }

    public static function published_placements($pool_id = 0, $limit = 50) {
        $placements = self::placements($pool_id, $limit);

        return array_values(array_filter($placements, static function ($placement) {
            return 'approved' === (string) ($placement['status'] ?? '');
        }));
    }

    public static function audit($event, $object_type, $object_id, $message = '', array $details = []) {
        $event = sanitize_key((string) $event);
        if (!$event || self::AUDIT_POST_TYPE === get_post_type(absint($object_id))) {
            return 0;
        }

        $post_id = wp_insert_post([
            'post_type' => self::AUDIT_POST_TYPE,
            'post_title' => $event,
            'post_content' => sanitize_textarea_field((string) $message),
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return 0;
        }

        update_post_meta($post_id, '_mds_ac_event', $event);
        update_post_meta($post_id, '_mds_ac_object_type', sanitize_key((string) $object_type));
        update_post_meta($post_id, '_mds_ac_object_id', absint($object_id));
        update_post_meta($post_id, '_mds_ac_user_id', get_current_user_id());
        update_post_meta($post_id, '_mds_ac_ip_hash', self::ip_hash());
        update_post_meta($post_id, '_mds_ac_details', self::json($details));
        update_post_meta($post_id, '_mds_ac_pool_id', absint($details['pool_id'] ?? 0));

        return absint($post_id);
    }

    public static function audits($limit = 100) {
        return self::post_payloads(self::AUDIT_POST_TYPE, [__CLASS__, 'audit_payload'], $limit);
    }

    public static function pools_page($page = 1, $per_page = 20) {
        return self::post_payload_page(self::POOL_POST_TYPE, [__CLASS__, 'pool_payload'], 0, $page, $per_page);
    }

    public static function partners_page($pool_id, $page = 1, $per_page = 20) {
        return self::post_payload_page(self::PARTNER_POST_TYPE, [__CLASS__, 'partner_payload'], $pool_id, $page, $per_page);
    }

    public static function inventory_page($pool_id, $page = 1, $per_page = 20) {
        return self::post_payload_page(self::INVENTORY_POST_TYPE, [__CLASS__, 'inventory_payload'], $pool_id, $page, $per_page);
    }

    public static function placements_page($pool_id, $page = 1, $per_page = 20) {
        return self::post_payload_page(self::PLACEMENT_POST_TYPE, [__CLASS__, 'placement_payload'], $pool_id, $page, $per_page);
    }

    public static function audits_page($pool_id = 0, $page = 1, $per_page = 20) {
        return self::post_payload_page(self::AUDIT_POST_TYPE, [__CLASS__, 'audit_payload'], $pool_id, $page, $per_page);
    }

    public static function partners_by_email($email, $page = 1, $per_page = 50) {
        return self::post_payloads_by_meta(
            self::PARTNER_POST_TYPE,
            [__CLASS__, 'partner_payload'],
            '_mds_ac_contact_email',
            sanitize_email((string) $email),
            $page,
            $per_page
        );
    }

    public static function placements_by_email($email, $page = 1, $per_page = 50) {
        return self::post_payloads_by_meta(
            self::PLACEMENT_POST_TYPE,
            [__CLASS__, 'placement_payload'],
            '_mds_ac_sponsor_email',
            sanitize_email((string) $email),
            $page,
            $per_page
        );
    }

    public static function anonymize_partner($partner_id) {
        $partner_id = absint($partner_id);
        if (!self::partner($partner_id)) {
            return false;
        }

        ServiceCredentialRepository::revoke_for_partner($partner_id);
        update_post_meta($partner_id, '_mds_ac_contact_email', '');
        update_post_meta($partner_id, '_mds_ac_contact_name', '');
        update_post_meta($partner_id, '_mds_ac_service_endpoint', '');
        update_post_meta($partner_id, '_mds_ac_api_key_label', '');
        wp_update_post(['ID' => $partner_id, 'post_content' => '']);

        return true;
    }

    public static function anonymize_placement($placement_id) {
        $placement_id = absint($placement_id);
        if (!self::placement($placement_id)) {
            return false;
        }

        update_post_meta($placement_id, '_mds_ac_sponsor_email', '');
        update_post_meta($placement_id, '_mds_ac_notes', '');

        return true;
    }

    public static function cleanup_expired_private_data($pool_id, $retention_days, $batch_size = 250) {
        $pool_id = absint($pool_id);
        $cutoff = time() - (max(1, absint($retention_days)) * DAY_IN_SECONDS);
        $batch_size = min(500, max(1, absint($batch_size)));
        $counts = ['partners' => 0, 'placements' => 0, 'audits' => 0];

        $partner_ids = self::expired_record_ids(
            self::PARTNER_POST_TYPE,
            $pool_id,
            '_mds_ac_partner_status',
            ['rejected', 'revoked'],
            $cutoff,
            $batch_size,
            ['_mds_ac_contact_email', '_mds_ac_contact_name', '_mds_ac_service_endpoint', '_mds_ac_api_key_label']
        );
        foreach ($partner_ids as $partner_id) {
            if (self::anonymize_partner($partner_id)) {
                $counts['partners']++;
            }
        }

        $placement_ids = self::expired_record_ids(
            self::PLACEMENT_POST_TYPE,
            $pool_id,
            '_mds_ac_placement_status',
            ['rejected', 'revoked', 'takedown'],
            $cutoff,
            $batch_size,
            ['_mds_ac_sponsor_email', '_mds_ac_notes']
        );
        foreach ($placement_ids as $placement_id) {
            if (self::anonymize_placement($placement_id)) {
                $counts['placements']++;
            }
        }

        $audit_ids = get_posts([
            'post_type' => self::AUDIT_POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => $batch_size,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_mds_ac_pool_id', 'value' => $pool_id, 'compare' => '='],
                ['key' => '_mds_ac_ip_hash', 'value' => '', 'compare' => '!='],
            ],
            'date_query' => [['column' => 'post_date_gmt', 'before' => gmdate('Y-m-d H:i:s', $cutoff), 'inclusive' => true]],
        ]);
        foreach ($audit_ids as $audit_id) {
            update_post_meta(absint($audit_id), '_mds_ac_ip_hash', '');
            $counts['audits']++;
        }

        return $counts;
    }

    public static function backfill_audit_pool_ids($batch_size = 250) {
        $audit_ids = get_posts([
            'post_type' => self::AUDIT_POST_TYPE,
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => min(500, max(1, absint($batch_size))),
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'meta_query' => [['key' => '_mds_ac_pool_id', 'compare' => 'NOT EXISTS']],
        ]);

        foreach ($audit_ids as $audit_id) {
            $details = self::decode_json(get_post_meta($audit_id, '_mds_ac_details', true));
            $pool_id = absint($details['pool_id'] ?? 0);
            if (!$pool_id) {
                $object_type = (string) get_post_meta($audit_id, '_mds_ac_object_type', true);
                $object_id = absint(get_post_meta($audit_id, '_mds_ac_object_id', true));
                if ('pool' === $object_type) {
                    $pool_id = $object_id;
                } elseif ('partner' === $object_type) {
                    $pool_id = absint((self::partner($object_id)['pool_id'] ?? 0));
                } elseif ('inventory' === $object_type) {
                    $pool_id = absint((self::inventory($object_id)['pool_id'] ?? 0));
                } elseif ('placement' === $object_type) {
                    $pool_id = absint((self::placement($object_id)['pool_id'] ?? 0));
                }
            }
            update_post_meta(absint($audit_id), '_mds_ac_pool_id', $pool_id);
        }

        return count($audit_ids);
    }

    public static function local_inventory_manifest($pool_id = 0) {
        $pool = $pool_id ? self::pool($pool_id) : (self::pools(1)[0] ?? null);
        if (!$pool || 'active' !== (string) ($pool['status'] ?? '') || empty($pool['legal_review_completed'])) {
            return [
                'site' => self::site_manifest($pool),
                'inventory' => [],
                'providers' => self::provider_manifests(),
                'applications_open' => false,
            ];
        }

        return [
            'site' => self::site_manifest($pool),
            'inventory' => self::inventory_items(absint($pool['id']), true),
            'providers' => self::provider_manifests(),
            'applications_open' => !empty($pool['public_applications_enabled']),
        ];
    }

    public static function provider_manifests() {
        $providers = [];
        $providers = array_merge($providers, self::grid_manifest());
        $providers = array_merge($providers, self::sponsorboard_manifest());
        $providers = array_merge($providers, self::launch_wall_manifest());
        $providers = array_merge($providers, self::missions_manifest());
        $providers = array_merge($providers, self::local_money_map_manifest());

        return array_values(\MillionDollarScript\Core\Hooks::apply_compat('million-dollar-script/extensions/attention-cooperative/inventory/providers', ['mds_attention_cooperative_inventory_providers'], $providers));
    }

    public static function inventory_types() {
        return ['grid', 'sponsorboard', 'missions', 'launch-wall', 'local-money-map', 'custom'];
    }

    public static function partner_statuses() {
        return ['invited', 'applied', 'changes_requested', 'approved', 'rejected', 'revoked'];
    }

    public static function placement_statuses() {
        return ['pending_source', 'pending_destination', 'approved', 'rejected', 'revoked', 'takedown'];
    }

    public static function default_currency() {
        $settings = get_option('mds3_settings', []);
        $settings = is_array($settings) ? $settings : [];
        $fallback = '';

        if (function_exists('get_woocommerce_currency')) {
            $fallback = get_woocommerce_currency();
        }
        if (!$fallback && !empty($settings['currency'])) {
            $fallback = (string) $settings['currency'];
        }
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency')) {
            return \MillionDollarScript\Commerce\Currency::current_code($settings);
        }

        return self::currency($fallback ?: 'USD');
    }

    public static function effective_currency($value = '') {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'effective_code')) {
            return \MillionDollarScript\Commerce\Currency::effective_code($value, null, 8);
        }

        if (self::currency_locked()) {
            return self::default_currency();
        }

        return self::currency($value ?: self::default_currency());
    }

    public static function currency_locked() {
        $settings = get_option('mds3_settings', []);
        $settings = is_array($settings) ? $settings : [];

        return class_exists('\\MillionDollarScript\\Commerce\\Currency') && \MillionDollarScript\Commerce\Currency::provider_locks_currency($settings);
    }

    public static function pool_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'title' => get_the_title($post),
            'slug' => (string) get_post_meta($post->ID, '_mds_ac_slug', true),
            'status' => (string) get_post_meta($post->ID, '_mds_ac_status', true),
            'site_name' => (string) get_post_meta($post->ID, '_mds_ac_site_name', true),
            'site_url' => (string) get_post_meta($post->ID, '_mds_ac_site_url', true),
            'contact_email' => (string) get_post_meta($post->ID, '_mds_ac_contact_email', true),
            'takedown_url' => (string) get_post_meta($post->ID, '_mds_ac_takedown_url', true),
            'terms_url' => (string) get_post_meta($post->ID, '_mds_ac_terms_url', true),
            'privacy_url' => (string) get_post_meta($post->ID, '_mds_ac_privacy_url', true),
            'disclosure_label' => (string) get_post_meta($post->ID, '_mds_ac_disclosure_label', true),
            'terms_summary' => (string) $post->post_content,
            'prohibited_content' => (string) get_post_meta($post->ID, '_mds_ac_prohibited_content', true),
            'rate_limit_per_hour' => absint(get_post_meta($post->ID, '_mds_ac_rate_limit_per_hour', true)),
            'retention_days' => absint(get_post_meta($post->ID, '_mds_ac_retention_days', true)),
            'public_applications_enabled' => '1' === (string) get_post_meta($post->ID, '_mds_ac_public_applications_enabled', true),
            'source_approval_required' => '1' === (string) get_post_meta($post->ID, '_mds_ac_source_approval_required', true),
            'destination_approval_required' => '1' === (string) get_post_meta($post->ID, '_mds_ac_destination_approval_required', true),
            'legal_review_completed' => '1' === (string) get_post_meta($post->ID, '_mds_ac_legal_review_completed', true),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    public static function partner_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'pool_id' => absint(get_post_meta($post->ID, '_mds_ac_pool_id', true)),
            'type' => (string) get_post_meta($post->ID, '_mds_ac_partner_type', true),
            'status' => (string) get_post_meta($post->ID, '_mds_ac_partner_status', true),
            'site_name' => get_the_title($post),
            'site_url' => (string) get_post_meta($post->ID, '_mds_ac_site_url', true),
            'contact_name' => (string) get_post_meta($post->ID, '_mds_ac_contact_name', true),
            'contact_email' => (string) get_post_meta($post->ID, '_mds_ac_contact_email', true),
            'requested_scope' => self::sanitize_inventory_types(self::decode_json(get_post_meta($post->ID, '_mds_ac_requested_scope', true))),
            'allowed_inventory' => self::sanitize_inventory_types(self::decode_json(get_post_meta($post->ID, '_mds_ac_allowed_inventory', true))),
            'rate_limit_per_hour' => absint(get_post_meta($post->ID, '_mds_ac_rate_limit_per_hour', true)),
            'terms_accepted' => '1' === (string) get_post_meta($post->ID, '_mds_ac_terms_accepted', true),
            'terms_snapshot' => (string) get_post_meta($post->ID, '_mds_ac_terms_snapshot', true),
            'service_endpoint' => (string) get_post_meta($post->ID, '_mds_ac_service_endpoint', true),
            'api_key_label' => (string) get_post_meta($post->ID, '_mds_ac_api_key_label', true),
            'notes' => (string) $post->post_content,
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    public static function inventory_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'pool_id' => absint(get_post_meta($post->ID, '_mds_ac_pool_id', true)),
            'type' => (string) get_post_meta($post->ID, '_mds_ac_inventory_type', true),
            'label' => get_the_title($post),
            'description' => (string) $post->post_content,
            'status' => (string) get_post_meta($post->ID, '_mds_ac_inventory_status', true),
            'scope' => (string) get_post_meta($post->ID, '_mds_ac_inventory_scope', true),
            'capacity' => absint(get_post_meta($post->ID, '_mds_ac_capacity', true)),
            'available' => absint(get_post_meta($post->ID, '_mds_ac_available', true)),
            'currency' => (string) get_post_meta($post->ID, '_mds_ac_currency', true),
            'price_from' => (float) get_post_meta($post->ID, '_mds_ac_price_from', true),
            'approval_required' => '1' === (string) get_post_meta($post->ID, '_mds_ac_approval_required', true),
            'disclosure_label' => (string) get_post_meta($post->ID, '_mds_ac_disclosure_label', true),
            'source_ref' => (string) get_post_meta($post->ID, '_mds_ac_source_ref', true),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    public static function placement_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'pool_id' => absint(get_post_meta($post->ID, '_mds_ac_pool_id', true)),
            'partner_id' => absint(get_post_meta($post->ID, '_mds_ac_partner_id', true)),
            'inventory_id' => absint(get_post_meta($post->ID, '_mds_ac_inventory_id', true)),
            'status' => (string) get_post_meta($post->ID, '_mds_ac_placement_status', true),
            'sponsor_name' => get_the_title($post),
            'sponsor_url' => (string) get_post_meta($post->ID, '_mds_ac_sponsor_url', true),
            'sponsor_email' => (string) get_post_meta($post->ID, '_mds_ac_sponsor_email', true),
            'placement_copy' => (string) $post->post_content,
            'disclosure_label' => (string) get_post_meta($post->ID, '_mds_ac_disclosure_label', true),
            'source_approved' => '1' === (string) get_post_meta($post->ID, '_mds_ac_source_approved', true),
            'destination_approved' => '1' === (string) get_post_meta($post->ID, '_mds_ac_destination_approved', true),
            'terms_accepted' => '1' === (string) get_post_meta($post->ID, '_mds_ac_terms_accepted', true),
            'notes' => (string) get_post_meta($post->ID, '_mds_ac_notes', true),
            'inventory_consumed' => '1' === (string) get_post_meta($post->ID, '_mds_ac_inventory_consumed', true),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    public static function audit_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'event' => (string) get_post_meta($post->ID, '_mds_ac_event', true),
            'object_type' => (string) get_post_meta($post->ID, '_mds_ac_object_type', true),
            'object_id' => absint(get_post_meta($post->ID, '_mds_ac_object_id', true)),
            'message' => (string) $post->post_content,
            'user_id' => absint(get_post_meta($post->ID, '_mds_ac_user_id', true)),
            'ip_hash' => (string) get_post_meta($post->ID, '_mds_ac_ip_hash', true),
            'details' => self::decode_json(get_post_meta($post->ID, '_mds_ac_details', true)),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    private static function post_payloads($post_type, $callback, $limit = 50) {
        $posts = get_posts([
            'post_type' => $post_type,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => min(100, max(1, absint($limit))),
            'no_found_rows' => true,
        ]);

        return array_values(array_filter(array_map($callback, $posts)));
    }

    private static function meta_filtered_posts($post_type, $callback, $pool_id = 0, $limit = 100) {
        $query = [
            'post_type' => $post_type,
            'post_status' => ['publish', 'pending', 'draft'],
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => min(100, max(1, absint($limit))),
            'no_found_rows' => true,
        ];
        if ($pool_id) {
            $query['meta_query'] = [[
                'key' => '_mds_ac_pool_id',
                'value' => absint($pool_id),
                'compare' => '=',
            ]];
        }

        $posts = get_posts($query);

        return array_values(array_filter(array_map($callback, $posts)));
    }

    private static function post_payload_page($post_type, $callback, $pool_id, $page, $per_page) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $query_args = [
            'post_type' => $post_type,
            'post_status' => self::POOL_POST_TYPE === $post_type || self::AUDIT_POST_TYPE === $post_type
                ? 'publish'
                : ['publish', 'pending', 'draft'],
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ];
        if ($pool_id) {
            $query_args['meta_query'] = [[
                'key' => '_mds_ac_pool_id',
                'value' => absint($pool_id),
                'compare' => '=',
            ]];
        }

        $query = new \WP_Query($query_args);

        return [
            'items' => array_values(array_filter(array_map($callback, $query->posts))),
            'page' => $page,
            'per_page' => $per_page,
            'total' => absint($query->found_posts),
            'pages' => max(1, absint($query->max_num_pages)),
        ];
    }

    private static function post_payloads_by_meta($post_type, $callback, $meta_key, $meta_value, $page, $per_page) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        if ('' === (string) $meta_value) {
            return ['items' => [], 'page' => $page, 'per_page' => $per_page, 'total' => 0, 'pages' => 1];
        }

        $query = new \WP_Query([
            'post_type' => $post_type,
            'post_status' => ['publish', 'pending', 'draft'],
            'orderby' => 'ID',
            'order' => 'ASC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
            'meta_query' => [[
                'key' => sanitize_key((string) $meta_key),
                'value' => (string) $meta_value,
                'compare' => '=',
            ]],
        ]);

        return [
            'items' => array_values(array_filter(array_map($callback, $query->posts))),
            'page' => $page,
            'per_page' => $per_page,
            'total' => absint($query->found_posts),
            'pages' => max(1, absint($query->max_num_pages)),
        ];
    }

    private static function expired_record_ids($post_type, $pool_id, $status_key, array $statuses, $cutoff, $batch_size, array $private_meta_keys) {
        $private_query = ['relation' => 'OR'];
        foreach ($private_meta_keys as $private_meta_key) {
            $private_query[] = ['key' => sanitize_key((string) $private_meta_key), 'value' => '', 'compare' => '!='];
        }

        return array_map('absint', get_posts([
            'post_type' => $post_type,
            'post_status' => ['publish', 'pending', 'draft'],
            'fields' => 'ids',
            'posts_per_page' => $batch_size,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'meta_query' => [
                'relation' => 'AND',
                ['key' => '_mds_ac_pool_id', 'value' => absint($pool_id), 'compare' => '='],
                ['key' => sanitize_key((string) $status_key), 'value' => array_map('sanitize_key', $statuses), 'compare' => 'IN'],
                [
                    'relation' => 'OR',
                    ['key' => '_mds_ac_private_data_updated_at', 'value' => absint($cutoff), 'compare' => '<=', 'type' => 'NUMERIC'],
                    ['key' => '_mds_ac_private_data_updated_at', 'compare' => 'NOT EXISTS'],
                ],
                $private_query,
            ],
            'date_query' => [['column' => 'post_date_gmt', 'before' => gmdate('Y-m-d H:i:s', $cutoff), 'inclusive' => true]],
        ]));
    }

    private static function consume_inventory(array $placement) {
        if (!empty($placement['inventory_consumed'])) {
            return true;
        }

        global $wpdb;
        $placement_id = absint($placement['id'] ?? 0);
        $inventory_id = absint($placement['inventory_id'] ?? 0);
        if (!$placement_id || !$inventory_id) {
            return false;
        }

        $wpdb->query('START TRANSACTION');
        $consumed = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1 FOR UPDATE",
            $placement_id,
            '_mds_ac_inventory_consumed'
        ));
        if ('1' === (string) $consumed) {
            $wpdb->query('COMMIT');
            return true;
        }

        $available = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1 FOR UPDATE",
            $inventory_id,
            '_mds_ac_available'
        ));
        if (absint($available) < 1) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE post_id = %d AND meta_key = %s",
            absint($available) - 1,
            $inventory_id,
            '_mds_ac_available'
        ));
        if (1 !== (int) $updated) {
            $wpdb->query('ROLLBACK');
            return false;
        }

        update_post_meta($placement_id, '_mds_ac_inventory_consumed', '1');
        $wpdb->query('COMMIT');
        wp_cache_delete($placement_id, 'post_meta');
        wp_cache_delete($inventory_id, 'post_meta');

        return true;
    }

    private static function restore_inventory(array $placement) {
        if (empty($placement['inventory_consumed'])) {
            return true;
        }

        global $wpdb;
        $placement_id = absint($placement['id'] ?? 0);
        $inventory_id = absint($placement['inventory_id'] ?? 0);
        $inventory = self::inventory($inventory_id);
        if (!$placement_id || !$inventory) {
            return false;
        }

        $wpdb->query('START TRANSACTION');
        $consumed = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1 FOR UPDATE",
            $placement_id,
            '_mds_ac_inventory_consumed'
        ));
        if ('1' !== (string) $consumed) {
            $wpdb->query('COMMIT');
            return true;
        }

        $available = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s LIMIT 1 FOR UPDATE",
            $inventory_id,
            '_mds_ac_available'
        ));
        $restored = min(absint($inventory['capacity'] ?? 0), absint($available) + 1);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE post_id = %d AND meta_key = %s",
            $restored,
            $inventory_id,
            '_mds_ac_available'
        ));
        update_post_meta($placement_id, '_mds_ac_inventory_consumed', '0');
        $wpdb->query('COMMIT');
        wp_cache_delete($placement_id, 'post_meta');
        wp_cache_delete($inventory_id, 'post_meta');

        return true;
    }

    private static function site_manifest($pool) {
        $pool = is_array($pool) ? $pool : [];

        return [
            'name' => (string) ($pool['site_name'] ?? get_bloginfo('name')),
            'url' => (string) ($pool['site_url'] ?? home_url('/')),
            'takedown_url' => (string) ($pool['takedown_url'] ?? ''),
            'terms_url' => (string) ($pool['terms_url'] ?? ''),
            'privacy_url' => (string) ($pool['privacy_url'] ?? ''),
            'disclosure_label' => (string) ($pool['disclosure_label'] ?? __('Sponsored placement', 'mds-attention-cooperative')),
        ];
    }

    private static function grid_manifest() {
        if (!class_exists('\\MillionDollarScript\\Core\\Grids')) {
            return [];
        }

        $items = [];
        foreach (\MillionDollarScript\Core\Grids::all() as $grid) {
            if (!is_object($grid) || !method_exists($grid, 'id') || !method_exists($grid, 'get')) {
                continue;
            }
            if ('active' !== (string) $grid->get('status', '')) {
                continue;
            }
            $items[] = [
                'provider' => 'grid',
                'source_id' => absint($grid->id()),
                'label' => (string) $grid->get('title', __('Classic Pixel Grid', 'mds-attention-cooperative')),
                'status' => (string) $grid->get('status', ''),
                'currency' => self::default_currency(),
                'inventory_url' => rest_url('million-dollar-script/v1/grids/' . absint($grid->id())),
            ];
        }

        return $items;
    }

    private static function sponsorboard_manifest() {
        if (!class_exists('\\MillionDollarScript\\Extensions\\SponsorBoard\\Repository') || !method_exists('\\MillionDollarScript\\Extensions\\SponsorBoard\\Repository', 'all_boards')) {
            return [];
        }

        $items = [];
        foreach (\MillionDollarScript\Extensions\SponsorBoard\Repository::all_boards(50) as $board) {
            if ('active' !== (string) ($board['status'] ?? '')) {
                continue;
            }
            $slots = method_exists('\\MillionDollarScript\\Extensions\\SponsorBoard\\Repository', 'slots_for_board') ? \MillionDollarScript\Extensions\SponsorBoard\Repository::slots_for_board(absint($board['id'] ?? 0)) : [];
            $available = count(array_filter($slots, static fn($slot) => 'available' === (string) ($slot['status'] ?? '')));
            $items[] = [
                'provider' => 'sponsorboard',
                'source_id' => absint($board['id'] ?? 0),
                'label' => (string) ($board['title'] ?? __('SponsorBoard', 'mds-attention-cooperative')),
                'status' => (string) ($board['status'] ?? ''),
                'currency' => self::effective_currency($board['currency'] ?? ''),
                'available' => $available,
                'inventory_url' => rest_url('million-dollar-script/v1/sponsorboard/boards/' . absint($board['id'] ?? 0)),
            ];
        }

        return $items;
    }

    private static function launch_wall_manifest() {
        if (!class_exists('\\MillionDollarScript\\Extensions\\LaunchWall\\Repository') || !method_exists('\\MillionDollarScript\\Extensions\\LaunchWall\\Repository', 'walls')) {
            return [];
        }

        $items = [];
        foreach (\MillionDollarScript\Extensions\LaunchWall\Repository::walls(50) as $wall) {
            if ('active' !== (string) ($wall['status'] ?? '')) {
                continue;
            }
            $items[] = [
                'provider' => 'launch-wall',
                'source_id' => absint($wall['id'] ?? 0),
                'label' => (string) ($wall['title'] ?? __('Launch Wall', 'mds-attention-cooperative')),
                'status' => (string) ($wall['status'] ?? ''),
                'currency' => self::effective_currency($wall['currency'] ?? ''),
                'price_from' => (float) ($wall['base_price'] ?? 0),
                'inventory_url' => rest_url('million-dollar-script/v1/launch-wall/walls'),
            ];
        }

        return $items;
    }

    private static function missions_manifest() {
        if (!class_exists('\\MillionDollarScript\\Extensions\\Missions\\Repository') || !method_exists('\\MillionDollarScript\\Extensions\\Missions\\Repository', 'missions')) {
            return [];
        }

        $items = [];
        foreach (\MillionDollarScript\Extensions\Missions\Repository::missions(50) as $mission) {
            if ('active' !== (string) ($mission['status'] ?? '')) {
                continue;
            }
            $items[] = [
                'provider' => 'missions',
                'source_id' => absint($mission['id'] ?? 0),
                'label' => (string) ($mission['title'] ?? __('Mission', 'mds-attention-cooperative')),
                'status' => (string) ($mission['status'] ?? ''),
                'currency' => self::effective_currency($mission['currency'] ?? ''),
                'price_from' => (float) ($mission['suggested_amount'] ?? 0),
                'inventory_url' => rest_url('million-dollar-script/v1/missions'),
            ];
        }

        return $items;
    }

    private static function local_money_map_manifest() {
        if (!class_exists('\\MillionDollarScript\\Extensions\\LocalMoneyMap\\Repository') || !method_exists('\\MillionDollarScript\\Extensions\\LocalMoneyMap\\Repository', 'boards')) {
            return [];
        }

        $items = [];
        foreach (\MillionDollarScript\Extensions\LocalMoneyMap\Repository::boards(50) as $board) {
            if ('active' !== (string) ($board['status'] ?? '')) {
                continue;
            }
            $items[] = [
                'provider' => 'local-money-map',
                'source_id' => absint($board['id'] ?? 0),
                'label' => (string) ($board['title'] ?? __('Local Money Map', 'mds-attention-cooperative')),
                'status' => (string) ($board['status'] ?? ''),
                'currency' => self::effective_currency($board['currency'] ?? ''),
                'available' => max(0, absint($board['localmap_limit'] ?? 0) - absint($board['used_spots'] ?? 0)),
                'inventory_url' => rest_url('million-dollar-script/v1/local-money-map/boards'),
            ];
        }

        return $items;
    }

    private static function sanitize_inventory_types($value) {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value);
        }
        $value = is_array($value) ? $value : [];
        $valid = self::inventory_types();
        $types = [];
        foreach ($value as $item) {
            $type = sanitize_key((string) $item);
            if (in_array($type, $valid, true)) {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    private static function one_of($value, array $valid, $fallback) {
        $value = sanitize_key((string) $value);

        return in_array($value, $valid, true) ? $value : $fallback;
    }

    private static function money($value) {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'amount')) {
            return \MillionDollarScript\Commerce\Currency::amount($value);
        }

        return round(max(0, (float) $value), 2);
    }

    private static function currency($value) {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'normalize_code')) {
            return \MillionDollarScript\Commerce\Currency::normalize_code($value, self::default_currency(), 8);
        }

        $code = strtoupper(substr(preg_replace('/[^A-Z]/', '', strtoupper((string) $value)), 0, 8));

        return '' !== $code ? $code : 'USD';
    }

    private static function json($value) {
        return wp_json_encode(is_array($value) ? $value : []);
    }

    private static function decode_json($value) {
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function valid_https_url($value) {
        return '' !== self::https_url($value);
    }

    private static function http_url($value) {
        return esc_url_raw(self::bounded_raw($value, 2048), ['http', 'https']);
    }

    private static function https_url($value) {
        $url = esc_url_raw(self::bounded_raw($value, 2048), ['https']);
        if ('' === $url) {
            return '';
        }

        $parts = wp_parse_url($url);
        return is_array($parts) && 'https' === strtolower((string) ($parts['scheme'] ?? '')) && !empty($parts['host'])
            ? $url
            : '';
    }

    private static function bounded_raw($value, $length) {
        $value = trim((string) $value);
        $length = max(1, absint($length));

        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    private static function bounded_text($value, $length) {
        return sanitize_text_field(self::bounded_raw($value, $length));
    }

    private static function bounded_textarea($value, $length) {
        return sanitize_textarea_field(self::bounded_raw($value, $length));
    }

    private static function ip_hash() {
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));

        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }
}
