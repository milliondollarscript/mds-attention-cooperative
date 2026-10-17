<?php
/** Attention Cooperative uninstall cleanup. */
if (!defined('WP_UNINSTALL_PLUGIN') || !class_exists('\\MillionDollarScript\\Extensions\\CleanupPolicy')) { exit; }
\MillionDollarScript\Extensions\CleanupPolicy::cleanup('mds-attention-cooperative', [
    'post_types' => ['mds_ac_pool', 'mds_ac_partner', 'mds_ac_inventory', 'mds_ac_placement', 'mds_ac_audit'],
    'tables' => ['mds_ac_service_credentials', 'mds_ac_signature_nonces', 'mds_ac_placement_idempotency'],
    'option_prefixes' => ['mds_attention_cooperative_'],
    'transient_prefixes' => ['mds_attention_cooperative_'],
    'cron_hooks' => ['mds_attention_cooperative_retention'],
]);
