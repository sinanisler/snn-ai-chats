<?php
/**
 * Plugin Name: SNN AI Chat
 * Plugin URI: https://sinanisler.com
 * Description: Advanced AI Chat Plugin with OpenRouter, OpenAI, and Custom API support
 * Version: 0.4.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author: sinanisler
 * Author URI: https://sinanisler.com
 * License: GPL v3
 */


if (!defined('ABSPATH')) { exit; }

define('SNN_AI_CHAT_VERSION', '1.2.1');
define('SNN_AI_CHAT_PLUGIN_DIR', plugin_dir_path((string)__FILE__));
define('SNN_AI_CHAT_PLUGIN_URL', plugin_dir_url((string)__FILE__));

class SNN_AI_Chat {
    /**
     * AJAX: Update user_name and user_email for an existing session
     */
    public function update_user_info_ajax() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $user_name = sanitize_text_field($_POST['user_name'] ?? '');
        $user_email = sanitize_email($_POST['user_email'] ?? '');
        if (empty($session_id) || (empty($user_name) && empty($user_email))) {
            wp_send_json_error('Missing data.');
        }
        global $wpdb;
        $data = array();
        if (!empty($user_name)) $data['user_name'] = $user_name;
        if (!empty($user_email)) $data['user_email'] = $user_email;
        if ($data) {
            $wpdb->update(
                $wpdb->prefix . 'snn_chat_sessions',
                $data,
                array('session_id' => $session_id),
                array_fill(0, count($data), '%s'),
                array('%s')
            );
        }
        wp_send_json_success();
    }

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'handle_admin_form_submissions'));
        add_action('admin_init', array($this, 'handle_delete_actions'));

        add_action('admin_init', array($this, 'handle_reset_action'));
        add_action('admin_action_snn_delete_data', array($this, 'handle_delete_data_from_link'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
        add_action('wp_ajax_snn_ai_chat_api', array($this, 'handle_chat_api'));
        add_action('wp_ajax_nopriv_snn_ai_chat_api', array($this, 'handle_chat_api'));
        
        add_action('wp_ajax_snn_get_models', array($this, 'get_models'));
        add_action('wp_action_snn_get_model_details', array($this, 'get_model_details'));
        add_action('wp_ajax_snn_get_model_details', array($this, 'get_model_details'));
        add_action('wp_ajax_snn_delete_chat', array($this, 'delete_chat'));
        add_action('wp_footer', array($this, 'render_frontend_chats'));
        // New AJAX action to fetch session messages and details
        add_action('wp_ajax_snn_get_session_details_and_messages', array($this, 'get_session_details_and_messages'));
        add_action('wp_ajax_nopriv_snn_get_session_details_and_messages', array($this, 'get_session_details_and_messages'));
        // New AJAX action to fetch all public posts/pages for datalist
        add_action('wp_ajax_snn_get_public_posts', array($this, 'get_all_public_posts_for_datalist'));
        add_action('wp_ajax_nopriv_snn_get_public_posts', array($this, 'get_all_public_posts_for_datalist'));

        // AJAX: Update user info for session
        add_action('wp_ajax_snn_update_user_info', array($this, 'update_user_info_ajax'));
        add_action('wp_ajax_nopriv_snn_update_user_info', array($this, 'update_user_info_ajax'));


        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        add_action('load-admin_page_snn-ai-chat-preview', array($this, 'set_preview_page_title'));
        add_action('load-admin_page_snn-ai-chat-session-history', array($this, 'set_session_history_page_title'));
    }

    public function init() {
        $this->create_post_types();
    }

    public function handle_admin_form_submissions() {
        // Handle saving from the main Settings, Tools, or individual Chat pages
        if (isset($_POST['snn_ai_chat_settings_nonce'])) {
            $this->save_settings();
        }
        
        if (!isset($_GET['page']) || $_GET['page'] !== 'snn-ai-chat-chats' || !isset($_POST['submit_chat_settings'])) {
            return;
        }
        
        $is_new_chat = empty($_POST['chat_id']);
        
        $saved_chat_id = $this->save_chat_settings_form_submit();
        
        if ($saved_chat_id && !is_wp_error($saved_chat_id)) {
            if ($is_new_chat) {
                wp_safe_redirect(admin_url('admin.php?page=snn-ai-chat-chats&action=edit&id=' . $saved_chat_id . '&snn_new_chat_saved=1'));
            } else {
                wp_safe_redirect(admin_url('admin.php?page=snn-ai-chat-chats&action=edit&id=' . $saved_chat_id . '&snn_chat_updated=1'));
            }
            exit;
        }
    }

    public function handle_delete_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'snn-ai-chat-history') {            return;            }
        if (!current_user_can('manage_options')) {            return;            }
        global $wpdb;
        if (isset($_POST['action']) && $_POST['action'] === 'delete_session') {
            if (!isset($_POST['snn_delete_session_nonce']) || !wp_verify_nonce(sanitize_text_field((string)wp_unslash($_POST['snn_delete_session_nonce'] ?? '')), 'snn_delete_session')) {
                wp_die(esc_html__('Nonce verification failed.', 'snn-ai-chat'));
            }
            $session_id = sanitize_text_field($_POST['session_id'] ?? '');
            if (!empty($session_id)) {
                $wpdb->delete(
                    $wpdb->prefix . 'snn_chat_messages',
                    array('session_id' => $session_id),
                    array('%s')
                );
                $wpdb->delete(
                    $wpdb->prefix . 'snn_chat_sessions',
                    array('session_id' => $session_id),
                    array('%s')
                );
                wp_safe_redirect(admin_url('admin.php?page=snn-ai-chat-history&snn_session_deleted=1'));
                exit;
            }
        }

        if (isset($_POST['action']) && $_POST['action'] === 'delete_all_sessions') {
            if (!isset($_POST['snn_delete_all_sessions_nonce']) || !wp_verify_nonce(sanitize_text_field((string)wp_unslash($_POST['snn_delete_all_sessions_nonce'] ?? '')), 'snn_delete_all_sessions')) {
                wp_die(esc_html__('Nonce verification failed.', 'snn-ai-chat'));
            }
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}snn_chat_messages");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}snn_chat_sessions");
            wp_safe_redirect(admin_url('admin.php?page=snn-ai-chat-history&snn_all_sessions_deleted=1'));
            exit;
        }
    }

    public function activate() {
        $this->create_database_tables();
        $this->create_post_types();
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function create_post_types() {
        register_post_type('snn_ai_chat', array(
            'labels' => array(
                'name' => 'AI Chats',
                'singular_name' => 'AI Chat'
            ),
            'public' => false,
            'show_ui' => false,
            'capability_type' => 'post',
            'supports' => array('title', 'custom-fields')
        ));

        register_post_type('snn_chat_history', array(
            'labels' => array(
                'name' => 'Chat History',
                'singular_name' => 'Chat History'
            ),
            'public' => false,
            'show_ui' => false,
            'capability_type' => 'post',
            'supports' => array('title', 'editor', 'custom-fields')
        ));
    }

    public function create_database_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $table_name = $wpdb->prefix . 'snn_chat_sessions';
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            chat_id bigint(20) unsigned NOT NULL,
            session_id varchar(255) NOT NULL,
            user_name varchar(255),
            user_email varchar(255),
            ip_address varchar(45),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY chat_id (chat_id),
            KEY session_id (session_id)
        ) $charset_collate;";

        $table_name_messages = $wpdb->prefix . 'snn_chat_messages';
        $sql_messages = "CREATE TABLE $table_name_messages (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            session_id varchar(255) NOT NULL,
            message text NOT NULL,
            response text NOT NULL,
            tokens_used int DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql_messages);
    }

    public function admin_menu() {
        add_menu_page(
            'SNN AI Chat',
            'SNN AI Chat',
            'manage_options',
            'snn-ai-chat',
            array($this, 'dashboard_page'),
            'dashicons-format-chat',
            81
        );

        add_submenu_page(
            'snn-ai-chat',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'snn-ai-chat',
            array($this, 'dashboard_page')
        );
        
        add_submenu_page(
            'snn-ai-chat',
            'Chats',
            'Chats',
            'manage_options',
            'snn-ai-chat-chats',
            array($this, 'chats_page')
        );

        add_submenu_page(
            'snn-ai-chat',
            'Chat History',
            'Chat History',
            'manage_options',
            'snn-ai-chat-history',
            array($this, 'chat_history_page')
        );
        
        add_submenu_page(
            'snn-ai-chat',
            'Settings',
            'Settings',
            'manage_options',
            'snn-ai-chat-settings',
            array($this, 'settings_page')
        );

        add_submenu_page(
            '',
            'Session History',
            'Session History',
            'manage_options',
            'snn-ai-chat-session-history',
            array($this, 'session_history_page')
        );

        add_submenu_page(
            '',
            'Chat Preview',
            'Chat Preview',
            'manage_options',
            'snn-ai-chat-preview',
            array($this, 'preview_page')
        );
    }

    public function set_preview_page_title() {
        global $admin_title, $title;
        $admin_title = 'SNN AI Chat Preview';
        $title = 'SNN AI Chat Preview';
    }

    public function set_session_history_page_title() {
        global $admin_title, $title;
        $admin_title = 'SNN AI Chat Session History';
        $title = 'SNN AI Chat Session History';
    }

    public function admin_enqueue_scripts($hook) {
        if (empty($hook) || strpos((string)$hook, 'snn-ai-chat') === false) {
            return;
        }

        wp_enqueue_style('snn-ai-chat-admin', SNN_AI_CHAT_PLUGIN_URL . 'snn-ai-chat-admin.css', array(), SNN_AI_CHAT_VERSION);
        wp_enqueue_script('snn-ai-chat-admin', SNN_AI_CHAT_PLUGIN_URL . 'snn-ai-chat-admin.js', array('jquery'), SNN_AI_CHAT_VERSION, true);

        wp_enqueue_script('tailwind-css', 'https://cdn.tailwindcss.com', array(), null);
        wp_enqueue_script('tippy-js', 'https://unpkg.com/@popperjs/core@2', array(), null, true);
        wp_enqueue_script('tippy-bundle', 'https://unpkg.com/tippy.js@6', array('tippy-js'), null, true);

        $global_settings = $this->get_settings();

        wp_localize_script('snn-ai-chat-admin', 'snn_ai_chat_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snn_ai_chat_nonce'),
            'global_api_provider' => $global_settings['api_provider'],
            'global_openrouter_api_key' => $global_settings['openrouter_api_key'],
            'global_openai_api_key' => $global_settings['openai_api_key'],
            'global_custom_api_endpoint' => $global_settings['custom_api_endpoint'], // New
            'global_custom_api_key' => $global_settings['custom_api_key'], // New
            'global_custom_model' => $global_settings['custom_model'], // New
            'global_temperature' => $global_settings['temperature'] ?? 0.7,
            'global_max_tokens' => $global_settings['max_tokens'] ?? 500,
            'global_top_p' => $global_settings['top_p'] ?? 1.0,
            'global_frequency_penalty' => $global_settings['frequency_penalty'] ?? 0.0,
            'global_presence_penalty' => $global_settings['presence_penalty'] ?? 0.0,
        ));

        if ($hook === 'admin_page_snn-ai-chat-preview') {
            $this->frontend_enqueue_scripts();
        }
    }

    public function frontend_enqueue_scripts() {
        wp_enqueue_style('snn-ai-chat-frontend', SNN_AI_CHAT_PLUGIN_URL . 'snn-ai-chat-frontend.css', array(), SNN_AI_CHAT_VERSION);

        wp_enqueue_style('dashicons');

        wp_enqueue_script('jquery');
        // Enqueue markdown.min.js for frontend rendering
        wp_enqueue_script('markdown-js', SNN_AI_CHAT_PLUGIN_URL . 'js/markdown.min.js', array(), SNN_AI_CHAT_VERSION, true);


        wp_localize_script('jquery', 'snn_ai_chat_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snn_ai_chat_nonce')
        ));
    }

    public function dashboard_page() {
        $stats = $this->get_dashboard_stats();
        ?>
        <div class="wrap">
            <h1>SNN AI Chat Dashboard</h1>
            
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-4" id="snn-dashboard-stats-section">
                <div class="bg-white p-4 rounded-lg shadow stats-block" id="snn-active-chats-block">
                    <h3 class="text-lg font-semibold text-gray-700">Active Chats</h3>
                    <p class="text-3xl font-bold text-blue-600"><?php echo esc_html($stats['active_chats']); ?></p>
                    <p class="text-sm text-gray-500">Total chat instances</p>
                </div>
                
                <div class="bg-white p-4 rounded-lg shadow stats-block" id="snn-total-tokens-block">
                    <h3 class="text-lg font-semibold text-gray-700">Total Tokens</h3>
                    <p class="text-3xl font-bold text-green-600"><?php echo esc_html(number_format($stats['total_tokens'])); ?></p>
                    <p class="text-sm text-gray-500">Tokens processed</p>
                </div>
                
                <div class="bg-white p-4 rounded-lg shadow stats-block" id="snn-total-sessions-block">
                    <h3 class="text-lg font-semibold text-gray-700">Total Sessions</h3>
                    <p class="text-3xl font-bold text-purple-600"><?php echo esc_html($stats['total_sessions']); ?></p>
                    <p class="text-sm text-gray-500">Chat sessions started</p>
                </div>
                
                <div class="bg-white p-4 rounded-lg shadow stats-block" id="snn-today-sessions-block">
                    <h3 class="text-lg font-semibold text-gray-700">Today's Sessions</h3>
                    <p class="text-3xl font-bold text-orange-600"><?php echo esc_html($stats['today_sessions']); ?></p>
                    <p class="text-sm text-gray-500">Sessions today</p>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow mb-4" id="snn-dashboard-quick-actions-section">
                <h2 class="text-xl font-semibold mb-4">Quick Actions</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats&action=new')); ?>" class="bg-black text-white px-6 py-3 rounded-lg text-lg text-center quick-action-btn hover:bg-[#1b1b1b] transition-colors duration-200" id="snn-create-chat-btn">
                        Create New Chat
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats')); ?>" class="bg-black text-white px-6 py-3 rounded-lg text-lg text-center quick-action-btn hover:bg-[#1b1b1b] transition-colors duration-200" id="snn-manage-chats-btn">
                        Manage Chats
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-settings')); ?>" class="bg-black text-white px-6 py-3 rounded-lg text-lg text-center quick-action-btn hover:bg-[#1b1b1b] transition-colors duration-200" id="snn-settings-btn">
                        Settings
                    </a>
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4" id="snn-dashboard-usage-summary-section">
                <div class="bg-white p-6 rounded-lg shadow usage-stats-block" id="snn-today-usage-block">
                    <h2 class="text-xl font-semibold mb-4">Today's Usage</h2>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span>Messages:</span>
                            <span class="font-semibold"><?php echo esc_html($stats['today_messages']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Tokens:</span>
                            <span class="font-semibold"><?php echo esc_html(number_format($stats['today_tokens'])); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Sessions:</span>
                            <span class="font-semibold"><?php echo esc_html($stats['today_sessions']); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow usage-stats-block" id="snn-month-usage-block">
                    <h2 class="text-xl font-semibold mb-4">This Month's Usage</h2>
                    <div class="space-y-2">
                        <div class="flex justify-between">
                            <span>Messages:</span>
                            <span class="font-semibold"><?php echo esc_html($stats['month_messages']); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Tokens:</span>
                            <span class="font-semibold"><?php echo esc_html(number_format($stats['month_tokens'])); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span>Sessions:</span>
                            <span class="font-semibold"><?php echo esc_html($stats['month_sessions']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="bg-white p-6 rounded-lg shadow recent-activity-block" id="snn-dashboard-recent-history-section">
                <h2 class="text-xl font-semibold mb-4">Recent Chat History</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200" id="snn-recent-history-table">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Session</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Messages</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tokens</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="snn-recent-history-table-body">
                            <?php
                            $recent_activities = $this->get_recent_activities(5);
                            foreach ($recent_activities as $activity) {
                                ?>
                                <tr class="activity-row" id="snn-recent-activity-row-<?php echo esc_attr($activity->session_id); ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(substr((string)$activity->session_id, 0, 8)); ?>...</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html($activity->user_name ?: 'Anonymous'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html($activity->message_count); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(number_format($activity->total_tokens)); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(date('M j, Y H:i', strtotime($activity->created_at))); ?></td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-center mt-4">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-history')); ?>" class="bg-black text-white px-4 py-2 rounded-lg text-center quick-action-btn hover:bg-[#1b1b1b] transition-colors duration-200" id="snn-view-all-history-btn">
                        View All Chat History
                    </a>
                </div>
            </div>
        </div>
        <?php
    }

    public function settings_page() {
        if (isset($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
        }
        
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>SNN AI Chat Settings</h1>
            
            <form method="post" action="" class="snn-settings-form" id="snn-settings-form">
                <?php wp_nonce_field('snn_ai_chat_settings_nonce_action', 'snn_ai_chat_settings_nonce'); ?>
                
                <div class="bg-white p-6 rounded-lg shadow mb-4" id="snn-api-provider-section">
                    <h2 class="text-xl font-semibold mb-4">AI Provider Selection</h2>
                    
                    <div class="api-provider-selection" id="snn-provider-selection">
                        <label class="block mb-2 text-gray-700">
                            <input type="radio" name="api_provider" value="openrouter" <?php checked($settings['api_provider'], 'openrouter'); ?> class="mr-2 api-provider-radio" id="snn-openrouter-radio">
                            OpenRouter
                        </label>
                        <label class="block mb-2 text-gray-700">
                            <input type="radio" name="api_provider" value="openai" <?php checked($settings['api_provider'], 'openai'); ?> class="mr-2 api-provider-radio" id="snn-openai-radio">
                            OpenAI
                        </label>
                        <label class="block mb-4 text-gray-700">
                            <input type="radio" name="api_provider" value="custom" <?php checked($settings['api_provider'], 'custom'); ?> class="mr-2 api-provider-radio" id="snn-custom-api-radio">
                            Custom API
                        </label>
                    </div>
                    
                    <div class="api-settings <?php echo ($settings['api_provider'] !== 'openrouter') ? 'hidden' : ''; ?> bg-gray-50 p-4 rounded-md border border-gray-200" id="snn-openrouter-settings">
                        <h3 class="text-lg font-semibold mb-3 text-gray-800">OpenRouter API Settings</h3>
                        <div class="mb-4">
                            <label for="openrouter_api_key" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Your OpenRouter API key for accessing AI models">
                                OpenRouter API Key
                            </label>
                            <input type="text" id="openrouter_api_key" name="openrouter_api_key" value="<?php echo esc_attr($settings['openrouter_api_key']); ?>" class="w-[50%] p-2 border border-gray-300 rounded-md api-key-input focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label for="openrouter_model" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Select the AI model to use for chat responses">
                                Model Selection
                            </label>
                            <input type="text" id="openrouter_model" name="openrouter_model" value="<?php echo esc_attr($settings['openrouter_model']); ?>" list="openrouter_models" class="w-full p-2 border border-gray-300 rounded-md model-input focus:ring-blue-500 focus:border-blue-500">
                            <datalist id="openrouter_models"></datalist>
                        </div>
                        <div class="model-details text-gray-600 text-sm" id="openrouter-model-details"></div>
                    </div>
                    
                    <div class="api-settings <?php echo ($settings['api_provider'] !== 'openai') ? 'hidden' : ''; ?> bg-gray-50 p-4 rounded-md border border-gray-200" id="snn-openai-settings">
                        <h3 class="text-lg font-semibold mb-3 text-gray-800">OpenAI API Settings</h3>
                        <div class="mb-4">
                            <label for="openai_api_key" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Your OpenAI API key for accessing GPT models">
                                OpenAI API Key
                            </label>
                            <input type="text" id="openai_api_key" name="openai_api_key" value="<?php echo esc_attr($settings['openai_api_key']); ?>" class="w-[50%] p-2 border border-gray-300 rounded-md api-key-input focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label for="openai_model" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Select the OpenAI model to use for chat responses">
                                Model Selection
                            </label>
                            <input type="text" id="openai_model" name="openai_model" value="<?php echo esc_attr($settings['openai_model']); ?>" list="openai_models" class="w-full p-2 border border-gray-300 rounded-md model-input focus:ring-blue-500 focus:border-blue-500">
                            <datalist id="openai_models"></datalist>
                        </div>
                        <div class="model-details text-gray-600 text-sm" id="openai-model-details"></div>
                    </div>

                    <div class="api-settings <?php echo ($settings['api_provider'] !== 'custom') ? 'hidden' : ''; ?> bg-gray-50 p-4 rounded-md border border-gray-200" id="snn-custom-api-settings">
                        <h3 class="text-lg font-semibold mb-3 text-gray-800">Custom API Settings (OpenAI Compatible)</h3>
                        <div class="mb-4">
                            <label for="custom_api_endpoint" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The base URL for your custom OpenAI-compatible API (e.g., https://your-custom-api.com/v1)">
                                Custom API Endpoint URL
                            </label>
                            <input type="url" id="custom_api_endpoint" name="custom_api_endpoint" value="<?php echo esc_attr($settings['custom_api_endpoint']); ?>" class="w-full p-2 border border-gray-300 rounded-md api-endpoint-input focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label for="custom_api_key" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Your API key for your custom OpenAI-compatible service">
                                Custom API Key
                            </label>
                            <input type="text" id="custom_api_key" name="custom_api_key" value="<?php echo esc_attr($settings['custom_api_key']); ?>" class="w-[50%] p-2 border border-gray-300 rounded-md api-key-input focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label for="custom_model" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Select the model from your custom API to use for chat responses">
                                Model Selection
                            </label>
                            <input type="text" id="custom_model" name="custom_model" value="<?php echo esc_attr($settings['custom_model']); ?>" list="custom_models" class="w-full p-2 border border-gray-300 rounded-md model-input focus:ring-blue-500 focus:border-blue-500">
                            <datalist id="custom_models"></datalist>
                        </div>
                        <div class="model-details text-gray-600 text-sm" id="custom-model-details"></div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow mb-4" id="snn-shared-api-parameters-section">
                    <h2 class="text-xl font-semibold mb-4">Shared API Parameters</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="mb-4">
                            <label for="temperature" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Controls randomness. Lower values mean more deterministic responses, higher values mean more creative responses. (0.0 - 2.0)">
                                Temperature
                            </label>
                            <input type="number" step="0.1" min="0.0" max="2.0" id="temperature" name="temperature" value="<?php echo esc_attr($settings['temperature']); ?>" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label for="max_tokens" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The maximum number of tokens to generate in the chat completion.">
                                Max Response Tokens
                            </label>
                            <input type="number" id="max_tokens" name="max_tokens" value="<?php echo esc_attr($settings['max_tokens']); ?>" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label for="top_p" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="An alternative to sampling with temperature, called nucleus sampling, where the model considers the results of the tokens with top_p probability mass. (0.0 - 1.0)">
                                Top P
                            </label>
                            <input type="number" step="0.01" min="0.0" max="1.0" id="top_p" name="top_p" value="<?php echo esc_attr($settings['top_p']); ?>" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label for="frequency_penalty" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Penalize new tokens based on their existing frequency in the text so far. ( -2.0 to 2.0)">
                                Frequency Penalty
                            </label>
                            <input type="number" step="0.1" min="-2.0" max="2.0" id="frequency_penalty" name="frequency_penalty" value="<?php echo esc_attr($settings['frequency_penalty']); ?>" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="mb-4">
                            <label for="presence_penalty" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Penalize new tokens based on whether they appear in the text so far. ( -2.0 to 2.0)">
                                Presence Penalty
                            </label>
                            <input type="number" step="0.1" min="-2.0" max="2.0" id="presence_penalty" name="presence_penalty" value="<?php echo esc_attr($settings['presence_penalty']); ?>" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-lg shadow mb-4" id="snn-general-settings-section">
                    <h2 class="text-xl font-semibold mb-4">General Settings</h2>
                    
                    <div class="mb-4">
                        <label for="default_system_prompt" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The default system prompt that will be used for all chats unless overridden">
                            Default System Prompt
                        </label>
                        <textarea id="default_system_prompt" name="default_system_prompt" rows="4" class="w-full p-2 border border-gray-300 rounded-md system-prompt-input focus:ring-blue-500 focus:border-blue-500"><?php echo esc_textarea($settings['default_system_prompt']); ?></textarea>
                    </div>
                    
                    <div class="mb-4">
                        <label for="default_initial_message" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The first message users will see when they start a chat">
                            Default Initial Message
                        </label>
                        <input type="text" id="default_initial_message" name="default_initial_message" value="<?php echo esc_attr($settings['default_initial_message']); ?>" class="w-full p-2 border border-gray-300 rounded-md initial-message-input focus:ring-blue-500 focus:border-blue-500">
                    </div>
                </div>
                
                <div class="flex items-center justify-between">
                    <button type="submit" name="submit_settings" class="bg-black text-white px-6 py-2 rounded-md settings-save-btn hover:bg-[#1b1b1b] transition-colors duration-200" id="snn-save-settings-btn">
                        Save Settings
                    </button>
                </div>
            </form>
        </div>
        <script>
            jQuery(document).ready(function($) {
                // Function to fetch and display model details
                function fetchAndDisplayModelDetails(provider, model, apiKey, endpoint = '') {
                    const detailsContainer = $('#' + provider + '-model-details');
                    if (!model || !apiKey || (provider === 'custom' && !endpoint)) {
                        detailsContainer.html('<p class="text-red-500">Please provide API Key, ' + (provider === 'custom' ? 'Endpoint, ' : '') + 'and select a model to see details.</p>');
                        return;
                    }

                    detailsContainer.html('<p class="text-blue-500">Loading model details...</p>');

                    $.ajax({
                        url: snn_ai_chat_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'snn_get_model_details',
                            nonce: snn_ai_chat_ajax.nonce,
                            provider: provider,
                            model: model,
                            api_key: apiKey,
                            api_endpoint: endpoint // Pass endpoint for custom API
                        },
                        success: function(response) {
                            if (response.success) {
                                let details = response.data;
                                let output = '';
                                if (details) {
                                    output += '<p><strong>ID:</strong> ' + details.id + '</p>';
                                    if (details.context_window) {
                                        output += '<p><strong>Context Window:</strong> ' + details.context_window + ' tokens</p>';
                                    }
                                    if (details.pricing && details.pricing.prompt && details.pricing.completion) {
                                        output += '<p><strong>Pricing:</strong> Input: $' + details.pricing.prompt + '/1M tokens, Output: $' + details.pricing.completion + '/1M tokens</p>';
                                    } else if (details.price) { // For OpenAI, price might be directly available or inferred
                                        output += '<p><strong>Pricing:</strong> Varies by usage. Check OpenAI API docs.</p>';
                                    }
                                    if (details.owned_by) {
                                        output += '<p><strong>Owned By:</strong> ' + details.owned_by + '</p>';
                                    }
                                    if (details.description) {
                                        output += '<p><strong>Description:</strong> ' + details.description + '</p>';
                                    }
                                } else {
                                    output = '<p class="text-red-500">Model details not found or API error.</p>';
                                }
                                detailsContainer.html(output);
                            } else {
                                detailsContainer.html('<p class="text-red-500">Error: ' + (response.data || 'Could not fetch model details.') + '</p>');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error("AJAX error fetching model details:", textStatus, errorThrown);
                            detailsContainer.html('<p class="text-red-500">Failed to fetch model details. Network error or invalid API key/endpoint.</p>');
                        }
                    });
                }

                // Function to fetch models for datalist
                function fetchModelsForDatalist(provider, apiKey, endpoint = '') {
                    const datalist = $('#' + provider + '_models');
                    if (!apiKey || (provider === 'custom' && !endpoint)) {
                        datalist.empty();
                        return;
                    }

                    $.ajax({
                        url: snn_ai_chat_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'snn_get_models',
                            nonce: snn_ai_chat_ajax.nonce,
                            provider: provider,
                            api_key: apiKey,
                            api_endpoint: endpoint // Pass endpoint for custom API
                        },
                        success: function(response) {
                            if (response.success) {
                                datalist.empty();
                                response.data.forEach(function(model) {
                                    datalist.append('<option value="' + model.id + '">');
                                });
                            } else {
                                console.error('Error fetching models:', response.data);
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error("AJAX error fetching models:", textStatus, errorThrown);
                        }
                    });
                }

                // Initial load: Fetch models and details for the currently selected provider
                const initialProvider = snn_ai_chat_ajax.global_api_provider;
                if (initialProvider === 'openrouter') {
                    fetchModelsForDatalist('openrouter', snn_ai_chat_ajax.global_openrouter_api_key);
                    fetchAndDisplayModelDetails('openrouter', snn_ai_chat_ajax.global_openrouter_model, snn_ai_chat_ajax.global_openrouter_api_key);
                } else if (initialProvider === 'openai') {
                    fetchModelsForDatalist('openai', snn_ai_chat_ajax.global_openai_api_key);
                    fetchAndDisplayModelDetails('openai', snn_ai_chat_ajax.global_openai_model, snn_ai_chat_ajax.global_openai_api_key);
                } else if (initialProvider === 'custom') {
                    fetchModelsForDatalist('custom', snn_ai_chat_ajax.global_custom_api_key, snn_ai_chat_ajax.global_custom_api_endpoint);
                    fetchAndDisplayModelDetails('custom', snn_ai_chat_ajax.global_custom_model, snn_ai_chat_ajax.global_custom_api_key, snn_ai_chat_ajax.global_custom_api_endpoint);
                }

                // Event listeners for API provider radio buttons
                $('input[name="api_provider"]').on('change', function() {
                    const selectedProvider = $(this).val();
                    $('.api-settings').addClass('hidden');
                    $('#snn-' + selectedProvider + '-settings').removeClass('hidden');

                    // Fetch models and details for the newly selected provider
                    if (selectedProvider === 'openrouter') {
                        const apiKey = $('#openrouter_api_key').val();
                        const model = $('#openrouter_model').val();
                        fetchModelsForDatalist('openrouter', apiKey);
                        fetchAndDisplayModelDetails('openrouter', model, apiKey);
                    } else if (selectedProvider === 'openai') {
                        const apiKey = $('#openai_api_key').val();
                        const model = $('#openai_model').val();
                        fetchModelsForDatalist('openai', apiKey);
                        fetchAndDisplayModelDetails('openai', model, apiKey);
                    } else if (selectedProvider === 'custom') {
                        const apiKey = $('#custom_api_key').val();
                        const endpoint = $('#custom_api_endpoint').val();
                        const model = $('#custom_model').val();
                        fetchModelsForDatalist('custom', apiKey, endpoint);
                        fetchAndDisplayModelDetails('custom', model, apiKey, endpoint);
                    }
                });

                // Event listeners for API key, endpoint, and model input changes
                $('#openrouter_api_key').on('input', function() {
                    const apiKey = $(this).val();
                    const model = $('#openrouter_model').val();
                    fetchModelsForDatalist('openrouter', apiKey);
                    fetchAndDisplayModelDetails('openrouter', model, apiKey);
                });

                $('#openrouter_model').on('change', function() {
                    const model = $(this).val();
                    const apiKey = $('#openrouter_api_key').val();
                    fetchAndDisplayModelDetails('openrouter', model, apiKey);
                });

                $('#openai_api_key').on('input', function() {
                    const apiKey = $(this).val();
                    const model = $('#openai_model').val();
                    fetchModelsForDatalist('openai', apiKey);
                    fetchAndDisplayModelDetails('openai', model, apiKey);
                });

                $('#openai_model').on('change', function() {
                    const model = $(this).val();
                    const apiKey = $('#openai_api_key').val();
                    fetchAndDisplayModelDetails('openai', model, apiKey);
                });

                // New event listeners for Custom API
                $('#custom_api_endpoint, #custom_api_key').on('input', function() {
                    const endpoint = $('#custom_api_endpoint').val();
                    const apiKey = $('#custom_api_key').val();
                    const model = $('#custom_model').val();
                    fetchModelsForDatalist('custom', apiKey, endpoint);
                    fetchAndDisplayModelDetails('custom', model, apiKey, endpoint);
                });

                $('#custom_model').on('change', function() {
                    const model = $(this).val();
                    const endpoint = $('#custom_api_endpoint').val();
                    const apiKey = $('#custom_api_key').val();
                    fetchAndDisplayModelDetails('custom', model, apiKey, endpoint);
                });
            });
        </script>
        <?php
    }

    public function chats_page() {
        $action = isset($_GET['action']) ? sanitize_text_field((string)$_GET['action']) : 'list';
        $chat_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
        
        if (isset($_GET['snn_new_chat_saved']) && $_GET['snn_new_chat_saved'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Your new chat is saved. You can now preview it.</p></div>';
        }
        
        if (isset($_GET['snn_chat_updated']) && $_GET['snn_chat_updated'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>Chat settings saved successfully!</p></div>';
        }
        
        if ($action === 'edit' || $action === 'new') {
            $this->render_chat_edit_form($chat_id);
        } else {
            $this->render_chats_list();
        }
    }

    public function render_chats_list() {
        $chats = $this->get_all_chats();
        ?>
        <div class="wrap">
            <h1>Manage Chats</h1>
            
            <div class="mb-4">
                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats&action=new')); ?>" class="bg-black text-white px-4 py-2 rounded-md add-chat-btn hover:bg-[#1b1b1b] transition-colors duration-200" id="snn-add-new-chat-btn">
                    Add New Chat
                </a>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 chats-grid" id="snn-chats-grid">
                <?php foreach ($chats as $chat) {
                    $chat_settings = get_post_meta($chat->ID, '_snn_chat_settings', true);
                    $chat_settings = is_array($chat_settings) ? $chat_settings : [];
                    $defaults = $this->get_default_chat_settings();
                    $chat_settings = wp_parse_args($chat_settings, $defaults);
                    ?>
                    <div class="bg-white p-6 rounded-lg shadow chat-card" id="snn-chat-card-<?php echo esc_attr($chat->ID); ?>">
                        <h3 class="text-lg font-semibold mb-2 text-gray-800"><?php echo esc_html($chat->post_title); ?></h3>
                        
                        <div class="flex justify-between items-center mt-4">
                            <div class="text-sm text-gray-500" id="snn-chat-stats-<?php echo esc_attr($chat->ID); ?>">
                                <?php echo $this->get_chat_stats($chat->ID); ?>
                            </div>
                            <div class="space-x-2" id="snn-chat-actions-<?php echo esc_attr($chat->ID); ?>">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats&action=edit&id=' . $chat->ID)); ?>" class="inline-flex items-center px-10 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white hover:text-white bg-black hover:bg-[#1b1b1b] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 edit-chat-btn transition-colors duration-200" id="snn-edit-chat-btn-<?php echo esc_attr($chat->ID); ?>">
                                    Edit
                                </a>
                                <button class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white hover:text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 delete-chat-btn transition-colors duration-200" data-chat-id="<?php echo esc_attr($chat->ID); ?>" id="snn-delete-chat-btn-<?php echo esc_attr($chat->ID); ?>">
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            </div>
        </div>
        <?php
    }

    public function render_chat_edit_form($chat_id) {
        $chat = null;
        $chat_settings_raw = array();

        if ($chat_id > 0) {
            $chat = get_post($chat_id);
            $chat_settings_raw = get_post_meta($chat_id, '_snn_chat_settings', true);
        }
        
        $defaults = $this->get_default_chat_settings();
        $chat_settings = wp_parse_args(is_array($chat_settings_raw) ? $chat_settings_raw : [], $defaults);
        
        $global_settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($chat_id > 0 ? 'Edit Chat' : 'Create New Chat'); ?></h1>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow chat-settings-form" id="snn-chat-settings-form-container">
                    <form id="chat-settings-form" method="post" action="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats')); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('snn_ai_chat_settings_form', 'snn_ai_chat_settings_nonce_field', false, true); ?>
                        <input type="hidden" name="chat_id" value="<?php echo esc_attr($chat_id); ?>">
                        
                        <div class="mb-4 basic-info-section" id="snn-basic-info-section">
                            <h3 class="text-lg font-semibold mb-4">Basic Information</h3>
                            
                            <div class="mb-4">
                                <label for="chat_name" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The name for this chat configuration">
                                    Chat Name
                                </label>
                                <input type="text" id="chat_name" name="chat_name" value="<?php echo esc_attr($chat ? $chat->post_title : ''); ?>" class="w-full p-2 border border-gray-300 rounded-md chat-name-input focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            
                            <div class="mb-4">
                                <label for="initial_message" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The first message users will see when they start this chat">
                                    Initial Message
                                </label>
                                <input type="text" id="initial_message" name="initial_message" value="<?php echo esc_attr($chat_settings['initial_message']); ?>" class="w-full p-2 border border-gray-300 rounded-md initial-message-input focus:ring-blue-500 focus:border-blue-500">
                            </div>

                            <div class="mb-4">
                                <label for="ready_questions" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Enter each question on a new line. These will appear as clickable buttons for users to start the chat.">
                                    Ready Questions
                                </label>
                                <textarea id="ready_questions" name="ready_questions" rows="4" class="w-full p-2 border border-gray-300 rounded-md focus:ring-blue-500 focus:border-blue-500"><?php echo esc_textarea($chat_settings['ready_questions']); ?></textarea>
                            </div>
                            
                            <div class="mb-4">
                                <label for="system_prompt" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The system prompt that defines the AI's behavior and personality">
                                    System Prompt
                                </label>
                                <textarea id="system_prompt" name="system_prompt" rows="3" class="w-full p-2 border border-gray-300 rounded-md system-prompt-input focus:ring-blue-500 focus:border-blue-500"><?php echo esc_textarea($chat_settings['system_prompt']); ?></textarea>
                            </div>

                            <div class="mb-4 dynamic-tags-section p-2 bg-gray-50 rounded-lg border border-gray-200" id="snn-dynamic-tags-section">
                                <button type="button" class="snn-accordion-header flex justify-between items-center w-full text-left py-2 px-0 bg-transparent border-none cursor-pointer text-md font-semibold text-gray-700" id="snn-dynamic-tags-accordion-toggle">
                                    Dynamic Tags and Variables
                                    <span class="dashicons dashicons-arrow-down snn-accordion-icon transition-transform duration-300"></span>
                                </button>
                                <div class="snn-accordion-content hidden" id="snn-dynamic-tags-accordion-content">
                                    <?php $dynamic_tags = $this->get_dynamic_tags(); ?>
                                    <?php foreach ($dynamic_tags as $group_name => $tags) : ?>
                                        <div class="mb-3">
                                            <h5 class="text-sm font-medium text-gray-600 mb-1"><?php echo esc_html($group_name); ?></h5>
                                            <div class="flex flex-wrap gap-1">
                                                <?php foreach ($tags as $tag => $description) : ?>
                                                    <code class="dynamic-tag cursor-pointer bg-gray-200 text-gray-800 px-2 py-1 rounded text-xs snn-tooltip hover:bg-gray-300 transition-colors" data-tippy-content="<?php echo esc_attr($description); ?>"><?php echo esc_html($tag); ?></code>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="mb-4">
                                <label class="flex items-center text-gray-700">
                                    <input type="checkbox" name="keep_conversation_history" value="1" <?php checked($chat_settings['keep_conversation_history'], 1); ?> class="mr-2 conversation-history-checkbox" id="snn-keep-conversation-history-checkbox">
                                    <span class="text-sm font-medium snn-tooltip" data-tippy-content="Whether to maintain conversation context between messages">
                                        Keep conversation history during session
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-4 styling-section" id="snn-styling-appearance-section">
                            <h3 class="text-lg font-semibold mb-4">Styling & Appearance</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                                <div>
                                    <label for="chat_position" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Where the chat widget will appear on the page">Chat Position</label>
                                    <select id="chat_position" name="chat_position" class="w-full h-10 p-2 border border-gray-300 rounded-md chat-position-select focus:ring-blue-500 focus:border-blue-500">
                                        <option value="bottom-right" <?php selected($chat_settings['chat_position'], 'bottom-right'); ?>>Bottom Right</option>
                                        <option value="bottom-left" <?php selected($chat_settings['chat_position'], 'bottom-left'); ?>>Bottom Left</option>
                                        <option value="top-right" <?php selected($chat_settings['chat_position'], 'top-right'); ?>>Top Right</option>
                                        <option value="top-left" <?php selected($chat_settings['chat_position'], 'top-left'); ?>>Top Left</option>
                                    </select>
                                </div>
                                <div>
                                    <label for="primary_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Main color for the chat widget toggle button and user messages">Primary Color</label>
                                    <input type="color" id="primary_color" name="primary_color" value="<?php echo esc_attr($chat_settings['primary_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="secondary_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Background color for AI messages">Secondary Color</label>
                                    <input type="color" id="secondary_color" name="secondary_color" value="<?php echo esc_attr($chat_settings['secondary_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="text_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Default text color for the chat widget (e.g., header text, AI message text)">Header Text Color</label>
                                    <input type="color" id="text_color" name="text_color" value="<?php echo esc_attr($chat_settings['text_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="chat_widget_bg_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Background color of the main chat container.">Widget BG</label>
                                    <input type="color" id="chat_widget_bg_color" name="chat_widget_bg_color" value="<?php echo esc_attr($chat_settings['chat_widget_bg_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="chat_input_bg_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Background color of the message input field.">Input BG</label>
                                    <input type="color" id="chat_input_bg_color" name="chat_input_bg_color" value="<?php echo esc_attr($chat_settings['chat_input_bg_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="chat_input_text_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Text color of the message input field.">Input Text</label>
                                    <input type="color" id="chat_input_text_color" name="chat_input_text_color" value="<?php echo esc_attr($chat_settings['chat_input_text_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="chat_send_button_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Color for the send button icon.">Send Button</label>
                                    <input type="color" id="chat_send_button_color" name="chat_send_button_color" value="<?php echo esc_attr($chat_settings['chat_send_button_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="user_message_bg_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Background color for user messages.">User Message BG</label>
                                    <input type="color" id="user_message_bg_color" name="user_message_bg_color" value="<?php echo esc_attr($chat_settings['user_message_bg_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="user_message_text_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Text color for user messages.">User Message Text</label>
                                    <input type="color" id="user_message_text_color" name="user_message_text_color" value="<?php echo esc_attr($chat_settings['user_message_text_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="ai_message_bg_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Background color for AI messages.">AI Message BG</label>
                                    <input type="color" id="ai_message_bg_color" name="ai_message_bg_color" value="<?php echo esc_attr($chat_settings['ai_message_bg_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="ai_message_text_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Text color for AI messages.">AI Message Text</label>
                                    <input type="color" id="ai_message_text_color" name="ai_message_text_color" value="<?php echo esc_attr($chat_settings['ai_message_text_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="font_size" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Font size for chat messages">Font Size (px)</label>
                                    <input type="number" id="font_size" name="font_size" value="<?php echo esc_attr($chat_settings['font_size']); ?>" class="w-full h-10 p-2 border border-gray-300 rounded-md font-size-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="border_radius" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Rounded corners for the chat widget">Border Radius (px)</label>
                                    <input type="number" id="border_radius" name="border_radius" value="<?php echo esc_attr($chat_settings['border_radius']); ?>" class="w-full h-10 p-2 border border-gray-300 rounded-md border-radius-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="widget_width" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Width of the chat widget">Widget Width (px)</label>
                                    <input type="number" id="widget_width" name="widget_width" value="<?php echo esc_attr($chat_settings['widget_width']); ?>" class="w-full h-10 p-2 border border-gray-300 rounded-md widget-width-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                <div>
                                    <label for="widget_height" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Height of the chat widget">Widget Height (px)</label>
                                    <input type="number" id="widget_height" name="widget_height" value="<?php echo esc_attr($chat_settings['widget_height']); ?>" class="w-full h-10 p-2 border border-gray-300 rounded-md widget-height-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4 display-settings-section" id="snn-display-settings-section">
                            <h3 class="text-lg font-semibold mb-4">Display Settings</h3>
                            
                            <div class="mb-4">
                                <label class="flex items-center text-gray-700">
                                    <input type="checkbox" name="show_on_all_pages" value="1" <?php checked($chat_settings['show_on_all_pages'], 1); ?> class="mr-2 show-all-pages-checkbox" id="snn-show-on-all-pages-checkbox">
                                    <span class="text-sm font-medium snn-tooltip" data-tippy-content="Display this chat on all pages of the website. This overrides other display settings except for 'Exclude Pages'.">
                                        Show on all pages
                                    </span>
                                </label>
                            </div>
                            
                            <div class="mb-4">
                                <label for="specific_pages" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Comma-separated list of page/post IDs where this chat should appear. e.g., 1,2,3">
                                    Display On Specific Pages
                                </label>
                                <input type="text" id="specific_pages" name="specific_pages" value="<?php echo esc_attr($chat_settings['specific_pages']); ?>" placeholder="1,2,3" class="w-full p-2 border border-gray-300 rounded-md specific-pages-input focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <label for="exclude_pages" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Comma-separated list of page/post IDs where this chat should NOT appear. This overrides all other settings. e.g., 4,5,6">
                                    Exclude Pages
                                </label>
                                <input type="text" id="exclude_pages" name="exclude_pages" value="<?php echo esc_attr($chat_settings['exclude_pages']); ?>" placeholder="4,5,6" class="w-full p-2 border border-gray-300 rounded-md exclude-pages-input focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <h4 class="text-md font-semibold mb-2">Template Conditions</h4>
                                <div class="space-y-2">
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_home" value="1" <?php checked($chat_settings['show_on_home'], 1); ?> class="mr-2 template-condition-checkbox" id="snn-show-on-home-checkbox">
                                        <span class="text-sm">Home</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_front_page" value="1" <?php checked($chat_settings['show_on_front_page'], 1); ?> class="mr-2 template-condition-checkbox" id="snn-show-on-front-page-checkbox">
                                        <span class="text-sm">Front Page</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_posts" value="1" <?php checked($chat_settings['show_on_posts'], 1); ?> class="mr-2 template-condition-checkbox" id="snn-show-on-posts-checkbox">
                                        <span class="text-sm">Posts</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_pages" value="1" <?php checked($chat_settings['show_on_pages'], 1); ?> class="mr-2 template-condition-checkbox" id="snn-show-on-pages-checkbox">
                                        <span class="text-sm">Pages</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_categories" value="1" <?php checked($chat_settings['show_on_categories'], 1); ?> class="mr-2 template-condition-checkbox" id="snn-show-on-categories-checkbox">
                                        <span class="text-sm">Categories</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_archives" value="1" <?php checked($chat_settings['show_on_archives'], 1); ?> class="mr-2 template-condition-checkbox" id="snn-show-on-archives-checkbox">
                                        <span class="text-sm">Archives</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4 usage-limits-section" id="snn-usage-limits-section">
                            <h3 class="text-lg font-semibold mb-4">Usage Limits</h3>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="max_tokens_per_session" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Maximum number of tokens allowed per chat session">
                                        Max Tokens Per Session
                                    </label>
                                    <input type="number" id="max_tokens_per_session" name="max_tokens_per_session" value="<?php echo esc_attr($chat_settings['max_tokens_per_session']); ?>" class="w-full p-2 border border-gray-300 rounded-md tokens-per-session-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div>
                                    <label for="max_tokens_per_ip_daily" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Maximum number of tokens allowed per IP address per day">
                                        Max Tokens Per IP Daily
                                    </label>
                                    <input type="number" id="max_tokens_per_ip_daily" name="max_tokens_per_ip_daily" value="<?php echo esc_attr($chat_settings['max_tokens_per_ip_daily']); ?>" class="w-full p-2 border border-gray-300 rounded-md tokens-per-ip-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="max_chats_per_ip_daily" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Maximum number of chat sessions allowed per IP address per day">
                                        Max Chats Per IP Daily
                                    </label>
                                    <input type="number" id="max_chats_per_ip_daily" name="max_chats_per_ip_daily" value="<?php echo esc_attr($chat_settings['max_chats_per_ip_daily']); ?>" class="w-full p-2 border border-gray-300 rounded-md chats-per-ip-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div>
                                    <label for="rate_limit_per_minute" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Maximum number of messages allowed per minute">
                                        Rate Limit Per Minute
                                    </label>
                                    <input type="number" id="rate_limit_per_minute" name="rate_limit_per_minute" value="<?php echo esc_attr($chat_settings['rate_limit_per_minute']); ?>" class="w-full p-2 border border-gray-300 rounded-md rate-limit-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-4 user-info-section" id="snn-user-info-collection-section">
                            <h3 class="text-lg font-semibold mb-4">User Information Collection</h3>
                            
                            <div class="mb-4">
                                <label class="flex items-center text-gray-700">
                                    <input type="checkbox" name="collect_user_info" value="1" <?php checked($chat_settings['collect_user_info'], 1); ?> class="mr-2 collect-user-info-checkbox" id="snn-collect-user-info-checkbox">
                                    <span class="text-sm font-medium snn-tooltip" data-tippy-content="Require users to provide their name and email before they can start a chat">
                                        Collect user name and email before starting chat
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-4 p-4 bg-gray-50 rounded-lg border border-gray-200" id="snn-context-links-feature-section">
                            <h3 class="text-lg font-semibold mb-2">Contextual Link Cards</h3>
                            <label class="flex items-center mb-2">
                                <input type="checkbox" name="enable_context_link_cards" id="enable_context_link_cards" value="1" <?php checked($chat_settings['enable_context_link_cards'] ?? 0, 1); ?> />
                                <span class="ml-2 text-sm">Enable Contextual Link Cards (AI will show link cards when relevant)</span>
                            </label>
                            <div class="mb-2">
                                <label for="context_link_cards_system_prompt" class="block text-sm font-medium text-gray-700 mb-1">Tool Card System Prompt</label>
                                <textarea name="context_link_cards_system_prompt" id="context_link_cards_system_prompt" rows="3" class="w-full p-2 border border-gray-300 rounded-md" placeholder="Instructions for the AI about how to use link cards. Example: If the user's question matches a context, include [LINK_CARD:N] in your response. Provide helpful info and insert the card when relevant."><?php echo esc_textarea($chat_settings['context_link_cards_system_prompt'] ?? ''); ?></textarea>
                            </div>
                            <p class="text-sm text-gray-600 mb-2">Add context keywords/phrases and link info. The AI will use these to show link cards when relevant.</p>
                            <?php 
                            $context_links = get_post_meta($chat_id, 'snn_ai_chats_context_links', true);
                            if (!is_array($context_links)) $context_links = array();
                            ?>
                            <div id="snn-context-links-repeater">
                                <?php foreach ($context_links as $i => $link) : ?>
                                <div class="snn-context-link-item border p-2 mb-2 rounded bg-gray-50">
                                    <textarea name="snn_ai_chats_context_links[<?php echo $i; ?>][context]" placeholder="Context keywords/phrases" rows="2" class="w-full mb-1"><?php echo esc_textarea($link['context'] ?? ''); ?></textarea>
                                    <input type="text" name="snn_ai_chats_context_links[<?php echo $i; ?>][url]" placeholder="Link URL" value="<?php echo esc_attr($link['url'] ?? ''); ?>" class="w-full mb-1" />
                                    <input type="text" name="snn_ai_chats_context_links[<?php echo $i; ?>][title]" placeholder="Title" value="<?php echo esc_attr($link['title'] ?? ''); ?>" class="w-full mb-1" />
                                    <input type="text" name="snn_ai_chats_context_links[<?php echo $i; ?>][desc]" placeholder="Description" value="<?php echo esc_attr($link['desc'] ?? ''); ?>" class="w-full mb-1" />
                                    <input type="text" name="snn_ai_chats_context_links[<?php echo $i; ?>][img]" placeholder="Image URL (optional)" value="<?php echo esc_attr($link['img'] ?? ''); ?>" class="w-full mb-1" />
                                    <button type="button" class="snn-remove-link bg-red-100 text-red-700 px-2 py-1 rounded">Remove</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="snn-add-link" class="bg-blue-100 text-blue-700 px-3 py-1 rounded">Add Link Card</button>
                        </div>
                        <script>
                        jQuery(document).ready(function($){
                            $('#snn-add-link').click(function(){
                                var i = $('#snn-context-links-repeater .snn-context-link-item').length;
                                var html = `<div class="snn-context-link-item border p-2 mb-2 rounded bg-gray-50">`
                                    + `<textarea name="snn_ai_chats_context_links[${i}][context]" placeholder="Context keywords/phrases" rows="2" class="w-full mb-1"></textarea>`
                                    + `<input type="text" name="snn_ai_chats_context_links[${i}][url]" placeholder="Link URL" class="w-full mb-1" />`
                                    + `<input type="text" name="snn_ai_chats_context_links[${i}][title]" placeholder="Title" class="w-full mb-1" />`
                                    + `<input type="text" name="snn_ai_chats_context_links[${i}][desc]" placeholder="Description" class="w-full mb-1" />`
                                    + `<input type="text" name="snn_ai_chats_context_links[${i}][img]" placeholder="Image URL (optional)" class="w-full mb-1" />`
                                    + `<button type="button" class="snn-remove-link bg-red-100 text-red-700 px-2 py-1 rounded">Remove</button>`
                                    + `</div>`;
                                $('#snn-context-links-repeater').append(html);
                            });
                            $(document).on('click', '.snn-remove-link', function(){
                                $(this).parent().remove();
                            });
                        });
                        </script>
                        <div class="flex space-x-4">
                            <button type="submit" name="submit_chat_settings" class="bg-black text-white px-6 py-2 rounded-md save-chat-btn hover:bg-[#1b1b1b] transition-colors duration-200" id="snn-save-chat-btn">
                                Save Chat
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="lg:col-span-1 bg-white p-6 rounded-lg shadow chat-preview-section lg:sticky lg:top-4 lg:self-start" id="snn-chat-preview-section">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold m-0">Live Preview</h3>
                        <div class="flex items-center">
                            <label for="preview_post_id" class="text-sm font-medium text-gray-700 mr-2">Post ID:</label>
                            <input type="text" id="preview_post_id_input" list="snn_post_datalist" class="w-[100px] p-1 border border-gray-300 rounded-md text-sm" value="<?php echo esc_attr(get_queried_object_id()); ?>">
                            <datalist id="snn_post_datalist"></datalist>
                        </div>
                    </div>
                    <?php if ($chat_id > 0) : ?>
                        <div class="border border-gray-300 rounded-lg overflow-hidden preview-container relative" id="snn-preview-container" style="min-height: 660px; max-height: calc(100vh - 80px);">
                            <iframe id="chat-preview-iframe" src="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-preview&chat_id=' . $chat_id . '&post_id=' . get_queried_object_id())); ?>" width="100%" height="100%" frameborder="0" class="preview-iframe absolute inset-0"></iframe>
                        </div>
                    <?php else : ?>
                        <div class="text-center p-4 border-2 border-dashed rounded-lg text-gray-600" id="snn-preview-placeholder">
                            <p>Save the chat to enable the live preview.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($) {
                const chatSettingsForm = $('#chat-settings-form');
                const chatPreviewIframe = $('#chat-preview-iframe');
                const chatId = chatSettingsForm.find('input[name="chat_id"]').val();
                const previewPostIdInput = $('#preview_post_id_input');
                const postDatalist = $('#snn_post_datalist');

                // Function to fetch posts for datalist
                function fetchPostsForDatalist() {
                    $.ajax({
                        url: snn_ai_chat_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'snn_get_public_posts',
                            nonce: snn_ai_chat_ajax.nonce,
                        },
                        success: function(response) {
                            if (response.success) {
                                postDatalist.empty();
                                response.data.forEach(function(post) {
                                    postDatalist.append('<option value="' + post.id + '">' + post.title + '</option>');
                                });
                            } else {
                                console.error('Error fetching posts:', response.data);
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            console.error("AJAX error fetching posts:", textStatus, errorThrown);
                        }
                    });
                }

                // Initial fetch of posts for datalist
                fetchPostsForDatalist();

                // Update iframe src when preview_post_id_input changes
                previewPostIdInput.on('change', function() {
                    const selectedPostId = $(this).val();
                    if (chatId > 0 && chatPreviewIframe.length) {
                        const currentSrc = chatPreviewIframe.attr('src');
                        const url = new URL(currentSrc);
                        url.searchParams.set('post_id', selectedPostId);
                        chatPreviewIframe.attr('src', url.toString());
                    }
                });

                // Dynamic Tag insertion logic
                $('#snn-dynamic-tags-section').on('click', '.dynamic-tag', function() {
                    const tag = $(this).text();
                    const promptTextarea = $('#system_prompt');
                    const currentVal = promptTextarea.val();
                    const cursorPos = promptTextarea[0].selectionStart;
                    const newVal = currentVal.substring(0, cursorPos) + ' ' + tag + ' ' + currentVal.substring(cursorPos);
                    promptTextarea.val(newVal).focus();
                    promptTextarea[0].selectionStart = cursorPos + tag.length + 2;
                    promptTextarea[0].selectionEnd = cursorPos + tag.length + 2;
                });

                // Accordion functionality for Dynamic Tags
                const accordionToggle = $('#snn-dynamic-tags-accordion-toggle');
                const accordionContent = $('#snn-dynamic-tags-accordion-content');
                const accordionIcon = accordionToggle.find('.snn-accordion-icon');

                accordionToggle.on('click', function() {
                    accordionContent.slideToggle(300, function() {
                        if (accordionContent.is(':visible')) {
                            accordionIcon.removeClass('dashicons-arrow-down').addClass('dashicons-arrow-up');
                        } else {
                            accordionIcon.removeClass('dashicons-arrow-up').addClass('dashicons-arrow-down');
                        }
                    });
                });

                function adjustBrightness(hex, steps) {
                    hex = hex.replace('#', '');
                    if (hex.length === 3) {
                        hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
                    }
                    let rgb = [];
                    for (let i = 0; i < 6; i += 2) {
                        rgb.push(parseInt(hex.substr(i, 2), 16));
                    }

                    for (let i = 0; i < 3; i++) {
                        rgb[i] = Math.max(0, Math.min(255, rgb[i] + steps));
                    }

                    return '#' + rgb.map(val => ('0' + val.toString(16)).slice(-2)).join('');
                }

                function applyAllStylesToPreview() {
                    if (!chatPreviewIframe.length || !chatPreviewIframe[0].contentWindow || !chatPreviewIframe[0].contentWindow.updateAllChatStyles) {
                        // If the iframe or the function isn't ready, try again. This handles slow iframe loading.
                        setTimeout(applyAllStylesToPreview, 100);
                        return;
                    }

                    const settings = {};
                    const inputs = chatSettingsForm.find('input[type="color"], input[type="number"], input[type="text"], textarea, select, input[type="checkbox"]');
                    
                    inputs.each(function() {
                        const field = $(this);
                        const name = field.attr('name');
                        if (name) {
                            if (field.is(':checkbox')) {
                                settings[name] = field.prop('checked') ? 1 : 0;
                            } else {
                                settings[name] = field.val();
                            }
                        }
                    });
                    
                    // Add the selected preview_post_id to the settings object
                    settings['preview_post_id'] = previewPostIdInput.val();


                    chatPreviewIframe[0].contentWindow.updateAllChatStyles(settings, chatId);
                }

                // Listen for changes on all relevant input fields
                chatSettingsForm.find('input[type="color"], input[type="number"], input[type="text"], textarea, select').on('change', applyAllStylesToPreview);
                chatSettingsForm.find('input[type="checkbox"]').on('change', applyAllStylesToPreview);
                previewPostIdInput.on('change', applyAllStylesToPreview); // Also trigger on post ID change
                
                // Also apply styles when the iframe has finished loading to sync it with the form's initial state.
                chatPreviewIframe.on('load', function() {
                    applyAllStylesToPreview();
                });
            });
        </script>
        <?php
    }

    private function save_chat_settings_form_submit() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to save settings.'));
        }
        
        if (!isset($_POST['snn_ai_chat_settings_nonce_field']) || !wp_verify_nonce(sanitize_text_field((string)wp_unslash($_POST['snn_ai_chat_settings_nonce_field'] ?? '')), 'snn_ai_chat_settings_form')) {
            wp_die(esc_html__('Nonce verification failed.'));
        }
        
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $chat_name = sanitize_text_field($_POST['chat_name'] ?? 'New Chat');
        
        $settings = [];
        $defaults = $this->get_default_chat_settings();
        
        foreach ($defaults as $key => $default_value) {
            $value = $_POST[$key] ?? null;

            if ($key === 'keep_conversation_history' || $key === 'show_on_all_pages' || substr($key, 0, 8) === 'show_on_' || $key === 'collect_user_info' || $key === 'enable_context_link_cards') {
                $settings[$key] = isset($_POST[$key]) ? 1 : 0;
                continue;
            }

            if ($key === 'context_link_cards_system_prompt') {
                $settings[$key] = sanitize_textarea_field($value ?? '');
                continue;
            }

            if (is_int($default_value)) {
                $settings[$key] = intval($value ?? 0);
            } elseif (is_float($default_value)) {
                $settings[$key] = floatval($value ?? 0.0);
            } elseif (str_contains((string)$key, '_color')) {
                $settings[$key] = sanitize_hex_color($value ?? '#000000');
            } elseif ($key === 'system_prompt' || $key === 'initial_message' || $key === 'ready_questions') {
                $settings[$key] = sanitize_textarea_field($value ?? '');
            } elseif ($key === 'specific_pages' || $key === 'exclude_pages') {
                $ids = array_filter(array_map('intval', explode(',', (string)($value ?? ''))));
                $settings[$key] = implode(',', $ids);
            } else {
                $settings[$key] = sanitize_text_field($value ?? '');
            }
        }
        
        $post_data = array(
            'post_title' => $chat_name,
            'post_type' => 'snn_ai_chat',
            'post_status' => 'publish'
        );
        
        if ($chat_id > 0) {
            $post_data['ID'] = $chat_id;
            wp_update_post($post_data);
        } else {
            $new_chat_id = wp_insert_post($post_data);
            if ($new_chat_id && !is_wp_error($new_chat_id)) {
                $chat_id = $new_chat_id;
            } else {
                return 0; // Return a value that indicates failure
            }
        }
        
        if ($chat_id && !is_wp_error($chat_id)) {
            update_post_meta($chat_id, '_snn_chat_settings', $settings);
            // Save Contextual Link Cards
            if (isset($_POST['snn_ai_chats_context_links']) && is_array($_POST['snn_ai_chats_context_links'])) {
                $links = array();
                foreach ($_POST['snn_ai_chats_context_links'] as $link) {
                    if (empty($link['context']) || empty($link['url'])) continue;
                    $links[] = array(
                        'context' => sanitize_text_field($link['context']),
                        'url' => esc_url_raw($link['url']),
                        'title' => sanitize_text_field($link['title']),
                        'desc' => sanitize_text_field($link['desc']),
                        'img' => esc_url_raw($link['img'])
                    );
                }
                update_post_meta($chat_id, 'snn_ai_chats_context_links', $links);
            } else {
                delete_post_meta($chat_id, 'snn_ai_chats_context_links');
            }
        } else {
            return 0; // Return a value that indicates failure
        }

        return $chat_id;
    }

    public function chat_history_page() {
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Chat History</h1>
            <div class="alignright">
                <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-history')); ?>" onsubmit="return confirm('Are you sure you want to delete ALL chat sessions? This action cannot be undone.');">
                    <?php wp_nonce_field('snn_delete_all_sessions', 'snn_delete_all_sessions_nonce'); ?>
                    <input type="hidden" name="action" value="delete_all_sessions">
                    <button type="submit" class=" bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md transition-colors duration-200" id="snn-delete-all-chats-btn">
                        Delete All Chats
                    </button>
                </form>
            </div>
            <hr class="wp-header-end">

            <?php
            // Display success notices
            if (isset($_GET['snn_session_deleted']) && $_GET['snn_session_deleted'] == '1') {
                echo '<div class="notice notice-success is-dismissible"><p>Chat session deleted successfully!</p></div>';
            }
            if (isset($_GET['snn_all_sessions_deleted']) && $_GET['snn_all_sessions_deleted'] == '1') {
                echo '<div class="notice notice-success is-dismissible"><p>All chat sessions deleted successfully!</p></div>';
            }
            ?>
            
            <div class="bg-white p-6 rounded-lg shadow chat-history-table" id="snn-chat-history-table-container">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200" id="snn-chat-history-table">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Session ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Chat Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Messages</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tokens</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="snn-chat-history-table-body">
                            <?php
                            $chat_history = $this->get_chat_history();
                            foreach ($chat_history as $history) {
                                ?>
                                <tr class="history-row" id="snn-history-row-<?php echo esc_attr($history->session_id); ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(substr((string)$history->session_id, 0, 12)); ?>...</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(get_the_title($history->chat_id) ?: 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html($history->user_name ?: 'Anonymous'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html($history->user_email ?: 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html($history->message_count); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(number_format($history->total_tokens)); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(date('M j, Y H:i', strtotime($history->created_at))); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-session-history&session_id=' . $history->session_id)); ?>" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white hover:text-white bg-black hover:bg-[#1b1b1b] focus:outline-none focus:ring-2 focus:ring-offset-2 view-history-btn transition-colors duration-200" id="snn-view-session-details-btn-<?php echo esc_attr($history->session_id); ?>">
                                            View Details
                                        </a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-history')); ?>" style="display: inline-block; margin-left: 8px;" onsubmit="return confirm('Are you sure you want to delete this chat session? This action cannot be undone.');">
                                            <?php wp_nonce_field('snn_delete_session', 'snn_delete_session_nonce'); ?>
                                            <input type="hidden" name="action" value="delete_session">
                                            <input type="hidden" name="session_id" value="<?php echo esc_attr($history->session_id); ?>">
                                            <button type="submit" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 delete-session-btn transition-colors duration-200" id="snn-delete-session-btn-<?php echo esc_attr($history->session_id); ?>">
                                                X Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    public function session_history_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }

        $current_session_id = sanitize_text_field($_GET['session_id'] ?? '');
        $all_sessions = $this->get_chat_history_sessions_for_sidebar(); // Get all sessions for the sidebar
        ?>
        <div class="wrap">
            <h1>Chat Session Details</h1>
            <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-history')); ?>" class="bg-black text-white px-4 py-2 rounded-lg text-center quick-action-btn hover:bg-[#1b1b1b] transition-colors duration-200 mb-4 inline-block" id="snn-back-to-history-btn">← Back to Chat History</a>

            <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 mt-4">
                <div class="lg:col-span-1 bg-white p-4 rounded-lg shadow overflow-y-auto max-h-[calc(100vh-180px)]" id="snn-session-sidebar">
                    <h2 class="text-lg font-semibold mb-3">All Sessions</h2>
                    <ul class="space-y-2">
                        <?php if (!empty($all_sessions)) : ?>
                            <?php foreach ($all_sessions as $session_item) : ?>
                                <li class="snn-session-history-item cursor-pointer p-2 rounded-md transition-colors duration-150 <?php echo ($session_item->session_id === $current_session_id) ? 'bg-blue-100 text-blue-800 font-semibold' : 'hover:bg-gray-100 text-gray-700'; ?>"
                                    data-session-id="<?php echo esc_attr($session_item->session_id); ?>"
                                    id="snn-session-item-<?php echo esc_attr($session_item->session_id); ?>">
                                    <span class="block text-sm"><?php echo esc_html(substr($session_item->session_id, 0, 8)); ?>...</span>
                                    <span class="block text-xs text-gray-500"><?php echo esc_html(date('M j, Y H:i', strtotime($session_item->created_at))); ?></span>
                                    <span class="block text-xs text-gray-500">User: <?php echo esc_html($session_item->user_name ?: 'Anonymous'); ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <li class="text-gray-600 text-sm">No chat sessions found.</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <div class="lg:col-span-3 bg-white p-6 rounded-lg shadow" id="snn-session-details-panel">
                    <?php if (!empty($current_session_id)) : ?>
                        <?php
                        // Initial load of the current session's details
                        global $wpdb;
                        $session_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}snn_chat_sessions WHERE session_id = %s", $current_session_id));
                        $session_messages = $wpdb->get_results($wpdb->prepare("SELECT message, response, created_at, tokens_used FROM {$wpdb->prefix}snn_chat_messages WHERE session_id = %s ORDER BY created_at ASC", $current_session_id));

                        if ($session_info) {
                            ?>
                            <h2 class="text-xl font-semibold mb-4">Session Information: <?php echo esc_html(substr($current_session_id, 0, 12)); ?>...</h2>
                            <div class="mb-4" id="snn-session-info-block">
                                <p><strong>Chat Name:</strong> <?php echo esc_html(get_the_title($session_info->chat_id) ?: 'N/A'); ?></p>
                                <p><strong>User Name:</strong> <?php echo esc_html($session_info->user_name ?: 'Anonymous'); ?></p>
                                <p><strong>User Email:</strong> <?php echo esc_html($session_info->user_email ?: 'N/A'); ?></p>
                                <p><strong>IP Address:</strong> <?php echo esc_html($session_info->ip_address ?: 'N/A'); ?></p>
                                <p><strong>Started At:</strong> <?php echo esc_html(date('M j, Y H:i:s', strtotime($session_info->created_at))); ?></p>
                                <p><strong>Last Updated:</strong> <?php echo esc_html(date('M j, Y H:i:s', strtotime($session_info->updated_at))); ?></p>
                            </div>

                            <h2 class="text-xl font-semibold mb-4">Messages</h2>
                            <div class="space-y-4" id="snn-session-messages-list">
                                <?php if (!empty($session_messages)) : ?>
                                    <?php foreach ($session_messages as $index => $msg) : ?>
                                        <div class="snn-chat-message-detail <?php echo !empty($msg->message) ? 'snn-user-message-detail' : 'snn-ai-message-detail'; ?> p-3 rounded-lg shadow-sm" id="snn-session-message-<?php echo esc_attr($index); ?>">
                                            <?php if (!empty($msg->message)) : ?>
                                                <p class="font-semibold text-blue-700">You:</p>
                                                <p class="text-gray-800"><?php echo nl2br(esc_html($msg->message)); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($msg->response)) : ?>
                                                <p class="font-semibold text-green-700 mt-2">AI:</p>
                                                <div class="text-gray-800"><?php echo wp_kses_post($msg->response); // Use wp_kses_post to allow the HTML of content cards ?></div>
                                            <?php endif; ?>
                                            <p class="text-xs text-gray-500 mt-1">Tokens: <?php echo esc_html($msg->tokens_used); ?> | Time: <?php echo esc_html(date('H:i:s', strtotime($msg->created_at))); ?></p>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <p class="text-gray-600">No messages found for this session.</p>
                                <?php endif; ?>
                            </div>
                            <?php
                        } else {
                            echo '<p class="text-gray-600">Session details not found.</p>';
                        }
                        ?>
                    <?php else : ?>
                        <p class="text-gray-600 text-center py-10">Select a session from the left sidebar to view its details.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <style>
            .snn-user-message-detail {
                background-color: #e0f2fe;
                border-left: 4px solid #3b82f6;
            }
            .snn-ai-message-detail {
                background-color: #e2e8f0;
                border-left: 4px solid #10b981;
            }
            .view-history-btn:hover {
                color: #ffffff !important;
            }
            /* Style for active session in sidebar */
            .snn-session-history-item.bg-blue-100 {
                border-left: 4px solid #3b82f6;
            }
        </style>
        <script>
            jQuery(document).ready(function($) {
                const sessionDetailsPanel = $('#snn-session-details-panel');
                const adminUrl = '<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-session-history')); ?>';

                function fetchAndRenderSessionDetails(sessionId) {
                    sessionDetailsPanel.html('<p class="text-center py-10 text-blue-500">Loading session details...</p>');
                    
                    $.ajax({
                        url: snn_ai_chat_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'snn_get_session_details_and_messages', // Use the new endpoint
                            nonce: snn_ai_chat_ajax.nonce,
                            session_id: sessionId
                        },
                        success: function(response) {
                            if (response.success && response.data) {
                                const sessionInfo = response.data.session_info;
                                const messages = response.data.messages;
                                let htmlContent = '';

                                if (sessionInfo) {
                                    htmlContent += `
                                        <h2 class="text-xl font-semibold mb-4">Session Information: ${sessionInfo.session_id.substring(0, 12)}...</h2>
                                        <div class="mb-4" id="snn-session-info-block">
                                            <p><strong>Chat Name:</strong> ${sessionInfo.chat_name || 'N/A'}</p>
                                            <p><strong>User Name:</strong> ${sessionInfo.user_name || 'Anonymous'}</p>
                                            <p><strong>User Email:</strong> ${sessionInfo.user_email || 'N/A'}</p>
                                            <p><strong>IP Address:</strong> ${sessionInfo.ip_address || 'N/A'}</p>
                                            <p><strong>Started At:</strong> ${new Date(sessionInfo.created_at).toLocaleString()}</p>
                                            <p><strong>Last Updated:</strong> ${new Date(sessionInfo.updated_at).toLocaleString()}</p>
                                        </div>
                                        <h2 class="text-xl font-semibold mb-4">Messages</h2>
                                        <div class="space-y-4" id="snn-session-messages-list">
                                    `;

                                    if (messages && messages.length > 0) {
                                        messages.forEach((msg, index) => {
                                            const messageClass = msg.message ? 'snn-user-message-detail' : 'snn-ai-message-detail';
                                            htmlContent += `
                                                <div class="snn-chat-message-detail ${messageClass} p-3 rounded-lg shadow-sm" id="snn-session-message-${index}">
                                                    ${msg.message ? `<p class="font-semibold text-blue-700">You:</p><p class="text-gray-800">${msg.message.replace(/\n/g, '<br>')}</p>` : ''}
                                                    ${msg.response ? `<p class="font-semibold text-green-700 mt-2">AI:</p><div class="text-gray-800">${msg.response}</div>` : ''}
                                                    <p class="text-xs text-gray-500 mt-1">Tokens: ${msg.tokens_used} | Time: ${new Date(msg.created_at).toLocaleTimeString()}</p>
                                                </div>
                                            `;
                                        });
                                    } else {
                                        htmlContent += '<p class="text-gray-600">No messages found for this session.</p>';
                                    }
                                    htmlContent += '</div>'; // Close snn-session-messages-list
                                } else {
                                    htmlContent = '<p class="text-gray-600">Session details not found.</p>';
                                }
                                sessionDetailsPanel.html(htmlContent);
                            } else {
                                sessionDetailsPanel.html('<p class="text-red-500">Error: Could not fetch session details.</p>');
                            }
                        },
                        error: function() {
                            sessionDetailsPanel.html('<p class="text-red-500">Failed to load session details. Network error.</p>');
                        }
                    });
                }

                // Handle click on sidebar session items
                $('#snn-session-sidebar').on('click', '.snn-session-history-item', function(e) {
                    e.preventDefault();
                    const newSessionId = $(this).data('session-id');

                    // Update URL without full reload
                    history.pushState(null, '', adminUrl + '&session_id=' + newSessionId);

                    // Update active class
                    $('.snn-session-history-item').removeClass('bg-blue-100 text-blue-800 font-semibold').addClass('hover:bg-gray-100 text-gray-700');
                    $(this).addClass('bg-blue-100 text-blue-800 font-semibold').removeClass('hover:bg-gray-100 text-gray-700');

                    fetchAndRenderSessionDetails(newSessionId);
                });

                // Initial load: Check if a session_id is in the URL and load it
                const initialSessionId = new URLSearchParams(window.location.search).get('session_id');
                if (initialSessionId) {
                    fetchAndRenderSessionDetails(initialSessionId);
                    // Highlight the initial session in the sidebar
                    $('#snn-session-item-' + initialSessionId).addClass('bg-blue-100 text-blue-800 font-semibold').removeClass('hover:bg-gray-100 text-gray-700');
                } else if ($('.snn-session-history-item').length > 0) {
                    // If no session ID in URL, but there are sessions, select the first one
                    const firstSessionId = $('.snn-session-history-item').first().data('session-id');
                    history.replaceState(null, '', adminUrl + '&session_id=' + firstSessionId); // Use replaceState to not add to history
                    fetchAndRenderSessionDetails(firstSessionId);
                    $('.snn-session-history-item').first().addClass('bg-blue-100 text-blue-800 font-semibold').removeClass('hover:bg-gray-100 text-gray-700');
                }
            });
        </script>
        <?php
    }
    
    public function preview_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.'));
        }

        if (!isset($_GET['chat_id'])) {
            wp_die(esc_html__('No chat ID provided for preview.'));
        }
        $chat_id = intval($_GET['chat_id']);
        $chat = get_post($chat_id);

        if (!$chat) {
            wp_die(esc_html__('Invalid chat ID.'));
        }

        $chat_settings_raw = get_post_meta($chat_id, '_snn_chat_settings', true);
        $defaults = $this->get_default_chat_settings();
        $chat_settings = wp_parse_args(is_array($chat_settings_raw) ? $chat_settings_raw : [], $defaults);
        
        // Get post_id from URL for preview context, default to 0 if not set
        $post_id_for_preview = isset($_GET['post_id']) ? intval($_GET['post_id']) : 0;

        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>SNN AI Chat Preview</title>
            <style>#wpadminbar{display:none}</style>
            <?php
            wp_print_styles();
            wp_print_head_scripts();
            ?>
        </head>
        <body class="snn-ai-chat-preview-body">
            <?php
            $this->render_chat_widget($chat, $chat_settings, $post_id_for_preview);
            
            wp_print_footer_scripts();
            ?>
            <script>
            // Function to adjust brightness (replicated from PHP for client-side preview)
            function adjustBrightness(hex, steps) {
                hex = hex.replace('#', '');
                if (hex.length === 3) {
                    hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
                }
                let rgb = [];
                for (let i = 0; i < 6; i += 2) {
                    rgb.push(parseInt(hex.substr(i, 2), 16));
                }

                for (let i = 0; i < 3; i++) {
                    rgb[i] = Math.max(0, Math.min(255, rgb[i] + steps));
                }

                return '#' + rgb.map(val => ('0' + val.toString(16)).slice(-2)).join('');
            }

            window.updateAllChatStyles = function(settings, targetChatId) {
                const chatWidget = document.getElementById('snn-chat-' + targetChatId);
                if (!chatWidget) return;

                const getSetting = (key, defaultValue) => settings[key] !== undefined && settings[key] !== '' ? settings[key] : defaultValue;

                // --- Apply all styles from the settings object ---
                const primaryColor = getSetting('primary_color', '#3b82f6');
                chatWidget.style.setProperty('--snn-primary-color', primaryColor);
                chatWidget.style.setProperty('--snn-primary-color-hover', adjustBrightness(primaryColor, -20));
                
                chatWidget.style.setProperty('--snn-secondary-color', getSetting('secondary_color', '#e5e7eb'));
                chatWidget.style.setProperty('--snn-text-color', getSetting('text_color', '#ffffff'));

                const widgetBgColor = getSetting('chat_widget_bg_color', '#ffffff');
                chatWidget.style.setProperty('--snn-chat-widget-bg-color', widgetBgColor);
                chatWidget.style.setProperty('--snn-widget-border-top-color', adjustBrightness(widgetBgColor, -10));

                chatWidget.style.setProperty('--snn-chat-text-color', getSetting('chat_text_color', '#374151'));

                const inputBgColor = getSetting('chat_input_bg_color', '#f9fafb');
                chatWidget.style.setProperty('--snn-chat-input-bg-color', inputBgColor);
                chatWidget.style.setProperty('--snn-input-border-color', adjustBrightness(inputBgColor, -10));

                const inputTextColor = getSetting('chat_input_text_color', '#1f2937');
                chatWidget.style.setProperty('--snn-chat-input-text-color', inputTextColor);
                chatWidget.style.setProperty('--snn-placeholder-color', adjustBrightness(inputTextColor, 50));

                chatWidget.style.setProperty('--snn-chat-send-button-color', getSetting('chat_send_button_color', '#3b82f6'));
                chatWidget.style.setProperty('--snn-user-message-bg-color', getSetting('user_message_bg_color', '#3b82f6'));
                chatWidget.style.setProperty('--snn-user-message-text-color', getSetting('user_message_text_color', '#ffffff'));
                chatWidget.style.setProperty('--snn-ai-message-bg-color', getSetting('ai_message_bg_color', '#e5e7eb'));
                chatWidget.style.setProperty('--snn-ai-message-text-color', getSetting('ai_message_text_color', '#374151'));

                chatWidget.style.setProperty('--snn-font-size', getSetting('font_size', 14) + 'px');
                chatWidget.style.setProperty('--snn-border-radius', getSetting('border_radius', 8) + 'px');
                chatWidget.style.setProperty('--snn-widget-width', getSetting('widget_width', 350) + 'px');
                chatWidget.style.setProperty('--snn-widget-height', getSetting('widget_height', 500) + 'px');

                // --- Apply functional changes ---
                const position = getSetting('chat_position', 'bottom-right');
                chatWidget.style.removeProperty('bottom');
                chatWidget.style.removeProperty('right');
                chatWidget.style.removeProperty('left');
                chatWidget.style.removeProperty('top');
                const posParts = position.split('-');
                if (posParts.length === 2) {
                    chatWidget.style[posParts[0]] = '20px';
                    chatWidget.style[posParts[1]] = '20px';
                }

                const initialMessage = getSetting('initial_message', 'Hello! How can I help you today?');
                chatWidget.dataset.initialMessage = initialMessage;

                const collectUserInfo = getSetting('collect_user_info', 0);
                chatWidget.dataset.collectUserInfo = collectUserInfo;
                
                const readyQuestions = getSetting('ready_questions', '');
                chatWidget.dataset.readyQuestions = readyQuestions;

                // Update the post_id for dynamic tags in preview
                chatWidget.dataset.postId = settings['preview_post_id'] || 0;


                // Trigger a custom event to re-initialize the chat widget's state in the main script
                const event = new CustomEvent('snn-settings-updated');
                chatWidget.dispatchEvent(event);
            };
            </script>
        </body>
        </html>
        <?php
        exit;
    }
    
    // Changed visibility to public
    public function get_models() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $api_endpoint = esc_url_raw($_POST['api_endpoint'] ?? ''); // New: for custom API
        
        if (empty($provider) || empty($api_key) || ($provider === 'custom' && empty($api_endpoint))) {
            wp_send_json_error('Provider, API key, or Custom API Endpoint missing.');
        }

        $models = [];
        if ($provider === 'openrouter') {
            $models = $this->fetch_openrouter_models($api_key);
        } else if ($provider === 'openai') {
            $models = $this->fetch_openai_models($api_key);
        } else if ($provider === 'custom') { // New: Custom API
            $models = $this->fetch_custom_api_models($api_endpoint, $api_key);
        } else {
            wp_send_json_error('Invalid provider');
        }
        
        wp_send_json_success($models);
    }

    // Changed visibility to public
    public function get_model_details() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        $provider = sanitize_text_field($_POST['provider'] ?? '');
        $model = sanitize_text_field($_POST['model'] ?? '');
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');
        $api_endpoint = esc_url_raw($_POST['api_endpoint'] ?? ''); // New: for custom API
        
        if (empty($provider) || empty($model) || empty($api_key) || ($provider === 'custom' && empty($api_endpoint))) {
            wp_send_json_error('Provider, model, API key, or Custom API Endpoint missing.');
        }
        
        $details = [];
        if ($provider === 'openrouter') {
            
            $models = $this->fetch_openrouter_models($api_key);
            foreach($models as $m) {
                if ($m['id'] === $model) {
                    $details = $m;
                    break;
                }
            }
        } else if ($provider === 'openai') {
            $details = $this->fetch_openai_model_details($model, $api_key);
        } else if ($provider === 'custom') { // New: Custom API
            $details = $this->fetch_custom_api_model_details($model, $api_endpoint, $api_key);
        } else {
            wp_send_json_error('Invalid provider');
        }
        
        wp_send_json_success($details);
    }

    public function delete_chat() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to delete chats.');
        }

        $chat_id = intval($_POST['chat_id'] ?? 0);
        
        if ($chat_id > 0 && get_post_type($chat_id) === 'snn_ai_chat') {
            wp_delete_post($chat_id, true);
            wp_send_json_success();
        } else {
            wp_send_json_error('Invalid chat ID');
        }
    }
    



private function process_link_cards($content, $chat_id) {
        $context_links = get_post_meta($chat_id, 'snn_ai_chats_context_links', true);

        // If no links are defined or the tag isn't in the response, do nothing.
        if (!is_array($context_links) || empty($context_links) || strpos($content, '[LINK_CARD:') === false) {
            return $content;
        }

        $processed_content = preg_replace_callback('/\[LINK_CARD:(\d+)\]/i', function($matches) use ($context_links) {
            $index = intval($matches[1]);

            // Check if the link card index from the AI exists in your settings
            if (isset($context_links[$index])) {
                $link = $context_links[$index];
                $url = esc_url($link['url'] ?? '#');
                $title = esc_html($link['title'] ?? 'Learn More');
                $desc = esc_html($link['desc'] ?? '');
                $img_html = !empty($link['img']) ? '<img src="' . esc_url($link['img']) . '" alt="' . esc_attr($title) . '">' : '';
                $link_text = !empty($link['title']) ? 'View ' . esc_html($link['title']) : 'Learn More';

                // Return the clean, safe HTML for the card
                return '<div class="snn-content-card"><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $img_html . '<div><h4>' . $title . '</h4><p>' . $desc . '</p><span>' . $link_text . ' &rarr;</span></div></a></div>';
            }
            
            // If the AI mentioned a card that doesn't exist, remove the tag
            return '';
        }, $content);

        return $processed_content;
    }





public function handle_chat_api() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        $message = sanitize_text_field($_POST['message'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $chat_id = intval($_POST['chat_id'] ?? 0);
        $user_name = sanitize_text_field($_POST['user_name'] ?? '');
        $user_email = sanitize_email($_POST['user_email'] ?? '');
        $current_post_id = intval($_POST['post_id'] ?? 0);
        
        if (empty($message) || empty($session_id) || empty($chat_id)) {
            wp_send_json_error('Missing required data.');
        }

        if (!$this->check_rate_limits($session_id, $chat_id)) {
            wp_send_json_error(array('response' => 'Rate limit exceeded. Please try again later.'));
        }
        
        $chat_settings_raw = get_post_meta($chat_id, '_snn_chat_settings', true);
        $chat_settings = wp_parse_args(is_array($chat_settings_raw) ? $chat_settings_raw : [], $this->get_default_chat_settings());
        $api_settings = $this->get_settings();
        
        $api_provider = (string)($api_settings['api_provider'] ?? 'openrouter');
        $model = '';
        $api_key = '';
        $custom_api_endpoint = ''; // New variable for custom API endpoint

        if ($api_provider === 'openai') {
            $model = (string)($api_settings['openai_model'] ?? '');
            $api_key = (string)($api_settings['openai_api_key'] ?? '');
        } elseif ($api_provider === 'openrouter') {
            $model = (string)($api_settings['openrouter_model'] ?? '');
            $api_key = (string)($api_settings['openrouter_api_key'] ?? '');
        } elseif ($api_provider === 'custom') { // New: Custom API
            $model = (string)($api_settings['custom_model'] ?? '');
            $api_key = (string)($api_settings['custom_api_key'] ?? '');
            $custom_api_endpoint = (string)($api_settings['custom_api_endpoint'] ?? '');
        }

        if (empty($api_key) || ($api_provider === 'custom' && empty($custom_api_endpoint))) {
            wp_send_json_error(array('response' => 'API key or Custom API Endpoint is not configured.'));
        }
        
        // --- Conversation History Setup ---
        $conversation_history = [];
        $context = [
            'post_id'    => $current_post_id,
            'user_name'  => $user_name,
            'user_email' => $user_email,
            'user_ip'    => (string)$_SERVER['REMOTE_ADDR'],
        ];

        // Build system prompt with tool card logic if enabled
        $final_system_prompt = '';

        // Always add the main system prompt (with dynamic tags) first
        $processed_prompt = $this->process_dynamic_tags($chat_settings['system_prompt'], $context);
        $final_system_prompt .= $processed_prompt;

        // Append tool card system prompt and context links if enabled
        if (!empty($chat_settings['enable_context_link_cards'])) {
            $tool_card_prompt = trim($chat_settings['context_link_cards_system_prompt'] ?? '');
            if (!empty($tool_card_prompt)) {
                $final_system_prompt .= "\n\n" . $tool_card_prompt;
            }
            // Add context links info
            $context_links = get_post_meta($chat_id, 'snn_ai_chats_context_links', true);
            if (is_array($context_links) && count($context_links) > 0) {
                $final_system_prompt .= "\n\nAvailable Contextual Link Cards:\n";
                foreach ($context_links as $i => $link) {
                    $final_system_prompt .= "--- Link Card " . $i . " ---\n";
                    $final_system_prompt .= "Instruction: To show this card, you MUST include the exact string [LINK_CARD:" . $i . "] in your response.\n";
                    $final_system_prompt .= "Context Keywords: " . ($link['context'] ?? '') . "\n";
                }
            }
        }
        
        if (!empty($final_system_prompt)) {
            $conversation_history[] = [
                'role' => 'system',
                'content' => $final_system_prompt
            ];
        }

        if (!empty($chat_settings['keep_conversation_history'])) {
            $previous_messages = $this->get_conversation_history($session_id);
            $conversation_history = array_merge($conversation_history, $previous_messages);
        }
        
        $conversation_history[] = [
            'role' => 'user',
            'content' => (string)$message
        ];
        
        // --- API Parameters ---
        $api_params = [
            'temperature' => floatval($api_settings['temperature'] ?? 0.7),
            'max_tokens' => intval($api_settings['max_tokens'] ?? 500),
            'top_p' => floatval($api_settings['top_p'] ?? 1.0),
            'frequency_penalty' => floatval($api_settings['frequency_penalty'] ?? 0.0),
            'presence_penalty' => floatval($api_settings['presence_penalty'] ?? 0.0),
        ];

        // --- AI Interaction ---
        $response_content = '';
        $tokens_used = 0;

        $response = false;
        if ($api_provider === 'openrouter') {
            $response = $this->send_to_openrouter($conversation_history, $model, $api_key, $api_params);
        } elseif ($api_provider === 'openai') {
            $response = $this->send_to_openai($conversation_history, $model, $api_key, $api_params);
        } elseif ($api_provider === 'custom') {
            $response = $this->send_to_custom_api($conversation_history, $model, $custom_api_endpoint, $api_key, $api_params);
        }

        if ($response && isset($response['content'])) {
            // This is the raw response from the AI, e.g., "[LINK_CARD:0] Some text."
            $raw_ai_response = $response['content']; 
            $tokens_used = $response['tokens'];

            // Process the response to convert [LINK_CARD:N] to HTML for database storage ONLY.
            // This ensures the admin chat history looks correct.
            $processed_for_db = $this->process_link_cards($raw_ai_response, $chat_id);

            // Save the PROCESSED HTML to the database.
            $this->save_chat_message($session_id, $chat_id, $message, $processed_for_db, $tokens_used, $user_name, $user_email);

            // Send the RAW AI response to the frontend JavaScript.
            // The JavaScript is already set up to handle the [LINK_CARD:N] tag correctly.
            wp_send_json_success([
                'response' => $raw_ai_response, 
                'tokens' => $tokens_used
            ]);
        } else {
            wp_send_json_error(array('response' => 'Failed to get an AI response. Please check your API settings.'));
        }
    }




    




    public function render_frontend_chats() {
        $chats = $this->get_active_chats();
        
        foreach ($chats as $chat) {
            $chat_settings_raw = get_post_meta($chat->ID, '_snn_chat_settings', true);
            if (!is_array($chat_settings_raw)) {
                $chat_settings_raw = [];
            }
            $chat_settings = wp_parse_args($chat_settings_raw, $this->get_default_chat_settings());

            if ($this->should_show_chat($chat_settings)) {
                $this->render_chat_widget($chat, $chat_settings);
            }
        }
    }

private function render_chat_widget($chat, $settings, $post_id_override = null) {
        // session_id will now be managed by frontend JS using localStorage
        // $session_id = 'snn_' . time() . '_' . bin2hex(random_bytes(8)); // Removed PHP-side generation
        $post_id = ($post_id_override !== null) ? $post_id_override : get_queried_object_id();
        ?>
        <div class="snn-ai-chat-widget" id="snn-chat-<?php echo esc_attr($chat->ID); ?>"
             data-chat-id="<?php echo esc_attr($chat->ID); ?>"
             data-post-id="<?php echo esc_attr($post_id); ?>"
             data-initial-message="<?php echo esc_attr($settings['initial_message']); ?>"
             data-collect-user-info="<?php echo esc_attr((int)$settings['collect_user_info']); ?>"
             data-ready-questions="<?php echo esc_attr($settings['ready_questions']); ?>"
             style="
                 --snn-primary-color: <?php echo esc_attr((string)($settings['primary_color'] ?? '#3b82f6')); ?>;
                 --snn-primary-color-hover: <?php echo esc_attr($this->adjust_brightness((string)($settings['primary_color'] ?? '#3b82f6'), -20)); ?>;
                 --snn-secondary-color: <?php echo esc_attr((string)($settings['secondary_color'] ?? '#e5e7eb')); ?>;
                 --snn-text-color: <?php echo esc_attr((string)($settings['text_color'] ?? '#ffffff')); ?>;
                 --snn-chat-widget-bg-color: <?php echo esc_attr((string)($settings['chat_widget_bg_color'] ?? '#ffffff')); ?>;
                 --snn-chat-text-color: <?php echo esc_attr((string)($settings['chat_text_color'] ?? '#374151')); ?>;
                 --snn-user-message-bg-color: <?php echo esc_attr((string)($settings['user_message_bg_color'] ?? '#3b82f6')); ?>;
                 --snn-user-message-text-color: <?php echo esc_attr((string)($settings['user_message_text_color'] ?? '#ffffff')); ?>;
                 --snn-ai-message-bg-color: <?php echo esc_attr((string)($settings['ai_message_bg_color'] ?? '#e5e7eb')); ?>;
                 --snn-ai-message-text-color: <?php echo esc_attr((string)($settings['ai_message_text_color'] ?? '#374151')); ?>;
                 --snn-chat-input-bg-color: <?php echo esc_attr((string)($settings['chat_input_bg_color'] ?? '#f9fafb')); ?>;
                 --snn-chat-input-text-color: <?php echo esc_attr((string)($settings['chat_input_text_color'] ?? '#1f2937')); ?>;
                 --snn-chat-send-button-color: <?php echo esc_attr((string)($settings['chat_send_button_color'] ?? '#3b82f6')); ?>;
                 --snn-font-size: <?php echo esc_attr((string)($settings['font_size'] ?? 14)); ?>px;
                 --snn-border-radius: <?php echo esc_attr((string)($settings['border_radius'] ?? 8)); ?>px;
                 --snn-widget-width: <?php echo esc_attr((string)($settings['widget_width'] ?? 350)); ?>px;
                 --snn-widget-height: <?php echo esc_attr((string)($settings['widget_height'] ?? 500)); ?>px;
                 --snn-input-border-color: <?php echo esc_attr($this->adjust_brightness((string)($settings['chat_input_bg_color'] ?? '#f9fafb'), -10)); ?>;
                 --snn-widget-border-top-color: <?php echo esc_attr($this->adjust_brightness((string)($settings['chat_widget_bg_color'] ?? '#ffffff'), -10)); ?>;
                 --snn-placeholder-color: <?php echo esc_attr($this->adjust_brightness((string)($settings['chat_input_text_color'] ?? '#1f2937'), 50)); ?>;
                 <?php switch ((string)($settings['chat_position'] ?? 'bottom-right')) { case 'bottom-right': echo 'bottom: 20px; right: 20px;'; break; case 'bottom-left': echo 'bottom: 20px; left: 20px;'; break; case 'top-right': echo 'top: 20px; right: 20px;'; break; case 'top-left': echo 'top: 20px; left: 20px;'; break; } ?>
             ">
            <div class="snn-chat-toggle" id="snn-chat-toggle-<?php echo esc_attr($chat->ID); ?>">
                <span class="dashicons dashicons-format-chat"></span>
            </div>
            
            <div class="snn-chat-container" id="snn-chat-container-<?php echo esc_attr($chat->ID); ?>" style="display: none;">
                <div class="snn-chat-header" id="snn-chat-header-<?php echo esc_attr($chat->ID); ?>">
                    <h3><?php echo esc_html($chat->post_title); ?></h3>
                    <div class="snn-header-controls">
                        <button class="snn-new-chat" id="snn-new-chat-<?php echo esc_attr($chat->ID); ?>" title="New Chat">
                            <span class="dashicons dashicons-plus-alt"></span>
                        </button>
                        <button class="snn-chat-close" id="snn-chat-close-<?php echo esc_attr($chat->ID); ?>">
                            <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 4L4 12M4 4L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="snn-user-info-form-overlay" id="snn-user-info-form-overlay-<?php echo esc_attr($chat->ID); ?>" style="display: none;">
                    <div class="snn-user-info-form" id="snn-user-info-form-<?php echo esc_attr($chat->ID); ?>">
                        <p>Please provide your information to start the chat:</p>
                        <input type="text" placeholder="Your Name" id="snn-user-name-input-<?php echo esc_attr($chat->ID); ?>" required>
                        <input type="email" placeholder="Your Email" id="snn-user-email-input-<?php echo esc_attr($chat->ID); ?>" required>
                        <button type="button" class="snn-start-chat-btn" id="snn-start-chat-btn-<?php echo esc_attr($chat->ID); ?>">Start Chat</button>
                        <div class="snn-chat-message-box" style="display: none; background-color: #ffe0e0; border: 1px solid #ff0000; color: #cc0000; padding: 10px; margin-top: 10px; border-radius: 5px;" id="snn-chat-error-message-box-<?php echo esc_attr($chat->ID); ?>"></div>
                    </div>
                </div>

                <div class="snn-chat-messages" id="snn-chat-messages-<?php echo esc_attr($chat->ID); ?>">
                </div>
                
                <div class="snn-ready-questions-container" id="snn-ready-questions-container-<?php echo esc_attr($chat->ID); ?>">
                </div>

                <div class="snn-chat-input-container" id="snn-chat-input-container-<?php echo esc_attr($chat->ID); ?>">
                    <input type="text" class="snn-chat-input" id="snn-chat-input-<?php echo esc_attr($chat->ID); ?>" placeholder="Type your message...">
                    <button class="snn-chat-send" id="snn-chat-send-<?php echo esc_attr($chat->ID); ?>">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M22 2L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            <path d="M22 2L15 22L11 13L2 9L22 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        <style>
        .snn-ai-chat-widget { position: fixed; z-index: 99999; }
        .snn-ai-chat-widget .snn-chat-toggle { background-color: var(--snn-primary-color); border-radius: 9999px; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); transition: background-color 0.3s ease; }
        .snn-ai-chat-widget .snn-chat-toggle:hover { background-color: var(--snn-primary-color-hover); }
        .snn-ai-chat-widget .snn-chat-toggle .dashicons { color: var(--snn-text-color); font-size: 28px; width: 28px; height: 28px; line-height: 1; display: flex; align-items: center; justify-content: center; }
        .snn-ai-chat-widget .snn-chat-container { width: var(--snn-widget-width); height: var(--snn-widget-height); border-radius: var(--snn-border-radius); background-color: var(--snn-chat-widget-bg-color); box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1); display: flex; flex-direction: column; overflow: hidden; position: absolute; bottom: 0; right: 0; }
        .snn-ai-chat-widget .snn-chat-header { background-color: var(--snn-primary-color); color: var(--snn-text-color); padding: 15px; display: flex; justify-content: space-between; align-items: center; border-top-left-radius: var(--snn-border-radius); border-top-right-radius: var(--snn-border-radius); z-index:    11; }
        .snn-ai-chat-widget .snn-header-controls { display: flex; align-items: center; }
        .snn-ai-chat-widget .snn-new-chat { background: none; border: none; color: var(--snn-text-color); cursor: pointer; padding: 3.75px; margin-right: 7.5px; border-radius: 3.75px; transition: background-color 0.3s ease; display: flex; align-items: center; justify-content: center; }
        .snn-ai-chat-widget .snn-new-chat:hover { background-color: rgba(255, 255, 255, 0.2); }
        .snn-ai-chat-widget .snn-new-chat .dashicons { font-size: 20px; width: 20px; height: 20px; line-height: 1; }
        .snn-ai-chat-widget .snn-chat-header h3 { margin: 0; font-size: 16.875px; font-weight: 600; color: var(--snn-text-color); }
        .snn-ai-chat-widget .snn-chat-close { background: none; border: none; color: var(--snn-text-color); cursor: pointer; font-size: 18.75px; line-height: 1; padding: 3.75px; border-radius: 3.75px; transition: background-color 0.3s ease; }
        .snn-ai-chat-widget .snn-chat-close:hover { background-color: rgba(255, 255, 255, 0.2); }
        .snn-ai-chat-widget .snn-chat-messages { flex-grow: 1; padding: 15px; overflow-y: auto; font-size: var(--snn-font-size); color: var(--snn-chat-text-color); position: relative; }
        .snn-ai-chat-widget .snn-chat-message-box { margin: 0 15px 10px; } /* Adjust margin for error box within messages */
        .snn-ai-chat-widget .snn-chat-message { margin-bottom: 11.25px; display: flex; }
        .snn-ai-chat-widget .snn-chat-message.snn-user-message { justify-content: flex-end; }
        .snn-ai-chat-widget .snn-chat-message.snn-ai-message { justify-content: flex-start; }
        .snn-ai-chat-widget .snn-message-content { max-width: 80%; padding: 11.25px 15px; border-radius: 11.25px; word-wrap: break-word; line-height: 1.5; }
        .snn-ai-chat-widget .snn-chat-message.snn-user-message .snn-message-content { background-color: var(--snn-user-message-bg-color); color: var(--snn-user-message-text-color); border-bottom-right-radius: 3.75px; }
        .snn-ai-chat-widget .snn-chat-message.snn-ai-message .snn-message-content { background-color: var(--snn-ai-message-bg-color); color: var(--snn-ai-message-text-color); border-bottom-left-radius: 3.75px; }
        
        /* User Info Form Overlay - Updated styles */
        .snn-ai-chat-widget .snn-user-info-form-overlay { 
            position: absolute; 
            top: 0; 
            left: 0; 
            right: 0; 
            bottom: 0; 
            background: rgba(255, 255, 255, 0.95); /* Semi-transparent white */
            z-index: 10; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            padding: 15px; 
            flex-direction: column; /* Align content vertically */
        }
        .snn-ai-chat-widget .snn-user-info-form { 
            text-align: center; 
            background: #fff; 
            padding: 20px; 
            border-radius: var(--snn-border-radius); 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
            width: 90%; 
            max-width: 300px; /* Limit form width */
        }
        .snn-ai-chat-widget .snn-user-info-form p { margin-bottom: 15px; color: var(--snn-chat-text-color); }
        .snn-ai-chat-widget .snn-user-info-form input { width: 100%; padding: 11.25px; margin-bottom: 11.25px; border: 1px solid var(--snn-input-border-color); border-radius: 7.5px; background-color: var(--snn-chat-input-bg-color); color: var(--snn-chat-input-text-color); }
        .snn-ai-chat-widget .snn-user-info-form input::placeholder { color: var(--snn-placeholder-color); }
        .snn-ai-chat-widget .snn-user-info-form button.snn-start-chat-btn { background-color: var(--snn-primary-color); color: var(--snn-text-color); padding: 11.25px 22.5px; border: none; border-radius: 7.5px; cursor: pointer; transition: background-color 0.3s ease; }
        .snn-ai-chat-widget .snn-user-info-form button.snn-start-chat-btn:hover { background-color: var(--snn-primary-color-hover); }

        /* Ready Questions Position - New styles */
        .snn-ai-chat-widget .snn-ready-questions-container { 
            padding: 15px; 
            padding-bottom: 0; /* No padding at bottom as input container follows */
            display: flex; 
            flex-direction: column; 
            align-items: flex-start; 
            gap: 8px; 
            border-top: 1px solid var(--snn-widget-border-top-color); /* Separator from messages */
        }
        .snn-ai-chat-widget .snn-ready-question-btn { 
            background-color: var(--snn-chat-input-bg-color); 
            color: var(--snn-primary-color); 
            border: 1px solid var(--snn-primary-color); 
            padding: 8px 12px; 
            border-radius: 10px; 
            cursor: pointer; 
            text-align: left; 
            transition: all 0.2s ease; 
            font-size: calc(var(--snn-font-size) * 0.9); 
            width: 100%; 
        }
        .snn-ai-chat-widget .snn-ready-question-btn:hover { background-color: var(--snn-primary-color); color: var(--snn-text-color); }
        
        .snn-ai-chat-widget .snn-chat-input-container { display: flex; padding: 15px; border-top: 1px solid var(--snn-widget-border-top-color); background-color: var(--snn-chat-widget-bg-color); border-bottom-left-radius: var(--snn-border-radius); border-bottom-right-radius: var(--snn-border-radius); }
        .snn-ai-chat-widget .snn-chat-input { flex-grow: 1; padding: 11.25px 15px; border: 1px solid var(--snn-input-border-color); border-radius: 7.5px; outline: none; background-color: var(--snn-chat-input-bg-color); color: var(--snn-chat-input-text-color); margin-right: 7.5px; }
        .snn-ai-chat-widget .snn-chat-input:focus { border-color: var(--snn-primary-color); box-shadow: 0 0 0 2px rgba(var(--snn-primary-color-rgb), 0.5); }
        .snn-ai-chat-widget .snn-chat-input::placeholder { color: var(--snn-placeholder-color); }
        .snn-ai-chat-widget .snn-chat-send { background: none; border: none; color: var(--snn-chat-send-button-color); cursor: pointer; padding: 7.5px; border-radius: 7.5px; transition: background-color 0.3s ease; }
        .snn-ai-chat-widget .snn-chat-send:hover { background-color: rgba(0, 0, 0, 0.05); }
        .snn-ai-chat-widget .snn-chat-send:disabled { opacity: 0.5; cursor: not-allowed; }
        @media (max-width: 768px) { .snn-ai-chat-widget .snn-chat-container { width: calc(100vw - 20px); height: calc(100vh - 100px); max-width: 400px; max-height: 600px; } }
        .snn-message-content .snn-content-card { border: 1px solid #e0e0e0; border-radius: 8px; padding: 12px; margin: 10px 0; background-color: #f9f9f9; font-family: sans-serif; }
        .snn-message-content .snn-content-card a { text-decoration: none; color: inherit; display: flex; align-items: center; }
        .snn-message-content .snn-content-card img { width: 65px; height: 65px; border-radius: 4px; object-fit: cover; margin-right: 12px; }
        .snn-message-content .snn-content-card div { flex-grow: 1; }
        .snn-message-content .snn-content-card h4 { margin: 0 0 5px 0; font-size: 1.05em; font-weight: bold; color: #1f2937; }
        .snn-message-content .snn-content-card p { margin: 0 0 8px 0; font-size: 0.9em; color: #555; max-height: 40px; overflow: hidden; }
        .snn-message-content .snn-content-card span { font-size: 0.85em; color: var(--snn-primary-color, #3b82f6); font-weight: bold; }
        </style>
        <script>
        jQuery(document).ready(function($) {
            $('.snn-ai-chat-widget').each(function() {
                const widget = $(this);
                if (!widget.length) return;

                const chatId = widget.data('chat-id');
                let contextualId = widget.data('post-id'); // Use 'let' as it can be updated
                let initialMessage = widget.data('initial-message');
                let collectUserInfo = widget.data('collect-user-info') == 1;
                let readyQuestionsRaw = widget.data('ready-questions');

                const container = widget.find('#snn-chat-container-' + chatId);
                const toggleBtn = widget.find('#snn-chat-toggle-' + chatId);
                const closeBtn = widget.find('#snn-chat-close-' + chatId);
                const newChatBtn = widget.find('#snn-new-chat-' + chatId);
                const messagesContainer = widget.find('#snn-chat-messages-' + chatId);
                const userInfoOverlay = widget.find('#snn-user-info-form-overlay-' + chatId);
                const startChatBtn = widget.find('#snn-start-chat-btn-' + chatId);
                const userNameInput = widget.find('#snn-user-name-input-' + chatId);
                const userEmailInput = widget.find('#snn-user-email-input-' + chatId);
                const chatInput = widget.find('#snn-chat-input-' + chatId);
                const chatSend = widget.find('#snn-chat-send-' + chatId);
                const errorBox = widget.find('#snn-chat-error-message-box-' + chatId);
                const readyQuestionsContainer = widget.find('#snn-ready-questions-container-' + chatId);

                // Local Storage Keys
                const SESSION_ID_KEY = `snn_chat_${chatId}_session_id`;
                const USER_NAME_KEY = `snn_chat_${chatId}_user_name`;
                const USER_EMAIL_KEY = `snn_chat_${chatId}_user_email`;

                function displayError(message) {
                    errorBox.text(message).show();
                    setTimeout(() => errorBox.hide().text(''), 5000);
                }

                // *** NEW and IMPROVED appendMessage function ***
                function appendMessage(role, content) {
                    const messageClass = role === 'user' ? 'snn-user-message' : 'snn-ai-message';
                    let finalContentHTML = '';

                    if (role === 'ai') {
                        // Regex to find the HTML for our content card(s)
                        const cardRegex = /(<div class="snn-content-card">[\s\S]*?<\/div>)/g;
                        
                        // Extract all card HTML blocks
                        const cardsHTML = (content.match(cardRegex) || []).join('');
                        
                        // Get the text part by removing the card HTML
                        const markdownText = content.replace(cardRegex, '').trim();

                        let markdownHTML = '';
                        if (markdownText) {
                            // Check if the markdown library is loaded
                            if (typeof markdown !== 'undefined' && typeof markdown.toHTML === 'function') {
                                try {
                                    // Convert ONLY the text part to HTML
                                    markdownHTML = markdown.toHTML(markdownText);
                                } catch (e) {
                                    console.error("Markdown parsing error:", e);
                                    // Fallback for the text part if markdown fails
                                    markdownHTML = `<p>${$('<div>').text(markdownText).html().replace(/\n/g, '<br>')}</p>`;
                                }
                            } else {
                                 // Fallback if markdown library is not available
                                 markdownHTML = `<p>${$('<div>').text(markdownText).html().replace(/\n/g, '<br>')}</p>`;
                            }
                        }
                        
                        // Combine the rendered markdown text and the raw HTML of the cards
                        finalContentHTML = markdownHTML + cardsHTML;

                    } else { // For user messages, just safely escape the text
                        finalContentHTML = $('<div>').text(content).html();
                    }

                    // Append the final combined content to the chat
                    const messageHTML = `<div class="snn-chat-message ${messageClass}"><div class="snn-message-content">${finalContentHTML}</div></div>`;
                    messagesContainer.append(messageHTML);
                    messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
                }

                function sendMessage(messageOverride) {
                    const message = messageOverride || chatInput.val().trim();
                    if (message === '') return;

                    readyQuestionsContainer.empty(); // Clear the ready questions container

                    appendMessage('user', message);
                    chatInput.val('');
                    
                    const typingIndicatorHTML = `<div class="snn-chat-message snn-ai-message snn-typing-indicator"><div class="snn-message-content"><span>.</span><span>.</span><span>.</span></div></div>`;
                    messagesContainer.append(typingIndicatorHTML);
                    messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
                    chatSend.prop('disabled', true);
                    chatInput.prop('disabled', true);

                    const currentSessionId = widget.data('session-id');
                    const currentUserName = widget.data('user-name') || '';
                    const currentUserEmail = widget.data('user-email') || '';

                    $.ajax({
                        url: snn_ai_chat_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'snn_ai_chat_api',
                            nonce: snn_ai_chat_ajax.nonce,
                            message: message,
                            session_id: currentSessionId,
                            chat_id: chatId,
                            user_name: currentUserName,
                            user_email: currentUserEmail,
                            post_id: contextualId || 0
                        },
                        success: function(response) {
                            if (response.success) {
                                appendMessage('ai', response.data.response);
                            } else {
                                displayError(response.data.response || 'An unknown error occurred.');
                            }
                        },
                        error: function() {
                            displayError('Request failed. Please check your connection.');
                        },
                        complete: function() {
                            widget.find('.snn-typing-indicator').remove();
                            messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
                            chatSend.prop('disabled', false);
                            chatInput.prop('disabled', false).focus();
                        }
                    });
                }

                function loadSessionMessages(sessionId) {
                    $.ajax({
                        url: snn_ai_chat_ajax.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'snn_get_session_details_and_messages', // Use the new endpoint
                            nonce: snn_ai_chat_ajax.nonce,
                            session_id: sessionId,
                            chat_id: chatId
                        },
                        success: function(response) {
                            if (response.success && response.data && response.data.messages.length > 0) {
                                messagesContainer.empty(); // Clear initial message if history exists
                                response.data.messages.forEach(function(msg) {
                                    if (msg.message) {
                                        appendMessage('user', msg.message);
                                    }
                                    if (msg.response) {
                                        appendMessage('ai', msg.response);
                                    }
                                });
                            } else {
                                // If no history, show initial message and ready questions
                                appendMessage('ai', initialMessage);
                                renderReadyQuestions();
                            }
                        },
                        error: function() {
                            displayError('Failed to load chat history.');
                            appendMessage('ai', initialMessage); // Fallback to initial message
                            renderReadyQuestions();
                        }
                    });
                }

                function renderReadyQuestions() {
                    readyQuestionsContainer.empty();
                    if (readyQuestionsRaw) {
                        const questions = readyQuestionsRaw.split('\n').filter(q => q.trim() !== '');
                        if (questions.length > 0) {
                            const questionsHTML = questions.map(q => `<button class="snn-ready-question-btn">${$('<div>').text(q).html()}</button>`).join('');
                            readyQuestionsContainer.append(questionsHTML);
                        }
                    }
                }

                function initializeChatUI() {
                    messagesContainer.empty(); // Clear messages
                    errorBox.hide();

                    let currentSessionId = localStorage.getItem(SESSION_ID_KEY);
                    let currentUserName = localStorage.getItem(USER_NAME_KEY);
                    let currentUserEmail = localStorage.getItem(USER_EMAIL_KEY);

                    if (!currentSessionId) {
                        currentSessionId = 'snn_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
                        localStorage.setItem(SESSION_ID_KEY, currentSessionId);
                    }
                    widget.data('session-id', currentSessionId);

                    if (collectUserInfo && (!currentUserName || !currentUserEmail)) {
                        userInfoOverlay.show();
                        chatInput.prop('disabled', true);
                        chatSend.prop('disabled', true);
                        userNameInput.val(currentUserName || ''); // Pre-fill if partial info exists
                        userEmailInput.val(currentUserEmail || '');
                    } else {
                        userInfoOverlay.hide();
                        chatInput.prop('disabled', false);
                        chatSend.prop('disabled', false);
                        widget.data('user-name', currentUserName);
                        widget.data('user-email', currentUserEmail);
                        loadSessionMessages(currentSessionId); // Load history if session exists
                    }
                }

                function startNewSession() {
                    localStorage.removeItem(SESSION_ID_KEY);
                    // Keep user info for convenience
                    // localStorage.removeItem(USER_NAME_KEY);
                    // localStorage.removeItem(USER_EMAIL_KEY);
                    initializeChatUI(); // This will generate a new session ID and re-init
                }

                toggleBtn.on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    container.toggle();
                    if (container.is(':visible') && !chatInput.is(':disabled')) {
                        chatInput.focus();
                    }
                });

                closeBtn.on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    container.hide();
                });
                
                newChatBtn.on('click', startNewSession);
                chatSend.on('click', () => sendMessage());
                chatInput.on('keypress', function(e) {
                    if (e.which === 13 && !e.shiftKey) {
                        e.preventDefault();
                        sendMessage();
                    }
                });

                readyQuestionsContainer.on('click', '.snn-ready-question-btn', function() {
                    const question = $(this).text();
                    sendMessage(question);
                });

                startChatBtn.on('click', function() {
                    const userName = userNameInput.val().trim();
                    const userEmail = userEmailInput.val().trim();
                    if (userName && userEmail) {
                        localStorage.setItem(USER_NAME_KEY, userName);
                        localStorage.setItem(USER_EMAIL_KEY, userEmail);
                        widget.data('user-name', userName);
                        widget.data('user-email', userEmail);

                        // Update user info in session if session already exists
                        const sessionId = localStorage.getItem(SESSION_ID_KEY);
                        if (sessionId) {
                            $.post({
                                url: snn_ai_chat_ajax.ajax_url,
                                data: {
                                    action: 'snn_update_user_info',
                                    nonce: snn_ai_chat_ajax.nonce,
                                    session_id: sessionId,
                                    user_name: userName,
                                    user_email: userEmail
                                }
                            });
                        }

                        initializeChatUI();
                        chatInput.focus();
                    } else {
                        displayError('Please fill in your name and email.');
                    }
                });

                // For live preview updates
                widget.on('snn-settings-updated', function(event, newSettings) {
                    initialMessage = widget.data('initial-message');
                    collectUserInfo = widget.data('collect-user-info') == 1;
                    readyQuestionsRaw = widget.data('ready-questions');
                    contextualId = widget.data('post-id'); // Update contextualId from data attribute

                    // Re-initialize UI to apply new settings, but keep session if possible
                    initializeChatUI(); 
                });
                
                // Initial setup
                initializeChatUI();
            });
        });
        </script>
        <?php
    }
    
    private function get_dashboard_stats() {
        global $wpdb;
        $stats = array();
        $stats['active_chats'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'snn_ai_chat' AND post_status = 'publish'") ?: 0;
        $stats['total_tokens'] = $wpdb->get_var("SELECT SUM(tokens_used) FROM {$wpdb->prefix}snn_chat_messages") ?: 0;
        $stats['total_sessions'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_sessions") ?: 0;
        $stats['today_sessions'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_sessions WHERE DATE(created_at) = %s", current_time('Y-m-d'))) ?: 0;
        $stats['today_messages'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_messages WHERE DATE(created_at) = %s", current_time('Y-m-d'))) ?: 0;
        $stats['today_tokens'] = $wpdb->get_var($wpdb->prepare("SELECT SUM(tokens_used) FROM {$wpdb->prefix}snn_chat_messages WHERE DATE(created_at) = %s", current_time('Y-m-d'))) ?: 0;
        $stats['month_messages'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_messages WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d", current_time('Y'), current_time('m'))) ?: 0;
        $stats['month_tokens'] = $wpdb->get_var($wpdb->prepare("SELECT SUM(tokens_used) FROM {$wpdb->prefix}snn_chat_messages WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d", current_time('Y'), current_time('m'))) ?: 0;
        $stats['month_sessions'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_sessions WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d", current_time('Y'), current_time('m'))) ?: 0;
        return $stats;
    }
    
    private function get_recent_activities($limit = 20) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT s.*,
                   COUNT(m.id) as message_count,
                   SUM(m.tokens_used) as total_tokens
            FROM {$wpdb->prefix}snn_chat_sessions s
            LEFT JOIN {$wpdb->prefix}snn_chat_messages m ON s.session_id = m.session_id
            GROUP BY s.id
            ORDER BY s.created_at DESC
            LIMIT %d
        ", $limit)) ?: [];
    }
    
    private function get_settings() {
        $defaults = array(
            'api_provider' => 'openrouter',
            'openrouter_api_key' => '',
            'openrouter_model' => 'openai/gpt-4.1-mini',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4.1-mini',
            'custom_api_endpoint' => '', // New
            'custom_api_key' => '', // New
            'custom_model' => '', // New
            'default_system_prompt' => 'You are a helpful assistant.',
            'default_initial_message' => 'Hello! How can I help you today?',
            'temperature' => 0.7,
            'max_tokens' => 500,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
        );
        return get_option('snn_ai_chat_settings', $defaults);
    }
    
    private function save_settings() {
        if (!isset($_POST['snn_ai_chat_settings_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['snn_ai_chat_settings_nonce'])), 'snn_ai_chat_settings_nonce_action')) {
            return;
        }

        $current_settings = $this->get_settings();
        $new_settings = [];

        // Loop through all possible settings keys (from defaults) to ensure we process all of them
        foreach ($current_settings as $key => $default_value) {
            if (isset($_POST[$key])) {
                if (is_int($default_value)) {
                        $new_settings[$key] = intval($_POST[$key]);
                } elseif (is_float($default_value)) {
                        $new_settings[$key] = floatval($_POST[$key]);
                } elseif ($key === 'default_system_prompt') {
                    $new_settings[$key] = sanitize_textarea_field(wp_unslash($_POST[$key]));
                } elseif ($key === 'custom_api_endpoint') { // Sanitize URL for custom endpoint
                    $new_settings[$key] = esc_url_raw(wp_unslash($_POST[$key]));
                }
                else {
                    $new_settings[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
                }
            } else {
                // If the key wasn't in the form, keep the old value
                $new_settings[$key] = $current_settings[$key];
            }
        }
        
        update_option('snn_ai_chat_settings', $new_settings);

        // Redirect with a success message
        $current_page_url = remove_query_arg('settings-updated', wp_get_referer());
        wp_safe_redirect(add_query_arg('settings-updated', 'true', $current_page_url));
        exit;
    }
    
    private function get_all_chats() {
        return get_posts(array(
            'post_type' => 'snn_ai_chat',
            'post_status' => 'publish',
            'numberposts' => -1
        )) ?: [];
    }
    
    private function get_active_chats() {
        return $this->get_all_chats();
    }
    
    private function get_chat_history() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT s.*,
                   COUNT(m.id) as message_count,
                   SUM(m.tokens_used) as total_tokens
            FROM {$wpdb->prefix}snn_chat_sessions s
            LEFT JOIN {$wpdb->prefix}snn_chat_messages m ON s.session_id = m.session_id
            GROUP BY s.id
            ORDER BY s.created_at DESC
            LIMIT 100
        ") ?: [];
    }

    private function get_chat_history_sessions_for_sidebar() {
        global $wpdb;
        return $wpdb->get_results("
            SELECT session_id, user_name, created_at
            FROM {$wpdb->prefix}snn_chat_sessions
            ORDER BY created_at DESC
            LIMIT 200
        ") ?: [];
    }

    public function get_session_details_and_messages() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        if (empty($session_id)) {
            wp_send_json_error('Session ID missing.');
        }

        global $wpdb;
        // Fetch session info
        $session_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}snn_chat_sessions WHERE session_id = %s", $session_id));
        
        // Fetch messages
        $messages = $wpdb->get_results($wpdb->prepare("
            SELECT message, response FROM {$wpdb->prefix}snn_chat_messages
            WHERE session_id = %s
            ORDER BY created_at ASC
        ", $session_id));

        // Add chat name to session info
        if ($session_info) {
            $session_info->chat_name = get_the_title($session_info->chat_id) ?: 'N/A';
        }

        if ($session_info || $messages) {
            wp_send_json_success([
                'session_info' => $session_info,
                'messages' => $messages
            ]);
        } else {
            wp_send_json_success(['session_info' => null, 'messages' => []]); // Return empty if nothing found
        }
    }
    
    private function get_chat_stats($chat_id) {
        global $wpdb;
        
        $sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_sessions WHERE chat_id = %d
        ", $chat_id)) ?: 0;
        
        return sprintf('%d sessions', $sessions);
    }
    
    private function get_default_chat_settings() {
        return array(
            'initial_message' => 'Hello! How can I help you today?',
            'ready_questions' => "Contact info?\nWhat services you provide?", // Added default questions
            'system_prompt' => 'You are a helpful assistant.',
            'keep_conversation_history' => 1,
            'chat_position' => 'bottom-right',
            'primary_color' => '#3b82f6',
            'secondary_color' => '#e5e7eb',
            'text_color' => '#ffffff',
            'chat_widget_bg_color' => '#ffffff',
            'chat_text_color' => '#374151',
            'user_message_bg_color' => '#3b82f6',
            'user_message_text_color' => '#ffffff',
            'ai_message_bg_color' => '#e5e7eb',
            'ai_message_text_color' => '#374151',
            'chat_input_bg_color' => '#f9fafb',
            'chat_input_text_color' => '#1f2937',
            'chat_send_button_color' => '#3b82f6',
            'font_size' => 14,
            'border_radius' => 8,
            'widget_width' => 350,
            'widget_height' => 500,
            'show_on_all_pages' => 1,
            'specific_pages' => '',
            'exclude_pages' => '',
            'show_on_home' => 0,
            'show_on_front_page' => 0,
            'show_on_posts' => 0,
            'show_on_pages' => 0,
            'show_on_categories' => 0,
            'show_on_archives' => 0,
            'max_tokens_per_session' => 4000,
            'max_tokens_per_ip_daily' => 10000,
            'max_chats_per_ip_daily' => 50,
            'rate_limit_per_minute' => 10,
            'collect_user_info' => 0,
            'temperature' => 0.7,
            'max_tokens' => 500,
            'top_p' => 1.0,
            'frequency_penalty' => 0.0,
            'presence_penalty' => 0.0,
            'enable_context_link_cards' => 0, // Added default for the new setting
            'context_link_cards_system_prompt' => '', // Added default for the new setting
        );
    }
    
    private function should_show_chat($settings) {
        $post_id = get_queried_object_id();

        if (!empty($settings['exclude_pages'])) {
            $exclude_pages = array_map('intval', explode(',', (string)($settings['exclude_pages'] ?? '')));
            if (in_array($post_id, $exclude_pages, true)) {
                return false;
            }
        }

        if (!empty($settings['show_on_all_pages'])) {
            return true;
        }

        if (!empty($settings['specific_pages'])) {
            $specific_pages = array_map('intval', explode(',', (string)($settings['specific_pages'] ?? '')));
            if (in_array($post_id, $specific_pages, true)) {
                return true;
            }
        }

        if (!empty($settings['show_on_home']) && is_home()) return true;
        if (!empty($settings['show_on_front_page']) && is_front_page()) return true;
        if (!empty($settings['show_on_posts']) && is_singular('post')) return true;
        if (!empty($settings['show_on_pages']) && is_page()) return true;
        if (!empty($settings['show_on_categories']) && is_category()) return true;
        if (!empty($settings['show_on_archives']) && is_archive()) return true;

        return false;
    }
    
    private function fetch_openrouter_models($api_key) {
        $response = wp_remote_get('https://openrouter.ai/api/v1/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat OpenRouter Models Error: ' . $response->get_error_message());
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['data']) ? $data['data'] : array();
    }
    
    private function fetch_openai_models($api_key) {
        $response = wp_remote_get('https://api.openai.com/v1/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat OpenAI Models Error: ' . $response->get_error_message());
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['data']) ? $data['data'] : array();
    }
    
    private function fetch_openai_model_details($model, $api_key) {
        $response = wp_remote_get('https://api.openai.com/v1/models/' . (string)$model, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat OpenAI Model Details Error: ' . $response->get_error_message());
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }

    private function fetch_custom_api_models($endpoint, $api_key) {
        $url = trailingslashit($endpoint) . 'models';
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat Custom API Models Error: ' . $response->get_error_message());
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        return isset($data['data']) ? $data['data'] : array();
    }

    private function fetch_custom_api_model_details($model, $endpoint, $api_key) {
        $url = trailingslashit($endpoint) . 'models/' . (string)$model;
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15,
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat Custom API Model Details Error: ' . $response->get_error_message());
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function check_rate_limits($session_id, $chat_id) {
        global $wpdb;
        
        $chat_settings_raw = get_post_meta($chat_id, '_snn_chat_settings', true);
        $chat_settings = wp_parse_args(is_array($chat_settings_raw) ? $chat_settings_raw : [], $this->get_default_chat_settings());
        
        $ip = (string)$_SERVER['REMOTE_ADDR'];
        
        $minute_ago = date('Y-m-d H:i:s', time() - 60);
        $recent_messages = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_messages
            WHERE session_id = %s AND created_at > %s
        ", $session_id, $minute_ago)) ?: 0;
        
        if ($recent_messages >= (int)($chat_settings['rate_limit_per_minute'] ?? 10)) {
            return false;
        }
        
        $today = current_time('Y-m-d');
        $daily_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}snn_chat_sessions
            WHERE ip_address = %s AND DATE(created_at) = %s
        ", $ip, $today)) ?: 0;
        
        if ($daily_sessions >= (int)($chat_settings['max_chats_per_ip_daily'] ?? 50)) {
            return false;
        }
        
        $daily_tokens = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(m.tokens_used) FROM {$wpdb->prefix}snn_chat_messages m
            JOIN {$wpdb->prefix}snn_chat_sessions s ON m.session_id = s.session_id
            WHERE s.ip_address = %s AND DATE(m.created_at) = %s
        ", $ip, $today)) ?: 0;
        
        if ($daily_tokens >= (int)($chat_settings['max_tokens_per_ip_daily'] ?? 10000)) {
            return false;
        }
        
        return true;
    }
    
    private function get_conversation_history($session_id) {
        global $wpdb;
        
        $messages = $wpdb->get_results($wpdb->prepare("
            SELECT message, response FROM {$wpdb->prefix}snn_chat_messages
            WHERE session_id = %s
            ORDER BY created_at ASC
            LIMIT 10
        ", $session_id)) ?: [];
        
        $history = array();
        foreach ($messages as $msg) {
            $history[] = array('role' => 'user', 'content' => (string)$msg->message);
            $history[] = array('role' => 'assistant', 'content' => (string)$msg->response);
        }
        
        return $history;
    }
    
    private function send_to_openrouter($messages, $model, $api_key, $api_params) {
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . (string)$api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => (string)$model,
                'messages' => $messages,
                'temperature' => floatval($api_params['temperature'] ?? 0.7),
                'max_tokens' => intval($api_params['max_tokens'] ?? 500),
                'top_p' => floatval($api_params['top_p'] ?? 1.0),
                'frequency_penalty' => floatval($api_params['frequency_penalty'] ?? 0.0),
                'presence_penalty' => floatval($api_params['presence_penalty'] ?? 0.0),
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat OpenRouter API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            return array(
                'content' => (string)$data['choices'][0]['message']['content'],
                'tokens' => (int)($data['usage']['total_tokens'] ?? 0)
            );
        }
        
        error_log('SNN AI Chat OpenRouter API Response Error: ' . print_r($data, true));
        return false;
    }
    
    private function send_to_openai($messages, $model, $api_key, $api_params) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . (string)$api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => (string)$model,
                'messages' => $messages,
                'temperature' => floatval($api_params['temperature'] ?? 0.7),
                'max_tokens' => intval($api_params['max_tokens'] ?? 500),
                'top_p' => floatval($api_params['top_p'] ?? 1.0),
                'frequency_penalty' => floatval($api_params['frequency_penalty'] ?? 0.0),
                'presence_penalty' => floatval($api_params['presence_penalty'] ?? 0.0),
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat OpenAI API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            return array(
                'content' => (string)$data['choices'][0]['message']['content'],
                'tokens' => (int)($data['usage']['total_tokens'] ?? 0)
            );
        }
        
        error_log('SNN AI Chat OpenAI API Response Error: ' . print_r($data, true));
        return false;
    }

    private function send_to_custom_api($messages, $model, $endpoint, $api_key, $api_params) {
        $url = trailingslashit($endpoint) . 'chat/completions';
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . (string)$api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => (string)$model,
                'messages' => $messages,
                'temperature' => floatval($api_params['temperature'] ?? 0.7),
                'max_tokens' => intval($api_params['max_tokens'] ?? 500),
                'top_p' => floatval($api_params['top_p'] ?? 1.0),
                'frequency_penalty' => floatval($api_params['frequency_penalty'] ?? 0.0),
                'presence_penalty' => floatval($api_params['presence_penalty'] ?? 0.0),
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat Custom API Error: ' . $response->get_error_message());
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['choices'][0]['message']['content'])) {
            return array(
                'content' => (string)$data['choices'][0]['message']['content'],
                'tokens' => (int)($data['usage']['total_tokens'] ?? 0)
            );
        }
        
        error_log('SNN AI Chat Custom API Response Error: ' . print_r($data, true));
        return false;
    }
    
    private function save_chat_message($session_id, $chat_id, $message, $response, $tokens, $user_name, $user_email) {
        global $wpdb;
        
        $session_exists = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}snn_chat_sessions WHERE session_id = %s
        ", $session_id));
        
        if (!$session_exists) {
            $wpdb->insert(
                $wpdb->prefix . 'snn_chat_sessions',
                array(
                    'chat_id' => $chat_id,
                    'session_id' => (string)$session_id,
                    'user_name' => (string)$user_name,
                    'user_email' => (string)$user_email,
                    'ip_address' => (string)$_SERVER['REMOTE_ADDR']
                ),
                array('%d', '%s', '%s', '%s', '%s')
            );
        }
        
        $wpdb->insert(
            $wpdb->prefix . 'snn_chat_messages',
            array(
                'session_id' => (string)$session_id,
                'message' => (string)$message,
                'response' => (string)$response,
                'tokens_used' => (int)$tokens
            ),
            array('%s', '%s', '%s', '%d')
        );
    }

    public function show_admin_notices() {
        if (isset($_GET['snn_data_deleted']) && $_GET['snn_data_deleted'] == '1') {
            echo '<div class="notice notice-success is-dismissible"><p>SNN AI Chat data has been successfully deleted and the plugin has been deactivated.</p></div>';
        }
    }
    public function handle_reset_action() {
        if (isset($_POST['reset_plugin_data'])) {
            if (!isset($_POST['snn_reset_plugin_data_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['snn_reset_plugin_data_nonce'])), 'snn_reset_plugin_data_nonce_action')) {
                wp_die('Nonce verification failed.');
            }

            if (!current_user_can('manage_options')) {
                wp_die('You do not have permission to perform this action.');
            }

            $this->delete_all_plugin_data();

            deactivate_plugins(plugin_basename(__FILE__));

            wp_safe_redirect(admin_url('plugins.php?snn_data_deleted=1'));
            exit;
        }
    }

    public function handle_delete_data_from_link() {
        if (!isset($_GET['snn_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['snn_nonce'])), 'snn_delete_data_nonce')) {
            wp_die('Nonce verification failed.');
        }

        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to perform this action.');
        }

        $this->delete_all_plugin_data();
        deactivate_plugins(plugin_basename(__FILE__));
        wp_safe_redirect(admin_url('plugins.php?snn_data_deleted=1'));
        exit;
    }


    public function add_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=snn-ai-chat-settings') . '">' . __('Settings') . '</a>';
        $delete_link_url = wp_nonce_url(admin_url('admin.php?action=snn_delete_data'), 'snn_delete_data_nonce', 'snn_nonce');
        $delete_link = '<a href="' . $delete_link_url . '" onclick="return confirm(\'Are you sure you want to delete all plugin data? This will remove all chats, history, and settings. This action cannot be undone.\');" style="color:red;">' . __('Delete Plugin Data') . '</a>';
        array_unshift($links, $settings_link);
        $links[] = $delete_link;
        return $links;
    }

    private function delete_all_plugin_data() {
        global $wpdb;
        $chat_posts = get_posts(array('post_type' => 'snn_ai_chat', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids'));
        foreach ($chat_posts as $post_id) {
            wp_delete_post($post_id, true);
        }

        $history_posts = get_posts(array('post_type' => 'snn_chat_history', 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids'));
        foreach ($history_posts as $post_id) {
            wp_delete_post($post_id, true);
        }
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}snn_chat_messages");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}snn_chat_sessions");
        delete_option('snn_ai_chat_settings');
        flush_rewrite_rules();
    }

    private function get_dynamic_tags() {
        return [
            'Site Information' => [
                '{{site_name}}' => 'The name of your website.',
                '{{site_description}}' => 'The tagline or description of your site.',
                '{{site_url}}' => 'The root URL of your site.',
            ],
            'Current Page/Post' => [
                '{{post_id}}' => 'The ID of the current page or post.',
                '{{post_title}}' => 'The title of the current page or post.',
                '{{post_content}}' => 'The full content of the current page or post (HTML tags will be removed).',
                '{{post_excerpt}}' => 'The excerpt of the current page or post (HTML tags will be removed).',
                '{{post_url}}' => 'The URL of the current page or post.',
                '{{post_author_name}}' => 'The display name of the author of the current post.',
                '{{post_custom_field:FIELD_NAME}}' => 'Value of a custom field from the current post. Replace FIELD_NAME with your custom field key.',
            ],
            'Specific Post (by ID)' => [
                '{{post_title:ID}}' => 'The title of a specific post. Replace ID with the post ID.',
                '{{post_content:ID}}' => 'The content of a specific post. Replace ID with the post ID.',
                '{{post_custom_field:FIELD_NAME:ID}}' => 'Value of a custom field from a specific post. Replace FIELD_NAME and ID.',
            ],
        ];
    }

    private function process_dynamic_tags($prompt, $context = []) {
        // This regex is now case-insensitive
        return preg_replace_callback('/\{\{(.*?)\}\}/i', function($matches) use ($context) {
            $full_tag = $matches[0];
            // Normalize the tag by making it lowercase and removing underscores
            $tag_content = str_replace('_', '', strtolower(trim($matches[1])));
            
            $parts = explode(':', $tag_content);
            $tag_name = $parts[0];
    
            switch ($tag_name) {
                // Site-wide tags (normalized)
                case 'sitename': return get_bloginfo('name');
                case 'sitedescription': return get_bloginfo('description');
                case 'siteurl': return home_url();
            }
    
            // Post-related tags require a post ID
            $target_post_id = $context['post_id'] ?? 0;
            $field_name = '';
    
            if ($tag_name === 'postcustomfield') {
                if (count($parts) === 3) { // {{post_custom_field:FIELD_NAME:ID}}
                    $field_name = $parts[1];
                    $target_post_id = intval($parts[2]);
                } elseif (count($parts) === 2) { // {{post_custom_field:FIELD_NAME}}
                    $field_name = $parts[1];
                }
            } elseif (count($parts) === 2) { // e.g. {{posttitle:123}}
                $target_post_id = intval($parts[1]);
            }
            
            // If no post ID is available for a post-specific tag, return the tag as is.
            if (!$target_post_id) {
                return $full_tag;
            }
            
            $post = get_post($target_post_id);
            if (!$post) {
                return $full_tag; // Post not found, return original tag
            }
    
            switch ($tag_name) {
                case 'postid': return $post->ID;
                case 'posttitle': return get_the_title($post);
                case 'postcontent': return wp_strip_all_tags(apply_filters('the_content', $post->post_content));
                case 'postexcerpt': return wp_strip_all_tags(get_the_excerpt($post));
                case 'posturl': return get_permalink($post);
                case 'postauthorname': return get_the_author_meta('display_name', $post->post_author);
                case 'postauthoremail': return get_the_author_meta('user_email', $post->post_author);
                case 'postdate': return get_the_date('', $post);
                case 'posttype': return get_post_type($post);
                case 'postcustomfield':
                    if ($field_name) {
                        $meta = get_post_meta($post->ID, $field_name, true);
                        return is_scalar($meta) ? (string)$meta : '';
                    }
                    break;
            }
    
            return $full_tag; // Return original tag if no match was found
        }, $prompt);
    }

    private function adjust_brightness($hex, $steps) {
        $hex = ltrim((string)($hex ?? ''), '#');
        
        if (strlen((string)$hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $rgb = array_map('hexdec', str_split((string)$hex, 2));

        foreach ($rgb as &$value) {
            $value = max(0, min(255, $value + $steps));
        }

        return '#' . implode('', array_map(function($val) {
            return str_pad(dechex($val), 2, '0', STR_PAD_LEFT);
        }, $rgb));
    }

    // New function to get all public posts and pages for the datalist
    public function get_all_public_posts_for_datalist() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');

        $args = array(
            'post_type'      => array('post', 'page'), // Include posts and pages
            'post_status'    => 'publish',
            'posts_per_page' => -1, // Get all posts
            'fields'         => 'ids', // Only get IDs for efficiency
        );

        $posts = get_posts($args);
        $post_data = [];

        foreach ($posts as $post_id) {
            $post_data[] = [
                'id'    => $post_id,
                'title' => get_the_title($post_id)
            ];
        }

        wp_send_json_success($post_data);
    }




    private function extract_and_build_cards_from_response($content, $chat_id) {
        $cards = [];
        $context_links = get_post_meta($chat_id, 'snn_ai_chats_context_links', true);

        if (!is_array($context_links) || empty($context_links)) {
            return $cards;
        }

        preg_match_all('/\[LINK_CARD:(\d+)\]/i', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $index = intval($match[1]);
            if (isset($context_links[$index])) {
                $link = $context_links[$index];
                $url = esc_url($link['url'] ?? '#');
                $title = esc_html($link['title'] ?? 'Learn More');
                $desc = esc_html($link['desc'] ?? '');
                $img_html = !empty($link['img']) ? '<img src="' . esc_url($link['img']) . '" alt="' . esc_attr($title) . '">' : '';
                $link_text = !empty($link['title']) ? 'View ' . esc_html($link['title']) : 'Learn More';
                
                // Build the card HTML and add it to our array, keyed by the index
                $cards[$index] = '<div class="snn-content-card"><a href="' . $url . '" target="_blank" rel="noopener noreferrer">' . $img_html . '<div><h4>' . $title . '</h4><p>' . $desc . '</p><span>' . $link_text . ' &rarr;</span></div></a></div>';
            }
        }
        
        return $cards;
    }






}

new SNN_AI_Chat();