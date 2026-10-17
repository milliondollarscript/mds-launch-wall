<?php
/**
 * Launch Wall local repository.
 *
 * @package MillionDollarScript\Extensions\LaunchWall
 */

namespace MillionDollarScript\Extensions\LaunchWall;

if (!defined('ABSPATH')) {
    exit;
}

final class Repository {

    public const WALL_POST_TYPE = 'mds_launch_wall';
    public const LAUNCH_POST_TYPE = 'mds_launch_item';

    public static function maybe_seed() {
        if (self::walls(1)) {
            return;
        }

        self::save_wall([
            'title' => __('Launch Wall', 'mds-launch-wall'),
            'slug' => 'launch-wall',
            'description' => __('Submit a launch for review and featured placement.', 'mds-launch-wall'),
            'status' => 'active',
            'currency' => self::default_currency(),
            'base_price' => 0,
            'featured_price' => 49,
            'launch_limit' => 100,
            'payment_mode' => 'manual',
            'submission_enabled' => '1',
            'voting_enabled' => '0',
            'disclosure' => __('Featured launches may be paid placements.', 'mds-launch-wall'),
            'terms_url' => '',
            'contact_email' => get_option('admin_email'),
            'retention_days' => 365,
            'submission_limit_per_hour' => 10,
            'vote_limit_per_day' => 1,
        ]);
    }

    public static function save_wall(array $data, $wall_id = 0) {
        $wall_id = absint($wall_id);
        $title = self::bounded_text($data['title'] ?? '', 160);
        $contact_email = sanitize_email((string) ($data['contact_email'] ?? get_option('admin_email')));
        if ('' === $title || !is_email($contact_email) || ($wall_id && !self::wall($wall_id))) {
            return 0;
        }

        $postarr = [
            'post_type' => self::WALL_POST_TYPE,
            'post_title' => $title,
            'post_status' => 'publish',
        ];
        if ($wall_id) {
            $postarr['ID'] = $wall_id;
            $result = wp_update_post($postarr, true);
        } else {
            $result = wp_insert_post($postarr, true);
        }

        if (is_wp_error($result) || !$result) {
            return 0;
        }

        $wall_id = absint($result);
        $slug = sanitize_title((string) ($data['slug'] ?? $title));
        $status = sanitize_key((string) ($data['status'] ?? 'draft'));
        if (!in_array($status, ['draft', 'active', 'paused', 'archived'], true)) {
            $status = 'draft';
        }
        $payment_mode = sanitize_key((string) ($data['payment_mode'] ?? 'manual'));
        if (!in_array($payment_mode, ['manual', 'provider'], true)) {
            $payment_mode = 'manual';
        }

        $meta = [
            '_mds_lw_slug' => $slug ?: 'launch-wall-' . $wall_id,
            '_mds_lw_description' => self::bounded_textarea($data['description'] ?? '', 4000),
            '_mds_lw_status' => $status,
            '_mds_lw_currency' => self::effective_currency($data['currency'] ?? self::default_currency()),
            '_mds_lw_base_price' => self::money($data['base_price'] ?? 0),
            '_mds_lw_featured_price' => self::money($data['featured_price'] ?? 0),
            '_mds_lw_launch_limit' => max(1, absint($data['launch_limit'] ?? 100)),
            '_mds_lw_payment_mode' => $payment_mode,
            '_mds_lw_submission_enabled' => !empty($data['submission_enabled']) ? '1' : '0',
            '_mds_lw_voting_enabled' => !empty($data['voting_enabled']) ? '1' : '0',
            '_mds_lw_disclosure' => self::bounded_textarea($data['disclosure'] ?? '', 4000),
            '_mds_lw_terms_url' => self::http_url($data['terms_url'] ?? ''),
            '_mds_lw_contact_email' => $contact_email,
            '_mds_lw_retention_days' => max(1, absint($data['retention_days'] ?? 365)),
            '_mds_lw_submission_limit_per_hour' => max(1, min(500, absint($data['submission_limit_per_hour'] ?? 10))),
            '_mds_lw_vote_limit_per_day' => max(1, min(50, absint($data['vote_limit_per_day'] ?? 1))),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($wall_id, $key, $value);
        }

        return $wall_id;
    }

    public static function wall($wall_id) {
        $post = get_post(absint($wall_id));
        if (!$post || self::WALL_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::wall_payload($post);
    }

    public static function wall_by_slug($slug) {
        $slug = sanitize_title((string) $slug);
        if (!$slug) {
            return null;
        }

        $posts = get_posts([
            'post_type' => self::WALL_POST_TYPE,
            'post_status' => 'publish',
            'meta_key' => '_mds_lw_slug',
            'meta_value' => $slug,
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);

        return $posts ? self::wall_payload($posts[0]) : null;
    }

    public static function walls($limit = 50) {
        $posts = get_posts([
            'post_type' => self::WALL_POST_TYPE,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => min(100, max(1, absint($limit))),
            'no_found_rows' => true,
        ]);

        return array_map([__CLASS__, 'wall_payload'], $posts);
    }

    public static function walls_page($page = 1, $per_page = 20, $active_only = false) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $args = [
            'post_type' => self::WALL_POST_TYPE,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ];
        if ($active_only) {
            $args['meta_query'] = [['key' => '_mds_lw_status', 'value' => 'active', 'compare' => '=']];
        }

        return self::collection(new \WP_Query($args), [__CLASS__, 'wall_payload'], $page, $per_page);
    }

    public static function create_launch($wall_id, array $data) {
        $wall_id = absint($wall_id);
        $validation = self::validate_launch_submission($wall_id, $data);
        if (is_wp_error($validation) || !self::acquire_wall_lock($wall_id)) {
            return 0;
        }

        $wall = self::wall($wall_id);
        if (!$wall || !self::has_open_launch_slots($wall_id)) {
            self::release_wall_lock($wall_id);
            return 0;
        }

        $title = self::bounded_text($data['title'] ?? '', 120);

        $post_id = wp_insert_post([
            'post_type' => self::LAUNCH_POST_TYPE,
            'post_title' => $title,
            'post_content' => self::bounded_textarea($data['description'] ?? '', 10000),
            'post_status' => 'pending',
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            self::release_wall_lock($wall_id);
            return 0;
        }

        $post_id = absint($post_id);
        $featured = !empty($data['featured_requested']) ? '1' : '0';
        $amount = (float) ($wall['base_price'] ?? 0) + ('1' === $featured ? (float) ($wall['featured_price'] ?? 0) : 0);
        $meta = [
            '_mds_lw_wall_id' => absint($wall_id),
            '_mds_lw_submitter_name' => self::bounded_text($data['submitter_name'] ?? '', 80),
            '_mds_lw_submitter_email' => sanitize_email((string) ($data['submitter_email'] ?? '')),
            '_mds_lw_launch_url' => self::http_url($data['launch_url'] ?? ''),
            '_mds_lw_category' => self::bounded_text($data['category'] ?? '', 80),
            '_mds_lw_tagline' => self::bounded_text($data['tagline'] ?? '', 140),
            '_mds_lw_launch_date' => self::normalize_date($data['launch_date'] ?? ''),
            '_mds_lw_logo_url' => self::http_url($data['logo_url'] ?? ''),
            '_mds_lw_featured_requested' => $featured,
            '_mds_lw_featured_approved' => '0',
            '_mds_lw_status' => 'pending_review',
            '_mds_lw_payment_status' => $amount > 0 ? 'requested' : 'not_required',
            '_mds_lw_amount' => self::money($amount),
            '_mds_lw_currency' => self::effective_currency($wall['currency'] ?? self::default_currency()),
            '_mds_lw_votes' => 0,
            '_mds_lw_terms_accepted' => '1',
            '_mds_lw_private_data_updated_at' => time(),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        self::release_wall_lock($wall_id);

        return $post_id;
    }

    /**
     * @return true|\WP_Error
     */
    public static function validate_launch_submission($wall_id, array $data) {
        $wall = self::wall(absint($wall_id));
        $title = self::bounded_text($data['title'] ?? '', 120);
        $email = sanitize_email((string) ($data['submitter_email'] ?? ''));
        $launch_url = trim((string) ($data['launch_url'] ?? ''));
        $logo_url = trim((string) ($data['logo_url'] ?? ''));
        $launch_date = trim((string) ($data['launch_date'] ?? ''));

        if (!$wall || 'active' !== (string) ($wall['status'] ?? '') || empty($wall['submission_enabled'])) {
            return new \WP_Error('mds_launch_wall_closed', __('Launch submissions are closed.', 'mds-launch-wall'));
        }
        if (!self::has_open_launch_slots(absint($wall_id))) {
            return new \WP_Error('mds_launch_wall_full', __('This launch wall has reached its capacity.', 'mds-launch-wall'));
        }
        if ('' === $title || !is_email($email) || '' === self::http_url($launch_url)) {
            return new \WP_Error('mds_launch_wall_invalid_submission', __('Launch name, valid email, and an HTTP or HTTPS launch URL are required.', 'mds-launch-wall'));
        }
        if (empty($data['terms_accepted'])) {
            return new \WP_Error('mds_launch_wall_terms_required', __('Accept the placement terms and sponsorship disclosure before submitting.', 'mds-launch-wall'));
        }
        if ('' !== $logo_url && '' === self::http_url($logo_url)) {
            return new \WP_Error('mds_launch_wall_invalid_logo_url', __('Use a valid HTTP or HTTPS logo URL.', 'mds-launch-wall'));
        }
        if ('' !== $launch_date && !self::valid_date($launch_date)) {
            return new \WP_Error('mds_launch_wall_invalid_date', __('Use a real calendar date for the launch date.', 'mds-launch-wall'));
        }

        return true;
    }

    public static function launch($launch_id) {
        $post = get_post(absint($launch_id));
        if (!$post || self::LAUNCH_POST_TYPE !== $post->post_type) {
            return null;
        }

        return self::launch_payload($post);
    }

    public static function launches($wall_id, array $args = []) {
        $statuses = !empty($args['public_only']) ? ['publish'] : ['publish', 'pending', 'draft'];
        $posts = get_posts([
            'post_type' => self::LAUNCH_POST_TYPE,
            'post_status' => $statuses,
            'meta_key' => '_mds_lw_wall_id',
            'meta_value' => absint($wall_id),
            'posts_per_page' => min(100, max(1, absint($args['limit'] ?? 50))),
            'no_found_rows' => true,
        ]);

        $launches = array_map([__CLASS__, 'launch_payload'], $posts);
        if (!empty($args['public_only'])) {
            $launches = array_values(array_filter($launches, function ($launch) {
                return 'approved' === (string) ($launch['status'] ?? '')
                    && self::payment_allows_publication($launch);
            }));
        }
        usort($launches, function ($a, $b) {
            $a_featured = !empty($a['featured_approved']) ? 1 : 0;
            $b_featured = !empty($b['featured_approved']) ? 1 : 0;
            if ($a_featured !== $b_featured) {
                return $b_featured <=> $a_featured;
            }

            return strcmp((string) ($a['launch_date'] ?? ''), (string) ($b['launch_date'] ?? ''));
        });

        return $launches;
    }

    public static function launches_page($wall_id, $page = 1, $per_page = 20, $public_only = false) {
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        $wall = self::wall($wall_id);
        if (!$wall || ($public_only && 'active' !== (string) ($wall['status'] ?? ''))) {
            return self::empty_collection($page, $per_page);
        }

        $meta_query = [
            'wall_clause' => ['key' => '_mds_lw_wall_id', 'value' => absint($wall_id), 'compare' => '='],
            'featured_clause' => ['key' => '_mds_lw_featured_approved', 'compare' => 'EXISTS'],
            'launch_date_clause' => ['key' => '_mds_lw_launch_date', 'compare' => 'EXISTS'],
        ];
        if ($public_only) {
            $meta_query['status_clause'] = ['key' => '_mds_lw_status', 'value' => 'approved', 'compare' => '='];
            if ('provider' === (string) ($wall['payment_mode'] ?? 'manual')) {
                $meta_query['payment_clause'] = [
                    'relation' => 'OR',
                    ['key' => '_mds_lw_amount', 'value' => 0, 'type' => 'NUMERIC', 'compare' => '<='],
                    ['key' => '_mds_lw_payment_status', 'value' => ['not_required', 'paid', 'completed', 'processing'], 'compare' => 'IN'],
                ];
            }
        }
        $query = new \WP_Query([
            'post_type' => self::LAUNCH_POST_TYPE,
            'post_status' => $public_only ? 'publish' : ['publish', 'pending', 'draft'],
            'meta_query' => $meta_query,
            'orderby' => ['featured_clause' => 'DESC', 'launch_date_clause' => 'DESC', 'date' => 'DESC'],
            'posts_per_page' => $per_page,
            'paged' => $page,
            'no_found_rows' => false,
        ]);

        return self::collection($query, [__CLASS__, 'launch_payload'], $page, $per_page);
    }

    public static function launches_by_email($email_address, $page = 1, $per_page = 100) {
        return self::launches_by_email_page($email_address, $page, $per_page)['items'];
    }

    public static function launches_by_email_page($email_address, $page = 1, $per_page = 50) {
        $email = sanitize_email((string) $email_address);
        $page = max(1, absint($page));
        $per_page = min(100, max(1, absint($per_page)));
        if (!is_email($email)) {
            return self::empty_collection($page, $per_page);
        }

        $query = new \WP_Query([
            'post_type' => self::LAUNCH_POST_TYPE,
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_key' => '_mds_lw_submitter_email',
            'meta_value' => $email,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
        ]);

        return self::collection($query, [__CLASS__, 'launch_payload'], $page, $per_page);
    }

    public static function anonymize_launch($launch_id) {
        $launch = self::launch($launch_id);
        if (!$launch) {
            return false;
        }

        $updated = wp_update_post([
            'ID' => absint($launch_id),
            'post_content' => '',
        ], true);
        if (is_wp_error($updated)) {
            return false;
        }

        update_post_meta(absint($launch_id), '_mds_lw_submitter_name', __('Anonymous submitter', 'mds-launch-wall'));
        update_post_meta(absint($launch_id), '_mds_lw_submitter_email', '');
        update_post_meta(absint($launch_id), '_mds_lw_launch_url', '');
        update_post_meta(absint($launch_id), '_mds_lw_logo_url', '');
        update_post_meta(absint($launch_id), '_mds_lw_tagline', '');
        update_post_meta(absint($launch_id), '_mds_lw_personal_data_erased_at', current_time('mysql', true));
        update_post_meta(absint($launch_id), '_mds_lw_private_data_updated_at', time());
        delete_post_meta(absint($launch_id), '_mds_lw_manage_token_hash');

        return true;
    }

    public static function update_launch_status($launch_id, $status) {
        $launch = self::launch($launch_id);
        if (!$launch) {
            return false;
        }

        $status = sanitize_key((string) $status);
        if ('approve' === $status) {
            update_post_meta($launch_id, '_mds_lw_status', 'approved');
            update_post_meta($launch_id, '_mds_lw_featured_approved', !empty($launch['featured_requested']) ? '1' : '0');
            update_post_meta($launch_id, '_mds_lw_private_data_updated_at', time());
            self::confirm_manual_payment($launch);
            self::reconcile_publication($launch_id);
            return true;
        }
        if ('reject' === $status) {
            wp_update_post(['ID' => absint($launch_id), 'post_status' => 'draft']);
            update_post_meta($launch_id, '_mds_lw_status', 'rejected');
            update_post_meta($launch_id, '_mds_lw_featured_approved', '0');
            update_post_meta($launch_id, '_mds_lw_private_data_updated_at', time());
            return true;
        }
        if ('feature' === $status) {
            update_post_meta($launch_id, '_mds_lw_featured_approved', '1');
            return true;
        }
        if ('unfeature' === $status) {
            update_post_meta($launch_id, '_mds_lw_featured_approved', '0');
            return true;
        }
        if ('trash' === $status) {
            wp_trash_post(absint($launch_id));
            return true;
        }

        return false;
    }

    public static function update_payment_status($launch_id, $status) {
        $launch = self::launch($launch_id);
        if (!$launch) {
            return false;
        }

        $status = sanitize_key((string) $status);
        if (!in_array($status, ['requested', 'pending', 'processing', 'paid', 'completed', 'failed', 'cancelled', 'expired', 'refunded', 'not_required'], true)) {
            return false;
        }

        update_post_meta(absint($launch_id), '_mds_lw_payment_status', $status);
        update_post_meta(absint($launch_id), '_mds_lw_private_data_updated_at', time());
        self::reconcile_publication($launch_id);
        return true;
    }

    private static function confirm_manual_payment(array $launch) {
        $wall = self::wall(absint($launch['wall_id'] ?? 0));
        if (!$wall || 'manual' !== (string) ($wall['payment_mode'] ?? 'manual')) {
            return;
        }

        $payment_status = sanitize_key((string) ($launch['payment_status'] ?? ''));
        if ((float) ($launch['amount'] ?? 0) > 0 && !in_array($payment_status, ['paid', 'completed', 'processing'], true)) {
            update_post_meta(absint($launch['id'] ?? 0), '_mds_lw_payment_status', 'paid');
        }
    }

    private static function reconcile_publication($launch_id) {
        $launch = self::launch($launch_id);
        if (!$launch || 'approved' !== (string) ($launch['status'] ?? '')) {
            return;
        }

        wp_update_post([
            'ID' => absint($launch_id),
            'post_status' => self::payment_allows_publication($launch) ? 'publish' : 'pending',
        ]);
    }

    private static function payment_allows_publication(array $launch) {
        $subscription_end_value = trim((string) ($launch['subscription_expires_at'] ?? ''));
        $subscription_end = $subscription_end_value ? strtotime($subscription_end_value . ' UTC') : false;
        if (false !== $subscription_end && $subscription_end <= time()) {
            return false;
        }

        if ((float) ($launch['amount'] ?? 0) <= 0) {
            return true;
        }

        $status = sanitize_key((string) ($launch['payment_status'] ?? ''));
        if (in_array($status, ['not_required', 'paid', 'completed', 'processing'], true)) {
            return true;
        }

        $wall = self::wall(absint($launch['wall_id'] ?? 0));

        return $wall && 'manual' === (string) ($wall['payment_mode'] ?? 'manual');
    }

    public static function is_launch_public($launch_id) {
        $launch = self::launch($launch_id);
        $wall = $launch ? self::wall(absint($launch['wall_id'] ?? 0)) : null;

        return $launch
            && $wall
            && 'active' === (string) ($wall['status'] ?? '')
            && 'approved' === (string) ($launch['status'] ?? '')
            && 'publish' === get_post_status(absint($launch_id))
            && self::payment_allows_publication($launch);
    }

    public static function increment_vote($launch_id) {
        $launch = self::launch($launch_id);
        if (!$launch) {
            return 0;
        }

        global $wpdb;
        add_post_meta(absint($launch_id), '_mds_lw_votes', 0, true);
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
            absint($launch_id),
            '_mds_lw_votes'
        ));
        if (!$updated) {
            return 0;
        }

        wp_cache_delete(absint($launch_id), 'post_meta');

        return absint(get_post_meta(absint($launch_id), '_mds_lw_votes', true));
    }

    public static function has_open_launch_slots($wall_id) {
        $wall = self::wall($wall_id);

        return $wall && self::used_launch_slots($wall_id) < absint($wall['launch_limit'] ?? 100);
    }

    public static function used_launch_slots($wall_id) {
        $query = new \WP_Query([
            'post_type' => self::LAUNCH_POST_TYPE,
            'post_status' => ['publish', 'pending'],
            'meta_key' => '_mds_lw_wall_id',
            'meta_value' => absint($wall_id),
            'posts_per_page' => 1,
            'no_found_rows' => false,
        ]);

        return absint($query->found_posts);
    }

    public static function cleanup_wall_private_data($wall_id, $retention_days, $limit = 100) {
        $wall_id = absint($wall_id);
        $cutoff = time() - (max(1, absint($retention_days)) * DAY_IN_SECONDS);
        $limit = min(250, max(1, absint($limit)));
        $posts = self::retention_posts($wall_id, $cutoff, $limit);
        $changed = 0;

        foreach ($posts as $post) {
            $launch = self::launch_payload($post);
            $terminal = 'rejected' === (string) ($launch['status'] ?? '')
                || ('publish' !== $post->post_status && in_array((string) ($launch['payment_status'] ?? ''), ['failed', 'cancelled', 'expired', 'refunded'], true));
            if ($terminal && self::anonymize_launch($post->ID)) {
                $changed++;
            }
        }

        return $changed;
    }

    public static function wall_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'title' => get_the_title($post),
            'slug' => (string) get_post_meta($post->ID, '_mds_lw_slug', true),
            'description' => (string) get_post_meta($post->ID, '_mds_lw_description', true),
            'status' => (string) get_post_meta($post->ID, '_mds_lw_status', true),
            'currency' => (string) get_post_meta($post->ID, '_mds_lw_currency', true),
            'base_price' => (float) get_post_meta($post->ID, '_mds_lw_base_price', true),
            'featured_price' => (float) get_post_meta($post->ID, '_mds_lw_featured_price', true),
            'launch_limit' => absint(get_post_meta($post->ID, '_mds_lw_launch_limit', true)) ?: 100,
            'payment_mode' => (string) get_post_meta($post->ID, '_mds_lw_payment_mode', true),
            'submission_enabled' => '1' === (string) get_post_meta($post->ID, '_mds_lw_submission_enabled', true),
            'voting_enabled' => '1' === (string) get_post_meta($post->ID, '_mds_lw_voting_enabled', true),
            'disclosure' => (string) get_post_meta($post->ID, '_mds_lw_disclosure', true),
            'terms_url' => (string) get_post_meta($post->ID, '_mds_lw_terms_url', true),
            'contact_email' => (string) get_post_meta($post->ID, '_mds_lw_contact_email', true) ?: (string) get_option('admin_email'),
            'retention_days' => absint(get_post_meta($post->ID, '_mds_lw_retention_days', true)) ?: 365,
            'submission_limit_per_hour' => absint(get_post_meta($post->ID, '_mds_lw_submission_limit_per_hour', true)) ?: 10,
            'vote_limit_per_day' => absint(get_post_meta($post->ID, '_mds_lw_vote_limit_per_day', true)) ?: 1,
        ];
    }

    public static function public_wall_payload($wall) {
        $wall = is_array($wall) ? $wall : self::wall_payload($wall);
        if (!$wall || 'active' !== (string) ($wall['status'] ?? '')) {
            return null;
        }
        $wall['used_launches'] = self::used_launch_slots(absint($wall['id'] ?? 0));

        return array_intersect_key($wall, array_flip([
            'id',
            'title',
            'slug',
            'description',
            'currency',
            'base_price',
            'featured_price',
            'launch_limit',
            'used_launches',
            'submission_enabled',
            'voting_enabled',
            'disclosure',
            'terms_url',
        ]));
    }

    public static function launch_payload($post) {
        $post = is_object($post) ? $post : get_post(absint($post));
        if (!$post) {
            return null;
        }

        return [
            'id' => absint($post->ID),
            'wall_id' => absint(get_post_meta($post->ID, '_mds_lw_wall_id', true)),
            'title' => get_the_title($post),
            'description' => (string) $post->post_content,
            'status' => (string) get_post_meta($post->ID, '_mds_lw_status', true),
            'submitter_name' => (string) get_post_meta($post->ID, '_mds_lw_submitter_name', true),
            'submitter_email' => (string) get_post_meta($post->ID, '_mds_lw_submitter_email', true),
            'launch_url' => (string) get_post_meta($post->ID, '_mds_lw_launch_url', true),
            'category' => (string) get_post_meta($post->ID, '_mds_lw_category', true),
            'tagline' => (string) get_post_meta($post->ID, '_mds_lw_tagline', true),
            'launch_date' => (string) get_post_meta($post->ID, '_mds_lw_launch_date', true),
            'logo_url' => (string) get_post_meta($post->ID, '_mds_lw_logo_url', true),
            'featured_requested' => '1' === (string) get_post_meta($post->ID, '_mds_lw_featured_requested', true),
            'featured_approved' => '1' === (string) get_post_meta($post->ID, '_mds_lw_featured_approved', true),
            'payment_status' => (string) get_post_meta($post->ID, '_mds_lw_payment_status', true),
            'subscription_expires_at' => (string) get_post_meta($post->ID, '_mds_lw_subscription_expires_at', true),
            'amount' => (float) get_post_meta($post->ID, '_mds_lw_amount', true),
            'currency' => (string) get_post_meta($post->ID, '_mds_lw_currency', true),
            'votes' => absint(get_post_meta($post->ID, '_mds_lw_votes', true)),
            'created_at' => mysql_to_rfc3339($post->post_date_gmt ?: $post->post_date),
        ];
    }

    public static function public_launch_payload($launch) {
        $launch = is_array($launch) ? $launch : self::launch_payload($launch);
        if (!$launch) {
            return null;
        }
        $launch['sponsored'] = !empty($launch['featured_approved']) || (float) ($launch['amount'] ?? 0) > 0;

        return array_intersect_key($launch, array_flip([
            'id',
            'wall_id',
            'title',
            'description',
            'category',
            'tagline',
            'launch_date',
            'logo_url',
            'launch_url',
            'featured_approved',
            'sponsored',
            'votes',
            'created_at',
        ]));
    }

    private static function normalize_date($value) {
        $value = self::bounded_text($value, 10);

        return self::valid_date($value) ? $value : '';
    }

    public static function valid_date($value) {
        $value = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, wp_timezone());
        $errors = \DateTimeImmutable::getLastErrors();

        return false !== $date
            && (false === $errors || (0 === (int) ($errors['warning_count'] ?? 0) && 0 === (int) ($errors['error_count'] ?? 0)))
            && $date->format('Y-m-d') === $value;
    }

    private static function money($value) {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'amount')) {
            return \MillionDollarScript\Commerce\Currency::amount($value);
        }

        return round(max(0, (float) $value), 2);
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
            return \MillionDollarScript\Commerce\Currency::effective_code($value);
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

    private static function currency($value) {
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency') && method_exists('\\MillionDollarScript\\Commerce\\Currency', 'normalize_code')) {
            return \MillionDollarScript\Commerce\Currency::normalize_code($value, self::default_currency());
        }

        $code = strtoupper(substr(preg_replace('/[^A-Z]/', '', strtoupper(sanitize_text_field((string) $value))), 0, 3));

        return '' !== $code ? $code : 'USD';
    }

    private static function acquire_wall_lock($wall_id) {
        global $wpdb;

        return 1 === (int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 3)', 'mds_lw_wall_' . absint($wall_id)));
    }

    private static function release_wall_lock($wall_id) {
        global $wpdb;
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', 'mds_lw_wall_' . absint($wall_id)));
    }

    private static function retention_posts($wall_id, $cutoff, $limit) {
        $base_meta = [
            ['key' => '_mds_lw_wall_id', 'value' => absint($wall_id), 'compare' => '='],
            ['key' => '_mds_lw_submitter_email', 'value' => '', 'compare' => '!='],
        ];
        $common = [
            'post_type' => self::LAUNCH_POST_TYPE,
            'post_status' => ['publish', 'pending', 'draft'],
            'orderby' => 'date',
            'order' => 'ASC',
            'posts_per_page' => max(1, absint($limit)),
            'no_found_rows' => true,
        ];
        $modern = get_posts(array_merge($common, [
            'meta_query' => array_merge($base_meta, [[
                'key' => '_mds_lw_private_data_updated_at',
                'value' => absint($cutoff),
                'type' => 'NUMERIC',
                'compare' => '<=',
            ]]),
        ]));
        $remaining = max(0, absint($limit) - count($modern));
        if (!$remaining) {
            return $modern;
        }

        $legacy = get_posts(array_merge($common, [
            'meta_query' => array_merge($base_meta, [['key' => '_mds_lw_private_data_updated_at', 'compare' => 'NOT EXISTS']]),
            'date_query' => [['before' => gmdate('Y-m-d H:i:s', absint($cutoff)), 'inclusive' => true]],
            'posts_per_page' => $remaining,
        ]));

        return array_merge($modern, $legacy);
    }

    private static function collection(\WP_Query $query, $callback, $page, $per_page) {
        $total = absint($query->found_posts);

        return [
            'items' => array_values(array_filter(array_map($callback, $query->posts))),
            'page' => max(1, absint($page)),
            'per_page' => max(1, absint($per_page)),
            'total' => $total,
            'pages' => max(1, (int) ceil($total / max(1, absint($per_page)))),
        ];
    }

    private static function empty_collection($page, $per_page) {
        return ['items' => [], 'page' => max(1, absint($page)), 'per_page' => max(1, absint($per_page)), 'total' => 0, 'pages' => 1];
    }

    private static function http_url($value) {
        return esc_url_raw(self::bounded_raw($value, 2048), ['http', 'https']);
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
}
