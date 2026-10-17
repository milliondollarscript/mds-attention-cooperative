<?php
/**
 * Local fixture checks for Attention Cooperative.
 *
 * Run with:
 * ./scripts/wp eval-file wp-content/plugins/mds-attention-cooperative/tests/mds3-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

use MillionDollarScript\Extensions\AttentionCooperative\Main;
use MillionDollarScript\Extensions\AttentionCooperative\Privacy;
use MillionDollarScript\Extensions\AttentionCooperative\Repository;
use MillionDollarScript\Extensions\AttentionCooperative\Retention;
use MillionDollarScript\Extensions\AttentionCooperative\ServiceCredentialAdmin;
use MillionDollarScript\Extensions\AttentionCooperative\ServiceCredentialRepository;
use MillionDollarScript\Extensions\AttentionCooperative\ServiceSignature;

if (!class_exists(Repository::class) || !class_exists(Main::class)) {
    fwrite(STDERR, "Attention Cooperative classes are not loaded.\n");
    exit(1);
}

wp_set_current_user(1);
Main::register_post_types();

$main = Main::instance();
$main->admin_menu();
global $submenu;
$has_menu = false;
foreach ((array) ($submenu['mds3'] ?? []) as $item) {
    if ('mds-attention-cooperative' === (string) ($item[2] ?? '')) {
        $has_menu = true;
        break;
    }
}
if (!$has_menu) {
    fwrite(STDERR, "Attention Cooperative did not register an extension submenu.\n");
    exit(1);
}

$onboarding = apply_filters('million-dollar-script/extension/onboarding/items', []);
if (empty($onboarding['mds-attention-cooperative']['actions']) || empty($onboarding['mds-attention-cooperative']['legal_documents'])) {
    fwrite(STDERR, "Attention Cooperative onboarding is incomplete.\n");
    exit(1);
}

$blocks = apply_filters('million-dollar-script/editor/extension/blocks', []);
$block_names = array_map(static function ($block) {
    return is_array($block) ? (string) ($block['name'] ?? '') : '';
}, $blocks);
if (!in_array('mds-attention-cooperative/pool', $block_names, true)) {
    fwrite(STDERR, "Attention Cooperative block is not registered.\n");
    exit(1);
}

$suffix = strtolower(wp_generate_password(8, false, false));
$email = 'attention-fixture-' . $suffix . '@example.test';
$created_ids = [];

$pool_id = Repository::save_pool([
    'title' => 'Fixture Cooperative ' . $suffix,
    'slug' => 'fixture-cooperative-' . $suffix,
    'status' => 'active',
    'site_name' => 'Fixture Site',
    'site_url' => home_url('/'),
    'contact_email' => $email,
    'takedown_url' => home_url('/contact/'),
    'terms_url' => home_url('/terms/'),
    'privacy_url' => home_url('/privacy-policy/'),
    'disclosure_label' => 'Fixture sponsor',
    'terms_summary' => 'Fixture terms snapshot.',
    'prohibited_content' => 'Fixture prohibited content policy.',
    'rate_limit_per_hour' => 20,
    'retention_days' => 30,
    'public_applications_enabled' => '1',
    'source_approval_required' => '1',
    'destination_approval_required' => '1',
    'legal_review_completed' => '1',
]);
if (!$pool_id) {
    fwrite(STDERR, "Fixture cooperative could not be created.\n");
    exit(1);
}
$created_ids[] = $pool_id;

$previous_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '192.0.2.55';
$hash_audit_id = Repository::audit('fixture_ip_hash', 'pool', $pool_id, 'Fixture IP hash.', ['pool_id' => $pool_id]);
$expected_ip_hash = hash_hmac('sha256', '192.0.2.55', wp_salt('auth'));
if ($expected_ip_hash !== (string) get_post_meta($hash_audit_id, '_mds_ac_ip_hash', true)) {
    fwrite(STDERR, "Audit IP identifiers were not protected with the site secret.\n");
    exit(1);
}
if (null === $previous_remote_addr) {
    unset($_SERVER['REMOTE_ADDR']);
} else {
    $_SERVER['REMOTE_ADDR'] = $previous_remote_addr;
}

$public_manifest = Repository::local_inventory_manifest($pool_id);
if (array_key_exists('contact_email', (array) ($public_manifest['site'] ?? []))) {
    fwrite(STDERR, "Public inventory manifest exposed the private cooperative contact email.\n");
    exit(1);
}

$invalid_endpoint_partner = Repository::save_partner([
    'pool_id' => $pool_id,
    'site_name' => 'Invalid endpoint partner',
    'service_endpoint' => 'http://partner.example.test/api',
]);
if ($invalid_endpoint_partner) {
    $created_ids[] = $invalid_endpoint_partner;
    fwrite(STDERR, "Partner repository accepted a non-HTTPS service endpoint.\n");
    exit(1);
}

$inventory_id = Repository::save_inventory([
    'pool_id' => $pool_id,
    'type' => 'custom',
    'label' => 'Fixture inventory ' . $suffix,
    'description' => 'Fixture public inventory.',
    'status' => 'exposed',
    'scope' => 'federated',
    'capacity' => 2,
    'available' => 2,
    'currency' => Repository::default_currency(),
    'price_from' => 15,
    'approval_required' => '1',
    'disclosure_label' => 'Fixture sponsor',
]);
if (!$inventory_id) {
    fwrite(STDERR, "Fixture inventory could not be created.\n");
    exit(1);
}
$created_ids[] = $inventory_id;

$partner_id = Repository::save_partner([
    'pool_id' => $pool_id,
    'type' => 'application',
    'status' => 'applied',
    'site_name' => 'Fixture Partner ' . $suffix,
    'site_url' => 'https://partner.example.test/',
    'contact_name' => 'Fixture Contact',
    'contact_email' => $email,
    'requested_scope' => ['custom'],
    'allowed_inventory' => [],
    'rate_limit_per_hour' => 10,
    'terms_accepted' => '1',
    'notes' => 'Fixture partner application.',
]);
if (!$partner_id || !Repository::update_partner_status($partner_id, 'approved')) {
    fwrite(STDERR, "Fixture partner approval failed.\n");
    exit(1);
}
$created_ids[] = $partner_id;
$partner = Repository::partner($partner_id);
if (!$partner || 'approved' !== $partner['status'] || !in_array('custom', (array) $partner['allowed_inventory'], true)) {
    fwrite(STDERR, "Approved partner scope was not persisted.\n");
    exit(1);
}

$placement_id = Repository::create_placement([
    'pool_id' => $pool_id,
    'partner_id' => $partner_id,
    'inventory_id' => $inventory_id,
    'status' => 'pending_destination',
    'sponsor_name' => 'Fixture Sponsor ' . $suffix,
    'sponsor_url' => 'https://sponsor.example.test/',
    'sponsor_email' => $email,
    'placement_copy' => 'Fixture approved placement copy.',
    'disclosure_label' => 'Fixture sponsor',
    'source_approved' => '1',
    'terms_accepted' => '1',
]);
if (!$placement_id || !Repository::update_placement_status($placement_id, 'approved')) {
    fwrite(STDERR, "Fixture placement approval failed.\n");
    exit(1);
}
$created_ids[] = $placement_id;
$approved_inventory = Repository::inventory($inventory_id);
$approved_placement = Repository::placement($placement_id);
if (1 !== absint($approved_inventory['available'] ?? -1) || empty($approved_placement['inventory_consumed'])) {
    fwrite(STDERR, "Placement approval did not consume exactly one inventory unit.\n");
    exit(1);
}
if (!Repository::update_placement_status($placement_id, 'approved') || 1 !== absint((Repository::inventory($inventory_id))['available'] ?? -1)) {
    fwrite(STDERR, "Repeated placement approval consumed inventory twice.\n");
    exit(1);
}
if (!Repository::update_placement_status($placement_id, 'revoked') || 2 !== absint((Repository::inventory($inventory_id))['available'] ?? -1)) {
    fwrite(STDERR, "Placement revocation did not restore inventory.\n");
    exit(1);
}
if (!Repository::update_placement_status($placement_id, 'revoked') || 2 !== absint((Repository::inventory($inventory_id))['available'] ?? -1)) {
    fwrite(STDERR, "Repeated placement revocation restored inventory twice.\n");
    exit(1);
}
if (!Repository::update_placement_status($placement_id, 'approved')) {
    fwrite(STDERR, "Fixture placement could not be re-approved after capacity restoration.\n");
    exit(1);
}

$other_pool_id = Repository::save_pool([
    'title' => 'Other Fixture Cooperative ' . $suffix,
    'status' => 'active',
    'site_name' => 'Other Fixture Site',
    'site_url' => home_url('/'),
    'contact_email' => $email,
    'terms_summary' => 'Other terms.',
    'legal_review_completed' => '1',
]);
$created_ids[] = $other_pool_id;
$cross_pool_placement = Repository::create_placement([
    'pool_id' => $other_pool_id,
    'partner_id' => $partner_id,
    'inventory_id' => $inventory_id,
    'sponsor_name' => 'Cross-pool sponsor',
    'source_approved' => '1',
    'terms_accepted' => '1',
]);
if ($cross_pool_placement) {
    $created_ids[] = $cross_pool_placement;
    fwrite(STDERR, "Cross-pool placement relationships were accepted.\n");
    exit(1);
}

$html = $main->shortcode([
    'id' => $pool_id,
    'show_inventory' => 'true',
    'show_placements' => 'true',
    'show_application_form' => 'true',
]);
if (false === strpos($html, 'Fixture approved placement copy.') || false === strpos($html, 'Fixture public inventory.')) {
    fwrite(STDERR, "Approved inventory and placement were not rendered publicly.\n");
    exit(1);
}

$privacy_partner_ids = [];
for ($index = 0; $index < 55; $index++) {
    $privacy_partner_id = wp_insert_post([
        'post_type' => Repository::PARTNER_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => 'Paged privacy partner ' . $index,
    ]);
    update_post_meta($privacy_partner_id, '_mds_ac_pool_id', $pool_id);
    update_post_meta($privacy_partner_id, '_mds_ac_partner_status', 'rejected');
    update_post_meta($privacy_partner_id, '_mds_ac_contact_email', $email);
    update_post_meta($privacy_partner_id, '_mds_ac_contact_name', 'Paged Contact');
    $privacy_partner_ids[] = $privacy_partner_id;
    $created_ids[] = $privacy_partner_id;
}

$export = $main->privacy_exporter($email, 1);
if (!empty($export['done']) || count($export['data']) < 50) {
    fwrite(STDERR, "Privacy export did not continue beyond its first page.\n");
    exit(1);
}
$export_page_two = $main->privacy_exporter($email, 2);
if (empty($export_page_two['done']) || empty($export_page_two['data'])) {
    fwrite(STDERR, "Privacy export did not include fixture partner data.\n");
    exit(1);
}

$erase_iterations = 0;
do {
    $erase = Privacy::eraser($email, $erase_iterations + 1);
    $erase_iterations++;
} while (empty($erase['done']) && $erase_iterations < 5);
if (empty($erase['done']) || !empty(Repository::partners_by_email($email, 1, 1)['items']) || !empty(Repository::placements_by_email($email, 1, 1)['items'])) {
    fwrite(STDERR, "Privacy erasure did not anonymize every matching paged record.\n");
    exit(1);
}

$retention_partner_id = Repository::save_partner([
    'pool_id' => $pool_id,
    'type' => 'application',
    'status' => 'rejected',
    'site_name' => 'Retention fixture partner',
    'contact_name' => 'Retention Contact',
    'contact_email' => 'retention-' . $suffix . '@example.test',
    'service_endpoint' => 'https://retention.example.test/api',
    'notes' => 'Private retention notes.',
]);
$created_ids[] = $retention_partner_id;
$old_date = '2020-01-01 00:00:00';
wp_update_post(['ID' => $retention_partner_id, 'post_date' => $old_date, 'post_date_gmt' => $old_date]);
update_post_meta($retention_partner_id, '_mds_ac_private_data_updated_at', strtotime($old_date . ' UTC'));
$cleanup = Repository::cleanup_expired_private_data($pool_id, 30);
$retained_partner = Repository::partner($retention_partner_id);
if (empty($cleanup['partners']) || !empty($retained_partner['contact_email']) || !empty($retained_partner['service_endpoint']) || !empty($retained_partner['notes'])) {
    fwrite(STDERR, "Configured retention did not anonymize stale rejected partner data.\n");
    exit(1);
}

Retention::register();
if (!wp_next_scheduled(Retention::HOOK)) {
    fwrite(STDERR, "Attention Cooperative retention was not scheduled.\n");
    exit(1);
}

do_action('rest_api_init');
$routes = rest_get_server()->get_routes();
foreach (['/million-dollar-script/v1/cooperative/inventory', '/million-dollar-script/v1/cooperative/partners/apply', '/million-dollar-script/v1/cooperative/placements'] as $route) {
    if (empty($routes[$route])) {
        fwrite(STDERR, "Missing REST route: {$route}.\n");
        exit(1);
    }
}

$created_credential = ServiceCredentialRepository::create($partner_id, 'Fixture signing credential', 90);
if (is_wp_error($created_credential)) {
    fwrite(STDERR, 'Service credential creation failed: ' . $created_credential->get_error_message() . "\n");
    exit(1);
}
$fixture_credential = $created_credential['credential'];
$fixture_secret = (string) $created_credential['secret'];
if (
    0 !== strpos((string) $fixture_credential['service_id'], 'acsvc_')
    || 43 !== strlen($fixture_secret)
    || false !== strpos((string) $fixture_credential['secret_envelope'], $fixture_secret)
    || 'Fixture signing credential' !== (string) $fixture_credential['label']
) {
    fwrite(STDERR, "Service credential IDs, secrets, labels, or encrypted storage are not separated correctly.\n");
    exit(1);
}
$expired_created = ServiceCredentialRepository::create($partner_id, 'Expired fixture credential', 1);
if (is_wp_error($expired_created)) {
    fwrite(STDERR, 'Expired credential fixture creation failed: ' . $expired_created->get_error_message() . "\n");
    exit(1);
}
$expired_credential = $expired_created['credential'];
$expired_secret = (string) $expired_created['secret'];
global $wpdb;
$wpdb->update(
    ServiceCredentialRepository::table('credentials'),
    ['expires_at' => gmdate('Y-m-d H:i:s', time() - HOUR_IN_SECONDS)],
    ['id' => absint($expired_credential['id'])],
    ['%s'],
    ['%d']
);

$service_test_host = $_SERVER['HTTP_HOST'] ?? null;
$service_test_remote_addr = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$placement_body = (string) wp_json_encode([
    'pool_id' => $pool_id,
    'partner_id' => $partner_id,
    'inventory_id' => $inventory_id,
    'sponsor_name' => 'Signed Fixture Sponsor ' . $suffix,
    'sponsor_url' => 'https://signed-sponsor.example.test/',
    'sponsor_email' => $email,
    'placement_copy' => 'Signed fixture placement pending local review.',
    'disclosure_label' => 'Fixture sponsor',
    'source_approved' => true,
    'terms_accepted' => true,
]);
$signed_request = static function (
    array $credential,
    $secret,
    $body,
    $idempotency_key,
    $nonce = '',
    $method = 'POST',
    $route = ''
) {
    $method = strtoupper((string) $method);
    $route = $route ?: ServiceSignature::ROUTE;
    $nonce = $nonce ?: rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $timestamp = time();
    $body_hash = hash('sha256', (string) $body);
    $canonical = implode("\n", [
        'v1',
        (string) $credential['service_id'],
        $method,
        $route,
        (string) $timestamp,
        $nonce,
        $body_hash,
        (string) $idempotency_key,
    ]);
    $request = new WP_REST_Request($method, $route);
    $request->set_header('Content-Type', 'application/json');
    $request->set_header('X-MDS-Service-Id', (string) $credential['service_id']);
    $request->set_header('X-MDS-Signature-Version', 'v1');
    $request->set_header('X-MDS-Timestamp', (string) $timestamp);
    $request->set_header('X-MDS-Nonce', $nonce);
    $request->set_header('X-MDS-Content-SHA256', $body_hash);
    $request->set_header('X-MDS-Signature', hash_hmac('sha256', $canonical, (string) $secret));
    $request->set_header('X-Idempotency-Key', (string) $idempotency_key);
    $request->set_body((string) $body);

    return $request;
};

wp_set_current_user(0);
$unregistered_request = $signed_request($fixture_credential, $fixture_secret, $placement_body, 'fixture-no-verifier');
$unregistered_response = rest_do_request($unregistered_request);
if (401 !== $unregistered_response->get_status()) {
    fwrite(STDERR, "A service request succeeded before its extension verifier was registered.\n");
    exit(1);
}

ServiceSignature::register();
$expired_response = rest_do_request($signed_request(
    $expired_credential,
    $expired_secret,
    $placement_body,
    'fixture-expired-credential'
));
if (401 !== $expired_response->get_status()) {
    fwrite(STDERR, "An expired service credential was accepted.\n");
    exit(1);
}
$valid_nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$valid_request = $signed_request(
    $fixture_credential,
    $fixture_secret,
    $placement_body,
    'fixture-signed-create',
    $valid_nonce
);
$valid_response = rest_do_request($valid_request);
$valid_payload = (array) $valid_response->get_data();
$signed_placement = (array) ($valid_payload['placement'] ?? []);
if (
    201 !== $valid_response->get_status()
    || 'pending_destination' !== (string) ($signed_placement['status'] ?? '')
    || !empty($signed_placement['destination_approved'])
    || absint($signed_placement['partner_id'] ?? 0) !== $partner_id
) {
    fwrite(STDERR, "A valid signed placement did not create exactly a pending local-review record.\n");
    exit(1);
}
$created_ids[] = absint($signed_placement['id'] ?? 0);
$service_identity = \MillionDollarScript\Core\ApiAccess::service_identity($valid_request);
if (
    'service_signature' !== (string) ($service_identity['authentication'] ?? '')
    || (string) $fixture_credential['service_id'] !== (string) ($service_identity['service_id'] ?? '')
    || current_user_can('manage_options')
) {
    fwrite(STDERR, "A signed request did not remain a non-administrator service identity.\n");
    exit(1);
}

$mismatched_body = (string) wp_json_encode(array_merge(
    (array) json_decode($placement_body, true),
    ['pool_id' => $other_pool_id]
));
$mismatched_response = rest_do_request($signed_request(
    $fixture_credential,
    $fixture_secret,
    $mismatched_body,
    'fixture-wrong-pool'
));
if (403 !== $mismatched_response->get_status()) {
    fwrite(STDERR, "A valid signature was allowed to substitute a different cooperative identity.\n");
    exit(1);
}

$replay_response = rest_do_request($valid_request);
if (401 !== $replay_response->get_status()) {
    fwrite(STDERR, "A replayed service-signature nonce was accepted.\n");
    exit(1);
}

$idempotent_request = $signed_request(
    $fixture_credential,
    $fixture_secret,
    $placement_body,
    'fixture-signed-create'
);
$idempotent_response = rest_do_request($idempotent_request);
$idempotent_payload = (array) $idempotent_response->get_data();
$idempotent_headers = (array) $idempotent_response->get_headers();
if (
    200 !== $idempotent_response->get_status()
    || absint($idempotent_payload['placement']['id'] ?? 0) !== absint($signed_placement['id'] ?? 0)
    || 'true' !== (string) (
        $idempotent_headers['X-MDS-Idempotent-Replay']
        ?? $idempotent_headers['x-mds-idempotent-replay']
        ?? ''
    )
) {
    fwrite(STDERR, "A signed placement retry did not return the original idempotent result.\n");
    exit(1);
}

$tampered_request = $signed_request(
    $fixture_credential,
    $fixture_secret,
    $placement_body,
    'fixture-tampered'
);
$tampered_request->set_body(str_replace('pending local review', 'tampered copy', $placement_body));
if (401 !== rest_do_request($tampered_request)->get_status()) {
    fwrite(STDERR, "A request body changed after signing was accepted.\n");
    exit(1);
}

wp_set_current_user(1);
$rotated = ServiceCredentialRepository::rotate(absint($fixture_credential['id']));
if (is_wp_error($rotated)) {
    fwrite(STDERR, 'Service credential rotation failed: ' . $rotated->get_error_message() . "\n");
    exit(1);
}
$replacement_credential = $rotated['credential'];
$replacement_secret = (string) $rotated['secret'];
ServiceSignature::register();
wp_set_current_user(0);

$overlap_response = rest_do_request($signed_request(
    $fixture_credential,
    $fixture_secret,
    $placement_body,
    'fixture-rotation-overlap'
));
if (201 !== $overlap_response->get_status()) {
    fwrite(STDERR, "The documented 24-hour rotation overlap did not keep the old credential valid.\n");
    exit(1);
}
$created_ids[] = absint($overlap_response->get_data()['placement']['id'] ?? 0);

$replacement_response = rest_do_request($signed_request(
    $replacement_credential,
    $replacement_secret,
    $placement_body,
    'fixture-rotation-new'
));
if (201 !== $replacement_response->get_status()) {
    fwrite(STDERR, "The replacement service credential was not valid after rotation.\n");
    exit(1);
}
$created_ids[] = absint($replacement_response->get_data()['placement']['id'] ?? 0);

update_post_meta($partner_id, '_mds_ac_rate_limit_per_hour', 1);
$limited_response = rest_do_request($signed_request(
    $replacement_credential,
    $replacement_secret,
    $placement_body,
    'fixture-partner-rate-limit'
));
update_post_meta($partner_id, '_mds_ac_rate_limit_per_hour', 10);
if (429 !== $limited_response->get_status()) {
    fwrite(STDERR, "The approved partner's signed-request rate limit was not enforced.\n");
    exit(1);
}

wp_set_current_user(1);
if (!ServiceCredentialRepository::revoke(absint($fixture_credential['id']))) {
    fwrite(STDERR, "The retiring service credential could not be revoked.\n");
    exit(1);
}
wp_set_current_user(0);
$revoked_response = rest_do_request($signed_request(
    $fixture_credential,
    $fixture_secret,
    $placement_body,
    'fixture-revoked-old'
));
if (401 !== $revoked_response->get_status()) {
    fwrite(STDERR, "A revoked service credential was accepted.\n");
    exit(1);
}

$attention_openapi = (new \MillionDollarScript\V3\Rest\ApiGovernance())->openapi();
$attention_operation = (array) ($attention_openapi['paths']['/cooperative/placements']['post'] ?? []);
$attention_parameters = array_column((array) ($attention_operation['parameters'] ?? []), null, 'name');
$attention_security = (array) ($attention_operation['security'] ?? []);
if (
    empty($attention_openapi['components']['securitySchemes']['ServiceSignatureV1'])
    || empty($attention_parameters['X-Idempotency-Key']['required'])
    || !in_array(['ServiceSignatureV1' => []], $attention_security, true)
) {
    fwrite(STDERR, "Attention Cooperative OpenAPI metadata does not describe its required signed idempotent request.\n");
    exit(1);
}
$public_security_payload = wp_json_encode([
    'inventory' => Repository::local_inventory_manifest($pool_id),
    'openapi' => $attention_openapi,
]);
$authorization_audit_payload = wp_json_encode(
    (new \MillionDollarScript\V3\Rest\ApiAuditRepository())->recent(200)
);
if (
    false !== strpos($public_security_payload, $fixture_secret)
    || false !== strpos($public_security_payload, $expired_secret)
    || false !== strpos($public_security_payload, $replacement_secret)
    || false !== strpos($public_security_payload, (string) $fixture_credential['secret_envelope'])
) {
    fwrite(STDERR, "Public discovery or inventory exposed service secret material.\n");
    exit(1);
}
foreach ([
    $fixture_secret,
    $expired_secret,
    $replacement_secret,
    (string) $fixture_credential['service_id'],
    (string) $valid_request->get_header('X-MDS-Signature'),
    $valid_nonce,
    $placement_body,
] as $private_auth_value) {
    if ('' !== $private_auth_value && false !== strpos($authorization_audit_payload, $private_auth_value)) {
        fwrite(STDERR, "The API authorization audit exposed a service credential, signature, nonce, or raw body.\n");
        exit(1);
    }
}
ob_start();
ServiceCredentialAdmin::render_for_partner(Repository::partner($partner_id));
$credential_admin_html = (string) ob_get_clean();
if (
    false !== strpos($credential_admin_html, $fixture_secret)
    || false !== strpos($credential_admin_html, $expired_secret)
    || false !== strpos($credential_admin_html, $replacement_secret)
    || false !== strpos($credential_admin_html, (string) $replacement_credential['secret_envelope'])
) {
    fwrite(STDERR, "The credential admin table exposed stored secret material.\n");
    exit(1);
}
update_post_meta($partner_id, '_mds_ac_contact_email', $email);
$credential_export = $main->privacy_exporter($email, 1);
$credential_export_payload = wp_json_encode($credential_export);
if (
    empty($credential_export['data'])
    || false !== strpos($credential_export_payload, (string) $fixture_credential['service_id'])
    || false !== strpos($credential_export_payload, (string) $replacement_credential['service_id'])
    || false !== strpos($credential_export_payload, $fixture_secret)
    || false !== strpos($credential_export_payload, $replacement_secret)
    || false !== strpos($credential_export_payload, (string) $replacement_credential['secret_envelope'])
) {
    fwrite(STDERR, "The WordPress personal-data export exposed service credential material.\n");
    exit(1);
}

wp_set_current_user(1);
$admin_request = new WP_REST_Request('POST', ServiceSignature::ROUTE);
$admin_request->set_header('Content-Type', 'application/json');
$admin_request->set_body($placement_body);
$admin_response = rest_do_request($admin_request);
if (201 !== $admin_response->get_status()) {
    fwrite(STDERR, "WordPress administrator access to the service-signature endpoint regressed.\n");
    exit(1);
}
$created_ids[] = absint($admin_response->get_data()['placement']['id'] ?? 0);
ServiceCredentialRepository::revoke(absint($replacement_credential['id']));
\MillionDollarScript\Extensions\AttentionCooperative\CredentialCipher::wipe($fixture_secret);
\MillionDollarScript\Extensions\AttentionCooperative\CredentialCipher::wipe($expired_secret);
\MillionDollarScript\Extensions\AttentionCooperative\CredentialCipher::wipe($replacement_secret);
if (null === $service_test_host) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $service_test_host;
}
if (null === $service_test_remote_addr) {
    unset($_SERVER['REMOTE_ADDR']);
} else {
    $_SERVER['REMOTE_ADDR'] = $service_test_remote_addr;
}

$manifest = apply_filters('million-dollar-script/api/endpoint/manifest', []);
$manifest_ids = array_map(static function ($entry) {
    return is_array($entry) ? (string) ($entry['id'] ?? '') : '';
}, $manifest);
if (!in_array('cooperative-inventory-read', $manifest_ids, true) || !in_array('cooperative-placement-create', $manifest_ids, true)) {
    fwrite(STDERR, "Attention Cooperative API manifest is incomplete.\n");
    exit(1);
}

foreach ($created_ids as $post_id) {
    wp_delete_post($post_id, true);
}
foreach (array_filter([$pool_id, $other_pool_id]) as $fixture_pool_id) {
    $audit_ids = get_posts([
        'post_type' => Repository::AUDIT_POST_TYPE,
        'post_status' => 'any',
        'fields' => 'ids',
        'posts_per_page' => -1,
        'meta_key' => '_mds_ac_pool_id',
        'meta_value' => $fixture_pool_id,
    ]);
    foreach ($audit_ids as $audit_id) {
        wp_delete_post($audit_id, true);
    }
}
$wpdb->delete(ServiceCredentialRepository::table('nonces'), ['partner_id' => $partner_id], ['%d']);
$wpdb->delete(ServiceCredentialRepository::table('idempotency'), ['partner_id' => $partner_id], ['%d']);
$wpdb->delete(ServiceCredentialRepository::table('credentials'), ['partner_id' => $partner_id], ['%d']);

echo "Success: Attention Cooperative fixture passed.\n";
