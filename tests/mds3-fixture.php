<?php
/**
 * Launch Wall runtime/security regression fixture.
 *
 * Usage:
 * wp --path=/var/www/html eval-file wp-content/plugins/mds-launch-wall/tests/mds3-fixture.php
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\\MillionDollarScript\\Extensions\\LaunchWall\\Repository') || !class_exists('\\MillionDollarScript\\Extensions\\LaunchWall\\Main')) {
    echo wp_json_encode(['skipped' => 'launch_wall_unavailable']) . "\n";
    return;
}

$repo = '\\MillionDollarScript\\Extensions\\LaunchWall\\Repository';
$main = \MillionDollarScript\Extensions\LaunchWall\Main::instance();
$failures = [];
$created_posts = [];
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    $wall_id = $repo::save_wall([
        'title' => 'Fixture Launch Wall ' . wp_generate_password(6, false, false),
        'slug' => 'fixture-launch-wall-' . wp_generate_password(6, false, false),
        'description' => 'Fixture launch wall.',
        'status' => 'active',
        'currency' => $repo::default_currency(),
        'base_price' => 10,
        'featured_price' => 5,
        'launch_limit' => 200,
        'payment_mode' => 'manual',
        'submission_enabled' => '1',
        'voting_enabled' => '1',
        'disclosure' => 'Featured launches may be paid placements.',
        'terms_url' => '',
        'contact_email' => 'fixture-admin@example.test',
        'retention_days' => 30,
        'submission_limit_per_hour' => 20,
        'vote_limit_per_day' => 1,
    ]);
    $created_posts[] = $wall_id;
    $assert($wall_id > 0, 'Fixture wall should be created.');
    $saved_wall = $repo::wall($wall_id);
    $assert(200 === absint($saved_wall['launch_limit'] ?? 0) && 20 === absint($saved_wall['submission_limit_per_hour'] ?? 0), 'Wall capacity and abuse limits should be saved.');

    $missing_terms = $repo::validate_launch_submission($wall_id, [
        'title' => 'Missing terms',
        'submitter_email' => 'fixture@example.test',
        'launch_url' => 'https://example.test/missing-terms',
    ]);
    $invalid_date = $repo::validate_launch_submission($wall_id, [
        'title' => 'Invalid date',
        'submitter_email' => 'fixture@example.test',
        'launch_url' => 'https://example.test/invalid-date',
        'launch_date' => '2026-02-31',
        'terms_accepted' => '1',
    ]);
    $invalid_scheme = $repo::validate_launch_submission($wall_id, [
        'title' => 'Invalid scheme',
        'submitter_email' => 'fixture@example.test',
        'launch_url' => 'javascript:alert(1)',
        'terms_accepted' => '1',
    ]);
    $assert(is_wp_error($missing_terms) && is_wp_error($invalid_date) && is_wp_error($invalid_scheme), 'Shared validation should reject missing terms, impossible dates, and unsafe URL schemes.');

    $invalid = new WP_REST_Request('POST', '/million-dollar-script/v1/launch-wall/launches');
    $invalid->set_param('wall_id', $wall_id);
    $invalid->set_param('title', 'Missing email');
    $invalid->set_param('launch_url', 'https://example.test/launch');
    $invalid_result = $main->rest_create_launch($invalid);
    $assert(is_wp_error($invalid_result), 'REST submission without a valid email should fail.');

    $valid = new WP_REST_Request('POST', '/million-dollar-script/v1/launch-wall/launches');
    $valid->set_param('wall_id', $wall_id);
    $valid->set_param('title', 'Fixture Launch');
    $valid->set_param('submitter_email', 'fixture@example.test');
    $valid->set_param('launch_url', 'https://example.test/launch');
    $valid->set_param('featured_requested', true);
    $valid->set_param('terms_accepted', true);
    $valid_result = $main->rest_create_launch($valid);
    $assert($valid_result instanceof WP_REST_Response && 201 === $valid_result->get_status(), 'Valid REST submission should return a created response.');
    $valid_data = $valid_result instanceof WP_REST_Response ? $valid_result->get_data() : [];
    $assert(!isset($valid_data['data']['submitter_email']) && !isset($valid_data['data']['payment_status']), 'REST create receipt should not echo private submitter or payment data.');
    $launch_id = absint($valid_data['data']['id'] ?? 0);
    $created_posts[] = $launch_id;
    $launch = $repo::launch($launch_id);
    $assert($launch_id > 0 && is_array($launch), 'Created launch should be readable.');
    $assert((float) ($launch['amount'] ?? 0) === 15.0, 'Featured launch amount should include base and featured price.');
    $assert((string) ($launch['currency'] ?? '') === $repo::default_currency(), 'Launch currency should use the effective site currency.');

    $repo::update_launch_status($launch_id, 'approve');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.' . random_int(10, 200);
    $vote_request = new WP_REST_Request('POST', '/million-dollar-script/v1/launch-wall/launches/' . $launch_id . '/vote');
    $vote_request->set_param('id', $launch_id);
    $vote_result = $main->rest_vote_launch($vote_request);
    $assert(!is_wp_error($vote_result), 'First vote should be accepted.');
    $vote_limited = $main->rest_vote_launch($vote_request);
    $assert(is_wp_error($vote_limited), 'Second immediate vote should be rate limited.');
    $assert(1 === absint($repo::launch($launch_id)['votes'] ?? 0), 'Atomic vote counter should retain the accepted vote exactly once.');

    $public_launch = $repo::public_launch_payload($repo::launch($launch_id));
    foreach (['submitter_name', 'submitter_email', 'payment_status', 'amount', 'currency', 'status'] as $private_key) {
        $assert(!array_key_exists($private_key, $public_launch), 'Public launch payload should omit ' . $private_key . '.');
    }
    $public_wall = $repo::public_wall_payload($repo::wall($wall_id));
    foreach (['contact_email', 'retention_days', 'payment_mode', 'submission_limit_per_hour', 'vote_limit_per_day'] as $private_key) {
        $assert(!array_key_exists($private_key, $public_wall), 'Public wall payload should omit ' . $private_key . '.');
    }

    for ($index = 0; $index < 25; $index++) {
        $paged_launch_id = wp_insert_post([
            'post_type' => $repo::LAUNCH_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Paged public launch ' . $index,
        ]);
        update_post_meta($paged_launch_id, '_mds_lw_wall_id', $wall_id);
        update_post_meta($paged_launch_id, '_mds_lw_status', 'approved');
        update_post_meta($paged_launch_id, '_mds_lw_payment_status', 'not_required');
        update_post_meta($paged_launch_id, '_mds_lw_amount', 0);
        update_post_meta($paged_launch_id, '_mds_lw_featured_approved', '0');
        update_post_meta($paged_launch_id, '_mds_lw_launch_date', '');
        update_post_meta($paged_launch_id, '_mds_lw_launch_url', 'https://example.test/paged-' . $index);
        $created_posts[] = $paged_launch_id;
    }
    $public_page_one = $repo::launches_page($wall_id, 1, 24, true);
    $public_page_two = $repo::launches_page($wall_id, 2, 24, true);
    $assert(26 === absint($public_page_one['total']) && 2 === absint($public_page_one['pages']) && 24 === count($public_page_one['items']) && 2 === count($public_page_two['items']), 'Public launch pagination should return accurate totals beyond one page.');

    $provider_wall_id = $repo::save_wall([
        'title' => 'Fixture Provider Launch Wall ' . wp_generate_password(6, false, false),
        'slug' => 'fixture-provider-launch-wall-' . wp_generate_password(6, false, false),
        'description' => 'Fixture provider launch wall.',
        'status' => 'active',
        'currency' => $repo::default_currency(),
        'base_price' => 10,
        'featured_price' => 0,
        'payment_mode' => 'provider',
        'submission_enabled' => '1',
        'voting_enabled' => '0',
        'disclosure' => 'Provider publication fixture.',
        'contact_email' => 'fixture-admin@example.test',
        'retention_days' => 30,
    ]);
    $created_posts[] = $provider_wall_id;

    $moderated_first_id = $repo::create_launch($provider_wall_id, [
        'title' => 'Moderated first launch',
        'description' => 'Must remain private before payment.',
        'submitter_email' => 'fixture@example.test',
        'launch_url' => 'https://example.test/moderated-first',
        'terms_accepted' => '1',
    ]);
    $created_posts[] = $moderated_first_id;
    $repo::update_launch_status($moderated_first_id, 'approve');
    $assert('pending' === get_post_status($moderated_first_id), 'Provider-pending launch should remain private after moderation.');
    $assert([] === $repo::launches($provider_wall_id, ['public_only' => true]), 'Provider-pending launch should not be returned publicly.');
    $repo::update_payment_status($moderated_first_id, 'paid');
    $assert('publish' === get_post_status($moderated_first_id), 'Moderated launch should publish after payment.');
    $repo::update_payment_status($moderated_first_id, 'refunded');
    $assert('pending' === get_post_status($moderated_first_id), 'Refunded launch should be removed from public display.');

    $paid_first_id = $repo::create_launch($provider_wall_id, [
        'title' => 'Paid first launch',
        'description' => 'Must remain private before moderation.',
        'submitter_email' => 'fixture@example.test',
        'launch_url' => 'https://example.test/paid-first',
        'terms_accepted' => '1',
    ]);
    $created_posts[] = $paid_first_id;
    $repo::update_payment_status($paid_first_id, 'paid');
    $assert('pending' === get_post_status($paid_first_id), 'Payment should not publish an unmoderated launch.');
    $repo::update_launch_status($paid_first_id, 'approve');
    $assert('publish' === get_post_status($paid_first_id), 'Paid launch should publish after moderation.');

    $provider_wall = $repo::wall($provider_wall_id);
    $repo::save_wall(array_merge($provider_wall, ['status' => 'paused']), $provider_wall_id);
    $assert(null === $repo::public_wall_payload($repo::wall($provider_wall_id)), 'Paused walls should not have a public API payload.');
    $assert(!$repo::is_launch_public($paid_first_id) && 0 === absint($repo::launches_page($provider_wall_id, 1, 24, true)['total']), 'Paused walls should hide previously approved launches and disable voting eligibility.');
    $repo::save_wall(array_merge($provider_wall, ['status' => 'active']), $provider_wall_id);

    $manifest = $main->api_endpoint_manifest([]);
    $endpoint_ids = array_map(static function ($endpoint) {
        return (string) ($endpoint['id'] ?? '');
    }, $manifest);
    $assert(in_array('launch-wall-launches-vote', $endpoint_ids, true), 'Vote endpoint should be included in the MDS API manifest.');

    $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
    $erasers = apply_filters('wp_privacy_personal_data_erasers', []);
    $assert(is_callable($exporters['mds-launch-wall']['callback'] ?? null), 'Launch Wall should register a personal-data exporter.');
    $assert(is_callable($erasers['mds-launch-wall']['callback'] ?? null), 'Launch Wall should register a personal-data eraser.');

    $privacy_email = 'launch-wall-privacy-' . wp_generate_password(8, false, false) . '@example.test';
    $privacy_launch_id = $repo::create_launch($wall_id, [
        'title' => 'Privacy Fixture Launch',
        'description' => 'Private launch description.',
        'submitter_name' => 'Private Submitter',
        'submitter_email' => $privacy_email,
        'launch_url' => 'https://example.test/private-launch',
        'logo_url' => 'https://example.test/private-logo.png',
        'tagline' => 'Private launch tagline.',
        'terms_accepted' => '1',
    ]);
    $created_posts[] = $privacy_launch_id;
    $privacy_payment_status = (string) ($repo::launch($privacy_launch_id)['payment_status'] ?? '');

    for ($index = 0; $index < 55; $index++) {
        $privacy_paged_id = wp_insert_post([
            'post_type' => $repo::LAUNCH_POST_TYPE,
            'post_status' => 'draft',
            'post_title' => 'Paged private launch ' . $index,
            'post_content' => 'Private paged description.',
        ]);
        update_post_meta($privacy_paged_id, '_mds_lw_wall_id', $wall_id);
        update_post_meta($privacy_paged_id, '_mds_lw_status', 'rejected');
        update_post_meta($privacy_paged_id, '_mds_lw_submitter_name', 'Private Paged Submitter');
        update_post_meta($privacy_paged_id, '_mds_lw_submitter_email', $privacy_email);
        update_post_meta($privacy_paged_id, '_mds_lw_launch_url', 'https://example.test/private-' . $index);
        $created_posts[] = $privacy_paged_id;
    }

    $export = $main->privacy_exporter($privacy_email, 1);
    $export_page_two = $main->privacy_exporter($privacy_email, 2);
    $assert(empty($export['done']) && 50 === count($export['data'] ?? []) && !empty($export_page_two['done']) && 6 === count($export_page_two['data'] ?? []), 'Launch Wall privacy export should include every matching paged submission.');
    $assert(empty($main->privacy_exporter('not-' . $privacy_email, 1)['data']), 'Launch Wall privacy export should ignore unrelated email addresses.');
    $assert(!empty($main->privacy_exporter($privacy_email, 2)['done']), 'Launch Wall privacy export should complete on an empty later page.');

    $erasure = $main->privacy_eraser($privacy_email, 1);
    $second_erasure = $main->privacy_eraser($privacy_email, 2);
    $erased_launch = $repo::launch($privacy_launch_id);
    $assert(empty($erasure['done']) && !empty($second_erasure['done']) && !empty($erasure['items_removed']) && !empty($erasure['items_retained']), 'Launch Wall privacy erasure should continue until every matching page is anonymized.');
    $assert('' === (string) ($erased_launch['submitter_email'] ?? '') && 'Anonymous submitter' === (string) ($erased_launch['submitter_name'] ?? ''), 'Launch Wall privacy erasure should anonymize submitter identity.');
    $assert('' === (string) ($erased_launch['description'] ?? '') && '' === (string) ($erased_launch['launch_url'] ?? '') && '' === (string) ($erased_launch['logo_url'] ?? '') && '' === (string) ($erased_launch['tagline'] ?? ''), 'Launch Wall privacy erasure should clear personal content and URLs.');
    $assert($privacy_payment_status === (string) ($erased_launch['payment_status'] ?? '') && 'pending_review' === (string) ($erased_launch['status'] ?? ''), 'Launch Wall privacy erasure should preserve payment and moderation state.');
    $repeat_erasure = $main->privacy_eraser($privacy_email, 1);
    $assert(!empty($repeat_erasure['done']) && empty($repeat_erasure['items_removed']) && empty($repeat_erasure['items_retained']), 'Launch Wall privacy erasure should be idempotent.');

    $retention_email = 'launch-wall-retention-' . wp_generate_password(8, false, false) . '@example.test';
    $retention_launch_id = $repo::create_launch($wall_id, [
        'title' => 'Retention Fixture Launch',
        'submitter_name' => 'Retention Submitter',
        'submitter_email' => $retention_email,
        'launch_url' => 'https://example.test/retention-launch',
        'description' => 'Stale private content.',
        'terms_accepted' => '1',
    ]);
    $created_posts[] = $retention_launch_id;
    $repo::update_launch_status($retention_launch_id, 'reject');
    update_post_meta($retention_launch_id, '_mds_lw_private_data_updated_at', strtotime('2020-01-01 00:00:00 UTC'));
    $assert($repo::cleanup_wall_private_data($wall_id, 30) > 0 && '' === (string) ($repo::launch($retention_launch_id)['submitter_email'] ?? ''), 'Configured retention should anonymize stale rejected launch data.');
    $assert(wp_next_scheduled(\MillionDollarScript\Extensions\LaunchWall\Retention::HOOK), 'Launch Wall retention should be scheduled.');
} finally {
    foreach (array_filter(array_map('absint', $created_posts)) as $post_id) {
        wp_delete_post($post_id, true);
    }
}

echo wp_json_encode([
    'status' => $failures ? 'failed' : 'passed',
    'failures' => $failures,
]) . "\n";
