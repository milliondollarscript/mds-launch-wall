<?php
/**
 * Scheduled private-data retention cleanup.
 *
 * @package MillionDollarScript\Extensions\LaunchWall
 */

namespace MillionDollarScript\Extensions\LaunchWall;

if (!defined('ABSPATH')) {
    exit;
}

final class Retention {

    public const HOOK = 'mds_launch_wall_retention';

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
        $page = 1;
        do {
            $walls = Repository::walls_page($page, 50);
            foreach ($walls['items'] as $wall) {
                $iterations = 0;
                do {
                    $changed = Repository::cleanup_wall_private_data(
                        absint($wall['id'] ?? 0),
                        max(1, absint($wall['retention_days'] ?? 365))
                    );
                    $iterations++;
                } while ($changed > 0 && $iterations < 20);
            }
            $page++;
        } while ($page <= absint($walls['pages'] ?? 1));
    }

    public static function schedule() {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::HOOK);
        }
    }
}
