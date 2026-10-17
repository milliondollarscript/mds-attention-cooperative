<?php
/**
 * Attention Cooperative runtime, admin UI, shortcode, REST API, and privacy hooks.
 *
 * @package MillionDollarScript\Extensions\AttentionCooperative
 */

namespace MillionDollarScript\Extensions\AttentionCooperative;

if (!defined('ABSPATH')) {
    exit;
}

final class Main {

    public const REST_NAMESPACE = 'million-dollar-script/v1';
    public const REST_BASE = 'cooperative';

    private static $instance;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register() {
        add_action('init', [__CLASS__, 'register_post_types'], 5);
        add_action('init', [Repository::class, 'migrate'], 6);
        add_action('init', [ServiceCredentialRepository::class, 'maybe_install'], 6);
        add_action('init', [ServiceSignature::class, 'register'], 8);
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('admin_menu', [$this, 'admin_menu'], 20);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('admin_post_mds_attention_cooperative_save_pool', [$this, 'handle_save_pool']);
        add_action('admin_post_mds_attention_cooperative_save_inventory', [$this, 'handle_save_inventory']);
        add_action('admin_post_mds_attention_cooperative_save_partner', [$this, 'handle_save_partner']);
        add_action('admin_post_mds_attention_cooperative_partner_action', [$this, 'handle_partner_action']);
        add_action('admin_post_mds_attention_cooperative_create_placement', [$this, 'handle_create_placement']);
        add_action('admin_post_mds_attention_cooperative_placement_action', [$this, 'handle_placement_action']);
        add_action('admin_post_mds_attention_cooperative_apply', [$this, 'handle_public_application']);
        add_action('admin_post_nopriv_mds_attention_cooperative_apply', [$this, 'handle_public_application']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_filter('million-dollar-script/api/endpoint/manifest', [$this, 'api_endpoint_manifest']);
        add_filter('million-dollar-script/api/openapi/document', [$this, 'openapi_document']);
        add_filter('million-dollar-script/extension/onboarding/items', [$this, 'extension_onboarding_items']);
        add_filter('million-dollar-script/editor/extension/blocks', [$this, 'editor_blocks']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'privacy_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'privacy_erasers']);
        ServiceCredentialAdmin::register();
    }

    public static function register_post_types() {
        Repository::register_post_types();
    }

    public function load_textdomain() {
        load_plugin_textdomain('mds-attention-cooperative', false, dirname(MDS_ATTENTION_COOPERATIVE_BASENAME) . '/languages');
    }

    public function register_shortcodes() {
        add_shortcode('mds_attention_cooperative', [$this, 'shortcode']);
    }

    public function editor_blocks(array $blocks) {
        $blocks[] = [
            'name' => 'mds-attention-cooperative/pool',
            'title' => __('Attention Cooperative', 'mds-attention-cooperative'),
            'description' => __('Display a Million Dollar Script cooperative pool with inventory, placements, and partner application details.', 'mds-attention-cooperative'),
            'icon' => 'networking',
            'category' => 'widgets',
            'shortcode' => 'mds_attention_cooperative',
            'attributes' => [
                'id' => ['type' => 'number', 'default' => 0],
                'slug' => ['type' => 'string', 'default' => ''],
                'show_inventory' => ['type' => 'boolean', 'default' => true],
                'show_placements' => ['type' => 'boolean', 'default' => true],
                'show_application_form' => ['type' => 'boolean', 'default' => true],
            ],
            'controls' => [
                [
                    'attribute' => 'id',
                    'type' => 'entity',
                    'label' => __('Cooperative', 'mds-attention-cooperative'),
                    'help' => __('Choose a saved cooperative by numeric ID. Leave this on First public cooperative to let the block pick automatically, or use Custom cooperative ID only when you already know the ID.', 'mds-attention-cooperative'),
                    'empty_label' => __('First public cooperative', 'mds-attention-cooperative'),
                    'custom_label' => __('Custom cooperative ID', 'mds-attention-cooperative'),
                    'custom_help' => __('Enter the numeric database ID for a cooperative that is not shown in the list. This is different from the cooperative slug.', 'mds-attention-cooperative'),
                    'options' => $this->block_entity_options(Repository::pools(100), __('Untitled cooperative', 'mds-attention-cooperative')),
                ],
                [
                    'attribute' => 'slug',
                    'type' => 'text',
                    'label' => __('Cooperative slug', 'mds-attention-cooperative'),
                    'help' => __('Optional URL-safe cooperative slug used only when no specific cooperative ID is selected. Leave blank unless you want this block to find a cooperative by slug.', 'mds-attention-cooperative'),
                ],
                [
                    'attribute' => 'show_inventory',
                    'type' => 'toggle',
                    'label' => __('Show exposed inventory', 'mds-attention-cooperative'),
                    'help' => __('Display public inventory records available to approved partners.', 'mds-attention-cooperative'),
                ],
                [
                    'attribute' => 'show_placements',
                    'type' => 'toggle',
                    'label' => __('Show approved placements', 'mds-attention-cooperative'),
                    'help' => __('Display approved cooperative sponsor placements.', 'mds-attention-cooperative'),
                ],
                [
                    'attribute' => 'show_application_form',
                    'type' => 'toggle',
                    'label' => __('Show partner application form', 'mds-attention-cooperative'),
                    'help' => __('Display the public partner application form when applications are enabled.', 'mds-attention-cooperative'),
                ],
            ],
            'preview' => [
                'title' => __('Attention Cooperative', 'mds-attention-cooperative'),
                'description' => __('Shows cooperative inventory, approved placements, disclosure links, and optional partner applications.', 'mds-attention-cooperative'),
                'rows' => [
                    __('Exposed inventory and sponsor placement cards', 'mds-attention-cooperative'),
                    __('Terms, privacy, takedown, and disclosure links', 'mds-attention-cooperative'),
                    __('Optional public partner application form', 'mds-attention-cooperative'),
                ],
            ],
            'render_callback' => [$this, 'render_editor_block'],
        ];

        return $blocks;
    }

    private function block_entity_options(array $items, $fallback_title) {
        $options = [];
        foreach ($items as $item) {
            $id = absint($item['id'] ?? 0);
            if (!$id) {
                continue;
            }

            $label = sprintf('#%d %s', $id, (string) ($item['title'] ?? $fallback_title));
            if (!empty($item['status'])) {
                $label .= ' - ' . ucfirst((string) $item['status']);
            }

            $options[] = [
                'label' => $label,
                'value' => (string) $id,
            ];
        }

        return $options;
    }

    public function render_editor_block(array $attributes = []) {
        return $this->shortcode([
            'id' => absint($attributes['id'] ?? 0),
            'slug' => sanitize_title((string) ($attributes['slug'] ?? '')),
            'show_inventory' => $this->truthy($attributes['show_inventory'] ?? true) ? 'true' : 'false',
            'show_placements' => $this->truthy($attributes['show_placements'] ?? true) ? 'true' : 'false',
            'show_application_form' => $this->truthy($attributes['show_application_form'] ?? true) ? 'true' : 'false',
        ]);
    }

    public function admin_menu() {
        add_submenu_page(
            $this->admin_parent_slug(),
            __('Attention Cooperative', 'mds-attention-cooperative'),
            __('Attention Cooperative', 'mds-attention-cooperative'),
            'manage_options',
            'mds-attention-cooperative',
            [$this, 'admin_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos((string) $hook, 'mds-attention-cooperative')) {
            return;
        }

        if (class_exists('\MillionDollarScript\Core\Runtime')) {
            wp_enqueue_style('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'css/admin.css', [], \MillionDollarScript\Core\Runtime::version());
            wp_enqueue_script('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'js/admin.js', [], \MillionDollarScript\Core\Runtime::version(), true);
        }
        wp_enqueue_style('mds-attention-cooperative-admin', MDS_ATTENTION_COOPERATIVE_URL . 'assets/css/admin.css', [], MDS_ATTENTION_COOPERATIVE_VERSION);
    }

    public function extension_onboarding_items(array $items) {
        $items['mds-attention-cooperative'] = [
            'name' => __('Attention Cooperative', 'mds-attention-cooperative'),
            'summary' => __('Review partner approvals, inventory sharing limits, revocation, audit logs, privacy exports, and takedown rules before accepting applications.', 'mds-attention-cooperative'),
            'priority' => 25,
            'actions' => [
                ['label' => __('Open Attention Cooperative', 'mds-attention-cooperative'), 'url' => admin_url('admin.php?page=mds-attention-cooperative'), 'primary' => true],
            ],
            'legal_documents' => [
                [
                    'slug' => 'partner-inventory-terms',
                    'title' => __('Partner Inventory and Privacy Terms', 'mds-attention-cooperative'),
                    'description' => __('Terms for partner applications, shared sponsor inventory, approvals, revocation, takedowns, audit records, and privacy requests.', 'mds-attention-cooperative'),
                    'page_slug' => 'partner-inventory-privacy-terms',
                    'content' => $this->attention_cooperative_legal_content(),
                ],
            ],
        ];

        return $items;
    }

    private function attention_cooperative_legal_content() {
        return '<h2>' . esc_html__('Partner Inventory and Privacy Terms', 'mds-attention-cooperative') . '</h2>'
            . '<p>' . esc_html__('These terms apply when {{site_name}} uses Attention Cooperative to coordinate sponsor inventory between approved partner sites, including applications, partner profiles, inventory exposure settings, placement records, audit logs, revocation, and takedown controls.', 'mds-attention-cooperative') . '</p>'
            . '<h3>' . esc_html__('Partner Approval and Scope', 'mds-attention-cooperative') . '</h3>'
            . '<p>' . esc_html__('Participation requires approval by {{site_name}} and, where applicable, destination partners. Approved partners may be allowed to expose limited sponsor inventory, application details, site profile information, placement availability, disclosure labels, and status data. Participation does not create exclusivity, agency, partnership, revenue guarantee, traffic guarantee, acceptance guarantee, or quality guarantee unless a separate written agreement says so.', 'mds-attention-cooperative') . '</p>'
            . '<h3>' . esc_html__('Revocation, Takedowns, and Privacy', 'mds-attention-cooperative') . '</h3>'
            . '<p>' . esc_html__('{{site_name}} may revoke access, pause inventory, remove placements, process takedowns, limit partner visibility, and retain audit records for security, abuse prevention, dispute handling, accounting, legal compliance, and operational reliability. Partner, sponsor, placement, application, contact, technical, and audit data may be shared only as needed to review applications, coordinate inventory, display approved placements, process removals, and maintain logs.', 'mds-attention-cooperative') . '</p>'
            . '<h3>' . esc_html__('Sponsor Content and Responsibility', 'mds-attention-cooperative') . '</h3>'
            . '<p>' . esc_html__('Partners and sponsors are responsible for the accuracy, rights, permissions, advertising claims, regulated disclosures, links, trademarks, and safety of inventory or creative they submit. Content may be rejected, relabeled, paused, or removed if it is unlawful, misleading, infringing, abusive, unsafe, malicious, technically harmful, or inconsistent with site or partner rules.', 'mds-attention-cooperative') . '</p>'
            . '<h3>' . esc_html__('Requests and Contact', 'mds-attention-cooperative') . '</h3>'
            . '<p>' . esc_html__('Privacy, access, correction, export, deletion, takedown, and partner-support requests can be sent through {{contact_method}}. Requests may be limited by security, fraud-prevention, accounting, dispute, legal-retention, backup, or partner-contract obligations.', 'mds-attention-cooperative') . '</p>';
    }

    public function frontend_assets() {
        wp_register_style('mds-attention-cooperative', MDS_ATTENTION_COOPERATIVE_URL . 'assets/css/style.css', [], MDS_ATTENTION_COOPERATIVE_VERSION);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage Attention Cooperative.', 'mds-attention-cooperative'));
        }

        $pools_page = Repository::pools_page(absint($_GET['pools_page'] ?? 1), 20);
        $pools = $pools_page['items'];
        $selected_id = absint($_GET['pool_id'] ?? ($pools[0]['id'] ?? 0));
        $pool = $selected_id ? Repository::pool($selected_id) : null;
        $new_pool = [
            'id' => 0,
            'title' => '',
            'slug' => '',
            'status' => 'draft',
            'site_name' => get_bloginfo('name'),
            'site_url' => home_url('/'),
            'contact_email' => get_option('admin_email'),
            'takedown_url' => home_url('/contact/'),
            'terms_url' => '',
            'privacy_url' => function_exists('get_privacy_policy_url') ? get_privacy_policy_url() : '',
            'disclosure_label' => __('Sponsored placement', 'mds-attention-cooperative'),
            'terms_summary' => '',
            'prohibited_content' => '',
            'rate_limit_per_hour' => 20,
            'retention_days' => 365,
            'public_applications_enabled' => true,
            'source_approval_required' => true,
            'destination_approval_required' => true,
            'legal_review_completed' => false,
        ];
        $form_pool = $pool ?: $new_pool;
        $partners_page = $pool ? Repository::partners_page($pool['id'], absint($_GET['partners_page'] ?? 1), 20) : $this->empty_page();
        $inventory_page = $pool ? Repository::inventory_page($pool['id'], absint($_GET['inventory_page'] ?? 1), 20) : $this->empty_page();
        $placements_page = $pool ? Repository::placements_page($pool['id'], absint($_GET['placements_page'] ?? 1), 20) : $this->empty_page();
        $audits_page = Repository::audits_page($pool ? $pool['id'] : 0, absint($_GET['audits_page'] ?? 1), 20);
        $partners = $partners_page['items'];
        $inventory = $inventory_page['items'];
        $placements = $placements_page['items'];
        $audits = $audits_page['items'];
        $providers = Repository::provider_manifests();
        $currency_locked = Repository::currency_locked();
        $active_tab = $this->active_admin_tab((bool) $pool);
        $tabs = [];
        if ($pool) {
            $tabs += [
                'placements' => __('Placements', 'mds-attention-cooperative'),
                'partners' => __('Partners', 'mds-attention-cooperative'),
                'inventory' => __('Inventory', 'mds-attention-cooperative'),
                'activity' => __('Activity', 'mds-attention-cooperative'),
                'providers' => __('Providers', 'mds-attention-cooperative'),
            ];
        }
        $tabs['overview'] = __('Settings', 'mds-attention-cooperative');
        ?>
        <div class="wrap mds3-admin mds-attention-cooperative-admin">
            <div class="mds-attention-cooperative-hero">
                <div>
                    <p class="mds-attention-cooperative-eyebrow"><?php esc_html_e('Million Dollar Script extension', 'mds-attention-cooperative'); ?></p>
                    <h1><?php esc_html_e('Attention Cooperative', 'mds-attention-cooperative'); ?></h1>
                    <p><?php esc_html_e('Create admin-owned partner pools for sponsor inventory with explicit applications, approvals, per-partner exposure, audit logs, revocation, and takedown controls.', 'mds-attention-cooperative'); ?></p>
                </div>
                <div class="mds-extension-header-actions has-shortcode">
                    <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::docs_button('mds-attention-cooperative')); ?>
                    <?php if ($pool) : ?>
                        <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::shortcode_copy(sprintf('[mds_attention_cooperative id="%d"]', absint($pool['id'])))); ?>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="wp-header-end">

            <?php $this->admin_notice(); ?>

            <nav class="nav-tab-wrapper wp-clearfix mds-attention-cooperative-tabs" aria-label="<?php esc_attr_e('Attention Cooperative workspace', 'mds-attention-cooperative'); ?>">
                <?php foreach ($tabs as $tab_key => $tab_label) : ?>
                    <a
                        class="nav-tab<?php echo $active_tab === $tab_key ? ' nav-tab-active' : ''; ?>"
                        href="<?php echo esc_url($this->admin_url(['pool_id' => $selected_id, 'tab' => $tab_key])); ?>"
                        <?php echo $active_tab === $tab_key ? 'aria-current="page"' : ''; ?>
                    ><?php echo esc_html($tab_label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if ('overview' === $active_tab) : ?>
                <section class="mds3-card mds-attention-cooperative-release">
                    <div>
                        <h2><?php esc_html_e('Release gate', 'mds-attention-cooperative'); ?></h2>
                        <p><?php esc_html_e('Public applications and partner-visible inventory remain paused until the site owner confirms legal, privacy, email-consent, refund, takedown, and prohibited-content requirements for their own site.', 'mds-attention-cooperative'); ?></p>
                    </div>
                    <span class="<?php echo !empty($form_pool['legal_review_completed']) ? 'is-ready' : 'is-paused'; ?>">
                        <?php echo !empty($form_pool['legal_review_completed']) ? esc_html__('Review confirmed', 'mds-attention-cooperative') : esc_html__('Review required', 'mds-attention-cooperative'); ?>
                    </span>
                </section>
            <?php endif; ?>

            <div class="mds-attention-cooperative-layout">
                <aside class="mds3-card mds-attention-cooperative-panel">
                    <h2><?php esc_html_e('Cooperatives', 'mds-attention-cooperative'); ?></h2>
                    <div class="mds-attention-cooperative-list">
                        <?php foreach ($pools as $item) : ?>
                            <a class="mds-attention-cooperative-list-item <?php echo absint($item['id']) === absint($selected_id) ? 'is-selected' : ''; ?>" href="<?php echo esc_url($this->admin_url(['pool_id' => $item['id'], 'tab' => $active_tab])); ?>">
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <span><?php echo esc_html($item['status']); ?> · <?php echo !empty($item['legal_review_completed']) ? esc_html__('reviewed', 'mds-attention-cooperative') : esc_html__('review needed', 'mds-attention-cooperative'); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <a class="button" href="<?php echo esc_url($this->admin_url(['pool_id' => 0, 'tab' => 'overview'])); ?>"><?php esc_html_e('New cooperative', 'mds-attention-cooperative'); ?></a>
                    </div>
                    <?php $this->render_pagination($pools_page, 'pools_page', 0); ?>

                    <?php if ($pool) : ?>
                        <div class="mds-attention-cooperative-metrics">
                            <?php $this->metric(__('Partners', 'mds-attention-cooperative'), $partners_page['total']); ?>
                            <?php $this->metric(__('Inventory', 'mds-attention-cooperative'), $inventory_page['total']); ?>
                            <?php $this->metric(__('Requests', 'mds-attention-cooperative'), $placements_page['total']); ?>
                            <?php $this->metric(__('Detected providers', 'mds-attention-cooperative'), count($providers)); ?>
                        </div>
                    <?php endif; ?>
                </aside>

                <main class="mds-attention-cooperative-stack">
                    <?php if ('overview' === $active_tab) : ?>
                        <section class="mds3-card mds-attention-cooperative-panel">
                            <div class="mds-attention-cooperative-heading">
                                <div>
                                    <h2><?php echo $pool ? esc_html__('Cooperative settings', 'mds-attention-cooperative') : esc_html__('Create cooperative', 'mds-attention-cooperative'); ?></h2>
                                    <p><?php esc_html_e('These settings define the local site identity, disclosure, contact, takedown, and legal-review gate shared with approved partners.', 'mds-attention-cooperative'); ?></p>
                                </div>
                            </div>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-attention-cooperative-form">
                                <?php wp_nonce_field('mds_attention_cooperative_save_pool_' . absint($form_pool['id'])); ?>
                                <input type="hidden" name="action" value="mds_attention_cooperative_save_pool">
                                <input type="hidden" name="pool_id" value="<?php echo esc_attr((string) $form_pool['id']); ?>">

                                <?php $this->field('title', __('Name', 'mds-attention-cooperative'), $form_pool['title'], 'text', true); ?>
                                <?php $this->field('slug', __('Slug', 'mds-attention-cooperative'), $form_pool['slug'], 'text'); ?>
                                <?php $this->select('status', __('Status', 'mds-attention-cooperative'), $form_pool['status'], ['draft' => __('Draft', 'mds-attention-cooperative'), 'active' => __('Active', 'mds-attention-cooperative'), 'paused' => __('Paused', 'mds-attention-cooperative'), 'archived' => __('Archived', 'mds-attention-cooperative')]); ?>
                                <?php $this->field('site_name', __('Site name', 'mds-attention-cooperative'), $form_pool['site_name'], 'text', true); ?>
                                <?php $this->field('site_url', __('Public site URL', 'mds-attention-cooperative'), $form_pool['site_url'], 'url', true); ?>
                                <?php $this->field('contact_email', __('Contact email', 'mds-attention-cooperative'), $form_pool['contact_email'], 'email', true); ?>
                                <?php $this->field('takedown_url', __('Takedown URL', 'mds-attention-cooperative'), $form_pool['takedown_url'], 'url'); ?>
                                <?php $this->field('terms_url', __('Terms URL', 'mds-attention-cooperative'), $form_pool['terms_url'], 'url'); ?>
                                <?php $this->field('privacy_url', __('Privacy URL', 'mds-attention-cooperative'), $form_pool['privacy_url'], 'url'); ?>
                                <?php $this->field('disclosure_label', __('Disclosure label', 'mds-attention-cooperative'), $form_pool['disclosure_label'], 'text', true); ?>
                                <?php $this->field('rate_limit_per_hour', __('Partner rate limit per hour', 'mds-attention-cooperative'), $form_pool['rate_limit_per_hour'], 'number'); ?>
                                <?php $this->field('retention_days', __('Private data retention days', 'mds-attention-cooperative'), $form_pool['retention_days'], 'number'); ?>
                                <?php $this->field('terms_summary', __('Partner terms snapshot', 'mds-attention-cooperative'), $form_pool['terms_summary'], 'textarea', true); ?>
                                <?php $this->field('prohibited_content', __('Prohibited content', 'mds-attention-cooperative'), $form_pool['prohibited_content'], 'textarea'); ?>

                                <?php $this->checkbox('public_applications_enabled', __('Accept public partner applications', 'mds-attention-cooperative'), !empty($form_pool['public_applications_enabled'])); ?>
                                <?php $this->checkbox('source_approval_required', __('Require source-site approval', 'mds-attention-cooperative'), !empty($form_pool['source_approval_required'])); ?>
                                <?php $this->checkbox('destination_approval_required', __('Require destination-site approval', 'mds-attention-cooperative'), !empty($form_pool['destination_approval_required'])); ?>
                                <?php $this->checkbox('legal_review_completed', __('Legal/privacy/compliance review completed for this cooperative', 'mds-attention-cooperative'), !empty($form_pool['legal_review_completed'])); ?>

                                <p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e('Save cooperative', 'mds-attention-cooperative'); ?></button></p>
                            </form>
                        </section>
                    <?php endif; ?>

                    <?php if ($pool) : ?>
                        <?php if ('inventory' === $active_tab) : ?>
                            <?php $this->render_inventory_section($pool, $inventory, $currency_locked, $inventory_page); ?>
                        <?php elseif ('partners' === $active_tab) : ?>
                            <?php $this->render_partners_section($pool, $partners, $partners_page); ?>
                        <?php elseif ('placements' === $active_tab) : ?>
                            <?php $this->render_placements_section(
                                $pool,
                                $placements,
                                array_values(array_filter(Repository::partners($pool['id'], 100), static fn($item) => 'approved' === (string) ($item['status'] ?? ''))),
                                array_values(array_filter(Repository::inventory_items($pool['id'], false, 100), static fn($item) => 'exposed' === (string) ($item['status'] ?? '') && 'federated' === (string) ($item['scope'] ?? '') && absint($item['available'] ?? 0) > 0)),
                                $placements_page
                            ); ?>
                        <?php elseif ('providers' === $active_tab) : ?>
                            <?php $this->render_providers_section($providers); ?>
                        <?php elseif ('activity' === $active_tab) : ?>
                            <?php $this->render_audit_section($audits, $audits_page, $pool['id']); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </main>
            </div>
        </div>
        <?php
    }

    public function handle_save_pool() {
        $this->require_manage();
        $pool_id = absint($_POST['pool_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_save_pool_' . $pool_id);
        $saved = Repository::save_pool([
            'title' => wp_unslash($_POST['title'] ?? ''),
            'slug' => wp_unslash($_POST['slug'] ?? ''),
            'status' => wp_unslash($_POST['status'] ?? 'draft'),
            'site_name' => wp_unslash($_POST['site_name'] ?? ''),
            'site_url' => wp_unslash($_POST['site_url'] ?? ''),
            'contact_email' => wp_unslash($_POST['contact_email'] ?? ''),
            'takedown_url' => wp_unslash($_POST['takedown_url'] ?? ''),
            'terms_url' => wp_unslash($_POST['terms_url'] ?? ''),
            'privacy_url' => wp_unslash($_POST['privacy_url'] ?? ''),
            'disclosure_label' => wp_unslash($_POST['disclosure_label'] ?? ''),
            'terms_summary' => wp_unslash($_POST['terms_summary'] ?? ''),
            'prohibited_content' => wp_unslash($_POST['prohibited_content'] ?? ''),
            'rate_limit_per_hour' => wp_unslash($_POST['rate_limit_per_hour'] ?? 20),
            'retention_days' => wp_unslash($_POST['retention_days'] ?? 365),
            'public_applications_enabled' => !empty($_POST['public_applications_enabled']),
            'source_approval_required' => !empty($_POST['source_approval_required']),
            'destination_approval_required' => !empty($_POST['destination_approval_required']),
            'legal_review_completed' => !empty($_POST['legal_review_completed']),
        ], $pool_id);

        $this->redirect(['pool_id' => $saved ?: $pool_id, 'tab' => 'overview', 'mds_ac_status' => $saved ? 'saved' : 'error']);
    }

    public function handle_save_inventory() {
        $this->require_manage();
        $inventory_id = absint($_POST['inventory_id'] ?? 0);
        $pool_id = absint($_POST['pool_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_save_inventory_' . $pool_id);
        $saved = Repository::save_inventory([
            'pool_id' => $pool_id,
            'type' => wp_unslash($_POST['type'] ?? 'custom'),
            'label' => wp_unslash($_POST['label'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'status' => wp_unslash($_POST['status'] ?? 'draft'),
            'scope' => wp_unslash($_POST['scope'] ?? 'local'),
            'capacity' => wp_unslash($_POST['capacity'] ?? 0),
            'available' => wp_unslash($_POST['available'] ?? 0),
            'currency' => wp_unslash($_POST['currency'] ?? Repository::default_currency()),
            'price_from' => wp_unslash($_POST['price_from'] ?? 0),
            'approval_required' => !empty($_POST['approval_required']),
            'disclosure_label' => wp_unslash($_POST['disclosure_label'] ?? ''),
            'source_ref' => wp_unslash($_POST['source_ref'] ?? ''),
        ], $inventory_id);

        $this->redirect(['pool_id' => $pool_id, 'tab' => 'inventory', 'mds_ac_status' => $saved ? 'inventory_saved' : 'error']);
    }

    public function handle_save_partner() {
        $this->require_manage();
        $partner_id = absint($_POST['partner_id'] ?? 0);
        $pool_id = absint($_POST['pool_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_save_partner_' . $pool_id);
        $service_endpoint = wp_unslash($_POST['service_endpoint'] ?? '');
        if ('' !== trim((string) $service_endpoint) && !Repository::valid_https_url($service_endpoint)) {
            $this->redirect(['pool_id' => $pool_id, 'tab' => 'partners', 'mds_ac_status' => 'invalid_endpoint']);
        }
        $saved = Repository::save_partner([
            'pool_id' => $pool_id,
            'type' => wp_unslash($_POST['type'] ?? 'invite'),
            'status' => wp_unslash($_POST['status'] ?? 'invited'),
            'site_name' => wp_unslash($_POST['site_name'] ?? ''),
            'site_url' => wp_unslash($_POST['site_url'] ?? ''),
            'contact_name' => wp_unslash($_POST['contact_name'] ?? ''),
            'contact_email' => wp_unslash($_POST['contact_email'] ?? ''),
            'requested_scope' => wp_unslash($_POST['requested_scope'] ?? []),
            'allowed_inventory' => wp_unslash($_POST['allowed_inventory'] ?? []),
            'rate_limit_per_hour' => wp_unslash($_POST['rate_limit_per_hour'] ?? 10),
            'terms_accepted' => !empty($_POST['terms_accepted']),
            'service_endpoint' => $service_endpoint,
            'api_key_label' => wp_unslash($_POST['api_key_label'] ?? ''),
            'notes' => wp_unslash($_POST['notes'] ?? ''),
        ], $partner_id);

        $this->redirect(['pool_id' => $pool_id, 'tab' => 'partners', 'mds_ac_status' => $saved ? 'partner_saved' : 'error']);
    }

    public function handle_partner_action() {
        $this->require_manage();
        $partner_id = absint($_POST['partner_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_partner_action_' . $partner_id);
        $partner = Repository::partner($partner_id);
        $ok = $partner && Repository::update_partner_status($partner_id, wp_unslash($_POST['partner_status'] ?? ''));
        $this->redirect(['pool_id' => absint($partner['pool_id'] ?? 0), 'tab' => 'partners', 'mds_ac_status' => $ok ? 'partner_updated' : 'error']);
    }

    public function handle_create_placement() {
        $this->require_manage();
        $pool_id = absint($_POST['pool_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_create_placement_' . $pool_id);
        $data = [
            'pool_id' => $pool_id,
            'partner_id' => absint($_POST['partner_id'] ?? 0),
            'inventory_id' => absint($_POST['inventory_id'] ?? 0),
            'status' => 'pending_destination',
            'sponsor_name' => wp_unslash($_POST['sponsor_name'] ?? ''),
            'sponsor_url' => wp_unslash($_POST['sponsor_url'] ?? ''),
            'sponsor_email' => wp_unslash($_POST['sponsor_email'] ?? ''),
            'placement_copy' => wp_unslash($_POST['placement_copy'] ?? ''),
            'disclosure_label' => wp_unslash($_POST['disclosure_label'] ?? ''),
            'source_approved' => !empty($_POST['source_approved']),
            'destination_approved' => false,
            'terms_accepted' => !empty($_POST['terms_accepted']),
            'notes' => wp_unslash($_POST['notes'] ?? ''),
        ];
        $validation = Repository::validate_placement_request($data);
        if (is_wp_error($validation)) {
            $this->redirect([
                'pool_id' => $pool_id,
                'tab' => 'placements',
                'mds_ac_status' => sanitize_key($validation->get_error_code()),
            ]);
        }
        $created = Repository::create_placement($data);

        $this->redirect(['pool_id' => $pool_id, 'tab' => 'placements', 'mds_ac_status' => $created ? 'placement_created' : 'error']);
    }

    public function handle_placement_action() {
        $this->require_manage();
        $placement_id = absint($_POST['placement_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_placement_action_' . $placement_id);
        $placement = Repository::placement($placement_id);
        $status = sanitize_key(wp_unslash($_POST['placement_status'] ?? ''));
        if ($placement && 'source_approved' === $status) {
            $ok = Repository::approve_placement_source($placement_id);
            $this->redirect(['pool_id' => absint($placement['pool_id'] ?? 0), 'tab' => 'placements', 'mds_ac_status' => $ok ? 'placement_updated' : 'error']);
        }
        if ($placement && 'approved' === $status) {
            $validation = Repository::validate_placement_request($placement, true);
            if (is_wp_error($validation)) {
                $this->redirect([
                    'pool_id' => absint($placement['pool_id'] ?? 0),
                    'tab' => 'placements',
                    'mds_ac_status' => sanitize_key($validation->get_error_code()),
                ]);
            }
        }
        $ok = $placement && Repository::update_placement_status($placement_id, $status);
        $this->redirect(['pool_id' => absint($placement['pool_id'] ?? 0), 'tab' => 'placements', 'mds_ac_status' => $ok ? 'placement_updated' : 'error']);
    }

    public function handle_public_application() {
        $pool_id = absint($_POST['pool_id'] ?? 0);
        check_admin_referer('mds_attention_cooperative_apply_' . $pool_id);
        $referrer = esc_url_raw(wp_unslash($_POST['_wp_http_referer'] ?? wp_get_referer() ?: home_url('/')));
        if (!empty($_POST['company'])) {
            $this->safe_redirect($referrer, ['cooperative_status' => 'submitted']);
        }
        $pool = Repository::pool($pool_id);
        if (!$pool || 'active' !== (string) ($pool['status'] ?? '') || empty($pool['public_applications_enabled']) || empty($pool['legal_review_completed'])) {
            $this->safe_redirect($referrer, ['cooperative_status' => 'closed']);
        }
        if ($this->rate_limited('apply_' . $pool_id, absint($pool['rate_limit_per_hour'] ?? 20))) {
            $this->safe_redirect($referrer, ['cooperative_status' => 'rate_limited']);
        }

        $site_name = sanitize_text_field(wp_unslash($_POST['site_name'] ?? ''));
        $site_url = esc_url_raw(wp_unslash($_POST['site_url'] ?? ''));
        $contact_name = sanitize_text_field(wp_unslash($_POST['contact_name'] ?? ''));
        $contact_email = sanitize_email(wp_unslash($_POST['contact_email'] ?? ''));
        $service_endpoint = wp_unslash($_POST['service_endpoint'] ?? '');
        if (!$site_name || !$site_url || !$contact_name || !$contact_email || !is_email($contact_email) || empty($_POST['terms_accepted']) || ('' !== trim((string) $service_endpoint) && !Repository::valid_https_url($service_endpoint))) {
            $this->safe_redirect($referrer, ['cooperative_status' => 'invalid']);
        }

        $saved = Repository::save_partner([
            'pool_id' => $pool_id,
            'type' => 'application',
            'status' => 'applied',
            'site_name' => $site_name,
            'site_url' => $site_url,
            'contact_name' => $contact_name,
            'contact_email' => $contact_email,
            'requested_scope' => wp_unslash($_POST['requested_scope'] ?? []),
            'allowed_inventory' => [],
            'rate_limit_per_hour' => absint($pool['rate_limit_per_hour'] ?? 20),
            'terms_accepted' => !empty($_POST['terms_accepted']),
            'service_endpoint' => $service_endpoint,
            'notes' => wp_unslash($_POST['notes'] ?? ''),
        ]);

        $this->safe_redirect($referrer, ['cooperative_status' => $saved ? 'submitted' : 'invalid']);
    }

    public function shortcode($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'slug' => '',
            'show_inventory' => 'true',
            'show_placements' => 'true',
            'show_application_form' => 'true',
        ], $atts, 'mds_attention_cooperative');
        $show_inventory = $this->truthy($atts['show_inventory']);
        $show_placements = $this->truthy($atts['show_placements']);
        $show_application_form = $this->truthy($atts['show_application_form']);
        if (absint($atts['id'])) {
            $pool = Repository::pool(absint($atts['id']));
        } elseif ('' !== (string) $atts['slug']) {
            $pool = Repository::pool_by_slug((string) $atts['slug']);
        } else {
            $pool = $this->first_public_pool();
        }
        if (!$pool) {
            return current_user_can('manage_options') ? '<p class="mds-attention-cooperative-empty">' . esc_html__('No Attention Cooperative has been created yet.', 'mds-attention-cooperative') . '</p>' : '';
        }

        if ('active' !== (string) ($pool['status'] ?? '') || empty($pool['legal_review_completed'])) {
            return current_user_can('manage_options') ? '<p class="mds-attention-cooperative-empty">' . esc_html__('This cooperative is not public until the site owner completes the legal/privacy review gate.', 'mds-attention-cooperative') . '</p>' : '';
        }

        wp_enqueue_style('mds-attention-cooperative');
        $inventory = Repository::inventory_items(absint($pool['id']), true, 100);
        $placements = Repository::published_placements(absint($pool['id']), 50);
        ob_start();
        ?>
        <section class="mds-attention-cooperative">
            <?php $this->public_notice(); ?>
            <header class="mds-attention-cooperative__header">
                <span><?php echo esc_html($pool['disclosure_label'] ?: __('Sponsored placement', 'mds-attention-cooperative')); ?></span>
                <h2><?php echo esc_html($pool['title']); ?></h2>
                <p><?php echo esc_html($pool['terms_summary']); ?></p>
                <div class="mds-attention-cooperative__links">
                    <?php if (!empty($pool['site_url'])) : ?><a href="<?php echo esc_url($pool['site_url']); ?>"><?php echo esc_html($pool['site_name'] ?: __('Site', 'mds-attention-cooperative')); ?></a><?php endif; ?>
                    <?php if (!empty($pool['terms_url'])) : ?><a href="<?php echo esc_url($pool['terms_url']); ?>"><?php esc_html_e('Terms', 'mds-attention-cooperative'); ?></a><?php endif; ?>
                    <?php if (!empty($pool['privacy_url'])) : ?><a href="<?php echo esc_url($pool['privacy_url']); ?>"><?php esc_html_e('Privacy', 'mds-attention-cooperative'); ?></a><?php endif; ?>
                    <?php if (!empty($pool['takedown_url'])) : ?><a href="<?php echo esc_url($pool['takedown_url']); ?>"><?php esc_html_e('Takedown/contact', 'mds-attention-cooperative'); ?></a><?php endif; ?>
                </div>
            </header>

            <?php if ($show_inventory || $show_placements) : ?>
                <div class="mds-attention-cooperative__grid">
                    <?php if ($show_inventory) : ?>
                        <section>
                            <h3><?php esc_html_e('Exposed inventory', 'mds-attention-cooperative'); ?></h3>
                            <?php if (!$inventory) : ?>
                                <p><?php esc_html_e('No inventory is currently exposed to partners.', 'mds-attention-cooperative'); ?></p>
                            <?php else : ?>
                                <div class="mds-attention-cooperative__cards">
                                    <?php foreach ($inventory as $item) : ?>
                                        <article>
                                            <span><?php echo esc_html($this->inventory_type_label($item['type'])); ?></span>
                                            <h4><?php echo esc_html($item['label']); ?></h4>
                                            <p><?php echo esc_html($item['description']); ?></p>
                                            <dl>
                                                <div><dt><?php esc_html_e('Available', 'mds-attention-cooperative'); ?></dt><dd><?php echo esc_html((string) absint($item['available'])); ?></dd></div>
                                                <div><dt><?php esc_html_e('Starting at', 'mds-attention-cooperative'); ?></dt><dd><?php echo esc_html($this->format_money($item['price_from'], $item['currency']) ?: __('By review', 'mds-attention-cooperative')); ?></dd></div>
                                            </dl>
                                            <small><?php echo esc_html($item['disclosure_label']); ?></small>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($show_placements) : ?>
                        <section>
                            <h3><?php esc_html_e('Approved placements', 'mds-attention-cooperative'); ?></h3>
                            <?php if (!$placements) : ?>
                                <p><?php esc_html_e('No cooperative placements have been approved yet.', 'mds-attention-cooperative'); ?></p>
                            <?php else : ?>
                                <div class="mds-attention-cooperative__cards">
                                    <?php foreach ($placements as $placement) : ?>
                                        <article>
                                            <span><?php echo esc_html($placement['disclosure_label']); ?></span>
                                            <h4><?php echo esc_html($placement['sponsor_name']); ?></h4>
                                            <p><?php echo esc_html($placement['placement_copy']); ?></p>
                                            <?php if (!empty($placement['sponsor_url'])) : ?><a href="<?php echo esc_url($placement['sponsor_url']); ?>" rel="nofollow sponsored noopener" target="_blank"><?php esc_html_e('Open sponsor', 'mds-attention-cooperative'); ?></a><?php endif; ?>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($show_application_form && !empty($pool['public_applications_enabled'])) : ?>
                <section class="mds-attention-cooperative__apply">
                    <h3><?php esc_html_e('Apply to partner', 'mds-attention-cooperative'); ?></h3>
                    <p class="mds-attention-cooperative__form-note"><?php esc_html_e('Submit a partner application for local review. Inventory exposure and sponsor placements require approval before anything is published.', 'mds-attention-cooperative'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('mds_attention_cooperative_apply_' . absint($pool['id'])); ?>
                        <input type="hidden" name="action" value="mds_attention_cooperative_apply">
                        <input type="hidden" name="pool_id" value="<?php echo esc_attr((string) $pool['id']); ?>">
                        <input type="text" name="company" value="" tabindex="-1" autocomplete="off" class="mds-attention-cooperative__trap" aria-hidden="true">
                        <label><span><?php esc_html_e('Site name', 'mds-attention-cooperative'); ?></span><input type="text" name="site_name" required maxlength="120"></label>
                        <label><span><?php esc_html_e('Site URL', 'mds-attention-cooperative'); ?></span><input type="url" name="site_url" required></label>
                        <label><span><?php esc_html_e('Contact name', 'mds-attention-cooperative'); ?></span><input type="text" name="contact_name" required maxlength="120"></label>
                        <label><span><?php esc_html_e('Contact email', 'mds-attention-cooperative'); ?></span><input type="email" name="contact_email" required></label>
                        <label><span><?php esc_html_e('Requested inventory', 'mds-attention-cooperative'); ?></span><select name="requested_scope[]" multiple><?php foreach (Repository::inventory_types() as $type) : ?><option value="<?php echo esc_attr($type); ?>"><?php echo esc_html($this->inventory_type_label($type)); ?></option><?php endforeach; ?></select></label>
                        <label><span><?php esc_html_e('Partner service endpoint', 'mds-attention-cooperative'); ?></span><input type="url" name="service_endpoint"></label>
                        <label class="mds-attention-cooperative__wide"><span><?php esc_html_e('Notes', 'mds-attention-cooperative'); ?></span><textarea name="notes" rows="4"></textarea></label>
                        <label class="mds-attention-cooperative__check"><input type="checkbox" name="terms_accepted" value="1" required> <span><?php esc_html_e('I accept the listed partner terms and understand placements require local approval before publication.', 'mds-attention-cooperative'); ?></span></label>
                        <button type="submit"><?php esc_html_e('Submit application', 'mds-attention-cooperative'); ?></button>
                    </form>
                </section>
            <?php endif; ?>
        </section>
        <?php
        return trim((string) ob_get_clean());
    }

    private function truthy($value) {
        if (is_bool($value)) {
            return $value;
        }

        return !in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    public function register_rest_routes() {
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/inventory', [[
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'rest_inventory'],
            'permission_callback' => function ($request) {
                return $this->permission($request, 'cooperative.inventory.read', 'api_key_read');
            },
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/partners/apply', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_apply_partner'],
            'permission_callback' => function ($request) {
                return $this->permission($request, 'cooperative.partner.apply', 'public_write_nonce');
            },
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/partners/(?P<id>\d+)/approve', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => function ($request) {
                return $this->rest_partner_status($request, 'approved');
            },
            'permission_callback' => function ($request) {
                return $this->permission($request, 'cooperative.partner.approve', 'wp_capability');
            },
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/placements', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_create_placement'],
            'permission_callback' => function ($request) {
                return $this->permission($request, 'cooperative.placement.write', 'service_signature');
            },
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/placements/(?P<id>\d+)/approve', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => function ($request) {
                return $this->rest_placement_status($request, 'approved');
            },
            'permission_callback' => function ($request) {
                return $this->permission($request, 'cooperative.placement.approve', 'wp_capability');
            },
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/placements/(?P<id>\d+)/revoke', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => function ($request) {
                return $this->rest_placement_status($request, 'revoked');
            },
            'permission_callback' => function ($request) {
                return $this->permission($request, 'cooperative.placement.revoke', 'wp_capability');
            },
        ]]);
    }

    public function rest_inventory($request) {
        $pool_id = absint($request['pool_id'] ?? 0);

        return rest_ensure_response(Repository::local_inventory_manifest($pool_id));
    }

    public function rest_apply_partner($request) {
        $params = $this->request_params($request);
        $pool_id = absint($params['pool_id'] ?? 0);
        $pool = $pool_id ? Repository::pool($pool_id) : (Repository::pools(1)[0] ?? null);
        if (!$pool || 'active' !== (string) ($pool['status'] ?? '') || empty($pool['public_applications_enabled']) || empty($pool['legal_review_completed'])) {
            return new \WP_Error('mds_attention_cooperative_closed', __('Partner applications are not currently open.', 'mds-attention-cooperative'), ['status' => 403]);
        }
        if ($this->rate_limited('apply_' . absint($pool['id']), absint($pool['rate_limit_per_hour'] ?? 20))) {
            return new \WP_Error('mds_attention_cooperative_rate_limited', __('Please wait before submitting another partner application.', 'mds-attention-cooperative'), ['status' => 429]);
        }

        $site_name = sanitize_text_field((string) ($params['site_name'] ?? ''));
        $site_url = esc_url_raw((string) ($params['site_url'] ?? ''));
        $contact_name = sanitize_text_field((string) ($params['contact_name'] ?? ''));
        $contact_email = sanitize_email((string) ($params['contact_email'] ?? ''));
        $service_endpoint = (string) ($params['service_endpoint'] ?? '');
        if (!$site_name || !$site_url || !$contact_name || !$contact_email || !is_email($contact_email) || empty($params['terms_accepted']) || ('' !== trim($service_endpoint) && !Repository::valid_https_url($service_endpoint))) {
            return new \WP_Error('mds_attention_cooperative_invalid_application', __('Add site, contact, and terms acceptance details before submitting.', 'mds-attention-cooperative'), ['status' => 400]);
        }

        $partner_id = Repository::save_partner([
            'pool_id' => absint($pool['id']),
            'type' => 'application',
            'status' => 'applied',
            'site_name' => $site_name,
            'site_url' => $site_url,
            'contact_name' => $contact_name,
            'contact_email' => $contact_email,
            'requested_scope' => $params['requested_scope'] ?? [],
            'allowed_inventory' => [],
            'rate_limit_per_hour' => absint($pool['rate_limit_per_hour'] ?? 20),
            'terms_accepted' => !empty($params['terms_accepted']),
            'service_endpoint' => $service_endpoint,
            'notes' => $params['notes'] ?? '',
        ]);

        if (!$partner_id) {
            return new \WP_Error('mds_attention_cooperative_apply_failed', __('Partner application could not be created.', 'mds-attention-cooperative'), ['status' => 400]);
        }

        return new \WP_REST_Response(['partner' => Repository::partner($partner_id)], 201);
    }

    public function rest_partner_status($request, $status) {
        $partner_id = absint($request['id'] ?? 0);
        if (!Repository::update_partner_status($partner_id, $status)) {
            return new \WP_Error('mds_attention_cooperative_partner_not_found', __('Partner could not be updated.', 'mds-attention-cooperative'), ['status' => 404]);
        }

        return rest_ensure_response(['partner' => Repository::partner($partner_id)]);
    }

    public function rest_create_placement($request) {
        $params = $this->request_params($request);
        $identity = class_exists('\\MillionDollarScript\\Core\\ApiAccess')
            ? \MillionDollarScript\Core\ApiAccess::service_identity($request)
            : null;
        $service_authenticated = is_array($identity)
            && 'service_signature' === (string) ($identity['authentication'] ?? '');
        $credential = $service_authenticated ? ServiceSignature::credential_for_request($request) : null;
        if ($service_authenticated && !$credential) {
            return \MillionDollarScript\Core\ApiAccess::service_signature_error('invalid');
        }

        $pool_id = $credential
            ? absint($credential['pool_id'])
            : absint($params['pool_id'] ?? 0);
        $partner_id = $credential
            ? absint($credential['partner_id'])
            : absint($params['partner_id'] ?? 0);
        if (
            $credential
            && (
                (!empty($params['pool_id']) && $pool_id !== absint($params['pool_id']))
                || (!empty($params['partner_id']) && $partner_id !== absint($params['partner_id']))
            )
        ) {
            return new \WP_Error(
                'mds_attention_cooperative_service_identity_mismatch',
                __('The authenticated service cannot create a placement for that partner or cooperative.', 'mds-attention-cooperative'),
                ['status' => 403]
            );
        }

        $sponsor_name = sanitize_text_field((string) ($params['sponsor_name'] ?? ''));
        $sponsor_email = sanitize_email((string) ($params['sponsor_email'] ?? ''));
        if (!$pool_id || '' === $sponsor_name) {
            return new \WP_Error('mds_attention_cooperative_invalid_placement', __('Add a pool and sponsor name before creating a placement request.', 'mds-attention-cooperative'), ['status' => 400]);
        }
        if ('' !== $sponsor_email && !is_email($sponsor_email)) {
            return new \WP_Error('mds_attention_cooperative_invalid_sponsor_email', __('Use a valid sponsor email address.', 'mds-attention-cooperative'), ['status' => 400]);
        }

        $data = [
            'pool_id' => $pool_id,
            'partner_id' => $partner_id,
            'inventory_id' => absint($params['inventory_id'] ?? 0),
            'status' => 'pending_destination',
            'sponsor_name' => $sponsor_name,
            'sponsor_url' => esc_url_raw((string) ($params['sponsor_url'] ?? '')),
            'sponsor_email' => $sponsor_email,
            'placement_copy' => $params['placement_copy'] ?? '',
            'disclosure_label' => $params['disclosure_label'] ?? '',
            'source_approved' => !empty($params['source_approved']),
            'destination_approved' => false,
            'terms_accepted' => !empty($params['terms_accepted']),
            'notes' => $params['notes'] ?? '',
        ];
        $validation = Repository::validate_placement_request($data);
        if (is_wp_error($validation)) {
            $validation->add_data(['status' => 400]);
            return $validation;
        }

        $idempotency = null;
        if ($credential) {
            $idempotency_key = trim((string) $request->get_header('X-Idempotency-Key'));
            $fingerprint = hash('sha256', implode("\n", [
                (string) $partner_id,
                strtoupper((string) $request->get_method()),
                '/' . ltrim((string) $request->get_route(), '/'),
                hash('sha256', (string) $request->get_body()),
            ]));
            $idempotency = ServiceCredentialRepository::begin_idempotency(
                $partner_id,
                $pool_id,
                $idempotency_key,
                $fingerprint
            );
            if (is_wp_error($idempotency)) {
                return $idempotency;
            }
            if ('replay' === (string) ($idempotency['state'] ?? '')) {
                $placement = Repository::placement(absint($idempotency['placement_id'] ?? 0));
                if (!$placement) {
                    return new \WP_Error(
                        'mds_attention_cooperative_idempotency_unavailable',
                        __('The earlier placement response is no longer available.', 'mds-attention-cooperative'),
                        ['status' => 409]
                    );
                }
                $response = new \WP_REST_Response(['placement' => $placement], 200);
                $response->header('X-MDS-Idempotent-Replay', 'true');
                return $response;
            }
        }

        $placement_id = Repository::create_placement($data);

        if (!$placement_id) {
            if ($idempotency) {
                ServiceCredentialRepository::fail_idempotency(absint($idempotency['id'] ?? 0));
            }
            return new \WP_Error('mds_attention_cooperative_placement_failed', __('Placement request could not be created.', 'mds-attention-cooperative'), ['status' => 400]);
        }
        if ($idempotency) {
            ServiceCredentialRepository::complete_idempotency(absint($idempotency['id'] ?? 0), $placement_id);
        }

        return new \WP_REST_Response(['placement' => Repository::placement($placement_id)], 201);
    }

    public function rest_placement_status($request, $status) {
        $placement_id = absint($request['id'] ?? 0);
        if (!Repository::update_placement_status($placement_id, $status)) {
            return new \WP_Error('mds_attention_cooperative_placement_not_found', __('Placement could not be updated.', 'mds-attention-cooperative'), ['status' => 404]);
        }

        return rest_ensure_response(['placement' => Repository::placement($placement_id)]);
    }

    public function api_endpoint_manifest($manifest) {
        $manifest = is_array($manifest) ? $manifest : [];
        $manifest[] = ['id' => 'cooperative-inventory-read', 'route' => '/million-dollar-script/v1/cooperative/inventory', 'methods' => ['GET'], 'scope' => 'cooperative.inventory.read', 'minimum_security_level' => 'api_key_read', 'description' => __('Read approved cooperative inventory manifests.', 'mds-attention-cooperative')];
        $manifest[] = ['id' => 'cooperative-partner-apply', 'route' => '/million-dollar-script/v1/cooperative/partners/apply', 'methods' => ['POST'], 'scope' => 'cooperative.partner.apply', 'minimum_security_level' => 'public_write_nonce', 'description' => __('Submit a partner application.', 'mds-attention-cooperative')];
        $manifest[] = ['id' => 'cooperative-partner-approve', 'route' => '/million-dollar-script/v1/cooperative/partners/(?P<id>\d+)/approve', 'methods' => ['POST'], 'scope' => 'cooperative.partner.approve', 'minimum_security_level' => 'wp_capability', 'description' => __('Approve a cooperative partner.', 'mds-attention-cooperative')];
        $manifest[] = ['id' => 'cooperative-placement-create', 'route' => '/million-dollar-script/v1/cooperative/placements', 'methods' => ['POST'], 'scope' => 'cooperative.placement.write', 'minimum_security_level' => 'service_signature', 'description' => __('Create an incoming cooperative placement request.', 'mds-attention-cooperative')];
        $manifest[] = ['id' => 'cooperative-placement-approve', 'route' => '/million-dollar-script/v1/cooperative/placements/(?P<id>\d+)/approve', 'methods' => ['POST'], 'scope' => 'cooperative.placement.approve', 'minimum_security_level' => 'wp_capability', 'description' => __('Approve a cooperative placement.', 'mds-attention-cooperative')];
        $manifest[] = ['id' => 'cooperative-placement-revoke', 'route' => '/million-dollar-script/v1/cooperative/placements/(?P<id>\d+)/revoke', 'methods' => ['POST'], 'scope' => 'cooperative.placement.revoke', 'minimum_security_level' => 'wp_capability', 'description' => __('Revoke a cooperative placement.', 'mds-attention-cooperative')];

        return $manifest;
    }

    public function openapi_document($document) {
        if (!is_array($document) || !isset($document['paths']['/cooperative/placements']['post']['parameters'])) {
            return $document;
        }

        foreach ($document['paths']['/cooperative/placements']['post']['parameters'] as &$parameter) {
            if (is_array($parameter) && 'X-Idempotency-Key' === (string) ($parameter['name'] ?? '')) {
                $parameter['required'] = true;
                $parameter['description'] = __('Required placement idempotency key. It is included in the v1 service-signature canonical string.', 'mds-attention-cooperative');
            }
        }
        unset($parameter);

        return $document;
    }

    public function privacy_exporters($exporters) {
        $exporters['mds-attention-cooperative'] = [
            'exporter_friendly_name' => __('Attention Cooperative', 'mds-attention-cooperative'),
            'callback' => [$this, 'privacy_exporter'],
        ];
        return $exporters;
    }

    public function privacy_exporter($email_address, $page = 1) {
        return Privacy::exporter($email_address, $page);
    }

    public function privacy_erasers($erasers) {
        $erasers['mds-attention-cooperative'] = [
            'eraser_friendly_name' => __('Attention Cooperative', 'mds-attention-cooperative'),
            'callback' => [$this, 'privacy_eraser'],
        ];
        return $erasers;
    }

    public function privacy_eraser($email_address, $page = 1) {
        return Privacy::eraser($email_address, $page);
    }

    private function render_inventory_section(array $pool, array $inventory, $currency_locked, array $pagination) {
        $blank = [
            'id' => 0,
            'type' => 'custom',
            'label' => '',
            'description' => '',
            'status' => 'draft',
            'scope' => 'local',
            'capacity' => 1,
            'available' => 1,
            'currency' => Repository::default_currency(),
            'price_from' => 0,
            'approval_required' => true,
            'disclosure_label' => $pool['disclosure_label'] ?? __('Sponsored placement', 'mds-attention-cooperative'),
            'source_ref' => '',
        ];
        $edit_id = absint($_GET['edit_inventory'] ?? 0);
        $edit = $edit_id ? Repository::inventory($edit_id) : null;
        if ($edit && absint($edit['pool_id'] ?? 0) !== absint($pool['id'])) {
            $edit = null;
        }
        $form = $edit ?: $blank;
        ?>
        <section class="mds3-card mds-attention-cooperative-panel">
            <div class="mds-attention-cooperative-heading">
                <div>
                    <h2><?php esc_html_e('Inventory exposure', 'mds-attention-cooperative'); ?></h2>
                    <p><?php esc_html_e('Choose the inventory this site is willing to expose. Exposed inventory is still review-only; partner requests do not publish automatically.', 'mds-attention-cooperative'); ?></p>
                </div>
            </div>
            <details class="mds-attention-cooperative-editor" <?php echo $edit ? 'open' : ''; ?>>
                <summary><?php echo $edit ? esc_html__('Edit inventory', 'mds-attention-cooperative') : esc_html__('Add inventory', 'mds-attention-cooperative'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-attention-cooperative-form">
                <?php wp_nonce_field('mds_attention_cooperative_save_inventory_' . absint($pool['id'])); ?>
                <input type="hidden" name="action" value="mds_attention_cooperative_save_inventory">
                <input type="hidden" name="pool_id" value="<?php echo esc_attr((string) $pool['id']); ?>">
                <input type="hidden" name="inventory_id" value="<?php echo esc_attr((string) absint($form['id'])); ?>">

                <?php $this->select('type', __('Type', 'mds-attention-cooperative'), $form['type'], $this->inventory_type_options()); ?>
                <?php $this->field('label', __('Label', 'mds-attention-cooperative'), $form['label'], 'text', true); ?>
                <?php $this->select('status', __('Status', 'mds-attention-cooperative'), $form['status'], ['draft' => __('Draft', 'mds-attention-cooperative'), 'exposed' => __('Exposed', 'mds-attention-cooperative'), 'paused' => __('Paused', 'mds-attention-cooperative'), 'retired' => __('Retired', 'mds-attention-cooperative')]); ?>
                <?php $this->select('scope', __('Scope', 'mds-attention-cooperative'), $form['scope'], ['local' => __('Local only', 'mds-attention-cooperative'), 'federated' => __('Approved partners', 'mds-attention-cooperative')]); ?>
                <?php $this->field('capacity', __('Capacity', 'mds-attention-cooperative'), $form['capacity'], 'number'); ?>
                <?php $this->field('available', __('Available', 'mds-attention-cooperative'), $form['available'], 'number'); ?>
                <?php $this->field('currency', __('Currency', 'mds-attention-cooperative'), $form['currency'], 'text', true, $currency_locked); ?>
                <?php if ($currency_locked) : ?><p class="mds-attention-cooperative-note"><?php esc_html_e('The active payment provider controls currency for this site.', 'mds-attention-cooperative'); ?></p><?php endif; ?>
                <?php $this->field('price_from', __('Starting price', 'mds-attention-cooperative'), $form['price_from'], 'number'); ?>
                <?php $this->field('source_ref', __('Source reference', 'mds-attention-cooperative'), $form['source_ref'], 'text'); ?>
                <?php $this->field('description', __('Description', 'mds-attention-cooperative'), $form['description'], 'textarea'); ?>
                <?php $this->field('disclosure_label', __('Disclosure label', 'mds-attention-cooperative'), $form['disclosure_label'], 'text'); ?>
                <?php $this->checkbox('approval_required', __('Require approval before any placement is published', 'mds-attention-cooperative'), !empty($form['approval_required'])); ?>

                <p class="submit"><button class="button button-primary" type="submit"><?php echo $edit ? esc_html__('Update inventory', 'mds-attention-cooperative') : esc_html__('Add inventory', 'mds-attention-cooperative'); ?></button></p>
            </form>
            </details>

            <?php if ($inventory) : ?>
                <div class="mds-attention-cooperative-table">
                    <?php foreach ($inventory as $item) : ?>
                        <article>
                            <div>
                                <strong><?php echo esc_html($item['label']); ?></strong>
                                <span><?php echo esc_html($this->inventory_type_label($item['type'])); ?> · <?php echo esc_html($item['status']); ?> · <?php echo esc_html(absint($item['available']) . '/' . absint($item['capacity'])); ?> · <?php echo esc_html($this->format_money($item['price_from'], $item['currency']) ?: __('reviewed pricing', 'mds-attention-cooperative')); ?></span>
                                <?php if (!empty($item['description'])) : ?><p><?php echo esc_html(wp_trim_words($item['description'], 24)); ?></p><?php endif; ?>
                            </div>
                            <div class="mds-attention-cooperative-actions">
                                <a class="button" href="<?php echo esc_url($this->admin_url(['pool_id' => $pool['id'], 'tab' => 'inventory', 'edit_inventory' => $item['id']])); ?>"><?php esc_html_e('Edit', 'mds-attention-cooperative'); ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php $this->render_pagination($pagination, 'inventory_page', $pool['id']); ?>
        </section>
        <?php
    }

    private function render_partners_section(array $pool, array $partners, array $pagination) {
        $blank = [
            'id' => 0,
            'type' => 'invite',
            'status' => 'invited',
            'site_name' => '',
            'site_url' => '',
            'contact_name' => '',
            'contact_email' => '',
            'requested_scope' => [],
            'allowed_inventory' => [],
            'rate_limit_per_hour' => 10,
            'service_endpoint' => '',
            'api_key_label' => '',
            'notes' => '',
            'terms_accepted' => false,
        ];
        $edit_id = absint($_GET['edit_partner'] ?? 0);
        $edit = $edit_id ? Repository::partner($edit_id) : null;
        if ($edit && absint($edit['pool_id'] ?? 0) !== absint($pool['id'])) {
            $edit = null;
        }
        $form = $edit ?: $blank;
        ?>
        <section class="mds3-card mds-attention-cooperative-panel">
            <div class="mds-attention-cooperative-heading">
                <div>
                    <h2><?php esc_html_e('Partners', 'mds-attention-cooperative'); ?></h2>
                    <p><?php esc_html_e('Create invites, review applications, choose allowed inventory, and revoke access when a relationship ends.', 'mds-attention-cooperative'); ?></p>
                </div>
            </div>
            <details class="mds-attention-cooperative-editor" <?php echo $edit ? 'open' : ''; ?>>
                <summary><?php echo $edit ? esc_html__('Edit partner', 'mds-attention-cooperative') : esc_html__('Add partner', 'mds-attention-cooperative'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-attention-cooperative-form">
                <?php wp_nonce_field('mds_attention_cooperative_save_partner_' . absint($pool['id'])); ?>
                <input type="hidden" name="action" value="mds_attention_cooperative_save_partner">
                <input type="hidden" name="pool_id" value="<?php echo esc_attr((string) $pool['id']); ?>">
                <input type="hidden" name="partner_id" value="<?php echo esc_attr((string) absint($form['id'])); ?>">

                <?php $this->select('type', __('Type', 'mds-attention-cooperative'), $form['type'], ['invite' => __('Invite', 'mds-attention-cooperative'), 'application' => __('Application', 'mds-attention-cooperative')]); ?>
                <?php $this->select('status', __('Status', 'mds-attention-cooperative'), $form['status'], $this->partner_status_options()); ?>
                <?php $this->field('site_name', __('Partner site name', 'mds-attention-cooperative'), $form['site_name'], 'text', true); ?>
                <?php $this->field('site_url', __('Partner site URL', 'mds-attention-cooperative'), $form['site_url'], 'url'); ?>
                <?php $this->field('contact_name', __('Contact name', 'mds-attention-cooperative'), $form['contact_name'], 'text'); ?>
                <?php $this->field('contact_email', __('Contact email', 'mds-attention-cooperative'), $form['contact_email'], 'email'); ?>
                <?php $this->multi_check('requested_scope', __('Requested inventory', 'mds-attention-cooperative'), Repository::inventory_types(), (array) $form['requested_scope']); ?>
                <?php $this->multi_check('allowed_inventory', __('Allowed inventory', 'mds-attention-cooperative'), Repository::inventory_types(), (array) $form['allowed_inventory']); ?>
                <?php $this->field('rate_limit_per_hour', __('Rate limit per hour', 'mds-attention-cooperative'), $form['rate_limit_per_hour'], 'number'); ?>
                <?php $this->field('service_endpoint', __('Partner service endpoint', 'mds-attention-cooperative'), $form['service_endpoint'], 'url'); ?>
                <?php $this->field('api_key_label', __('Legacy integration label (not a credential)', 'mds-attention-cooperative'), $form['api_key_label'], 'text'); ?>
                <?php $this->field('notes', __('Notes', 'mds-attention-cooperative'), $form['notes'], 'textarea'); ?>
                <?php $this->checkbox('terms_accepted', __('Partner has accepted current terms snapshot', 'mds-attention-cooperative'), !empty($form['terms_accepted'])); ?>

                <p class="submit"><button class="button button-primary" type="submit"><?php echo $edit ? esc_html__('Update partner', 'mds-attention-cooperative') : esc_html__('Add partner', 'mds-attention-cooperative'); ?></button></p>
            </form>
            </details>

            <?php if (!$partners) : ?>
                <p class="mds-attention-cooperative-note"><?php esc_html_e('No partners yet.', 'mds-attention-cooperative'); ?></p>
            <?php else : ?>
                <div class="mds-attention-cooperative-table">
                    <?php foreach ($partners as $partner) : ?>
                        <article>
                            <div>
                                <strong><?php echo esc_html($partner['site_name']); ?></strong>
                                <span><?php echo esc_html($partner['status']); ?> · <?php echo esc_html($partner['site_url']); ?> · <?php echo esc_html(implode(', ', array_map([$this, 'inventory_type_label'], $partner['allowed_inventory'])) ?: __('no allowed inventory', 'mds-attention-cooperative')); ?></span>
                                <?php if (!empty($partner['notes'])) : ?><p><?php echo esc_html(wp_trim_words($partner['notes'], 24)); ?></p><?php endif; ?>
                            </div>
                            <div class="mds-attention-cooperative-actions">
                                <a class="button" href="<?php echo esc_url($this->admin_url(['pool_id' => $pool['id'], 'tab' => 'partners', 'edit_partner' => $partner['id']])); ?>"><?php esc_html_e('Edit', 'mds-attention-cooperative'); ?></a>
                                <?php $this->partner_button($partner['id'], 'approved', __('Approve', 'mds-attention-cooperative')); ?>
                                <?php $this->partner_button($partner['id'], 'changes_requested', __('Request changes', 'mds-attention-cooperative')); ?>
                                <?php $this->partner_button($partner['id'], 'rejected', __('Reject', 'mds-attention-cooperative')); ?>
                                <?php $this->partner_button($partner['id'], 'revoked', __('Revoke', 'mds-attention-cooperative')); ?>
                            </div>
                            <?php ServiceCredentialAdmin::render_for_partner($partner); ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php $this->render_pagination($pagination, 'partners_page', $pool['id']); ?>
        </section>
        <?php
    }

    private function render_placements_section(array $pool, array $placements, array $partners, array $inventory, array $pagination) {
        ?>
        <section class="mds3-card mds-attention-cooperative-panel">
            <div class="mds-attention-cooperative-heading">
                <div>
                    <h2><?php esc_html_e('Placement requests', 'mds-attention-cooperative'); ?></h2>
                    <p><?php esc_html_e('Incoming sponsor placements stay pending until the destination admin approves them. Revoked and takedown requests stop publication.', 'mds-attention-cooperative'); ?></p>
                </div>
            </div>
            <details class="mds-attention-cooperative-editor">
                <summary><?php esc_html_e('Create placement request', 'mds-attention-cooperative'); ?></summary>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-attention-cooperative-form">
                <?php wp_nonce_field('mds_attention_cooperative_create_placement_' . absint($pool['id'])); ?>
                <input type="hidden" name="action" value="mds_attention_cooperative_create_placement">
                <input type="hidden" name="pool_id" value="<?php echo esc_attr((string) $pool['id']); ?>">

                <?php $this->entity_field('partner_id', __('Approved partner', 'mds-attention-cooperative'), $partners, 'site_name'); ?>
                <?php $this->entity_field('inventory_id', __('Available partner inventory', 'mds-attention-cooperative'), $inventory, 'label'); ?>
                <?php $this->field('sponsor_name', __('Sponsor name', 'mds-attention-cooperative'), '', 'text', true); ?>
                <?php $this->field('sponsor_url', __('Sponsor URL', 'mds-attention-cooperative'), '', 'url'); ?>
                <?php $this->field('sponsor_email', __('Sponsor email', 'mds-attention-cooperative'), '', 'email'); ?>
                <?php $this->field('disclosure_label', __('Disclosure label', 'mds-attention-cooperative'), $pool['disclosure_label'], 'text'); ?>
                <?php $this->field('placement_copy', __('Placement copy', 'mds-attention-cooperative'), '', 'textarea'); ?>
                <?php $this->field('notes', __('Review notes', 'mds-attention-cooperative'), '', 'textarea'); ?>
                <?php $this->checkbox('source_approved', __('Source site approval received', 'mds-attention-cooperative'), false); ?>
                <?php $this->checkbox('terms_accepted', __('Placement terms accepted', 'mds-attention-cooperative'), false); ?>

                <p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e('Create placement request', 'mds-attention-cooperative'); ?></button></p>
            </form>
            </details>

            <?php if (!$placements) : ?>
                <p class="mds-attention-cooperative-note"><?php esc_html_e('No placement requests yet.', 'mds-attention-cooperative'); ?></p>
            <?php else : ?>
                <div class="mds-attention-cooperative-table">
                    <?php foreach ($placements as $placement) : ?>
                        <article>
                            <div>
                                <strong><?php echo esc_html($placement['sponsor_name']); ?></strong>
                                <span><?php echo esc_html($placement['status']); ?> · <?php echo esc_html($placement['disclosure_label']); ?><?php echo !empty($placement['source_approved']) ? ' · ' . esc_html__('source approved', 'mds-attention-cooperative') : ''; ?></span>
                                <?php if (!empty($placement['placement_copy'])) : ?><p><?php echo esc_html(wp_trim_words($placement['placement_copy'], 24)); ?></p><?php endif; ?>
                            </div>
                            <div class="mds-attention-cooperative-actions">
                                <?php if (empty($placement['source_approved'])) : ?><?php $this->placement_button($placement['id'], 'source_approved', __('Record source approval', 'mds-attention-cooperative')); ?><?php endif; ?>
                                <?php $this->placement_button($placement['id'], 'approved', __('Approve', 'mds-attention-cooperative')); ?>
                                <?php $this->placement_button($placement['id'], 'rejected', __('Reject', 'mds-attention-cooperative')); ?>
                                <?php $this->placement_button($placement['id'], 'revoked', __('Revoke', 'mds-attention-cooperative')); ?>
                                <?php $this->placement_button($placement['id'], 'takedown', __('Takedown', 'mds-attention-cooperative')); ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php $this->render_pagination($pagination, 'placements_page', $pool['id']); ?>
        </section>
        <?php
    }

    private function render_providers_section(array $providers) {
        $visible = array_slice($providers, 0, 12);
        $hidden = max(0, count($providers) - count($visible));
        ?>
        <section class="mds3-card mds-attention-cooperative-panel">
            <div class="mds-attention-cooperative-heading">
                <div>
                    <h2><?php esc_html_e('Detected provider manifests', 'mds-attention-cooperative'); ?></h2>
                    <p><?php esc_html_e('These are read-only signals from active local inventory extensions. Add curated inventory above before exposing anything to partners.', 'mds-attention-cooperative'); ?></p>
                </div>
            </div>
            <?php if (!$providers) : ?>
                <p class="mds-attention-cooperative-note"><?php esc_html_e('No compatible inventory providers were detected yet.', 'mds-attention-cooperative'); ?></p>
            <?php else : ?>
                <div class="mds-attention-cooperative-provider-grid">
                    <?php foreach ($visible as $provider) : ?>
                        <article>
                            <span><?php echo esc_html($this->inventory_type_label($provider['provider'] ?? 'custom')); ?></span>
                            <strong><?php echo esc_html($provider['label'] ?? ''); ?></strong>
                            <p><?php echo esc_html(($provider['status'] ?? '') . (!empty($provider['currency']) ? ' · ' . $provider['currency'] : '')); ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($hidden) : ?>
                    <p class="mds-attention-cooperative-note"><?php echo esc_html(sprintf(_n('%d additional active provider is available through the REST manifest.', '%d additional active providers are available through the REST manifest.', $hidden, 'mds-attention-cooperative'), $hidden)); ?></p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }

    private function render_audit_section(array $audits, array $pagination, $pool_id) {
        ?>
        <section class="mds3-card mds-attention-cooperative-panel">
            <div class="mds-attention-cooperative-heading">
                <div>
                    <h2><?php esc_html_e('Audit log', 'mds-attention-cooperative'); ?></h2>
                    <p><?php esc_html_e('Records partner, inventory, approval, revocation, placement, and takedown events for local review.', 'mds-attention-cooperative'); ?></p>
                </div>
            </div>
            <?php if (!$audits) : ?>
                <p class="mds-attention-cooperative-note"><?php esc_html_e('No audit records yet.', 'mds-attention-cooperative'); ?></p>
            <?php else : ?>
                <div class="mds-attention-cooperative-audit">
                    <?php foreach ($audits as $audit) : ?>
                        <article>
                            <strong><?php echo esc_html($audit['event']); ?></strong>
                            <span><?php echo esc_html($audit['object_type'] . ' #' . absint($audit['object_id']) . ' · ' . $audit['created_at']); ?></span>
                            <?php if (!empty($audit['message'])) : ?><p><?php echo esc_html($audit['message']); ?></p><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php $this->render_pagination($pagination, 'audits_page', $pool_id); ?>
        </section>
        <?php
    }

    private function empty_page() {
        return ['items' => [], 'page' => 1, 'per_page' => 20, 'total' => 0, 'pages' => 1];
    }

    private function render_pagination(array $pagination, $query_var, $pool_id = 0) {
        $page = max(1, absint($pagination['page'] ?? 1));
        $pages = max(1, absint($pagination['pages'] ?? 1));
        if ($pages < 2) {
            return;
        }

        $base = $pool_id ? ['pool_id' => absint($pool_id)] : [];
        ?>
        <nav class="mds-attention-cooperative-pagination" aria-label="<?php esc_attr_e('List pagination', 'mds-attention-cooperative'); ?>">
            <?php if ($page > 1) : ?>
                <a class="button" href="<?php echo esc_url($this->admin_url(array_merge($base, [$query_var => $page - 1]))); ?>"><?php esc_html_e('Previous', 'mds-attention-cooperative'); ?></a>
            <?php else : ?>
                <span></span>
            <?php endif; ?>
            <span><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'mds-attention-cooperative'), $page, $pages)); ?></span>
            <?php if ($page < $pages) : ?>
                <a class="button" href="<?php echo esc_url($this->admin_url(array_merge($base, [$query_var => $page + 1]))); ?>"><?php esc_html_e('Next', 'mds-attention-cooperative'); ?></a>
            <?php else : ?>
                <span></span>
            <?php endif; ?>
        </nav>
        <?php
    }

    private function field($name, $label, $value, $type = 'text', $required = false, $disabled = false, $help = '') {
        $wide = in_array($type, ['textarea'], true) ? ' mds-attention-cooperative-field-wide' : '';
        $help = $help ?: $this->field_help($name);
        $help_id = 'mds-attention-cooperative-help-' . sanitize_html_class((string) $name);
        ?>
        <label class="mds-attention-cooperative-field<?php echo esc_attr($wide); ?>">
            <span><?php echo esc_html($label); ?></span>
            <?php if ('textarea' === $type) : ?>
                <textarea name="<?php echo esc_attr($name); ?>" rows="4" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?> <?php echo $required ? 'required' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?>><?php echo esc_textarea((string) $value); ?></textarea>
            <?php else : ?>
                <input type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $value); ?>" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?> <?php echo $required ? 'required' : ''; ?> <?php echo $disabled ? 'disabled' : ''; ?> <?php echo 'number' === $type ? 'min="0" step="0.01"' : ''; ?>>
            <?php endif; ?>
            <?php if ($help) : ?>
                <small id="<?php echo esc_attr($help_id); ?>" class="mds-attention-cooperative-field-help"><?php echo esc_html($help); ?></small>
            <?php endif; ?>
        </label>
        <?php
    }

    private function select($name, $label, $value, array $options, $help = '') {
        $help = $help ?: $this->field_help($name);
        $help_id = 'mds-attention-cooperative-help-' . sanitize_html_class((string) $name);
        ?>
        <label class="mds-attention-cooperative-field">
            <span><?php echo esc_html($label); ?></span>
            <select name="<?php echo esc_attr($name); ?>" <?php echo $help ? 'aria-describedby="' . esc_attr($help_id) . '"' : ''; ?>>
                <?php foreach ($options as $option_value => $option_label) : ?>
                    <option value="<?php echo esc_attr((string) $option_value); ?>" <?php selected((string) $value, (string) $option_value); ?>><?php echo esc_html($option_label); ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($help) : ?>
                <small id="<?php echo esc_attr($help_id); ?>" class="mds-attention-cooperative-field-help"><?php echo esc_html($help); ?></small>
            <?php endif; ?>
        </label>
        <?php
    }

    private function checkbox($name, $label, $checked) {
        ?>
        <label class="mds-attention-cooperative-check">
            <input type="checkbox" name="<?php echo esc_attr($name); ?>" value="1" <?php checked(!empty($checked)); ?>>
            <span><?php echo esc_html($label); ?></span>
        </label>
        <?php
    }

    private function multi_check($name, $label, array $values, array $selected, $help = '') {
        $help = $help ?: $this->field_help($name);
        $help_id = 'mds-attention-cooperative-help-' . sanitize_html_class((string) $name);
        ?>
        <fieldset class="mds-attention-cooperative-field mds-attention-cooperative-field-wide">
            <legend><?php echo esc_html($label); ?></legend>
            <?php if ($help) : ?>
                <small id="<?php echo esc_attr($help_id); ?>" class="mds-attention-cooperative-field-help"><?php echo esc_html($help); ?></small>
            <?php endif; ?>
            <div class="mds-attention-cooperative-check-grid">
                <?php foreach ($values as $value) : ?>
                    <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[]" value="<?php echo esc_attr($value); ?>" <?php checked(in_array($value, $selected, true)); ?>> <span><?php echo esc_html($this->inventory_type_label($value)); ?></span></label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    private function entity_field($name, $label, array $items, $label_key) {
        $list_id = 'mds-attention-cooperative-' . sanitize_html_class((string) $name) . '-options';
        $help = $this->field_help($name);
        $help_id = $list_id . '-help';
        ?>
        <label class="mds-attention-cooperative-field">
            <span><?php echo esc_html($label); ?></span>
            <input type="number" name="<?php echo esc_attr($name); ?>" min="1" step="1" list="<?php echo esc_attr($list_id); ?>" required aria-describedby="<?php echo esc_attr($help_id); ?>">
            <datalist id="<?php echo esc_attr($list_id); ?>">
                <?php foreach ($items as $item) : ?>
                    <option value="<?php echo esc_attr((string) absint($item['id'] ?? 0)); ?>"><?php echo esc_html((string) ($item[$label_key] ?? ('#' . absint($item['id'] ?? 0)))); ?></option>
                <?php endforeach; ?>
            </datalist>
            <small id="<?php echo esc_attr($help_id); ?>" class="mds-attention-cooperative-field-help"><?php echo esc_html($help); ?></small>
        </label>
        <?php
    }

    private function field_help($name) {
        $help = [
            'title' => __('Internal and public name for this cooperative pool.', 'mds-attention-cooperative'),
            'slug' => __('Optional URL-safe identifier for integrations. Leave blank to generate one from the name.', 'mds-attention-cooperative'),
            'status' => __('Only active cooperatives with completed review can show public applications.', 'mds-attention-cooperative'),
            'site_name' => __('Public site name shared with approved partners.', 'mds-attention-cooperative'),
            'site_url' => __('Public URL for this site or partner site.', 'mds-attention-cooperative'),
            'contact_email' => __('Private contact email for review, takedown, and partner follow-up.', 'mds-attention-cooperative'),
            'takedown_url' => __('Public contact or takedown page for sponsor and partner concerns.', 'mds-attention-cooperative'),
            'terms_url' => __('Public terms page covering sponsorship, approvals, takedowns, payments, and partner obligations.', 'mds-attention-cooperative'),
            'privacy_url' => __('Public privacy page covering data shared through cooperative applications and placements.', 'mds-attention-cooperative'),
            'disclosure_label' => __('Short disclosure label shown beside exposed inventory and placements.', 'mds-attention-cooperative'),
            'rate_limit_per_hour' => __('Maximum partner or service requests allowed per hour.', 'mds-attention-cooperative'),
            'retention_days' => __('How long private partner and placement contact data should be retained.', 'mds-attention-cooperative'),
            'terms_summary' => __('Snapshot of the current partner terms saved with partner approvals.', 'mds-attention-cooperative'),
            'prohibited_content' => __('Content types that should never be accepted through this cooperative.', 'mds-attention-cooperative'),
            'type' => __('Classifies the inventory, partner, or application record.', 'mds-attention-cooperative'),
            'label' => __('Public label for the exposed inventory item.', 'mds-attention-cooperative'),
            'scope' => __('Local inventory stays internal; approved-partner inventory can be requested by partners.', 'mds-attention-cooperative'),
            'capacity' => __('Total number of placements this inventory can support.', 'mds-attention-cooperative'),
            'available' => __('Current remaining placements available for review.', 'mds-attention-cooperative'),
            'currency' => __('Three-letter currency code. WooCommerce or the active payment provider may lock this value.', 'mds-attention-cooperative'),
            'price_from' => __('Starting price or review threshold shown to partners.', 'mds-attention-cooperative'),
            'source_ref' => __('Optional internal reference tying inventory back to a grid, board, page, or service.', 'mds-attention-cooperative'),
            'description' => __('Public explanation of this inventory or placement context.', 'mds-attention-cooperative'),
            'contact_name' => __('Private contact person for partner review.', 'mds-attention-cooperative'),
            'requested_scope' => __('Inventory types the partner is asking to access.', 'mds-attention-cooperative'),
            'allowed_inventory' => __('Inventory types this approved partner may request.', 'mds-attention-cooperative'),
            'service_endpoint' => __('Optional partner API endpoint. Only use reviewed HTTPS endpoints.', 'mds-attention-cooperative'),
            'api_key_label' => __('Legacy admin-only integration note. It is not a service credential or secret.', 'mds-attention-cooperative'),
            'notes' => __('Private review notes for admins.', 'mds-attention-cooperative'),
            'partner_id' => __('Choose a listed approved partner or enter its numeric ID. The partner must belong to this cooperative.', 'mds-attention-cooperative'),
            'inventory_id' => __('Choose listed exposed partner inventory or enter its numeric ID. The inventory must have remaining capacity and match the partner allowance.', 'mds-attention-cooperative'),
            'sponsor_name' => __('Sponsor name shown if the placement is approved.', 'mds-attention-cooperative'),
            'sponsor_url' => __('Reviewed public sponsor URL. External links are marked sponsored/nofollow.', 'mds-attention-cooperative'),
            'sponsor_email' => __('Private sponsor contact email for moderation follow-up.', 'mds-attention-cooperative'),
            'placement_copy' => __('Public copy shown with the placement after approval.', 'mds-attention-cooperative'),
        ];

        return (string) ($help[(string) $name] ?? '');
    }

    private function partner_button($partner_id, $status, $label) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mds_attention_cooperative_partner_action_' . absint($partner_id)); ?>
            <input type="hidden" name="action" value="mds_attention_cooperative_partner_action">
            <input type="hidden" name="partner_id" value="<?php echo esc_attr((string) absint($partner_id)); ?>">
            <input type="hidden" name="partner_status" value="<?php echo esc_attr($status); ?>">
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function placement_button($placement_id, $status, $label) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mds_attention_cooperative_placement_action_' . absint($placement_id)); ?>
            <input type="hidden" name="action" value="mds_attention_cooperative_placement_action">
            <input type="hidden" name="placement_id" value="<?php echo esc_attr((string) absint($placement_id)); ?>">
            <input type="hidden" name="placement_status" value="<?php echo esc_attr($status); ?>">
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function metric($label, $value) {
        ?>
        <div>
            <strong><?php echo esc_html(number_format_i18n(absint($value))); ?></strong>
            <span><?php echo esc_html($label); ?></span>
        </div>
        <?php
    }

    private function admin_notice() {
        $status = sanitize_key((string) ($_GET['mds_ac_status'] ?? ''));
        $messages = [
            'saved' => __('Cooperative saved.', 'mds-attention-cooperative'),
            'inventory_saved' => __('Inventory saved.', 'mds-attention-cooperative'),
            'partner_saved' => __('Partner saved.', 'mds-attention-cooperative'),
            'partner_updated' => __('Partner status updated.', 'mds-attention-cooperative'),
            'placement_created' => __('Placement request created.', 'mds-attention-cooperative'),
            'placement_updated' => __('Placement status updated.', 'mds-attention-cooperative'),
            'invalid_endpoint' => __('Partner service endpoints must use a valid HTTPS URL.', 'mds-attention-cooperative'),
            'mds_attention_cooperative_pool_unavailable' => __('Activate the cooperative and confirm its review gate before creating or approving placements.', 'mds-attention-cooperative'),
            'mds_attention_cooperative_placement_terms_required' => __('Add a sponsor name and confirm the placement terms.', 'mds-attention-cooperative'),
            'mds_attention_cooperative_partner_invalid' => __('Choose an approved partner from this cooperative.', 'mds-attention-cooperative'),
            'mds_attention_cooperative_inventory_invalid' => __('Choose exposed approved-partner inventory from this cooperative.', 'mds-attention-cooperative'),
            'mds_attention_cooperative_inventory_not_allowed' => __('That partner is not approved for the selected inventory type.', 'mds-attention-cooperative'),
            'mds_attention_cooperative_source_approval_required' => __('Record source-site approval before approving this placement.', 'mds-attention-cooperative'),
            'mds_attention_cooperative_inventory_exhausted' => __('The selected inventory has no remaining capacity.', 'mds-attention-cooperative'),
            'error' => __('Attention Cooperative could not complete that action.', 'mds-attention-cooperative'),
        ];
        if (isset($messages[$status])) {
            $success = in_array($status, ['saved', 'inventory_saved', 'partner_saved', 'partner_updated', 'placement_created', 'placement_updated'], true);
            echo '<div class="notice notice-' . ($success ? 'success' : 'error') . '"><p>' . esc_html($messages[$status]) . '</p></div>';
        }
    }

    private function public_notice() {
        $status = sanitize_key((string) ($_GET['cooperative_status'] ?? ''));
        $messages = [
            'submitted' => __('Application submitted for review.', 'mds-attention-cooperative'),
            'invalid' => __('Add the required partner details before submitting.', 'mds-attention-cooperative'),
            'rate_limited' => __('Please wait before submitting again.', 'mds-attention-cooperative'),
            'closed' => __('Partner applications are not currently open.', 'mds-attention-cooperative'),
        ];
        if (isset($messages[$status])) {
            $modifier = 'submitted' === $status ? ' is-success' : ' is-error';
            echo '<p class="mds-attention-cooperative__notice' . esc_attr($modifier) . '">' . esc_html($messages[$status]) . '</p>';
        }
    }

    private function inventory_type_options() {
        $options = [];
        foreach (Repository::inventory_types() as $type) {
            $options[$type] = $this->inventory_type_label($type);
        }

        return $options;
    }

    private function inventory_type_label($type) {
        $labels = [
            'grid' => __('Classic Pixel Grid', 'mds-attention-cooperative'),
            'sponsorboard' => __('SponsorBoard', 'mds-attention-cooperative'),
            'missions' => __('Missions', 'mds-attention-cooperative'),
            'launch-wall' => __('Launch Wall', 'mds-attention-cooperative'),
            'local-money-map' => __('Local Money Map', 'mds-attention-cooperative'),
            'custom' => __('Custom', 'mds-attention-cooperative'),
        ];

        return (string) ($labels[(string) $type] ?? $type);
    }

    private function partner_status_options() {
        return [
            'invited' => __('Invited', 'mds-attention-cooperative'),
            'applied' => __('Applied', 'mds-attention-cooperative'),
            'changes_requested' => __('Changes requested', 'mds-attention-cooperative'),
            'approved' => __('Approved', 'mds-attention-cooperative'),
            'rejected' => __('Rejected', 'mds-attention-cooperative'),
            'revoked' => __('Revoked', 'mds-attention-cooperative'),
        ];
    }

    private function first_public_pool() {
        foreach (Repository::pools(100) as $pool) {
            if ('active' === (string) ($pool['status'] ?? '') && !empty($pool['legal_review_completed'])) {
                return $pool;
            }
        }

        return Repository::pools(1)[0] ?? null;
    }

    private function format_money($amount, $currency) {
        $amount = (float) $amount;
        if ($amount <= 0) {
            return '';
        }

        $currency = Repository::effective_currency($currency);
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency')) {
            return \MillionDollarScript\Commerce\Currency::format($amount, $currency);
        }

        return trim(sprintf('%s %s', $currency, number_format_i18n($amount, 2)));
    }

    private function request_params($request) {
        if (is_object($request) && method_exists($request, 'get_json_params')) {
            $params = $request->get_json_params();
            if (is_array($params)) {
                return $params;
            }
        }
        if (is_object($request) && method_exists($request, 'get_params')) {
            $params = $request->get_params();
            if (is_array($params)) {
                return $params;
            }
        }

        return [];
    }

    private function permission($request, $scope, $level) {
        return \MillionDollarScript\Extensions\Support::permission($request, $scope, $level);
    }

    private function require_manage() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage Attention Cooperative.', 'mds-attention-cooperative'));
        }
    }

    private function rate_limited($key, $limit = 20) {
        $limit = max(1, absint($limit));
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
        $hash = hash_hmac('sha256', $ip . '|' . sanitize_key((string) $key), wp_salt('auth'));
        $transient = 'mds_ac_rate_' . substr($hash, 0, 40);
        $bucket = get_transient($transient);
        $bucket = is_array($bucket) ? $bucket : ['count' => 0];
        if (absint($bucket['count'] ?? 0) >= $limit) {
            return true;
        }

        $bucket['count'] = absint($bucket['count'] ?? 0) + 1;
        set_transient($transient, $bucket, HOUR_IN_SECONDS);
        return false;
    }

    private function admin_url(array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::admin_url('mds-attention-cooperative', $args);
        }

        return add_query_arg(array_merge(['page' => 'mds-attention-cooperative'], $args), admin_url('admin.php'));
    }

    private function active_admin_tab($has_pool) {
        $default_tab = $has_pool ? 'placements' : 'overview';
        $tab = sanitize_key((string) ($_GET['tab'] ?? $default_tab));
        $allowed = ['overview'];
        if ($has_pool) {
            $allowed = array_merge($allowed, ['inventory', 'partners', 'placements', 'providers', 'activity']);
        }

        return in_array($tab, $allowed, true) ? $tab : $default_tab;
    }

    private function redirect(array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            \MillionDollarScript\Extensions\Support::redirect_admin('mds-attention-cooperative', $args);
        }

        wp_safe_redirect($this->admin_url($args));
        exit;
    }

    private function safe_redirect($url, array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            \MillionDollarScript\Extensions\Support::safe_redirect($url, $args);
        }

        $url = $args ? add_query_arg($args, $url) : $url;
        wp_safe_redirect(esc_url_raw($url));
        exit;
    }

    private function admin_parent_slug() {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::admin_parent_slug();
        }

        global $admin_page_hooks;
        if (isset($admin_page_hooks['mds3'])) {
            return 'mds3';
        }
        if (isset($admin_page_hooks['million-dollar-script'])) {
            return 'million-dollar-script';
        }

        return 'options-general.php';
    }
}
