<?php
/**
 * Launch Wall runtime, admin UI, frontend, and REST API.
 *
 * @package MillionDollarScript\Extensions\LaunchWall
 */

namespace MillionDollarScript\Extensions\LaunchWall;

if (!defined('ABSPATH')) {
    exit;
}

final class Main {

    public const REST_NAMESPACE = 'million-dollar-script/v1';
    public const REST_BASE = 'launch-wall';

    private static $instance;

    public static function instance() {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function register() {
        Retention::register();
        add_action('init', [__CLASS__, 'register_post_types'], 5);
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_shortcodes']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'frontend_assets']);
        add_action('admin_post_mds_launch_wall_save_wall', [$this, 'handle_save_wall']);
        add_action('admin_post_mds_launch_wall_moderate', [$this, 'handle_moderate_launch']);
        add_action('admin_post_mds_launch_wall_submit', [$this, 'handle_submit_launch']);
        add_action('admin_post_nopriv_mds_launch_wall_submit', [$this, 'handle_submit_launch']);
        add_action('admin_post_mds_launch_wall_vote', [$this, 'handle_vote_launch']);
        add_action('admin_post_nopriv_mds_launch_wall_vote', [$this, 'handle_vote_launch']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('million-dollar-script/payment/source/status', [$this, 'handle_payment_status'], 10, 4);
        add_action('million-dollar-script/commerce/source/activate', [$this, 'handle_subscription_term'], 10, 4);
        add_action('million-dollar-script/commerce/source/renew', [$this, 'handle_subscription_term'], 10, 4);
        add_filter('million-dollar-script/api/endpoint/manifest', [$this, 'api_endpoint_manifest']);
        add_filter('million-dollar-script/extension/onboarding/items', [$this, 'extension_onboarding_items']);
        add_filter('million-dollar-script/editor/extension/blocks', [$this, 'editor_blocks']);
        add_filter('wp_privacy_personal_data_exporters', [$this, 'privacy_exporters']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'privacy_erasers']);
    }

    public static function register_post_types() {
        register_post_type(Repository::WALL_POST_TYPE, [
            'label' => __('Launch Walls', 'mds-launch-wall'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);

        register_post_type(Repository::LAUNCH_POST_TYPE, [
            'label' => __('Launch Submissions', 'mds-launch-wall'),
            'public' => false,
            'show_ui' => false,
            'show_in_rest' => false,
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
        ]);
    }

    public function load_textdomain() {
        load_plugin_textdomain('mds-launch-wall', false, dirname(MDS_LAUNCH_WALL_BASENAME) . '/languages');
    }

    public function register_shortcodes() {
        add_shortcode('mds_launch_wall', [$this, 'shortcode']);
    }

    public function editor_blocks(array $blocks) {
        $blocks[] = [
            'name' => 'mds-launch-wall/wall',
            'title' => __('Launch Wall', 'mds-launch-wall'),
            'description' => __('Display a Million Dollar Script launch wall with optional public submissions.', 'mds-launch-wall'),
            'icon' => 'megaphone',
            'category' => 'widgets',
            'shortcode' => 'mds_launch_wall',
            'attributes' => [
                'id' => ['type' => 'number', 'default' => 0],
                'slug' => ['type' => 'string', 'default' => ''],
                'show_form' => ['type' => 'boolean', 'default' => true],
                'show_disclosure' => ['type' => 'boolean', 'default' => true],
            ],
            'controls' => [
                [
                    'attribute' => 'id',
                    'type' => 'entity',
                    'label' => __('Wall', 'mds-launch-wall'),
                    'help' => __('Choose a saved wall by numeric ID. Leave this on First active wall to let the block pick automatically, or use Custom wall ID only when you already know the ID.', 'mds-launch-wall'),
                    'empty_label' => __('First active wall', 'mds-launch-wall'),
                    'custom_label' => __('Custom wall ID', 'mds-launch-wall'),
                    'custom_help' => __('Enter the numeric database ID for a wall that is not shown in the list. This is different from the wall slug.', 'mds-launch-wall'),
                    'options' => $this->block_entity_options(Repository::walls(100), __('Untitled wall', 'mds-launch-wall')),
                ],
                [
                    'attribute' => 'slug',
                    'type' => 'text',
                    'label' => __('Wall slug', 'mds-launch-wall'),
                    'help' => __('Optional URL-safe wall slug used only when no specific wall ID is selected. Leave blank unless you want this block to find a wall by slug.', 'mds-launch-wall'),
                ],
                [
                    'attribute' => 'show_form',
                    'type' => 'toggle',
                    'label' => __('Show submission form', 'mds-launch-wall'),
                    'help' => __('Display the public launch submission form when submissions are enabled for this wall.', 'mds-launch-wall'),
                ],
                [
                    'attribute' => 'show_disclosure',
                    'type' => 'toggle',
                    'label' => __('Show disclosure text', 'mds-launch-wall'),
                    'help' => __('Display the sponsorship disclosure configured for this wall.', 'mds-launch-wall'),
                ],
            ],
            'preview' => [
                'title' => __('Launch Wall', 'mds-launch-wall'),
                'description' => __('Shows approved launches, featured placements, sponsor disclosures, and an optional submission form.', 'mds-launch-wall'),
                'rows' => [
                    __('Featured launch cards and launch links', 'mds-launch-wall'),
                    __('Optional public submission form', 'mds-launch-wall'),
                    __('Uses the selected wall, or a slug fallback when no wall is selected', 'mds-launch-wall'),
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
            'show_form' => $this->truthy($attributes['show_form'] ?? true) ? 'true' : 'false',
            'show_disclosure' => $this->truthy($attributes['show_disclosure'] ?? true) ? 'true' : 'false',
        ]);
    }

    public function admin_menu() {
        add_submenu_page(
            $this->admin_parent_slug(),
            __('Launch Wall', 'mds-launch-wall'),
            __('Launch Wall', 'mds-launch-wall'),
            'manage_options',
            'mds-launch-wall',
            [$this, 'admin_page']
        );
    }

    public function admin_assets($hook) {
        if (false === strpos((string) $hook, 'mds-launch-wall')) {
            return;
        }

        if (class_exists('\MillionDollarScript\Core\Runtime')) {
            wp_enqueue_style('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'css/admin.css', [], \MillionDollarScript\Core\Runtime::version());
            wp_enqueue_script('wp-color-picker');
            wp_enqueue_script('million-dollar-script-admin', \MillionDollarScript\Core\Runtime::url('assets/mds3/') . 'js/admin.js', ['wp-color-picker'], \MillionDollarScript\Core\Runtime::version(), true);
        }
        wp_enqueue_style('mds-launch-wall-admin', MDS_LAUNCH_WALL_URL . 'assets/css/admin.css', [], MDS_LAUNCH_WALL_VERSION);
    }

    public function extension_onboarding_items(array $items) {
        $items['mds-launch-wall'] = [
            'name' => __('Launch Wall', 'mds-launch-wall'),
            'summary' => __('Configure launch submissions, featured pricing, disclosure copy, and moderation before accepting paid placements.', 'mds-launch-wall'),
            'priority' => 20,
            'actions' => [
                [
                    'label' => __('Open Launch Wall', 'mds-launch-wall'),
                    'url' => admin_url('admin.php?page=mds-launch-wall'),
                    'primary' => true,
                ],
            ],
            'legal_documents' => [
                [
                    'slug' => 'sponsorship-terms',
                    'title' => __('Launch Wall Sponsorship Terms', 'mds-launch-wall'),
                    'description' => __('Terms for paid launch submissions, featured placement, moderation, refunds, and removal.', 'mds-launch-wall'),
                    'page_slug' => 'launch-wall-sponsorship-terms',
                    'content' => $this->launch_wall_sponsorship_terms_content(),
                ],
                [
                    'slug' => 'submission-privacy-policy',
                    'title' => __('Launch Wall Submission Privacy Policy', 'mds-launch-wall'),
                    'description' => __('Privacy language for submitted launch details, contact information, voting, and moderation records.', 'mds-launch-wall'),
                    'page_slug' => 'launch-wall-submission-privacy-policy',
                    'content' => $this->launch_wall_privacy_policy_content(),
                ],
            ],
        ];

        return $items;
    }

    private function launch_wall_sponsorship_terms_content() {
        return '<h2>' . esc_html__('Launch Wall Sponsorship Terms', 'mds-launch-wall') . '</h2>'
            . '<p>' . esc_html__('These terms apply to launches, projects, products, services, offers, and sponsor entries submitted to Launch Wall on {{site_name}}. Launch Wall may include free submissions, paid featured placements, sponsored listings, badges, highlighted positions, calls to action, images, and manually approved promotional entries.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('Paid Placement Disclosure', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('Featured launches, highlighted entries, priority ordering, badges, promoted slots, and sponsor calls to action may be paid advertisements or sponsored placements. Payment does not create an endorsement, partnership, investment recommendation, ranking permanence, exclusivity, visitor guarantee, sales guarantee, or promise of commercial results.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('Submission Review', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('Submissions may be reviewed, edited for formatting, rejected, hidden, paused, reclassified, or removed when they are inaccurate, unlawful, misleading, abusive, infringing, unsafe, malicious, spammy, technically harmful, or inconsistent with site rules. Submitters are responsible for the accuracy of launch names, links, claims, media, pricing, availability, regulatory notices, intellectual-property rights, and contact details they provide.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('Fees and Refunds', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('Fees, placement terms, taxes, currency, failed-payment handling, chargebacks, cancellations, and refunds follow the checkout and billing terms presented at purchase. Unless a written offer says otherwise, fees cover review and display eligibility and do not guarantee publication, continued display, traffic, clicks, leads, conversions, investment, customers, or revenue. Launch Wall does not create automatic renewal or expiry terms; any fixed display period or renewal offer must be stated separately before purchase.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('Removal and Changes', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('{{site_name}} may update layout, categories, ordering, display rules, availability, moderation status, disclosure labels, and sponsor inventory at any time. A placement may be removed without refund if it violates these terms, triggers a payment dispute, infringes rights, creates legal or security risk, harms site reputation, or interferes with site operations.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('Submitter Permissions and Contact', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('Submitters confirm they have permission to provide all text, images, logos, trademarks, links, and contact details included in a launch submission. Questions, correction requests, takedown notices, and support requests can be sent through {{contact_method}}.', 'mds-launch-wall') . '</p>';
    }

    private function launch_wall_privacy_policy_content() {
        return '<h2>' . esc_html__('Launch Wall Submission Privacy Policy', 'mds-launch-wall') . '</h2>'
            . '<p>' . esc_html__('This policy explains how {{site_name}} handles information submitted through Launch Wall on {{site_url}}. It supplements the main privacy policy at {{privacy_policy_url}}.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('How Submission Data Is Used', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('Launch Wall may collect launch titles, descriptions, URLs, images, categories, tags, submitter names, email addresses, payment references, moderation notes, votes, timestamps, IP-derived abuse signals, and technical request data. This information is used to review entries, publish approved launches, manage paid featured placements, contact submitters, prevent abuse, maintain security, respond to disputes, and keep administrative records.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('Public Information', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('Approved launch details may be displayed publicly, including the title, description, link, image, category, vote totals, badge, featured state, and sponsor disclosure. Contact details and payment records should not be displayed publicly unless the submitter explicitly provided them for publication.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('Retention and Requests', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('Submissions, moderation logs, vote records, payment references, and security records may be retained while an entry is active and afterward for support, accounting, fraud prevention, dispute handling, legal compliance, backup recovery, and audit purposes. Submitters can request access, correction, removal, export, or erasure through {{contact_method}}. When an erasure request is approved, Launch Wall removes stored contact details, messages, destination URLs, and logo URLs while retaining non-identifying payment and moderation records when required for legal, tax, accounting, fraud-prevention, security, and dispute-retention obligations.', 'mds-launch-wall') . '</p>'
            . '<h3>' . esc_html__('Third-Party Services', 'mds-launch-wall') . '</h3>'
            . '<p>' . esc_html__('Payment gateways, email providers, analytics tools, hosting providers, security services, and embedded media providers may process submission-related data under their own terms and privacy policies when those services are enabled on {{site_name}}.', 'mds-launch-wall') . '</p>';
    }

    public function frontend_assets() {
        wp_register_style('mds-launch-wall', MDS_LAUNCH_WALL_URL . 'assets/css/style.css', [], MDS_LAUNCH_WALL_VERSION);
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage launch walls.', 'mds-launch-wall'));
        }

        $wall_page = max(1, absint($_GET['lw_wall_page'] ?? 1));
        $wall_collection = Repository::walls_page($wall_page, 20);
        $walls = $wall_collection['items'];
        $selected_id = absint($_GET['wall_id'] ?? ($walls[0]['id'] ?? 0));
        $wall = $selected_id ? Repository::wall($selected_id) : null;
        $launch_page = max(1, absint($_GET['lw_launch_page'] ?? 1));
        $launch_collection = $wall ? Repository::launches_page($wall['id'], $launch_page, 20) : ['items' => [], 'page' => 1, 'pages' => 1, 'total' => 0];
        $launches = $launch_collection['items'];
        $new_wall = [
            'id' => 0,
            'title' => '',
            'slug' => '',
            'description' => '',
            'status' => 'draft',
            'currency' => Repository::default_currency(),
            'base_price' => 0,
            'featured_price' => 49,
            'launch_limit' => 100,
            'payment_mode' => 'manual',
            'submission_enabled' => true,
            'voting_enabled' => false,
            'disclosure' => __('Featured launches may be paid placements.', 'mds-launch-wall'),
            'terms_url' => '',
            'contact_email' => get_option('admin_email'),
            'retention_days' => 365,
            'submission_limit_per_hour' => 10,
            'vote_limit_per_day' => 1,
        ];
        $form_wall = $wall ?: $new_wall;
        $currency_locked = Repository::currency_locked();
        if ($currency_locked) {
            $form_wall['currency'] = Repository::effective_currency($form_wall['currency'] ?? '');
        }
        $default_tab = $wall ? 'submissions' : 'settings';
        $active_tab = sanitize_key((string) ($_GET['tab'] ?? $default_tab));
        $allowed_tabs = $wall ? ['settings', 'submissions'] : ['settings'];
        if (!in_array($active_tab, $allowed_tabs, true)) {
            $active_tab = $default_tab;
        }
        ?>
        <div class="wrap mds3-admin mds-launch-wall-admin">
            <div class="mds-launch-wall-hero">
                <div>
                    <p class="mds-launch-wall-eyebrow"><?php esc_html_e('Million Dollar Script extension', 'mds-launch-wall'); ?></p>
                    <h1><?php esc_html_e('Launch Wall', 'mds-launch-wall'); ?></h1>
                    <p><?php esc_html_e('Create paid launch boards, review submissions, and publish sponsored launches with clear disclosure.', 'mds-launch-wall'); ?></p>
                </div>
                <div class="mds-extension-header-actions has-shortcode">
                    <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::docs_button('mds-launch-wall')); ?>
                    <?php if ($wall) : ?>
                        <?php echo wp_kses_post(\MillionDollarScript\Extensions\Admin::shortcode_copy(sprintf('[mds_launch_wall id="%d"]', absint($wall['id'])))); ?>
                    <?php endif; ?>
                </div>
            </div>
            <hr class="wp-header-end">

            <?php $this->admin_notice(); ?>

            <nav class="nav-tab-wrapper wp-clearfix mds-launch-wall-tabs" aria-label="<?php esc_attr_e('Launch Wall workspace', 'mds-launch-wall'); ?>">
                <?php if ($wall) : ?>
                    <a class="nav-tab<?php echo 'submissions' === $active_tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->admin_url(['wall_id' => $selected_id, 'tab' => 'submissions'])); ?>" <?php echo 'submissions' === $active_tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html(sprintf(__('Submissions (%d)', 'mds-launch-wall'), absint($launch_collection['total']))); ?></a>
                <?php endif; ?>
                <a class="nav-tab<?php echo 'settings' === $active_tab ? ' nav-tab-active' : ''; ?>" href="<?php echo esc_url($this->admin_url(['wall_id' => $selected_id, 'tab' => 'settings'])); ?>" <?php echo 'settings' === $active_tab ? 'aria-current="page"' : ''; ?>><?php esc_html_e('Wall settings', 'mds-launch-wall'); ?></a>
            </nav>

            <div class="mds-launch-wall-layout">
                <section class="mds3-card mds-launch-wall-panel">
                    <h2><?php esc_html_e('Walls', 'mds-launch-wall'); ?></h2>
                    <div class="mds-launch-wall-list">
                        <?php foreach ($walls as $item) : ?>
                            <a class="mds-launch-wall-list-item <?php echo absint($item['id']) === absint($selected_id) ? 'is-selected' : ''; ?>" href="<?php echo esc_url($this->admin_url(['wall_id' => $item['id'], 'tab' => $active_tab])); ?>">
                                <strong><?php echo esc_html($item['title']); ?></strong>
                                <span><?php echo esc_html($item['status']); ?> · <?php echo esc_html(Repository::effective_currency($item['currency'] ?? '')); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <a class="button" href="<?php echo esc_url($this->admin_url(['wall_id' => 0, 'tab' => 'settings'])); ?>"><?php esc_html_e('New wall', 'mds-launch-wall'); ?></a>
                    </div>
                    <?php $this->pagination($wall_collection, 'lw_wall_page', ['wall_id' => $selected_id, 'tab' => $active_tab]); ?>
                </section>

                <?php if ('settings' === $active_tab) : ?>
                <section class="mds3-card mds-launch-wall-panel">
                    <h2><?php echo $wall ? esc_html__('Wall settings', 'mds-launch-wall') : esc_html__('Create wall', 'mds-launch-wall'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mds-launch-wall-form">
                        <?php wp_nonce_field('mds_launch_wall_save_wall_' . absint($form_wall['id'])); ?>
                        <input type="hidden" name="action" value="mds_launch_wall_save_wall">
                        <input type="hidden" name="wall_id" value="<?php echo esc_attr((string) $form_wall['id']); ?>">

                        <?php $this->field('title', __('Title', 'mds-launch-wall'), $form_wall['title'], 'text', true, false, __('Public heading for this launch board.', 'mds-launch-wall')); ?>
                        <?php $this->field('slug', __('Slug', 'mds-launch-wall'), $form_wall['slug'], 'text', false, false, __('Optional shortcode lookup slug. Leave blank to generate one from the title.', 'mds-launch-wall')); ?>
                        <?php $this->field('description', __('Description', 'mds-launch-wall'), $form_wall['description'], 'textarea', false, false, __('Short public description shown above approved launches.', 'mds-launch-wall')); ?>
                        <?php $this->select('status', __('Status', 'mds-launch-wall'), $form_wall['status'], ['draft' => __('Draft', 'mds-launch-wall'), 'active' => __('Active', 'mds-launch-wall'), 'paused' => __('Paused', 'mds-launch-wall'), 'archived' => __('Archived', 'mds-launch-wall')], __('Only active walls render publicly and accept submissions.', 'mds-launch-wall'), __('Paused walls stay visible to admins but stop public submissions. Archived walls should be kept only for reporting or historical records.', 'mds-launch-wall')); ?>
                        <?php $this->field('currency', __('Currency', 'mds-launch-wall'), $form_wall['currency'], 'text', true, $currency_locked, __('Currency used for public pricing labels and MDS payment-provider checkout.', 'mds-launch-wall'), __('When a payment provider locks currency, Launch Wall uses the store currency automatically and saves this as read-only context.', 'mds-launch-wall')); ?>
                        <?php if ($currency_locked) : ?><p class="mds-launch-wall-note"><?php esc_html_e('The active payment provider controls currency for this site.', 'mds-launch-wall'); ?></p><?php endif; ?>
                        <?php $this->field('base_price', __('Base price', 'mds-launch-wall'), $form_wall['base_price'], 'number', false, false, __('Amount charged for each accepted submission before featured upgrades. Use 0 for free/manual review.', 'mds-launch-wall')); ?>
                        <?php $this->field('featured_price', __('Featured upgrade', 'mds-launch-wall'), $form_wall['featured_price'], 'number', false, false, __('Additional amount when a submitter requests featured placement.', 'mds-launch-wall')); ?>
                        <?php $this->field('launch_limit', __('Wall capacity', 'mds-launch-wall'), $form_wall['launch_limit'], 'number', false, false, __('Maximum active or pending launch submissions this wall can hold. Rejected submissions do not consume capacity.', 'mds-launch-wall')); ?>
                        <?php $this->select('payment_mode', __('Payment mode', 'mds-launch-wall'), $form_wall['payment_mode'], ['manual' => __('Manual or free requests', 'mds-launch-wall'), 'provider' => __('Use MDS payment provider', 'mds-launch-wall')], __('Provider checkout uses the active MDS payment adapter; it does not call WooCommerce directly.', 'mds-launch-wall'), __('Use manual mode for free listings, invoices, or offline review. Provider mode creates a payment source through Million Dollar Script so WooCommerce, standalone checkout, or another adapter can handle payment.', 'mds-launch-wall')); ?>
                        <?php $this->field('disclosure', __('Disclosure label', 'mds-launch-wall'), $form_wall['disclosure'], 'text', false, false, __('Public sponsorship disclosure shown above launch cards.', 'mds-launch-wall')); ?>
                        <?php $this->field('terms_url', __('Terms URL', 'mds-launch-wall'), $form_wall['terms_url'], 'url', false, false, __('Optional public terms page linked from the submission form.', 'mds-launch-wall')); ?>
                        <?php $this->field('contact_email', __('Moderation email', 'mds-launch-wall'), $form_wall['contact_email'], 'email', true, false, __('Private address that receives new-submission notifications.', 'mds-launch-wall')); ?>
                        <?php $this->field('retention_days', __('Private data retention days', 'mds-launch-wall'), $form_wall['retention_days'], 'number', false, false, __('How long rejected or terminal unpaid submissions keep private submitter details before scheduled anonymization.', 'mds-launch-wall')); ?>
                        <?php $this->field('submission_limit_per_hour', __('Submissions per visitor per hour', 'mds-launch-wall'), $form_wall['submission_limit_per_hour'], 'number', false, false, __('Combined public submissions accepted from one visitor in an hour.', 'mds-launch-wall')); ?>
                        <?php $this->field('vote_limit_per_day', __('Votes per visitor per launch per day', 'mds-launch-wall'), $form_wall['vote_limit_per_day'], 'number', false, false, __('Maximum votes accepted from one visitor for the same launch in a day.', 'mds-launch-wall')); ?>
                        <label class="mds-launch-wall-check"><input type="checkbox" name="submission_enabled" value="1" <?php checked(!empty($form_wall['submission_enabled'])); ?>> <span><?php esc_html_e('Accept public submissions', 'mds-launch-wall'); ?></span><em><?php esc_html_e('When disabled, approved launches remain visible but the public submission form is hidden.', 'mds-launch-wall'); ?></em></label>
                        <label class="mds-launch-wall-check"><input type="checkbox" name="voting_enabled" value="1" <?php checked(!empty($form_wall['voting_enabled'])); ?>> <span><?php esc_html_e('Allow public voting', 'mds-launch-wall'); ?></span><em><?php esc_html_e('Votes require the MDS public write nonce and are rate limited per launch and visitor.', 'mds-launch-wall'); ?></em></label>
                        <p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e('Save wall', 'mds-launch-wall'); ?></button></p>
                    </form>
                </section>
                <?php endif; ?>

            <?php if ($wall && 'submissions' === $active_tab) : ?>
                <section class="mds3-card mds-launch-wall-panel">
                    <h2><?php echo esc_html(sprintf(__('Submissions (%1$d of %2$d slots used)', 'mds-launch-wall'), Repository::used_launch_slots($wall['id']), absint($wall['launch_limit'] ?? 100))); ?></h2>
                    <?php if (!$launches) : ?>
                        <p><?php esc_html_e('No launch submissions yet.', 'mds-launch-wall'); ?></p>
                        <p><a class="button" href="<?php echo esc_url($this->admin_url(['wall_id' => $wall['id'], 'tab' => 'settings'])); ?>"><?php esc_html_e('Review wall settings', 'mds-launch-wall'); ?></a></p>
                    <?php else : ?>
                        <div class="mds-launch-wall-submissions">
                            <?php foreach ($launches as $launch) : ?>
                                <article>
                                    <div>
                                        <strong><?php echo esc_html($launch['title']); ?></strong>
                                        <span><?php echo esc_html($launch['status']); ?> · <?php echo esc_html($launch['payment_status']); ?> · <?php echo esc_html($launch['category']); ?></span>
                                        <p><?php echo esc_html($launch['tagline'] ?: wp_trim_words($launch['description'], 18)); ?></p>
                                    </div>
                                    <div class="mds-launch-wall-actions">
                                        <?php $this->moderation_button($launch['id'], 'approve', __('Approve', 'mds-launch-wall')); ?>
                                        <?php $this->moderation_button($launch['id'], 'reject', __('Reject', 'mds-launch-wall')); ?>
                                        <?php $this->moderation_button($launch['id'], !empty($launch['featured_approved']) ? 'unfeature' : 'feature', !empty($launch['featured_approved']) ? __('Unfeature', 'mds-launch-wall') : __('Feature', 'mds-launch-wall')); ?>
                                        <?php if (!empty($launch['launch_url'])) : ?>
                                            <a class="button" href="<?php echo esc_url($launch['launch_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open', 'mds-launch-wall'); ?></a>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                        <?php $this->pagination($launch_collection, 'lw_launch_page', ['wall_id' => $wall['id'], 'tab' => 'submissions']); ?>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function handle_save_wall() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save launch walls.', 'mds-launch-wall'));
        }

        $wall_id = absint($_POST['wall_id'] ?? 0);
        check_admin_referer('mds_launch_wall_save_wall_' . $wall_id);
        $saved = Repository::save_wall([
            'title' => wp_unslash($_POST['title'] ?? ''),
            'slug' => wp_unslash($_POST['slug'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'status' => wp_unslash($_POST['status'] ?? 'draft'),
            'currency' => Repository::currency_locked() ? Repository::default_currency() : wp_unslash($_POST['currency'] ?? Repository::default_currency()),
            'base_price' => wp_unslash($_POST['base_price'] ?? 0),
            'featured_price' => wp_unslash($_POST['featured_price'] ?? 0),
            'launch_limit' => wp_unslash($_POST['launch_limit'] ?? 100),
            'payment_mode' => wp_unslash($_POST['payment_mode'] ?? 'manual'),
            'submission_enabled' => !empty($_POST['submission_enabled']) ? '1' : '0',
            'voting_enabled' => !empty($_POST['voting_enabled']) ? '1' : '0',
            'disclosure' => wp_unslash($_POST['disclosure'] ?? ''),
            'terms_url' => wp_unslash($_POST['terms_url'] ?? ''),
            'contact_email' => wp_unslash($_POST['contact_email'] ?? ''),
            'retention_days' => wp_unslash($_POST['retention_days'] ?? 365),
            'submission_limit_per_hour' => wp_unslash($_POST['submission_limit_per_hour'] ?? 10),
            'vote_limit_per_day' => wp_unslash($_POST['vote_limit_per_day'] ?? 1),
        ], $wall_id);

        $this->redirect(['wall_id' => $saved ?: $wall_id, 'tab' => 'settings', 'mds_lw_status' => $saved ? 'saved' : 'error']);
    }

    public function handle_moderate_launch() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to moderate launches.', 'mds-launch-wall'));
        }

        $launch_id = absint($_POST['launch_id'] ?? 0);
        check_admin_referer('mds_launch_wall_moderate_' . $launch_id);
        $launch = Repository::launch($launch_id);
        $status = sanitize_key((string) ($_POST['moderation'] ?? ''));
        $ok = $launch && Repository::update_launch_status($launch_id, $status);
        $this->redirect([
            'wall_id' => absint($launch['wall_id'] ?? 0),
            'tab' => 'submissions',
            'mds_lw_status' => $ok ? 'moderated' : 'error',
        ]);
    }

    public function handle_submit_launch() {
        $wall_id = absint($_POST['wall_id'] ?? 0);
        check_admin_referer('mds_launch_wall_submit_' . $wall_id);
        $wall = Repository::wall($wall_id);
        $referrer = esc_url_raw(wp_unslash($_POST['_wp_http_referer'] ?? wp_get_referer() ?: home_url('/')));
        if (!$wall || 'active' !== (string) ($wall['status'] ?? '') || empty($wall['submission_enabled'])) {
            $this->safe_redirect($referrer, ['launch_wall_status' => 'closed']);
        }
        if (!empty($_POST['company'])) {
            $this->safe_redirect($referrer, ['launch_wall_status' => 'submitted']);
        }
        $data = [
            'title' => wp_unslash($_POST['title'] ?? ''),
            'description' => wp_unslash($_POST['description'] ?? ''),
            'submitter_name' => wp_unslash($_POST['submitter_name'] ?? ''),
            'submitter_email' => wp_unslash($_POST['submitter_email'] ?? ''),
            'launch_url' => wp_unslash($_POST['launch_url'] ?? ''),
            'category' => wp_unslash($_POST['category'] ?? ''),
            'tagline' => wp_unslash($_POST['tagline'] ?? ''),
            'launch_date' => wp_unslash($_POST['launch_date'] ?? ''),
            'logo_url' => wp_unslash($_POST['logo_url'] ?? ''),
            'featured_requested' => !empty($_POST['featured_requested']) ? '1' : '0',
            'terms_accepted' => !empty($_POST['terms_accepted']) ? '1' : '0',
        ];
        $validation = Repository::validate_launch_submission($wall_id, $data);
        if (is_wp_error($validation)) {
            $this->safe_redirect($referrer, ['launch_wall_status' => $this->validation_status($validation)]);
        }
        if ($this->rate_limited('submission_' . $wall_id, absint($wall['submission_limit_per_hour'] ?? 10), HOUR_IN_SECONDS)) {
            $this->safe_redirect($referrer, ['launch_wall_status' => 'rate_limited']);
        }

        $launch_id = Repository::create_launch($wall_id, $data);
        if (!$launch_id) {
            $this->safe_redirect($referrer, ['launch_wall_status' => 'failed']);
        }

        $launch = Repository::launch($launch_id);
        $this->notify_submission($wall, $launch);
        if ($this->should_start_checkout($wall, $launch)) {
            try {
                $checkout = $this->start_checkout($wall, $launch, $referrer);
            } catch (\Throwable $error) {
                $checkout = new \WP_Error('mds_launch_wall_checkout_failed', $error->getMessage());
            }
            if (is_array($checkout) && !empty($checkout['checkout_url'])) {
                $this->checkout_redirect((string) $checkout['checkout_url'], $referrer);
            }
            Repository::update_payment_status($launch_id, 'failed');
            $this->safe_redirect($referrer, ['launch_wall_status' => 'checkout_failed']);
        }

        $this->safe_redirect($referrer, ['launch_wall_status' => 'submitted']);
    }

    public function handle_vote_launch() {
        $launch_id = absint($_POST['launch_id'] ?? 0);
        check_admin_referer('mds_launch_wall_vote_' . $launch_id);
        $launch = Repository::launch($launch_id);
        $wall = $launch ? Repository::wall(absint($launch['wall_id'] ?? 0)) : null;
        $referrer = esc_url_raw(wp_unslash($_POST['_wp_http_referer'] ?? wp_get_referer() ?: home_url('/')));
        if (!$launch || !$wall || 'active' !== (string) ($wall['status'] ?? '') || empty($wall['voting_enabled']) || !Repository::is_launch_public($launch_id)) {
            $this->safe_redirect($referrer, ['launch_wall_vote_status' => 'voting_closed']);
        }
        if ($this->rate_limited('vote_' . $launch_id, absint($wall['vote_limit_per_day'] ?? 1), DAY_IN_SECONDS)) {
            $this->safe_redirect($referrer, ['launch_wall_vote_status' => 'vote_limited']);
        }

        $votes = Repository::increment_vote($launch_id);
        $this->safe_redirect($referrer, ['launch_wall_vote_status' => $votes ? 'vote_recorded' : 'vote_failed']);
    }

    public function shortcode($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'slug' => '',
            'show_form' => 'true',
            'show_disclosure' => 'true',
        ], $atts, 'mds_launch_wall');
        $show_form = $this->truthy($atts['show_form']);
        $show_disclosure = $this->truthy($atts['show_disclosure']);
        if (absint($atts['id'])) {
            $wall = Repository::wall(absint($atts['id']));
        } elseif ('' !== trim((string) $atts['slug'])) {
            $wall = Repository::wall_by_slug((string) $atts['slug']);
        } else {
            $walls = Repository::walls_page(1, 1, true);
            $wall = $walls['items'][0] ?? null;
        }
        if (!$wall || 'active' !== (string) ($wall['status'] ?? '')) {
            return current_user_can('manage_options') ? '<p class="mds-launch-wall-empty">' . esc_html__('Launch Wall is not active.', 'mds-launch-wall') . '</p>' : '';
        }

        wp_enqueue_style('mds-launch-wall');
        $wall['currency'] = Repository::effective_currency($wall['currency'] ?? '');
        $public_wall = Repository::public_wall_payload($wall);
        $page_key = 'launch_page_' . absint($wall['id']);
        $launch_page = max(1, absint($_GET[$page_key] ?? 1));
        $launch_collection = Repository::launches_page($wall['id'], $launch_page, 24, true);
        $launches = array_values(array_filter(array_map([Repository::class, 'public_launch_payload'], $launch_collection['items'])));
        ob_start();
        ?>
        <section class="mds-launch-wall" data-wall-id="<?php echo esc_attr((string) $wall['id']); ?>">
            <header class="mds-launch-wall__header">
                <span><?php esc_html_e('Launch Wall', 'mds-launch-wall'); ?></span>
                <h2><?php echo esc_html($wall['title']); ?></h2>
                <?php if (!empty($wall['description'])) : ?>
                    <p><?php echo esc_html($wall['description']); ?></p>
                <?php endif; ?>
                <?php if ($show_disclosure && !empty($wall['disclosure'])) : ?>
                    <small><?php echo esc_html($wall['disclosure']); ?></small>
                <?php endif; ?>
                <div class="mds-launch-wall__summary">
                    <span><?php echo esc_html(sprintf(__('%1$d of %2$d slots used', 'mds-launch-wall'), absint($public_wall['used_launches'] ?? 0), absint($public_wall['launch_limit'] ?? 100))); ?></span>
                    <span><?php echo esc_html(sprintf(__('Submission %s', 'mds-launch-wall'), $this->format_money($wall['base_price'], $wall['currency']))); ?></span>
                    <span><?php echo esc_html(sprintf(__('Featured +%s', 'mds-launch-wall'), $this->format_money($wall['featured_price'], $wall['currency']))); ?></span>
                </div>
            </header>

            <?php $this->vote_notice(); ?>

            <div class="mds-launch-wall__grid">
                <?php foreach ($launches as $launch) : ?>
                    <article class="mds-launch-wall__launch <?php echo !empty($launch['featured_approved']) ? 'is-featured' : ''; ?>">
                        <?php if (!empty($launch['logo_url'])) : ?>
                            <img src="<?php echo esc_url($launch['logo_url']); ?>" alt="<?php echo esc_attr(sprintf(__('%s logo', 'mds-launch-wall'), $launch['title'])); ?>" loading="lazy">
                        <?php endif; ?>
                        <div>
                            <div class="mds-launch-wall__meta">
                                <?php if (!empty($launch['featured_approved'])) : ?><span><?php esc_html_e('Featured', 'mds-launch-wall'); ?></span><?php endif; ?>
                                <?php if (!empty($launch['category'])) : ?><span><?php echo esc_html($launch['category']); ?></span><?php endif; ?>
                                <?php if (!empty($launch['launch_date'])) : ?><span><?php echo esc_html($launch['launch_date']); ?></span><?php endif; ?>
                            </div>
                            <h3><?php echo esc_html($launch['title']); ?></h3>
                            <?php if (!empty($launch['tagline'])) : ?><p><?php echo esc_html($launch['tagline']); ?></p><?php endif; ?>
                            <a href="<?php echo esc_url($launch['launch_url']); ?>" rel="nofollow<?php echo !empty($launch['sponsored']) ? ' sponsored' : ''; ?> noopener" target="_blank"><?php esc_html_e('Visit launch', 'mds-launch-wall'); ?></a>
                            <?php if (!empty($wall['voting_enabled'])) : ?>
                                <form class="mds-launch-wall__vote" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                    <?php wp_nonce_field('mds_launch_wall_vote_' . absint($launch['id'])); ?>
                                    <input type="hidden" name="action" value="mds_launch_wall_vote">
                                    <input type="hidden" name="launch_id" value="<?php echo esc_attr((string) absint($launch['id'])); ?>">
                                    <button type="submit"><?php echo esc_html(sprintf(_n('Vote (%d)', 'Vote (%d)', absint($launch['votes']), 'mds-launch-wall'), absint($launch['votes']))); ?></button>
                                </form>
                            <?php elseif (absint($launch['votes'])) : ?>
                                <span class="mds-launch-wall__votes"><?php echo esc_html(sprintf(_n('%d vote', '%d votes', absint($launch['votes']), 'mds-launch-wall'), absint($launch['votes']))); ?></span>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php $this->public_pagination($launch_collection, $page_key); ?>

            <?php if (empty($launches)) : ?>
                <p class="mds-launch-wall__empty"><?php esc_html_e('Approved launches will appear here.', 'mds-launch-wall'); ?></p>
            <?php endif; ?>

            <?php if ($show_form && !empty($wall['submission_enabled']) && Repository::has_open_launch_slots(absint($wall['id']))) : ?>
                <?php $this->submission_form($wall); ?>
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
        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/walls', [[
            'methods' => \WP_REST_Server::READABLE,
            'callback' => function ($request) {
                $collection = Repository::walls_page(
                    max(1, absint($request->get_param('page'))),
                    min(100, max(1, absint($request->get_param('per_page') ?: 24))),
                    true
                );
                return rest_ensure_response([
                    'data' => array_values(array_filter(array_map([Repository::class, 'public_wall_payload'], $collection['items']))),
                    'page' => $collection['page'],
                    'per_page' => $collection['per_page'],
                    'total' => $collection['total'],
                    'pages' => $collection['pages'],
                ]);
            },
            'permission_callback' => function ($request) {
                return $this->permission($request, 'launchwall.read', 'api_key_read');
            },
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/launches', [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => function ($request) {
                    $wall_id = absint($request['wall_id'] ?? 0);
                    $wall = Repository::public_wall_payload(Repository::wall($wall_id));
                    if (!$wall) {
                        return new \WP_Error('mds_launch_wall_not_found', __('Active launch wall not found.', 'mds-launch-wall'), ['status' => 404]);
                    }
                    $collection = Repository::launches_page(
                        $wall_id,
                        max(1, absint($request->get_param('page'))),
                        min(100, max(1, absint($request->get_param('per_page') ?: 24))),
                        true
                    );
                    return rest_ensure_response([
                        'data' => array_values(array_filter(array_map([Repository::class, 'public_launch_payload'], $collection['items']))),
                        'page' => $collection['page'],
                        'per_page' => $collection['per_page'],
                        'total' => $collection['total'],
                        'pages' => $collection['pages'],
                    ]);
                },
                'permission_callback' => function ($request) {
                    return $this->permission($request, 'launchwall.read', 'api_key_read');
                },
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'rest_create_launch'],
                'permission_callback' => function ($request) {
                    return $this->permission($request, 'launchwall.write', 'public_write_nonce');
                },
            ],
        ]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/launches/(?P<id>\d+)/moderate', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_moderate_launch'],
            'permission_callback' => function ($request) {
                return $this->permission($request, 'launchwall.write', 'wp_capability');
            },
        ]]);

        \MillionDollarScript\Extensions\Support::register_rest_route(self::REST_BASE . '/launches/(?P<id>\d+)/vote', [[
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_vote_launch'],
            'permission_callback' => function ($request) {
                return $this->permission($request, 'launchwall.write', 'public_write_nonce');
            },
        ]]);
    }

    public function api_endpoint_manifest(array $endpoints) {
        $endpoints[] = ['id' => 'launch-wall-walls-read', 'route' => '/million-dollar-script/v1/launch-wall/walls', 'methods' => ['GET'], 'scope' => 'launchwall.read', 'minimum_security_level' => 'api_key_read', 'description' => __('Read active launch walls without private operational settings.', 'mds-launch-wall')];
        $endpoints[] = ['id' => 'launch-wall-launches-read', 'route' => '/million-dollar-script/v1/launch-wall/launches', 'methods' => ['GET'], 'scope' => 'launchwall.read', 'minimum_security_level' => 'api_key_read', 'description' => __('Read approved public launches without submitter or payment data.', 'mds-launch-wall')];
        $endpoints[] = ['id' => 'launch-wall-launches-write', 'route' => '/million-dollar-script/v1/launch-wall/launches', 'methods' => ['POST'], 'scope' => 'launchwall.write', 'minimum_security_level' => 'public_write_nonce', 'description' => __('Create launch submissions.', 'mds-launch-wall')];
        $endpoints[] = ['id' => 'launch-wall-launches-moderate', 'route' => '/million-dollar-script/v1/launch-wall/launches/(?P<id>\d+)/moderate', 'methods' => ['POST'], 'scope' => 'launchwall.write', 'minimum_security_level' => 'wp_capability', 'description' => __('Moderate launch submissions.', 'mds-launch-wall')];
        $endpoints[] = ['id' => 'launch-wall-launches-vote', 'route' => '/million-dollar-script/v1/launch-wall/launches/(?P<id>\d+)/vote', 'methods' => ['POST'], 'scope' => 'launchwall.write', 'minimum_security_level' => 'public_write_nonce', 'description' => __('Record a public launch vote.', 'mds-launch-wall')];
        return $endpoints;
    }

    public function rest_create_launch($request) {
        $wall_id = absint($request['wall_id'] ?? 0);
        $wall = Repository::wall($wall_id);
        if (!$wall || 'active' !== (string) ($wall['status'] ?? '') || empty($wall['submission_enabled'])) {
            return new \WP_Error('mds_launch_wall_closed', __('Launch submissions are closed.', 'mds-launch-wall'), ['status' => 403]);
        }

        $data = [
            'title' => $request['title'] ?? '',
            'description' => $request['description'] ?? '',
            'submitter_name' => $request['submitter_name'] ?? '',
            'submitter_email' => $request['submitter_email'] ?? '',
            'launch_url' => $request['launch_url'] ?? '',
            'category' => $request['category'] ?? '',
            'tagline' => $request['tagline'] ?? '',
            'launch_date' => $request['launch_date'] ?? '',
            'logo_url' => $request['logo_url'] ?? '',
            'featured_requested' => !empty($request['featured_requested']) ? '1' : '0',
            'terms_accepted' => !empty($request['terms_accepted']) ? '1' : '0',
        ];
        $validation = Repository::validate_launch_submission($wall_id, $data);
        if (is_wp_error($validation)) {
            $validation->add_data(['status' => 400]);
            return $validation;
        }
        if ($this->rate_limited('submission_' . $wall_id, absint($wall['submission_limit_per_hour'] ?? 10), HOUR_IN_SECONDS)) {
            return new \WP_Error('mds_launch_wall_rate_limited', __('Please wait before sending another launch.', 'mds-launch-wall'), ['status' => 429]);
        }

        $id = Repository::create_launch($wall_id, $data);
        if (!$id) {
            return new \WP_Error('mds_launch_wall_create_failed', __('Launch submission could not be created.', 'mds-launch-wall'), ['status' => 400]);
        }

        $launch = Repository::launch($id);
        $this->notify_submission($wall, $launch);
        $response = ['data' => ['id' => $id, 'status' => 'pending_review']];
        if ($this->should_start_checkout($wall, $launch)) {
            try {
                $checkout = $this->start_checkout($wall, $launch, home_url('/'));
            } catch (\Throwable $error) {
                $checkout = new \WP_Error('mds_launch_wall_checkout_failed', $error->getMessage());
            }
            if (!is_array($checkout) || empty($checkout['checkout_url'])) {
                Repository::update_payment_status($id, 'failed');
                return new \WP_Error('mds_launch_wall_checkout_failed', __('The launch was saved, but payment checkout could not be started. No charge was made.', 'mds-launch-wall'), ['status' => 502, 'launch_id' => $id]);
            }
            $response['checkout_url'] = esc_url_raw((string) $checkout['checkout_url']);
        }

        return new \WP_REST_Response($response, 201);
    }

    public function rest_moderate_launch($request) {
        $id = absint($request['id'] ?? 0);
        $status = sanitize_key((string) ($request['status'] ?? ''));
        if (!Repository::update_launch_status($id, $status)) {
            return new \WP_Error('mds_launch_wall_moderate_failed', __('Launch submission could not be moderated.', 'mds-launch-wall'), ['status' => 400]);
        }

        return rest_ensure_response(['data' => Repository::launch($id)]);
    }

    public function rest_vote_launch($request) {
        $launch = Repository::launch(absint($request['id'] ?? 0));
        $wall = $launch ? Repository::wall(absint($launch['wall_id'] ?? 0)) : null;
        if (!$launch || !$wall || 'active' !== (string) ($wall['status'] ?? '') || empty($wall['voting_enabled']) || !Repository::is_launch_public(absint($launch['id'] ?? 0))) {
            return new \WP_Error('mds_launch_wall_vote_disabled', __('Voting is not enabled for this launch wall.', 'mds-launch-wall'), ['status' => 403]);
        }
        if ($this->rate_limited('vote_' . absint($launch['id'] ?? 0), absint($wall['vote_limit_per_day'] ?? 1), DAY_IN_SECONDS)) {
            return new \WP_Error('mds_launch_wall_vote_rate_limited', __('Please wait before voting again.', 'mds-launch-wall'), ['status' => 429]);
        }

        return rest_ensure_response(['data' => ['votes' => Repository::increment_vote(absint($launch['id']))]]);
    }

    public function handle_payment_status($source, $source_id, $status, $context = []) {
        if ('launch-wall' !== sanitize_key((string) $source)) {
            return;
        }

        Repository::update_payment_status(absint($source_id), sanitize_key((string) $status));
    }

    public function handle_subscription_term($source, $source_id, $context = [], $result = null) {
        unset($result);
        if ('launch-wall' !== sanitize_key((string) $source) || !Repository::launch(absint($source_id))) {
            return;
        }
        $subscription = is_array($context['subscription'] ?? null) ? $context['subscription'] : [];
        $period_end = sanitize_text_field((string) ($subscription['current_period_end'] ?? ''));
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $period_end, new \DateTimeZone('UTC'));
        if (!$date || $date->format('Y-m-d H:i:s') !== $period_end) {
            return;
        }
        $current = (string) get_post_meta(absint($source_id), '_mds_lw_subscription_expires_at', true);
        if (!$current || $period_end > $current) {
            update_post_meta(absint($source_id), '_mds_lw_subscription_expires_at', $period_end);
        }
        update_post_meta(absint($source_id), '_mds_lw_subscription_public_id', sanitize_text_field((string) ($subscription['public_id'] ?? '')));
    }

    public function privacy_exporters(array $exporters) {
        $exporters['mds-launch-wall'] = [
            'exporter_friendly_name' => __('Launch Wall submissions', 'mds-launch-wall'),
            'callback' => [$this, 'privacy_exporter'],
        ];

        return $exporters;
    }

    public function privacy_erasers(array $erasers) {
        $erasers['mds-launch-wall'] = [
            'eraser_friendly_name' => __('Launch Wall submissions', 'mds-launch-wall'),
            'callback' => [$this, 'privacy_eraser'],
        ];

        return $erasers;
    }

    public function privacy_exporter($email_address, $page = 1) {
        return Privacy::exporter($email_address, $page);
    }

    public function privacy_eraser($email_address, $page = 1) {
        return Privacy::eraser($email_address, $page);
    }

    private function submission_form(array $wall) {
        $status = sanitize_key((string) ($_GET['launch_wall_status'] ?? ''));
        ?>
        <details class="mds-launch-wall__submit"<?php echo $status ? ' open' : ''; ?>>
            <summary><?php esc_html_e('Submit a launch', 'mds-launch-wall'); ?></summary>
            <?php if ($status) : ?>
                <p class="mds-launch-wall__notice mds-launch-wall__notice--<?php echo 'submitted' === $status ? 'success' : 'error'; ?>" role="status"><?php echo esc_html($this->frontend_status_message($status)); ?></p>
            <?php endif; ?>
            <p class="mds-launch-wall__intro"><?php esc_html_e('Submissions are reviewed before they appear on the wall.', 'mds-launch-wall'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('mds_launch_wall_submit_' . absint($wall['id'])); ?>
                <input type="hidden" name="action" value="mds_launch_wall_submit">
                <input type="hidden" name="wall_id" value="<?php echo esc_attr((string) $wall['id']); ?>">
                <label><span><?php esc_html_e('Launch name', 'mds-launch-wall'); ?></span><input type="text" name="title" maxlength="120" required></label>
                <label><span><?php esc_html_e('Tagline', 'mds-launch-wall'); ?></span><input type="text" name="tagline" maxlength="140"></label>
                <label><span><?php esc_html_e('Launch URL', 'mds-launch-wall'); ?></span><input type="url" name="launch_url" maxlength="2048" autocomplete="url" required></label>
                <label><span><?php esc_html_e('Category', 'mds-launch-wall'); ?></span><input type="text" name="category" maxlength="80"></label>
                <label><span><?php esc_html_e('Launch date', 'mds-launch-wall'); ?></span><input type="date" name="launch_date"></label>
                <label><span><?php esc_html_e('Logo URL', 'mds-launch-wall'); ?></span><input type="url" name="logo_url" maxlength="2048" autocomplete="url"></label>
                <label><span><?php esc_html_e('Your name (optional)', 'mds-launch-wall'); ?></span><input type="text" name="submitter_name" maxlength="80" autocomplete="name"></label>
                <label><span><?php esc_html_e('Email', 'mds-launch-wall'); ?></span><input type="email" name="submitter_email" maxlength="254" autocomplete="email" required></label>
                <label class="mds-launch-wall__wide"><span><?php esc_html_e('Description', 'mds-launch-wall'); ?></span><textarea name="description" rows="4" maxlength="10000"></textarea></label>
                <label class="mds-launch-wall__check"><input type="checkbox" name="featured_requested" value="1"> <span><?php echo esc_html(sprintf(__('Request featured placement (%s)', 'mds-launch-wall'), $this->format_money($wall['featured_price'], $wall['currency']))); ?></span></label>
                <input type="text" name="company" value="" tabindex="-1" autocomplete="off" class="mds-launch-wall__hp">
                <?php if (!empty($wall['terms_url'])) : ?>
                    <p class="mds-launch-wall__terms"><a href="<?php echo esc_url($wall['terms_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Placement terms', 'mds-launch-wall'); ?></a></p>
                <?php endif; ?>
                <label class="mds-launch-wall__check"><input type="checkbox" name="terms_accepted" value="1" required> <span><?php esc_html_e('I accept the placement terms and understand that featured or highlighted launches may be paid sponsored placements.', 'mds-launch-wall'); ?></span></label>
                <button type="submit"><?php esc_html_e('Send for review', 'mds-launch-wall'); ?></button>
            </form>
        </details>
        <?php
    }

    private function should_start_checkout(array $wall, array $launch = null) {
        if (!$launch || 'provider' !== (string) ($wall['payment_mode'] ?? '')) {
            return false;
        }
        return (float) ($launch['amount'] ?? 0) > 0;
    }

    private function start_checkout(array $wall, array $launch, $referrer) {
        if (
            class_exists('\MillionDollarScript\Extensions\Subscriptions\Entitlements')
            && \MillionDollarScript\Extensions\Subscriptions\Entitlements::consume(
                'launch-wall',
                'launches',
                'launch:' . absint($launch['id'] ?? 0),
                [
                    'user_id' => get_current_user_id(),
                    'email' => sanitize_email((string) ($launch['submitter_email'] ?? '')),
                ],
                (string) absint($wall['id'] ?? 0)
            )
        ) {
            \MillionDollarScript\Commerce\Payments::mark_source_paid('launch-wall', absint($launch['id'] ?? 0), [
                'provider' => 'subscription_allowance',
            ]);
            return [
                'provider' => 'subscription_allowance',
                'status' => 'paid',
                'checkout_url' => add_query_arg('launch_wall_status', 'submitted', (string) $referrer),
            ];
        }

        return \MillionDollarScript\Commerce\Payments::create_checkout([
            'source' => 'launch-wall',
            'source_id' => absint($launch['id'] ?? 0),
            'source_key' => 'launch-wall-' . absint($launch['id'] ?? 0),
            'user_id' => get_current_user_id(),
            'email' => sanitize_email($launch['submitter_email'] ?? ''),
            'currency' => Repository::effective_currency($launch['currency'] ?? $wall['currency'] ?? ''),
            'subtotal' => (float) ($launch['amount'] ?? 0),
            'total' => (float) ($launch['amount'] ?? 0),
            'items' => [[
                'name' => sprintf(__('Launch Wall placement: %s', 'mds-launch-wall'), (string) ($launch['title'] ?? '')),
                'quantity' => 1,
                'unit_price' => (float) ($launch['amount'] ?? 0),
                'total' => (float) ($launch['amount'] ?? 0),
            ]],
            'manage_url' => esc_url_raw((string) $referrer),
            'metadata' => [
                'launch_wall_id' => absint($wall['id'] ?? 0),
                'launch_id' => absint($launch['id'] ?? 0),
            ],
        ]);
    }

    private function permission($request, $scope, $level) {
        return \MillionDollarScript\Extensions\Support::permission($request, $scope, $level);
    }

    private function field($name, $label, $value, $type = 'text', $required = false, $readonly = false, $description = '', $info = '') {
        $wide = 'textarea' === $type ? ' mds-launch-wall-field-wide' : '';
        $integer = in_array((string) $name, ['launch_limit', 'retention_days', 'submission_limit_per_hour', 'vote_limit_per_day'], true);
        echo '<label class="mds-launch-wall-field' . esc_attr($wide) . '"><span class="mds-launch-wall-label-text">' . esc_html($label) . $this->help_info($info) . '</span>';
        if ('textarea' === $type) {
            echo '<textarea name="' . esc_attr($name) . '" rows="3"' . ($required ? ' required' : '') . '>' . esc_textarea((string) $value) . '</textarea>';
        } else {
            echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '"' . ('number' === $type ? ($integer ? ' step="1" min="1"' : ' step="0.01" min="0"') : '') . ($required ? ' required' : '') . ($readonly ? ' readonly' : '') . '>';
        }
        if ('' !== (string) $description) {
            echo '<em class="mds-launch-wall-help">' . esc_html($description) . '</em>';
        }
        echo '</label>';
    }

    private function select($name, $label, $value, array $options, $description = '', $info = '') {
        echo '<label class="mds-launch-wall-field"><span class="mds-launch-wall-label-text">' . esc_html($label) . $this->help_info($info) . '</span><select name="' . esc_attr($name) . '">';
        foreach ($options as $key => $option_label) {
            echo '<option value="' . esc_attr($key) . '"' . selected((string) $value, (string) $key, false) . '>' . esc_html($option_label) . '</option>';
        }
        echo '</select>';
        if ('' !== (string) $description) {
            echo '<em class="mds-launch-wall-help">' . esc_html($description) . '</em>';
        }
        echo '</label>';
    }

    private function help_info($info) {
        if ('' === trim((string) $info) || !class_exists('\\MillionDollarScript\\Extensions\\Admin')) {
            return '';
        }

        return ' ' . \MillionDollarScript\Extensions\Admin::help($info);
    }

    private function moderation_button($launch_id, $action, $label) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('mds_launch_wall_moderate_' . absint($launch_id)); ?>
            <input type="hidden" name="action" value="mds_launch_wall_moderate">
            <input type="hidden" name="launch_id" value="<?php echo esc_attr((string) $launch_id); ?>">
            <input type="hidden" name="moderation" value="<?php echo esc_attr($action); ?>">
            <button class="button" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function admin_notice() {
        $status = sanitize_key((string) ($_GET['mds_lw_status'] ?? ''));
        if (!$status) {
            return;
        }

        $messages = [
            'saved' => __('Launch wall saved.', 'mds-launch-wall'),
            'moderated' => __('Launch submission updated.', 'mds-launch-wall'),
            'error' => __('The Launch Wall action could not be completed.', 'mds-launch-wall'),
        ];
        $class = 'error' === $status ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($messages[$status] ?? $messages['error']) . '</p></div>';
    }

    private function frontend_status_message($status) {
        $messages = [
            'submitted' => __('Your launch was submitted for review.', 'mds-launch-wall'),
            'invalid' => __('Please complete the required launch fields.', 'mds-launch-wall'),
            'mds_launch_wall_invalid_submission' => __('Launch name, valid email, and an HTTP or HTTPS launch URL are required.', 'mds-launch-wall'),
            'mds_launch_wall_terms_required' => __('Accept the placement terms and sponsorship disclosure before submitting.', 'mds-launch-wall'),
            'mds_launch_wall_invalid_logo_url' => __('Use a valid HTTP or HTTPS logo URL.', 'mds-launch-wall'),
            'mds_launch_wall_invalid_date' => __('Use a real calendar date for the launch date.', 'mds-launch-wall'),
            'mds_launch_wall_full' => __('This launch wall has reached its capacity.', 'mds-launch-wall'),
            'closed' => __('Launch submissions are closed.', 'mds-launch-wall'),
            'mds_launch_wall_closed' => __('Launch submissions are closed.', 'mds-launch-wall'),
            'rate_limited' => __('Please wait before sending another launch.', 'mds-launch-wall'),
            'failed' => __('The launch could not be submitted.', 'mds-launch-wall'),
            'checkout_failed' => __('The launch was saved, but payment checkout could not be started. No charge was made. Contact the site administrator before trying again.', 'mds-launch-wall'),
        ];

        return $messages[$status] ?? $messages['failed'];
    }

    private function vote_notice() {
        $status = sanitize_key((string) ($_GET['launch_wall_vote_status'] ?? ''));
        $messages = [
            'vote_recorded' => __('Your vote was recorded.', 'mds-launch-wall'),
            'vote_limited' => __('You have reached the voting limit for this launch.', 'mds-launch-wall'),
            'voting_closed' => __('Voting is not available for this launch.', 'mds-launch-wall'),
            'vote_failed' => __('The vote could not be recorded.', 'mds-launch-wall'),
        ];
        if (!isset($messages[$status])) {
            return;
        }
        $success = 'vote_recorded' === $status;
        echo '<p class="mds-launch-wall__notice mds-launch-wall__notice--' . ($success ? 'success' : 'error') . '" role="status">' . esc_html($messages[$status]) . '</p>';
    }

    private function validation_status(\WP_Error $error) {
        $allowed = [
            'mds_launch_wall_closed',
            'mds_launch_wall_full',
            'mds_launch_wall_invalid_submission',
            'mds_launch_wall_terms_required',
            'mds_launch_wall_invalid_logo_url',
            'mds_launch_wall_invalid_date',
        ];
        $code = sanitize_key((string) $error->get_error_code());

        return in_array($code, $allowed, true) ? $code : 'failed';
    }

    private function notify_submission(array $wall, array $launch) {
        $site_name = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        $admin_email = sanitize_email((string) ($wall['contact_email'] ?? ''));
        $submitter_email = sanitize_email((string) ($launch['submitter_email'] ?? ''));
        if (is_email($admin_email)) {
            wp_mail(
                $admin_email,
                sprintf(__('[%1$s] New launch awaiting review: %2$s', 'mds-launch-wall'), $site_name, (string) ($launch['title'] ?? '')),
                sprintf(__("A new launch was submitted to %1\$s.\n\nLaunch: %2\$s\nStatus: Awaiting moderation\n\nReview it: %3\$s", 'mds-launch-wall'), (string) ($wall['title'] ?? ''), (string) ($launch['title'] ?? ''), $this->admin_url(['wall_id' => absint($wall['id'] ?? 0)]))
            );
        }
        if (is_email($submitter_email)) {
            wp_mail(
                $submitter_email,
                sprintf(__('[%s] Launch submission received', 'mds-launch-wall'), $site_name),
                sprintf(__("We received your launch \"%1\$s\" for moderation on %2\$s. It will not appear publicly until its moderation and payment requirements are complete.", 'mds-launch-wall'), (string) ($launch['title'] ?? ''), $site_name)
            );
        }
    }

    private function pagination(array $collection, $query_key, array $context = []) {
        $current = max(1, absint($collection['page'] ?? 1));
        $pages = max(1, absint($collection['pages'] ?? 1));
        if ($pages <= 1) {
            return;
        }
        ?>
        <nav class="mds-launch-wall-pagination" aria-label="<?php esc_attr_e('Launch Wall pages', 'mds-launch-wall'); ?>">
            <?php if ($current > 1) : ?><a class="button" href="<?php echo esc_url($this->admin_url(array_merge($context, [$query_key => $current - 1]))); ?>"><?php esc_html_e('Previous', 'mds-launch-wall'); ?></a><?php endif; ?>
            <span><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'mds-launch-wall'), $current, $pages)); ?></span>
            <?php if ($current < $pages) : ?><a class="button" href="<?php echo esc_url($this->admin_url(array_merge($context, [$query_key => $current + 1]))); ?>"><?php esc_html_e('Next', 'mds-launch-wall'); ?></a><?php endif; ?>
        </nav>
        <?php
    }

    private function public_pagination(array $collection, $query_key) {
        $current = max(1, absint($collection['page'] ?? 1));
        $pages = max(1, absint($collection['pages'] ?? 1));
        if ($pages <= 1) {
            return;
        }
        $request_url = home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $base_url = remove_query_arg([$query_key, 'launch_wall_status'], $request_url);
        ?>
        <nav class="mds-launch-wall__pagination" aria-label="<?php esc_attr_e('Launch pages', 'mds-launch-wall'); ?>">
            <?php if ($current > 1) : ?><a href="<?php echo esc_url(add_query_arg($query_key, $current - 1, $base_url)); ?>"><?php esc_html_e('Previous', 'mds-launch-wall'); ?></a><?php endif; ?>
            <span><?php echo esc_html(sprintf(__('Page %1$d of %2$d', 'mds-launch-wall'), $current, $pages)); ?></span>
            <?php if ($current < $pages) : ?><a href="<?php echo esc_url(add_query_arg($query_key, $current + 1, $base_url)); ?>"><?php esc_html_e('Next', 'mds-launch-wall'); ?></a><?php endif; ?>
        </nav>
        <?php
    }

    private function admin_parent_slug() {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::admin_parent_slug('milliondollarscript');
        }

        return class_exists('\MillionDollarScript\Core\Runtime') || class_exists('\\MillionDollarScript\\Core\\Runtime') ? 'mds3' : 'milliondollarscript';
    }

    private function admin_url(array $args = []) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            return \MillionDollarScript\Extensions\Support::admin_url('mds-launch-wall', $args);
        }

        return add_query_arg(array_merge(['page' => 'mds-launch-wall'], $args), admin_url('admin.php'));
    }

    private function redirect(array $args) {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support')) {
            \MillionDollarScript\Extensions\Support::redirect_admin('mds-launch-wall', $args);
        }

        wp_safe_redirect($this->admin_url($args));
        exit;
    }

    private function checkout_redirect($url, $fallback_url = '') {
        if (class_exists('\\MillionDollarScript\\Extensions\\Support') && method_exists('\\MillionDollarScript\\Extensions\\Support', 'external_redirect')) {
            \MillionDollarScript\Extensions\Support::external_redirect($url, $fallback_url ?: home_url('/'));
        }

        $target = esc_url_raw((string) $url, ['http', 'https']);
        $parts = wp_parse_url($target);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!$target || empty($parts['host']) || !in_array($scheme, ['http', 'https'], true)) {
            $this->safe_redirect($fallback_url ?: home_url('/'));
        }

        wp_redirect($target, 302, 'Million Dollar Script - Launch Wall');
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

    private function rate_limited($scope, $limit, $window) {
        $scope = sanitize_key((string) $scope) ?: 'global';
        $limit = min(500, max(1, absint($limit)));
        $window = max(MINUTE_IN_SECONDS, absint($window));
        $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''));
        $bucket = (string) floor(time() / $window);
        $hash = hash_hmac('sha256', ($ip ?: 'unknown') . '|' . $scope . '|' . $bucket, wp_salt('nonce'));
        $key = 'mds_lw_rate_' . substr($hash, 0, 32);
        $count = absint(get_transient($key));
        if ($count >= $limit) {
            return true;
        }
        set_transient($key, $count + 1, $window + MINUTE_IN_SECONDS);
        return false;
    }

    private function format_money($amount, $currency) {
        $currency = Repository::effective_currency($currency);
        if (class_exists('\\MillionDollarScript\\Commerce\\Currency')) {
            return \MillionDollarScript\Commerce\Currency::format((float) $amount, $currency);
        }

        return trim(sprintf('%s %s', $currency, number_format_i18n((float) $amount, 2)));
    }
}
