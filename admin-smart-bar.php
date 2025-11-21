<?php
/**
 * Plugin Name: Admin Smart Bar
 * Plugin URI: https://codevera.ai
 * Description: A fast, searchable smart bar for WordPress admin with keyboard shortcuts
 * Version: 1.0.9
 * Author: Codevera
 * Author URI: https://codevera.ai
 * License: GPL v2 or later
 * Text Domain: admin-smart-bar
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('ADMIN_SMART_BAR_VERSION', '1.0.1');
define('ADMIN_SMART_BAR_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ADMIN_SMART_BAR_PLUGIN_URL', plugin_dir_url(__FILE__));

// Load Composer autoloader
if (file_exists(ADMIN_SMART_BAR_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once ADMIN_SMART_BAR_PLUGIN_DIR . 'vendor/autoload.php';
}

// Load plugin classes
require_once ADMIN_SMART_BAR_PLUGIN_DIR . 'includes/Page_Builder_Content_Extractor.php';
require_once ADMIN_SMART_BAR_PLUGIN_DIR . 'includes/Search_Engine.php';

// Main plugin class
class Admin_Smart_Bar {

    private static $instance = null;
    private $search_engine;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialise search engine
        if (class_exists('AdminSmartBar\Search_Engine')) {
            $this->search_engine = new \AdminSmartBar\Search_Engine();
        }

        add_action('admin_bar_menu', [$this, 'add_admin_bar_item'], 100);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_asb_search', [$this, 'ajax_search']);
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_footer', [$this, 'render_overlay']);
        add_action('wp_footer', [$this, 'render_overlay']);

        // YetiSearch incremental indexing hooks
        add_action('save_post', [$this, 'index_post_on_save'], 10, 1);
        add_action('delete_post', [$this, 'delete_post_from_index'], 10, 1);
        // Note: User indexing removed for security - users searched directly from WP database
        add_action('add_attachment', [$this, 'index_media_on_save'], 10, 1);
        add_action('edit_attachment', [$this, 'index_media_on_save'], 10, 1);
        add_action('delete_attachment', [$this, 'delete_media_from_index'], 10, 1);

        // WooCommerce-specific hooks for products (covers edge cases like stock updates)
        if (function_exists('WC') || class_exists('WooCommerce')) {
            add_action('woocommerce_update_product', [$this, 'index_post_on_save'], 10, 1);
            add_action('woocommerce_new_product', [$this, 'index_post_on_save'], 10, 1);
            add_action('woocommerce_delete_product', [$this, 'delete_post_from_index'], 10, 1);
        }
    }

    public function add_admin_bar_item($wp_admin_bar) {
        if (!current_user_can('edit_posts')) {
            return;
        }

        $wp_admin_bar->add_node([
            'id'    => 'admin-smart-bar',
            'title' => '<span class="ab-icon dashicons dashicons-search"></span><span class="ab-label">Smart Search</span>',
            'href'  => '#',
            'meta'  => [
                'class' => 'admin-smart-bar-trigger',
                'title' => 'Open Smart Search'
            ]
        ]);
    }

    public function enqueue_assets() {
        if (!current_user_can('edit_posts')) {
            return;
        }

        wp_enqueue_style(
            'admin-smart-bar',
            ADMIN_SMART_BAR_PLUGIN_URL . 'assets/css/admin-smart-bar.css',
            [],
            ADMIN_SMART_BAR_VERSION
        );

        // Enqueue Fuse.js library
        wp_enqueue_script(
            'fuse-js',
            ADMIN_SMART_BAR_PLUGIN_URL . 'assets/js/fuse.min.js',
            [],
            '7.0.0',
            true
        );

        wp_enqueue_script(
            'admin-smart-bar',
            ADMIN_SMART_BAR_PLUGIN_URL . 'assets/js/admin-smart-bar.js',
            ['fuse-js'],
            ADMIN_SMART_BAR_VERSION,
            true
        );

        wp_localize_script('admin-smart-bar', 'asbData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('asb_search_nonce'),
            'shortcut' => get_option('asb_keyboard_shortcut', 'ctrl+k'),
            'searchTypes' => get_option('asb_search_types', ['posts', 'pages', 'media', 'users'])
        ]);
    }

    public function ajax_search() {
        check_ajax_referer('asb_search_nonce', 'nonce');

        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';

        if (empty($query)) {
            wp_send_json_success([]);
        }

        // Load admin menu for AJAX context
        $this->load_admin_menu();

        $results = [];
        $search_types = get_option('asb_search_types', ['posts', 'pages', 'media', 'users']);

        // Use YetiSearch if available
        if ($this->search_engine) {
            $search_results = $this->search_engine->search($query, $search_types, 50);

            foreach ($search_results as $search_result) {
                $doc = $search_result['document'];
                $metadata = $doc['metadata'] ?? [];

                // Skip if type is user - handle separately
                $type = $doc['type'] ?? $metadata['type'] ?? null;
                if ($type === 'user') {
                    $user_id = $metadata['user_id'] ?? null;
                    if (!$user_id) {
                        continue;
                    }

                    $user = get_userdata($user_id);

                    if ($user) {
                        $results[] = [
                            'id' => $user->ID,
                            'title' => $user->display_name . ' (' . $user->user_login . ')',
                            'type' => 'User',
                            'url' => get_edit_user_link($user->ID),
                            'view_url' => get_author_posts_url($user->ID),
                            'status' => $user->user_email,
                            'icon' => 'dashicons-admin-users'
                        ];
                    }
                    continue;
                }

                // Handle posts, pages, products, media
                $post_id = $metadata['post_id'] ?? null;
                if (!$post_id) {
                    continue;
                }

                $post = get_post($post_id);

                if (!$post) {
                    continue;
                }

                // Determine view URL based on post status
                if ($post->post_status === 'publish') {
                    $view_url = get_permalink($post->ID);
                } else {
                    $view_url = get_preview_post_link($post->ID);
                }

                // Handle different post types
                if ($post->post_type === 'attachment') {
                    $results[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'type' => 'Media',
                        'url' => get_edit_post_link($post->ID, 'raw'),
                        'view_url' => wp_get_attachment_url($post->ID),
                        'status' => wp_get_attachment_image_url($post->ID, 'thumbnail') ? 'image' : 'file',
                        'icon' => 'dashicons-admin-media'
                    ];
                } elseif ($post->post_type === 'product') {
                    // Build status text with price if available
                    $status_text = ucfirst($post->post_status);

                    if (function_exists('wc_get_product')) {
                        $wc_product = wc_get_product($post->ID);
                        if ($wc_product && $wc_product->get_price()) {
                            $status_text .= ' - ' . wp_strip_all_tags(wc_price($wc_product->get_price()));
                        }
                    }

                    $results[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'type' => 'Product',
                        'url' => get_edit_post_link($post->ID, 'raw'),
                        'view_url' => $view_url,
                        'status' => $status_text,
                        'icon' => 'dashicons-products'
                    ];
                } else {
                    $results[] = [
                        'id' => $post->ID,
                        'title' => $post->post_title,
                        'type' => ucfirst($post->post_type),
                        'url' => get_edit_post_link($post->ID, 'raw'),
                        'view_url' => $view_url,
                        'status' => $post->post_status,
                        'icon' => 'dashicons-edit'
                    ];
                }
            }
        }

        // Add plugin-specific actions (only for installed plugins)
        $plugin_actions = $this->get_plugin_specific_actions($query);
        $results = array_merge($results, $plugin_actions);

        // Add admin actions with aliases (comprehensive search)
        $admin_actions = $this->get_admin_actions_with_aliases($query);
        $results = array_merge($results, $admin_actions);

        // Add dynamic admin menu items (includes custom plugin menus)
        $admin_menu_items = $this->get_admin_menu_items($query);
        $results = array_merge($results, $admin_menu_items);

        // Deduplicate results by URL (keep first occurrence)
        $unique_results = [];
        $seen_urls = [];

        foreach ($results as $result) {
            $url = $result['url'];
            if (!in_array($url, $seen_urls)) {
                $unique_results[] = $result;
                $seen_urls[] = $url;
            }
        }

        $results = $unique_results;

        // Limit results but allow more menu items to show
        $content_results = array_filter($results, function($r) {
            return in_array($r['type'], ['Post', 'Page', 'Media', 'User', 'Product']);
        });
        $menu_results = array_filter($results, function($r) {
            return $r['type'] === 'Menu';
        });

        // Group content results by type to prioritise Products
        $grouped_content = [
            'Product' => [],
            'Post' => [],
            'Page' => [],
            'Media' => [],
            'User' => []
        ];

        foreach ($content_results as $result) {
            if (isset($grouped_content[$result['type']])) {
                $grouped_content[$result['type']][] = $result;
            }
        }

        // Combine all results in priority order: Product, Post, Page, Media, User
        $prioritised_results = [];
        foreach (['Product', 'Post', 'Page', 'Media', 'User'] as $type) {
            $prioritised_results = array_merge($prioritised_results, $grouped_content[$type]);
        }

        $final_results = array_merge(
            $prioritised_results,
            $menu_results
        );

        wp_send_json_success($final_results);
    }


    /**
     * Index post when it's saved
     */
    public function index_post_on_save($post_id) {
        // Skip autosave and revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (wp_is_post_revision($post_id)) {
            return;
        }

        if ($this->search_engine) {
            $this->search_engine->index_post($post_id);
        }
    }

    /**
     * Delete post from index
     */
    public function delete_post_from_index($post_id) {
        if ($this->search_engine) {
            $this->search_engine->delete_document($post_id, 'post');
        }
    }

    /**
     * Index media when it's uploaded
     */
    public function index_media_on_save($attachment_id) {
        if ($this->search_engine) {
            $this->search_engine->index_media($attachment_id);
        }
    }

    /**
     * Delete media from index
     */
    public function delete_media_from_index($attachment_id) {
        if ($this->search_engine) {
            $this->search_engine->delete_document($attachment_id, 'media');
        }
    }


    private function load_admin_menu() {
        global $menu, $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv, $plugin_page, $_registered_pages;

        // Only load if not already loaded
        if (!empty($menu)) {
            return;
        }

        // Require necessary admin files
        if (!function_exists('get_plugin_page_hookname')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        if (!function_exists('wp_admin_bar_render')) {
            require_once ABSPATH . 'wp-admin/includes/admin-filters.php';
        }

        // Initialize menu arrays
        $menu = [];
        $submenu = [];
        $_wp_submenu_nopriv = [];
        $_wp_menu_nopriv = [];

        // Trigger menu building
        require_once ABSPATH . 'wp-admin/menu.php';
    }

    private function get_plugin_specific_actions($query) {
        // Ensure is_plugin_active() is available
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $plugin_actions = [
            // WooCommerce
            'woocommerce/woocommerce.php' => [
                ['title' => 'WooCommerce settings', 'url' => 'admin.php?page=wc-settings', 'icon' => 'dashicons-admin-generic', 'keywords' => ['woocommerce', 'woo', 'wc settings', 'woocommerce settings', 'shop settings', 'store settings']],
                ['title' => 'WooCommerce orders', 'url' => 'edit.php?post_type=shop_order', 'icon' => 'dashicons-list-view', 'keywords' => ['woocommerce orders', 'woo orders', 'orders', 'shop orders', 'customer orders']],
                ['title' => 'WooCommerce products', 'url' => 'edit.php?post_type=product', 'icon' => 'dashicons-products', 'keywords' => ['woocommerce products', 'woo products', 'products', 'shop products', 'manage products']],
                ['title' => 'Add product', 'url' => 'post-new.php?post_type=product', 'icon' => 'dashicons-plus', 'keywords' => ['add product', 'new product', 'create product', 'woocommerce add product']],
                ['title' => 'WooCommerce analytics', 'url' => 'admin.php?page=wc-admin&path=/analytics/overview', 'icon' => 'dashicons-chart-bar', 'keywords' => ['woocommerce analytics', 'woo analytics', 'shop analytics', 'sales analytics', 'revenue']],
            ],
            // Yoast SEO
            'wordpress-seo/wp-seo.php' => [
                ['title' => 'Yoast SEO settings', 'url' => 'admin.php?page=wpseo_dashboard', 'icon' => 'dashicons-admin-generic', 'keywords' => ['yoast', 'seo', 'yoast seo', 'seo settings', 'search engine', 'optimization']],
                ['title' => 'Search appearance', 'url' => 'admin.php?page=wpseo_titles', 'icon' => 'dashicons-visibility', 'keywords' => ['search appearance', 'yoast appearance', 'meta tags', 'titles', 'descriptions']],
                ['title' => 'SEO tools', 'url' => 'admin.php?page=wpseo_tools', 'icon' => 'dashicons-admin-tools', 'keywords' => ['seo tools', 'yoast tools', 'webmaster tools', 'bulk editor']],
            ],
            // Jetpack
            'jetpack/jetpack.php' => [
                ['title' => 'Jetpack dashboard', 'url' => 'admin.php?page=jetpack', 'icon' => 'dashicons-admin-generic', 'keywords' => ['jetpack', 'jetpack dashboard', 'jetpack settings']],
                ['title' => 'Jetpack settings', 'url' => 'admin.php?page=jetpack#/settings', 'icon' => 'dashicons-admin-settings', 'keywords' => ['jetpack settings', 'jetpack config', 'configure jetpack']],
            ],
            // Contact Form 7
            'contact-form-7/wp-contact-form-7.php' => [
                ['title' => 'Contact forms', 'url' => 'admin.php?page=wpcf7', 'icon' => 'dashicons-email', 'keywords' => ['contact form', 'contact forms', 'cf7', 'forms', 'contact form 7']],
                ['title' => 'Add contact form', 'url' => 'admin.php?page=wpcf7-new', 'icon' => 'dashicons-plus', 'keywords' => ['new contact form', 'add contact form', 'create form', 'new form']],
            ],
            // WPForms
            'wpforms-lite/wpforms.php' => [
                ['title' => 'WPForms', 'url' => 'admin.php?page=wpforms-overview', 'icon' => 'dashicons-feedback', 'keywords' => ['wpforms', 'forms', 'form builder', 'wp forms']],
                ['title' => 'Add new form', 'url' => 'admin.php?page=wpforms-builder', 'icon' => 'dashicons-plus', 'keywords' => ['new form', 'create form', 'add form', 'wpforms new']],
                ['title' => 'Form entries', 'url' => 'admin.php?page=wpforms-entries', 'icon' => 'dashicons-list-view', 'keywords' => ['form entries', 'submissions', 'wpforms entries', 'form submissions']],
            ],
            'wpforms/wpforms.php' => [
                ['title' => 'WPForms', 'url' => 'admin.php?page=wpforms-overview', 'icon' => 'dashicons-feedback', 'keywords' => ['wpforms', 'forms', 'form builder', 'wp forms']],
                ['title' => 'Add new form', 'url' => 'admin.php?page=wpforms-builder', 'icon' => 'dashicons-plus', 'keywords' => ['new form', 'create form', 'add form', 'wpforms new']],
                ['title' => 'Form entries', 'url' => 'admin.php?page=wpforms-entries', 'icon' => 'dashicons-list-view', 'keywords' => ['form entries', 'submissions', 'wpforms entries', 'form submissions']],
            ],
            // All in One SEO
            'all-in-one-seo-pack/all_in_one_seo_pack.php' => [
                ['title' => 'AIOSEO dashboard', 'url' => 'admin.php?page=aioseo', 'icon' => 'dashicons-admin-generic', 'keywords' => ['aioseo', 'all in one seo', 'seo', 'aio seo']],
                ['title' => 'AIOSEO search appearance', 'url' => 'admin.php?page=aioseo-search-appearance', 'icon' => 'dashicons-visibility', 'keywords' => ['aioseo search', 'search appearance', 'aioseo appearance']],
                ['title' => 'AIOSEO tools', 'url' => 'admin.php?page=aioseo-tools', 'icon' => 'dashicons-admin-tools', 'keywords' => ['aioseo tools', 'seo tools', 'aio tools']],
            ],
            // UpdraftPlus
            'updraftplus/updraftplus.php' => [
                ['title' => 'UpdraftPlus backups', 'url' => 'admin.php?page=updraftplus', 'icon' => 'dashicons-backup', 'keywords' => ['updraftplus', 'backup', 'backups', 'restore', 'updraft']],
                ['title' => 'UpdraftPlus settings', 'url' => 'admin.php?page=updraftplus&tab=settings', 'icon' => 'dashicons-admin-settings', 'keywords' => ['updraftplus settings', 'backup settings', 'updraft settings']],
            ],
            // WP Rocket
            'wp-rocket/wp-rocket.php' => [
                ['title' => 'WP Rocket settings', 'url' => 'admin.php?page=wprocket', 'icon' => 'dashicons-performance', 'keywords' => ['wp rocket', 'rocket', 'cache', 'caching', 'performance', 'speed']],
            ],
            // MonsterInsights
            'google-analytics-for-wordpress/googleanalytics.php' => [
                ['title' => 'MonsterInsights', 'url' => 'admin.php?page=monsterinsights_reports', 'icon' => 'dashicons-chart-line', 'keywords' => ['monsterinsights', 'analytics', 'google analytics', 'stats', 'statistics']],
                ['title' => 'MonsterInsights settings', 'url' => 'admin.php?page=monsterinsights_settings', 'icon' => 'dashicons-admin-settings', 'keywords' => ['monsterinsights settings', 'analytics settings']],
            ],
            // Elementor
            'elementor/elementor.php' => [
                ['title' => 'Elementor templates', 'url' => 'edit.php?post_type=elementor_library', 'icon' => 'dashicons-layout', 'keywords' => ['elementor', 'templates', 'elementor templates', 'page builder']],
                ['title' => 'Elementor settings', 'url' => 'admin.php?page=elementor', 'icon' => 'dashicons-admin-settings', 'keywords' => ['elementor settings', 'elementor config']],
            ],
            // Advanced Custom Fields
            'advanced-custom-fields/acf.php' => [
                ['title' => 'Custom fields', 'url' => 'edit.php?post_type=acf-field-group', 'icon' => 'dashicons-edit', 'keywords' => ['acf', 'custom fields', 'advanced custom fields', 'field groups']],
                ['title' => 'Add field group', 'url' => 'post-new.php?post_type=acf-field-group', 'icon' => 'dashicons-plus', 'keywords' => ['new field group', 'add field group', 'acf new', 'create fields']],
            ],
            'advanced-custom-fields-pro/acf.php' => [
                ['title' => 'Custom fields', 'url' => 'edit.php?post_type=acf-field-group', 'icon' => 'dashicons-edit', 'keywords' => ['acf', 'custom fields', 'advanced custom fields', 'field groups']],
                ['title' => 'Add field group', 'url' => 'post-new.php?post_type=acf-field-group', 'icon' => 'dashicons-plus', 'keywords' => ['new field group', 'add field group', 'acf new', 'create fields']],
            ],
        ];

        $matched = [];
        $query_lower = strtolower($query);

        // Check each plugin
        foreach ($plugin_actions as $plugin_path => $actions) {
            // Only include actions if the plugin is active
            if (!is_plugin_active($plugin_path)) {
                continue;
            }

            foreach ($actions as $action) {
                // Check if query matches any keyword
                foreach ($action['keywords'] as $keyword) {
                    if (stripos($keyword, $query_lower) !== false || stripos($query_lower, $keyword) !== false) {
                        $matched[] = [
                            'title' => $action['title'],
                            'type' => 'Menu',
                            'url' => admin_url($action['url']),
                            'icon' => $action['icon']
                        ];
                        break; // Only add once per action
                    }
                }
            }
        }

        return $matched;
    }

    private function get_admin_actions_with_aliases($query) {
        $actions = [
            // User Management
            ['title' => 'Create a new user', 'url' => 'user-new.php', 'icon' => 'dashicons-admin-users', 'keywords' => ['new user', 'add user', 'create user', 'user new', 'user add', 'user create', 'invite user', 'make user', 'register user']],
            ['title' => 'View all users', 'url' => 'users.php', 'icon' => 'dashicons-admin-users', 'keywords' => ['users', 'all users', 'user list', 'view users', 'manage users', 'see users', 'user management']],
            ['title' => 'Edit your profile', 'url' => 'profile.php', 'icon' => 'dashicons-admin-users', 'keywords' => ['profile', 'my profile', 'edit profile', 'user profile', 'account', 'my account', 'update profile']],

            // Posts
            ['title' => 'Create a new post', 'url' => 'post-new.php', 'icon' => 'dashicons-edit', 'keywords' => ['new post', 'add post', 'create post', 'write post', 'post new', 'post add', 'post create', 'make post', 'compose post']],
            ['title' => 'View all posts', 'url' => 'edit.php', 'icon' => 'dashicons-admin-post', 'keywords' => ['posts', 'all posts', 'view posts', 'post list', 'manage posts', 'see posts', 'edit posts']],
            ['title' => 'Manage categories', 'url' => 'edit-tags.php?taxonomy=category', 'icon' => 'dashicons-category', 'keywords' => ['categories', 'category', 'manage categories', 'edit categories', 'view categories', 'post categories', 'add category']],
            ['title' => 'Manage tags', 'url' => 'edit-tags.php?taxonomy=post_tag', 'icon' => 'dashicons-tag', 'keywords' => ['tags', 'tag', 'manage tags', 'edit tags', 'view tags', 'post tags', 'add tag']],

            // Pages
            ['title' => 'Create a new page', 'url' => 'post-new.php?post_type=page', 'icon' => 'dashicons-admin-page', 'keywords' => ['new page', 'add page', 'create page', 'page new', 'page add', 'page create', 'make page']],
            ['title' => 'View all pages', 'url' => 'edit.php?post_type=page', 'icon' => 'dashicons-admin-page', 'keywords' => ['pages', 'all pages', 'view pages', 'page list', 'manage pages', 'see pages', 'edit pages']],

            // Media
            ['title' => 'Upload media', 'url' => 'media-new.php', 'icon' => 'dashicons-upload', 'keywords' => ['upload', 'upload media', 'add media', 'new media', 'upload image', 'upload file', 'media upload', 'add image', 'add file']],
            ['title' => 'Media library', 'url' => 'upload.php', 'icon' => 'dashicons-admin-media', 'keywords' => ['media', 'media library', 'images', 'files', 'uploads', 'view media', 'manage media', 'all media', 'library']],

            // Comments
            ['title' => 'Manage comments', 'url' => 'edit-comments.php', 'icon' => 'dashicons-admin-comments', 'keywords' => ['comments', 'comment', 'manage comments', 'view comments', 'edit comments', 'moderate comments', 'comment moderation', 'all comments']],

            // Appearance
            ['title' => 'Change theme', 'url' => 'themes.php', 'icon' => 'dashicons-admin-appearance', 'keywords' => ['themes', 'theme', 'change theme', 'switch theme', 'appearance', 'design', 'look', 'activate theme']],
            ['title' => 'Customise your site', 'url' => 'customize.php', 'icon' => 'dashicons-admin-customizer', 'keywords' => ['customizer', 'customize', 'customise', 'theme customizer', 'appearance', 'design', 'edit appearance', 'style']],
            ['title' => 'Manage menus', 'url' => 'nav-menus.php', 'icon' => 'dashicons-menu', 'keywords' => ['menus', 'menu', 'navigation', 'nav', 'manage menus', 'edit menus', 'create menu', 'navigation menu']],
            ['title' => 'Manage widgets', 'url' => 'widgets.php', 'icon' => 'dashicons-screenoptions', 'keywords' => ['widgets', 'widget', 'sidebar', 'manage widgets', 'edit widgets', 'add widget']],
            ['title' => 'Edit theme files', 'url' => 'theme-editor.php', 'icon' => 'dashicons-editor-code', 'keywords' => ['theme editor', 'edit theme', 'theme files', 'theme code', 'css', 'php', 'template']],

            // Plugins
            ['title' => 'Install a plugin', 'url' => 'plugin-install.php', 'icon' => 'dashicons-admin-plugins', 'keywords' => ['install plugin', 'add plugin', 'new plugin', 'plugin install', 'get plugin', 'download plugin', 'plugin add']],
            ['title' => 'Manage plugins', 'url' => 'plugins.php', 'icon' => 'dashicons-admin-plugins', 'keywords' => ['plugins', 'plugin', 'manage plugins', 'view plugins', 'all plugins', 'installed plugins', 'activate plugin', 'deactivate plugin']],
            ['title' => 'Edit plugin files', 'url' => 'plugin-editor.php', 'icon' => 'dashicons-editor-code', 'keywords' => ['plugin editor', 'edit plugin', 'plugin files', 'plugin code']],

            // Settings
            ['title' => 'General settings', 'url' => 'options-general.php', 'icon' => 'dashicons-admin-settings', 'keywords' => ['general settings', 'settings', 'site settings', 'general', 'site title', 'tagline', 'timezone', 'date format']],
            ['title' => 'Writing settings', 'url' => 'options-writing.php', 'icon' => 'dashicons-edit', 'keywords' => ['writing', 'writing settings', 'post settings', 'default category']],
            ['title' => 'Reading settings', 'url' => 'options-reading.php', 'icon' => 'dashicons-book', 'keywords' => ['reading', 'reading settings', 'homepage', 'front page', 'posts per page', 'blog settings']],
            ['title' => 'Discussion settings', 'url' => 'options-discussion.php', 'icon' => 'dashicons-admin-comments', 'keywords' => ['discussion', 'discussion settings', 'comment settings', 'comments', 'moderation', 'avatars']],
            ['title' => 'Media settings', 'url' => 'options-media.php', 'icon' => 'dashicons-admin-media', 'keywords' => ['media settings', 'image settings', 'thumbnail', 'image sizes']],
            ['title' => 'Permalink settings', 'url' => 'options-permalink.php', 'icon' => 'dashicons-admin-links', 'keywords' => ['permalinks', 'permalink settings', 'url structure', 'links', 'pretty links', 'seo urls']],
            ['title' => 'Privacy settings', 'url' => 'options-privacy.php', 'icon' => 'dashicons-lock', 'keywords' => ['privacy', 'privacy settings', 'privacy policy', 'gdpr', 'data protection']],

            // Tools
            ['title' => 'Import content', 'url' => 'import.php', 'icon' => 'dashicons-download', 'keywords' => ['import', 'import content', 'import posts', 'import data', 'migrate', 'transfer in']],
            ['title' => 'Export content', 'url' => 'export.php', 'icon' => 'dashicons-upload', 'keywords' => ['export', 'export content', 'export posts', 'export data', 'backup', 'download content']],
            ['title' => 'Site health', 'url' => 'site-health.php', 'icon' => 'dashicons-admin-tools', 'keywords' => ['site health', 'health', 'diagnostics', 'debug', 'site status', 'performance', 'security check']],
            ['title' => 'Export personal data', 'url' => 'export-personal-data.php', 'icon' => 'dashicons-download', 'keywords' => ['export personal data', 'gdpr export', 'user data export', 'personal data']],
            ['title' => 'Erase personal data', 'url' => 'erase-personal-data.php', 'icon' => 'dashicons-trash', 'keywords' => ['erase personal data', 'delete personal data', 'gdpr erase', 'remove user data', 'forget user']],

            // Dashboard
            ['title' => 'Go to dashboard', 'url' => 'index.php', 'icon' => 'dashicons-dashboard', 'keywords' => ['dashboard', 'home', 'admin home', 'main', 'overview', 'admin dashboard']],
            ['title' => 'View updates', 'url' => 'update-core.php', 'icon' => 'dashicons-update', 'keywords' => ['updates', 'update', 'wordpress updates', 'plugin updates', 'theme updates', 'upgrade']],
        ];

        $matched = [];
        $query_lower = strtolower($query);

        foreach ($actions as $action) {
            // Check if query matches any keyword
            foreach ($action['keywords'] as $keyword) {
                if (stripos($keyword, $query_lower) !== false || stripos($query_lower, $keyword) !== false) {
                    $matched[] = [
                        'title' => $action['title'],
                        'type' => 'Menu',
                        'url' => admin_url($action['url']),
                        'icon' => $action['icon']
                    ];
                    break; // Only add once per action
                }
            }
        }

        return $matched;
    }

    private function get_admin_menu_items($query) {
        global $menu, $submenu;

        $menu_items = [];

        // Add dynamic menu items (includes custom post types and plugin menus)
        if (is_array($menu) && !empty($menu)) {
            // Process main menu items
            foreach ($menu as $menu_item) {
                // Skip separators and empty items
                if (empty($menu_item[0]) || strpos($menu_item[0], 'separator') !== false) {
                    continue;
                }

                // Clean up the title
                $title = wp_strip_all_tags($menu_item[0]);
                $url = $menu_item[2];
                $icon = $menu_item[6];

                // Skip if no proper URL
                if (empty($url)) {
                    continue;
                }

                // Build full URL
                if (strpos($url, '.php') !== false) {
                    $full_url = admin_url($url);
                } else {
                    $full_url = admin_url('admin.php?page=' . $url);
                }

                // Check if matches query
                if ((empty($query) || stripos($title, $query) !== false)) {
                    $menu_items[] = [
                        'title' => $title,
                        'type' => 'Menu',
                        'url' => $full_url,
                        'icon' => $this->get_dashicon_from_menu($icon)
                    ];
                }

                // Process submenu items
                if (isset($submenu[$menu_item[2]]) && is_array($submenu[$menu_item[2]])) {
                    foreach ($submenu[$menu_item[2]] as $submenu_item) {
                        $sub_title = wp_strip_all_tags($submenu_item[0]);
                        $sub_url = $submenu_item[2];

                        if (empty($sub_title) || empty($sub_url)) {
                            continue;
                        }

                        // Build full URL for submenu
                        if (strpos($sub_url, '.php') !== false) {
                            $sub_full_url = admin_url($sub_url);
                        } else {
                            $sub_full_url = admin_url('admin.php?page=' . $sub_url);
                        }

                        // Check if matches query
                        if ((empty($query) || stripos($sub_title, $query) !== false)) {
                            $menu_items[] = [
                                'title' => $sub_title,
                                'type' => 'Menu',
                                'url' => $sub_full_url,
                                'icon' => $this->get_dashicon_from_menu($icon)
                            ];
                        }
                    }
                }
            }
        }

        return $menu_items;
    }

    private function get_dashicon_from_menu($icon) {
        // If it's already a dashicon class
        if (strpos($icon, 'dashicons-') === 0) {
            return $icon;
        }

        // Map common menu icons to dashicons
        $icon_map = [
            'dashicons-dashboard' => 'dashicons-dashboard',
            'dashicons-admin-post' => 'dashicons-admin-post',
            'dashicons-admin-media' => 'dashicons-admin-media',
            'dashicons-admin-page' => 'dashicons-admin-page',
            'dashicons-admin-comments' => 'dashicons-admin-comments',
            'dashicons-admin-appearance' => 'dashicons-admin-appearance',
            'dashicons-admin-plugins' => 'dashicons-admin-plugins',
            'dashicons-admin-users' => 'dashicons-admin-users',
            'dashicons-admin-tools' => 'dashicons-admin-tools',
            'dashicons-admin-settings' => 'dashicons-admin-settings',
        ];

        // If icon contains a dashicon name, extract it
        if (is_string($icon)) {
            foreach ($icon_map as $key => $value) {
                if (strpos($icon, $key) !== false) {
                    return $value;
                }
            }
        }

        // Default icon
        return 'dashicons-admin-generic';
    }

    public function render_overlay() {
        if (!current_user_can('edit_posts')) {
            return;
        }
        ?>
        <div id="asb-overlay" class="asb-overlay" style="display: none;">
            <div class="asb-container">
                <div class="asb-search-box">
                    <span class="dashicons dashicons-search asb-search-icon"></span>
                    <input
                        type="text"
                        id="asb-search-input"
                        class="asb-input"
                        placeholder="Search posts, pages, media, or actions..."
                        autocomplete="off"
                    >
                    <button class="asb-clear-btn" id="asb-clear-btn" style="display: none;" aria-label="Clear search">
                        <span class="dashicons dashicons-no-alt"></span>
                    </button>
                    <span class="asb-shortcut-hint">ESC to close</span>
                </div>
                <div id="asb-results" class="asb-results"></div>
                <div class="asb-footer">
                    <span class="asb-tip">↑↓ Navigate · ↵ Open · ESC Close</span>
                    <a href="https://codevera.ai" target="_blank" rel="noopener noreferrer" class="asb-link">Built by codevera.ai</a>
                </div>
            </div>
        </div>
        <?php
    }

    public function add_settings_page() {
        add_options_page(
            'Admin Smart Bar Settings',
            'Admin Smart Bar',
            'manage_options',
            'admin-smart-bar',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('asb_settings', 'asb_keyboard_shortcut', [
            'sanitize_callback' => [$this, 'sanitize_keyboard_shortcut'],
        ]);
        register_setting('asb_settings', 'asb_search_types', [
            'sanitize_callback' => [$this, 'sanitize_search_types'],
        ]);

        add_settings_section(
            'asb_main_settings',
            'Smart Bar Settings',
            null,
            'admin-smart-bar'
        );

        add_settings_field(
            'asb_keyboard_shortcut',
            'Keyboard shortcut',
            [$this, 'render_shortcut_field'],
            'admin-smart-bar',
            'asb_main_settings'
        );

        add_settings_field(
            'asb_search_types',
            'Search in',
            [$this, 'render_search_types_field'],
            'admin-smart-bar',
            'asb_main_settings'
        );
    }

    public function sanitize_keyboard_shortcut($input) {
        $allowed_shortcuts = ['ctrl+k', 'ctrl+space', 'ctrl+/'];
        if (in_array($input, $allowed_shortcuts, true)) {
            return $input;
        }
        return 'ctrl+k'; // Default fallback
    }

    public function sanitize_search_types($input) {
        if (!is_array($input)) {
            return ['posts', 'pages', 'media', 'users']; // Default fallback
        }
        $allowed_types = ['posts', 'pages', 'media', 'users', 'products'];
        return array_values(array_intersect($input, $allowed_types));
    }


    public function render_shortcut_field() {
        $value = get_option('asb_keyboard_shortcut', 'ctrl+k');
        ?>
        <select name="asb_keyboard_shortcut" class="asb-select">
            <option value="ctrl+k" <?php selected($value, 'ctrl+k'); ?>>Ctrl+K (Cmd+K on Mac)</option>
            <option value="ctrl+space" <?php selected($value, 'ctrl+space'); ?>>Ctrl+Space (Cmd+Space on Mac)</option>
            <option value="ctrl+/" <?php selected($value, 'ctrl+/'); ?>>Ctrl+/ (Cmd+/ on Mac)</option>
        </select>
        <?php
    }

    public function render_search_types_field() {
        $value = get_option('asb_search_types', ['posts', 'pages', 'media', 'users']);
        ?>
        <div class="asb-checkbox-group">
            <label class="asb-checkbox-label">
                <input type="checkbox" name="asb_search_types[]" value="posts" <?php checked(in_array('posts', $value)); ?>>
                <span class="asb-checkbox-text">Posts</span>
            </label>
            <label class="asb-checkbox-label">
                <input type="checkbox" name="asb_search_types[]" value="pages" <?php checked(in_array('pages', $value)); ?>>
                <span class="asb-checkbox-text">Pages</span>
            </label>
            <label class="asb-checkbox-label">
                <input type="checkbox" name="asb_search_types[]" value="media" <?php checked(in_array('media', $value)); ?>>
                <span class="asb-checkbox-text">Media</span>
            </label>
            <label class="asb-checkbox-label">
                <input type="checkbox" name="asb_search_types[]" value="users" <?php checked(in_array('users', $value)); ?>>
                <span class="asb-checkbox-text">Users</span>
            </label>
            <label class="asb-checkbox-label">
                <input type="checkbox" name="asb_search_types[]" value="products" <?php checked(in_array('products', $value)); ?>>
                <span class="asb-checkbox-text">Products</span>
            </label>
        </div>
        <?php
    }


    public function render_settings_page() {
        // Handle re-index action
        if (isset($_POST['asb_reindex']) && check_admin_referer('asb_reindex', 'asb_reindex_nonce')) {
            if ($this->search_engine) {
                $result = $this->search_engine->reindex_all();
                echo '<div class="notice notice-success is-dismissible"><p>Successfully re-indexed ' . esc_html(number_format($result['total'])) . ' items in ' . esc_html(number_format($result['duration'], 2)) . ' seconds.</p></div>';
            }
        }

        // Get search stats
        $search_stats = $this->get_search_stats();
        ?>
        <div class="wrap asb-settings-wrap">
            <div class="asb-settings-header">
                <div class="asb-header-content">
                    <div class="asb-header-icon">
                        <svg width="48" height="48" viewBox="0 0 256 256" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <linearGradient id="iconGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#4F46E5;stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#7C3AED;stop-opacity:1" />
                                </linearGradient>
                            </defs>
                            <rect width="256" height="256" rx="48" fill="url(#iconGradient)"/>
                            <rect x="40" y="100" width="176" height="56" rx="28" fill="white" opacity="0.95"/>
                            <circle cx="70" cy="128" r="12" fill="none" stroke="#6B7280" stroke-width="3"/>
                            <line x1="79" y1="137" x2="88" y2="146" stroke="#6B7280" stroke-width="3" stroke-linecap="round"/>
                            <rect x="100" y="120" width="80" height="4" rx="2" fill="#D1D5DB" opacity="0.6"/>
                            <rect x="100" y="132" width="50" height="4" rx="2" fill="#D1D5DB" opacity="0.4"/>
                        </svg>
                    </div>
                    <div class="asb-header-text">
                        <h1>Admin Smart Bar</h1>
                        <p class="asb-subtitle">Configure your quick search experience</p>
                    </div>
                </div>
            </div>

            <div class="asb-settings-container">
                <div class="asb-settings-main">
                    <form method="post" action="options.php" class="asb-settings-form">
                        <?php settings_fields('asb_settings'); ?>

                        <div class="asb-card">
                            <div class="asb-card-header">
                                <h2>Keyboard shortcut</h2>
                                <p class="asb-card-description">Choose how to activate the smart bar</p>
                            </div>
                            <div class="asb-card-body">
                                <?php $this->render_shortcut_field(); ?>
                            </div>
                        </div>

                        <div class="asb-card">
                            <div class="asb-card-header">
                                <h2>Search content</h2>
                                <p class="asb-card-description">Select which content types to include in search results</p>
                            </div>
                            <div class="asb-card-body">
                                <?php $this->render_search_types_field(); ?>
                            </div>
                        </div>

                        <div class="asb-submit-wrapper">
                            <?php submit_button('Save settings', 'primary', 'submit', false); ?>
                        </div>
                    </form>
                </div>

                <div class="asb-settings-sidebar">
                    <div class="asb-card asb-help-card">
                        <div class="asb-card-header">
                            <h3>Quick start</h3>
                        </div>
                        <div class="asb-card-body">
                            <p>Press your keyboard shortcut anywhere in the admin area to open the smart bar.</p>
                            <div class="asb-help-list">
                                <div class="asb-help-item">
                                    <span>Use keyboard shortcuts to navigate</span>
                                </div>
                                <div class="asb-help-item">
                                    <span>Search across multiple content types</span>
                                </div>
                                <div class="asb-help-item">
                                    <span>Access results instantly</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="asb-card asb-help-card">
                        <div class="asb-card-header">
                            <h3>Search index</h3>
                        </div>
                        <div class="asb-card-body">
                            <div class="asb-cache-stats">
                                <p><strong>Status:</strong> <?php echo esc_html($search_stats['status']); ?></p>
                                <?php if ($search_stats['database_size']): ?>
                                    <p><strong>Database size:</strong> <?php echo esc_html($search_stats['database_size']); ?></p>
                                <?php endif; ?>
                            </div>
                            <form method="post" style="margin-top: 15px;">
                                <?php wp_nonce_field('asb_reindex', 'asb_reindex_nonce'); ?>
                                <button type="submit" name="asb_reindex" class="button button-primary" style="width: 100%;">
                                    Re-index all content
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Get the search engine instance
     */
    public function get_search_engine() {
        return $this->search_engine;
    }

    /**
     * Invalidate all search caches
     */
    public function invalidate_cache($post_id = null) {
        // Don't invalidate on autosave or revision
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if ($post_id && wp_is_post_revision($post_id)) {
            return;
        }

        delete_transient('asb_cached_posts');
        delete_transient('asb_cached_pages');
        delete_transient('asb_cached_post_page');
        delete_transient('asb_cached_media');
        delete_transient('asb_cached_products');
        delete_transient('asb_cached_users');
    }

    /**
     * Get search index statistics
     */
    private function get_search_stats() {
        $stats = [
            'status' => 'Not indexed',
            'items' => 0,
            'database_size' => '',
            'breakdown' => []
        ];

        if ($this->search_engine) {
            $engine_stats = $this->search_engine->get_stats();

            if ($engine_stats['total_documents'] > 0) {
                $stats['status'] = 'Active';
                $stats['items'] = number_format($engine_stats['total_documents']);
                $stats['breakdown'] = $engine_stats['breakdown'] ?? [];

                // Format database size
                $size_bytes = $engine_stats['database_size'];
                if ($size_bytes > 1048576) {
                    $stats['database_size'] = number_format($size_bytes / 1048576, 2) . ' MB';
                } elseif ($size_bytes > 1024) {
                    $stats['database_size'] = number_format($size_bytes / 1024, 2) . ' KB';
                } else {
                    $stats['database_size'] = $size_bytes . ' bytes';
                }
            }
        }

        return $stats;
    }

    /**
     * Get or build cached posts/pages
     */
    private function get_cached_posts($post_types) {
        $cache_key = 'asb_cached_' . implode('_', $post_types);
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        // Build full cache - convert WP_Post to arrays immediately
        $args = [
            'post_type' => $post_types,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false
        ];

        $posts = get_posts($args);

        // Convert WP_Post objects to arrays for Fuse performance
        $data = [];
        foreach ($posts as $post) {
            $data[] = [
                'ID' => $post->ID,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status
            ];
        }

        set_transient($cache_key, $data, HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Get or build cached media
     */
    private function get_cached_media() {
        $cached = get_transient('asb_cached_media');

        if ($cached !== false) {
            return $cached;
        }

        $args = [
            'post_type' => 'attachment',
            'posts_per_page' => -1,
            'post_status' => 'inherit',
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false
        ];

        $posts = get_posts($args);

        // Convert WP_Post objects to arrays for Fuse performance
        $data = [];
        foreach ($posts as $post) {
            $data[] = [
                'ID' => $post->ID,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status
            ];
        }

        set_transient('asb_cached_media', $data, HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Get or build cached products
     */
    private function get_cached_products() {
        $cached = get_transient('asb_cached_products');

        if ($cached !== false) {
            return $cached;
        }

        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false
        ];

        $posts = get_posts($args);

        // Convert WP_Post objects to arrays for Fuse performance
        $data = [];
        foreach ($posts as $post) {
            $data[] = [
                'ID' => $post->ID,
                'post_title' => $post->post_title,
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_type' => $post->post_type,
                'post_status' => $post->post_status
            ];
        }

        set_transient('asb_cached_products', $data, HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Get or build cached users
     */
    private function get_cached_users() {
        $cached = get_transient('asb_cached_users');

        if ($cached !== false) {
            return $cached;
        }

        $args = [
            'number' => -1,
            'fields' => 'all'
        ];

        $users = get_users($args);

        // Convert WP_User objects to arrays for Fuse performance
        $data = [];
        foreach ($users as $user) {
            $data[] = [
                'ID' => $user->ID,
                'user_login' => $user->user_login,
                'display_name' => $user->display_name,
                'user_email' => $user->user_email
            ];
        }

        set_transient('asb_cached_users', $data, HOUR_IN_SECONDS);

        return $data;
    }

    /**
     * Search cached posts/pages/media/products by query
     * Uses fast strpos matching for instant results
     */
    private function search_cached_data($data, $query) {
        if (empty($data)) {
            return [];
        }

        $query_lower = strtolower($query);
        $results = [];

        foreach ($data as $item) {
            $match_found = false;
            $score = 0;

            // Search title, content, and excerpt
            $title_lower = strtolower($item['post_title']);
            $content_lower = strtolower($item['post_content'] ?? '');
            $excerpt_lower = strtolower($item['post_excerpt'] ?? '');

            if (strpos($title_lower, $query_lower) !== false) {
                $match_found = true;
                $score = 100 - strpos($title_lower, $query_lower);
            } elseif (strpos($content_lower, $query_lower) !== false) {
                $match_found = true;
                $score = 60;
            } elseif (strpos($excerpt_lower, $query_lower) !== false) {
                $match_found = true;
                $score = 50;
            }

            if ($match_found) {
                $results[] = ['item' => (object) $item, 'score' => $score];
            }
        }

        // Sort by score (highest first)
        usort($results, function($a, $b) {
            return $b['score'] - $a['score'];
        });

        return array_column($results, 'item');
    }

    /**
     * Get context around a match for debugging
     */
    private function get_match_context($text, $query, $context_length = 50) {
        $pos = strpos($text, $query);
        if ($pos === false) {
            return '';
        }

        // Get surrounding context
        $start = max(0, $pos - $context_length);
        $end = min(strlen($text), $pos + strlen($query) + $context_length);
        $context = substr($text, $start, $end - $start);

        // Add ellipsis if truncated
        if ($start > 0) {
            $context = '...' . $context;
        }
        if ($end < strlen($text)) {
            $context = $context . '...';
        }

        return $context;
    }

    /**
     * Search cached users by query
     * Uses fast strpos matching for instant results
     */
    private function search_cached_users($users, $query) {
        $query_lower = strtolower($query);
        $results = [];

        foreach ($users as $user) {
            // Data is arrays from cache
            $login_lower = strtolower($user['user_login'] ?? '');
            $name_lower = strtolower($user['display_name'] ?? '');
            $email_lower = strtolower($user['user_email'] ?? '');

            if (strpos($login_lower, $query_lower) !== false ||
                strpos($name_lower, $query_lower) !== false ||
                strpos($email_lower, $query_lower) !== false) {
                $results[] = (object) $user;
            }
        }

        return $results;
    }
}

// Activation hook - run initial indexing
register_activation_hook(__FILE__, function() {
    // Schedule initial indexing to run after plugin activation
    if (!wp_next_scheduled('asb_initial_index')) {
        wp_schedule_single_event(time() + 10, 'asb_initial_index');
    }
});

// Handle initial indexing
add_action('asb_initial_index', function() {
    $plugin = Admin_Smart_Bar::get_instance();
    if ($plugin && method_exists($plugin, 'get_search_engine')) {
        $search_engine = $plugin->get_search_engine();
        if ($search_engine) {
            $search_engine->reindex_all();
        }
    }
});

// Initialize plugin
Admin_Smart_Bar::get_instance();
