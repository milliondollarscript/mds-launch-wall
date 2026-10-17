<?php
/** Launch Wall uninstall cleanup. */
if (!defined('WP_UNINSTALL_PLUGIN') || !class_exists('\\MillionDollarScript\\Extensions\\CleanupPolicy')) { exit; }
\MillionDollarScript\Extensions\CleanupPolicy::cleanup('mds-launch-wall', [
    'post_types' => ['mds_launch_wall', 'mds_launch_item'],
    'option_prefixes' => ['mds_launch_wall_'],
    'transient_prefixes' => ['mds_launch_wall_'],
]);
