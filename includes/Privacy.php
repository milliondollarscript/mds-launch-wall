<?php
/**
 * WordPress privacy export and erasure integration.
 *
 * @package MillionDollarScript\Extensions\LaunchWall
 */

namespace MillionDollarScript\Extensions\LaunchWall;

if (!defined('ABSPATH')) {
    exit;
}

final class Privacy {

    private const PAGE_SIZE = 50;

    public static function exporter($email_address, $page = 1) {
        $page = max(1, absint($page));
        $records = Repository::launches_by_email_page($email_address, $page, self::PAGE_SIZE);
        $items = [];

        foreach ($records['items'] as $launch) {
            $items[] = [
                'group_id' => 'mds-launch-wall',
                'group_label' => __('Launch Wall submissions', 'mds-launch-wall'),
                'item_id' => 'mds-launch-wall-' . absint($launch['id'] ?? 0),
                'data' => [
                    ['name' => __('Launch name', 'mds-launch-wall'), 'value' => (string) ($launch['title'] ?? '')],
                    ['name' => __('Description', 'mds-launch-wall'), 'value' => (string) ($launch['description'] ?? '')],
                    ['name' => __('Submitter name', 'mds-launch-wall'), 'value' => (string) ($launch['submitter_name'] ?? '')],
                    ['name' => __('Email', 'mds-launch-wall'), 'value' => (string) ($launch['submitter_email'] ?? '')],
                    ['name' => __('Launch URL', 'mds-launch-wall'), 'value' => (string) ($launch['launch_url'] ?? '')],
                    ['name' => __('Logo URL', 'mds-launch-wall'), 'value' => (string) ($launch['logo_url'] ?? '')],
                    ['name' => __('Tagline', 'mds-launch-wall'), 'value' => (string) ($launch['tagline'] ?? '')],
                    ['name' => __('Category', 'mds-launch-wall'), 'value' => (string) ($launch['category'] ?? '')],
                    ['name' => __('Launch date', 'mds-launch-wall'), 'value' => (string) ($launch['launch_date'] ?? '')],
                    ['name' => __('Moderation status', 'mds-launch-wall'), 'value' => (string) ($launch['status'] ?? '')],
                    ['name' => __('Payment status', 'mds-launch-wall'), 'value' => (string) ($launch['payment_status'] ?? '')],
                    ['name' => __('Amount', 'mds-launch-wall'), 'value' => (string) ($launch['amount'] ?? '')],
                    ['name' => __('Currency', 'mds-launch-wall'), 'value' => (string) ($launch['currency'] ?? '')],
                    ['name' => __('Created', 'mds-launch-wall'), 'value' => (string) ($launch['created_at'] ?? '')],
                ],
            ];
        }

        return ['data' => $items, 'done' => $page >= absint($records['pages'] ?? 1)];
    }

    public static function eraser($email_address, $page = 1) {
        unset($page);
        $records = Repository::launches_by_email_page($email_address, 1, self::PAGE_SIZE);
        $removed = 0;
        foreach ($records['items'] as $launch) {
            if (Repository::anonymize_launch(absint($launch['id'] ?? 0))) {
                $removed++;
            }
        }
        $remaining = Repository::launches_by_email_page($email_address, 1, 1);

        return [
            'items_removed' => $removed > 0,
            'items_retained' => $removed > 0,
            'messages' => $removed > 0 ? [__('Personal contact, message, and destination fields were erased. Non-identifying payment and moderation records were retained for accounting, fraud prevention, and dispute handling.', 'mds-launch-wall')] : [],
            'done' => empty($remaining['items']),
        ];
    }
}
