<?php
/**
 * Administrator-only Attention Cooperative credential lifecycle UI.
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

if (!defined('ABSPATH')) {
    exit;
}

final class ServiceCredentialAdmin {

    public static function register(): void {
        add_action('admin_post_mds_attention_cooperative_create_credential', [__CLASS__, 'handle_create']);
        add_action('admin_post_mds_attention_cooperative_rotate_credential', [__CLASS__, 'handle_rotate']);
        add_action('admin_post_mds_attention_cooperative_revoke_credential', [__CLASS__, 'handle_revoke']);
    }

    public static function render_for_partner(array $partner): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $partner_id = absint($partner['id'] ?? 0);
        $credentials = ServiceCredentialRepository::for_partner($partner_id);
        ?>
        <details class="mds-attention-cooperative-editor">
            <summary><?php esc_html_e('Service credentials', 'mds-attention-cooperative'); ?></summary>
            <div class="mds-attention-cooperative-credentials">
                <p><?php esc_html_e('Credentials authenticate signed placement requests only. They do not grant WordPress administrator access.', 'mds-attention-cooperative'); ?></p>
                <?php if ($credentials) : ?>
                    <div class="mds-attention-cooperative-table">
                        <?php foreach ($credentials as $credential) : ?>
                            <article>
                                <div>
                                    <strong><?php echo esc_html((string) $credential['label']); ?></strong>
                                    <span><code><?php echo esc_html((string) $credential['service_id']); ?></code></span>
                                    <span>
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                __('%1$s · expires %2$s', 'mds-attention-cooperative'),
                                                (string) $credential['status'],
                                                (string) $credential['expires_at']
                                            )
                                        );
                                        ?>
                                    </span>
                                </div>
                                <?php if (in_array((string) $credential['status'], ['active', 'retiring'], true)) : ?>
                                    <div class="mds-attention-cooperative-actions">
                                        <?php self::action_form('rotate', $partner_id, absint($credential['id']), __('Rotate', 'mds-attention-cooperative')); ?>
                                        <?php self::action_form('revoke', $partner_id, absint($credential['id']), __('Revoke', 'mds-attention-cooperative')); ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <p><?php esc_html_e('No service credentials have been configured. Remote placement requests remain denied.', 'mds-attention-cooperative'); ?></p>
                <?php endif; ?>

                <?php if ('approved' === (string) ($partner['status'] ?? '')) : ?>
                    <?php if (CredentialCipher::available()) : ?>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-attention-cooperative-form">
                            <?php wp_nonce_field('mds_attention_cooperative_create_credential_' . $partner_id); ?>
                            <input type="hidden" name="action" value="mds_attention_cooperative_create_credential">
                            <input type="hidden" name="partner_id" value="<?php echo esc_attr((string) $partner_id); ?>">
                            <input type="hidden" name="credential_action_token" value="<?php echo esc_attr(self::issue_action_token('create', $partner_id)); ?>">
                            <label class="mds-attention-cooperative-field">
                                <span><?php esc_html_e('Credential label', 'mds-attention-cooperative'); ?></span>
                                <input type="text" name="credential_label" maxlength="191" required value="<?php esc_attr_e('Partner placement service', 'mds-attention-cooperative'); ?>">
                            </label>
                            <label class="mds-attention-cooperative-field">
                                <span><?php esc_html_e('Expires after', 'mds-attention-cooperative'); ?></span>
                                <input type="number" name="credential_days" min="1" max="365" value="90" required>
                            </label>
                            <p class="submit"><button class="button" type="submit"><?php esc_html_e('Create service credential', 'mds-attention-cooperative'); ?></button></p>
                        </form>
                    <?php else : ?>
                        <p class="mds-attention-cooperative-note"><?php esc_html_e('Authenticated secret encryption is unavailable. Service credential creation is disabled.', 'mds-attention-cooperative'); ?></p>
                    <?php endif; ?>
                <?php else : ?>
                    <p class="mds-attention-cooperative-note"><?php esc_html_e('Approve this partner before creating a service credential.', 'mds-attention-cooperative'); ?></p>
                <?php endif; ?>
            </div>
        </details>
        <?php
    }

    public static function handle_create(): void {
        self::require_manage();
        $partner_id = absint($_POST['partner_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_create_credential_' . $partner_id);
        self::consume_action_token('create', $partner_id);

        $created = ServiceCredentialRepository::create(
            $partner_id,
            sanitize_text_field(wp_unslash((string) ($_POST['credential_label'] ?? ''))),
            min(365, max(1, absint($_POST['credential_days'] ?? 90)))
        );
        self::reveal_or_error($created, $partner_id);
    }

    public static function handle_rotate(): void {
        self::require_manage();
        $credential_id = absint($_POST['credential_id'] ?? 0);
        $partner_id = absint($_POST['partner_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_rotate_credential_' . $credential_id);
        self::consume_action_token('rotate', $credential_id);

        $credential = ServiceCredentialRepository::find($credential_id);
        if (!$credential || $partner_id !== absint($credential['partner_id'] ?? 0)) {
            wp_die(esc_html__('Service credential not found.', 'mds-attention-cooperative'), '', ['response' => 404]);
        }

        self::reveal_or_error(ServiceCredentialRepository::rotate($credential_id), $partner_id);
    }

    public static function handle_revoke(): void {
        self::require_manage();
        $credential_id = absint($_POST['credential_id'] ?? 0);
        $partner_id = absint($_POST['partner_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_revoke_credential_' . $credential_id);

        $credential = ServiceCredentialRepository::find($credential_id);
        if (!$credential || $partner_id !== absint($credential['partner_id'] ?? 0) || !ServiceCredentialRepository::revoke($credential_id)) {
            wp_die(esc_html__('Service credential could not be revoked.', 'mds-attention-cooperative'), '', ['response' => 400]);
        }

        wp_safe_redirect(self::partner_url($partner_id));
        exit;
    }

    private static function reveal_or_error($created, $partner_id): void {
        if (is_wp_error($created)) {
            wp_die(esc_html($created->get_error_message()), esc_html__('Service credential error', 'mds-attention-cooperative'), ['response' => 400]);
        }

        $credential = is_array($created['credential'] ?? null) ? $created['credential'] : [];
        $secret = (string) ($created['secret'] ?? '');
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        $back_url = self::partner_url($partner_id);

        ob_start();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Save this service credential now', 'mds-attention-cooperative'); ?></h1>
            <p><?php esc_html_e('The secret is shown once. Store it in the partner service secret manager before leaving this page.', 'mds-attention-cooperative'); ?></p>
            <p><strong><?php esc_html_e('Service ID', 'mds-attention-cooperative'); ?></strong><br><code><?php echo esc_html((string) ($credential['service_id'] ?? '')); ?></code></p>
            <p><strong><?php esc_html_e('Secret', 'mds-attention-cooperative'); ?></strong><br><code><?php echo esc_html($secret); ?></code></p>
            <p><a class="button button-primary" href="<?php echo esc_url($back_url); ?>"><?php esc_html_e('Return to partner credentials', 'mds-attention-cooperative'); ?></a></p>
        </div>
        <?php
        $html = (string) ob_get_clean();
        CredentialCipher::wipe($secret);
        wp_die($html, esc_html__('Service credential created', 'mds-attention-cooperative'), ['response' => 200]);
    }

    private static function action_form($operation, $partner_id, $credential_id, $label): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mds_attention_cooperative_' . $operation . '_credential_' . $credential_id); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr('mds_attention_cooperative_' . $operation . '_credential'); ?>">
            <input type="hidden" name="partner_id" value="<?php echo esc_attr((string) $partner_id); ?>">
            <input type="hidden" name="credential_id" value="<?php echo esc_attr((string) $credential_id); ?>">
            <?php if ('rotate' === $operation) : ?>
                <input type="hidden" name="credential_action_token" value="<?php echo esc_attr(self::issue_action_token('rotate', $credential_id)); ?>">
            <?php endif; ?>
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private static function issue_action_token($operation, $object_id): string {
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        set_transient(self::token_key($operation, $object_id), hash('sha256', $token), 15 * MINUTE_IN_SECONDS);

        return $token;
    }

    private static function consume_action_token($operation, $object_id): void {
        $key = self::token_key($operation, $object_id);
        $expected = (string) get_transient($key);
        delete_transient($key);
        $provided = (string) wp_unslash($_POST['credential_action_token'] ?? '');
        if ('' === $expected || !hash_equals($expected, hash('sha256', $provided))) {
            wp_die(esc_html__('This credential action has expired. Return to the partner and try again.', 'mds-attention-cooperative'), '', ['response' => 403]);
        }
    }

    private static function token_key($operation, $object_id): string {
        return 'mds_ac_credential_action_' . get_current_user_id() . '_' . sanitize_key((string) $operation) . '_' . absint($object_id);
    }

    private static function partner_url($partner_id): string {
        $partner = Repository::partner(absint($partner_id));

        return add_query_arg([
            'page' => 'mds-attention-cooperative',
            'tab' => 'partners',
            'pool_id' => absint($partner['pool_id'] ?? 0),
            'edit_partner' => absint($partner_id),
        ], admin_url('admin.php'));
    }

    private static function require_manage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage service credentials.', 'mds-attention-cooperative'), '', ['response' => 403]);
        }
    }
}
