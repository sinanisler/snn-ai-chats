<?php
/**
 * Plugin Name: SNN AI CHAT
 * Description: Advanced AI Chat Plugin with OpenRouter and OpenAI support
 * Version: 1.0.1
 * Author: SNN
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('SNN_AI_CHAT_VERSION', '1.0.1');
define('SNN_AI_CHAT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SNN_AI_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));

class SNN_AI_Chat {

    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));
        add_action('wp_ajax_snn_ai_chat_api', array($this, 'handle_chat_api'));
        add_action('wp_ajax_nopriv_snn_ai_chat_api', array($this, 'handle_chat_api'));
        add_action('wp_ajax_snn_get_models', array($this, 'get_models'));
        add_action('wp_ajax_snn_get_model_details', array($this, 'get_model_details'));
        add_action('wp_ajax_snn_save_chat_settings', array($this, 'save_chat_settings'));
        add_action('wp_ajax_snn_delete_chat', array($this, 'delete_chat'));
        add_action('wp_footer', array($this, 'render_frontend_chats'));

        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function init() {
        $this->create_post_types();
        // Moved table creation to activation hook to prevent running on every init
    }

    public function activate() {
        $this->create_database_tables();
        $this->create_post_types(); // Also run on activation
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function create_post_types() {
        // Chat configurations
        register_post_type('snn_ai_chat', array(
            'labels' => array(
                'name' => 'AI Chats',
                'singular_name' => 'AI Chat'
            ),
            'public' => false,
            'show_ui' => false, // We use our own admin pages
            'capability_type' => 'post',
            'supports' => array('title', 'custom-fields')
        ));

        // Chat history (Not used in this version, but kept for potential future use)
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

        // Chat sessions table
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

        // Chat messages table
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
            'Settings',
            'Settings',
            'manage_options',
            'snn-ai-chat-settings',
            array($this, 'settings_page')
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

        // Hidden submenu page for the live preview
        add_submenu_page(
            null, // This makes the page hidden from the menu
            'Chat Preview',
            'Chat Preview',
            'manage_options',
            'snn-ai-chat-preview',
            array($this, 'preview_page')
        );
    }

    public function admin_enqueue_scripts($hook) {
        // Only load scripts on our plugin pages
        // Cast $hook to string to prevent deprecated notice in strpos
        if (empty($hook) || strpos((string)$hook, 'snn-ai-chat') === false) {
            return;
        }

        wp_enqueue_style('snn-ai-chat-admin', SNN_AI_CHAT_PLUGIN_URL . 'snn-ai-chat-admin.css', array(), SNN_AI_CHAT_VERSION);
        wp_enqueue_script('snn-ai-chat-admin', SNN_AI_CHAT_PLUGIN_URL . 'snn-ai-chat-admin.js', array('jquery'), SNN_AI_CHAT_VERSION, true);

        // External CDN libraries
        wp_enqueue_script('tailwind-css', 'https://cdn.tailwindcss.com', array(), null);
        wp_enqueue_script('tippy-js', 'https://unpkg.com/@popperjs/core@2', array(), null, true);
        wp_enqueue_script('tippy-bundle', 'https://unpkg.com/tippy.js@6', array('tippy-js'), null, true);

        $global_settings = $this->get_settings();

        wp_localize_script('snn-ai-chat-admin', 'snn_ai_chat_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snn_ai_chat_nonce'), // Unified nonce
            'global_api_provider' => $global_settings['api_provider'],
            'global_openrouter_api_key' => $global_settings['openrouter_api_key'],
            'global_openai_api_key' => $global_settings['openai_api_key'],
            'global_openrouter_model' => $global_settings['openrouter_model'],
            'global_openai_model' => $global_settings['openai_model'],
        ));

        // If on the preview page, also load frontend styles for an accurate preview.
        if ($hook === 'admin_page_snn-ai-chat-preview') {
            $this->frontend_enqueue_scripts();
        }
    }

    public function frontend_enqueue_scripts() {
        wp_enqueue_style('snn-ai-chat-frontend', SNN_AI_CHAT_PLUGIN_URL . 'snn-ai-chat-frontend.css', array(), SNN_AI_CHAT_VERSION);
        wp_enqueue_script('snn-ai-chat-frontend', SNN_AI_CHAT_PLUGIN_URL . 'snn-ai-chat-frontend.js', array('jquery'), SNN_AI_CHAT_VERSION, true);

        wp_localize_script('snn-ai-chat-frontend', 'snn_ai_chat_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('snn_ai_chat_nonce') // Unified nonce
        ));
    }

    public function dashboard_page() {
        $stats = $this->get_dashboard_stats();
        ?>
        <div class="wrap">
            <h1>SNN AI Chat Dashboard</h1>
            
            <!-- Statistics Blocks -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
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
            
            <!-- Quick Actions -->
            <div class="bg-white p-6 rounded-lg shadow mb-6" id="snn-quick-actions-block">
                <h2 class="text-xl font-semibold mb-4">Quick Actions</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats&action=new')); ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-center quick-action-btn hover:bg-blue-700 transition-colors duration-200" id="snn-create-chat-btn">
                        Create New Chat
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-settings')); ?>" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-center quick-action-btn hover:bg-green-700 transition-colors duration-200" id="snn-settings-btn">
                        Settings
                    </a>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats')); ?>" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg text-center quick-action-btn hover:bg-purple-700 transition-colors duration-200" id="snn-manage-chats-btn">
                        Manage Chats
                    </a>
                </div>
            </div>
            
            <!-- Usage Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
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
            
            <!-- Recent Activity -->
            <div class="bg-white p-6 rounded-lg shadow recent-activity-block" id="snn-recent-activity-block">
                <h2 class="text-xl font-semibold mb-4">Recent Activity</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Session</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Messages</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tokens</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            $recent_activities = $this->get_recent_activities();
                            foreach ($recent_activities as $activity) {
                                ?>
                                <tr class="activity-row">
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
            </div>
        </div>
        <?php
    }

    public function settings_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }
        
        $settings = $this->get_settings();
        ?>
        <div class="wrap">
            <h1>SNN AI Chat Settings</h1>
            
            <form method="post" action="" class="snn-settings-form">
                <?php wp_nonce_field('snn_ai_chat_settings', 'snn_ai_chat_settings_nonce'); ?>
                
                <div class="bg-white p-6 rounded-lg shadow mb-6" id="snn-api-provider-block">
                    <h2 class="text-xl font-semibold mb-4">AI Provider Selection</h2>
                    
                    <div class="api-provider-selection" id="snn-provider-selection">
                        <label class="block mb-2 text-gray-700">
                            <input type="radio" name="api_provider" value="openrouter" <?php checked($settings['api_provider'], 'openrouter'); ?> class="mr-2 api-provider-radio" id="snn-openrouter-radio">
                            OpenRouter
                        </label>
                        <label class="block mb-4 text-gray-700">
                            <input type="radio" name="api_provider" value="openai" <?php checked($settings['api_provider'], 'openai'); ?> class="mr-2 api-provider-radio" id="snn-openai-radio">
                            OpenAI
                        </label>
                    </div>
                    
                    <!-- OpenRouter Settings -->
                    <div class="api-settings <?php echo ($settings['api_provider'] !== 'openrouter') ? 'hidden' : ''; ?> bg-gray-50 p-4 rounded-md border border-gray-200" id="snn-openrouter-settings">
                        <h3 class="text-lg font-semibold mb-3 text-gray-800">OpenRouter API Settings</h3>
                        <div class="mb-4">
                            <label for="openrouter_api_key" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Your OpenRouter API key for accessing AI models">
                                OpenRouter API Key
                            </label>
                            <input type="password" id="openrouter_api_key" name="openrouter_api_key" value="<?php echo esc_attr($settings['openrouter_api_key']); ?>" class="w-full p-2 border border-gray-300 rounded-md api-key-input focus:ring-blue-500 focus:border-blue-500">
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
                    
                    <!-- OpenAI Settings -->
                    <div class="api-settings <?php echo ($settings['api_provider'] !== 'openai') ? 'hidden' : ''; ?> bg-gray-50 p-4 rounded-md border border-gray-200" id="snn-openai-settings">
                        <h3 class="text-lg font-semibold mb-3 text-gray-800">OpenAI API Settings</h3>
                        <div class="mb-4">
                            <label for="openai_api_key" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Your OpenAI API key for accessing GPT models">
                                OpenAI API Key
                            </label>
                            <input type="password" id="openai_api_key" name="openai_api_key" value="<?php echo esc_attr($settings['openai_api_key']); ?>" class="w-full p-2 border border-gray-300 rounded-md api-key-input focus:ring-blue-500 focus:border-blue-500">
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
                </div>
                
                <div class="bg-white p-6 rounded-lg shadow mb-6" id="snn-general-settings-block">
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
                
                <button type="submit" name="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-md settings-save-btn hover:bg-blue-700 transition-colors duration-200" id="snn-save-settings-btn">
                    Save Settings
                </button>
            </form>
        </div>
        <?php
    }

    public function chats_page() {
        $action = isset($_GET['action']) ? sanitize_text_field((string)$_GET['action']) : 'list'; // Cast to string
        $chat_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

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
                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats&action=new')); ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-md add-chat-btn hover:bg-blue-700 transition-colors duration-200" id="snn-add-new-chat-btn">
                    Add New Chat
                </a>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 chats-grid" id="snn-chats-grid">
                <?php foreach ($chats as $chat) { 
                    $chat_settings = get_post_meta($chat->ID, '_snn_chat_settings', true);
                    // Ensure chat_settings is an array, even if empty
                    $chat_settings = is_array($chat_settings) ? $chat_settings : [];
                    $defaults = $this->get_default_chat_settings();
                    $chat_settings = wp_parse_args($chat_settings, $defaults);
                    ?>
                    <div class="bg-white p-6 rounded-lg shadow chat-card" id="snn-chat-card-<?php echo esc_attr($chat->ID); ?>">
                        <h3 class="text-lg font-semibold mb-2 text-gray-800"><?php echo esc_html($chat->post_title); ?></h3>
                        <p class="text-gray-600 mb-4">Model: <?php echo esc_html(!empty($chat_settings['model']) ? $chat_settings['model'] : 'Global Default'); ?></p>
                        
                        <div class="flex justify-between items-center">
                            <div class="text-sm text-gray-500">
                                <?php echo $this->get_chat_stats($chat->ID); ?>
                            </div>
                            <div class="space-x-2">
                                <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats&action=edit&id=' . $chat->ID)); ?>" class="text-blue-600 hover:text-blue-800 edit-chat-btn hover:underline transition-colors duration-200">
                                    Edit
                                </a>
                                <button class="text-red-600 hover:text-red-800 delete-chat-btn hover:underline transition-colors duration-200" data-chat-id="<?php echo esc_attr($chat->ID); ?>">
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
        
        // Use wp_parse_args to merge saved settings with defaults.
        $defaults = $this->get_default_chat_settings();
        $chat_settings = wp_parse_args(is_array($chat_settings_raw) ? $chat_settings_raw : [], $defaults);
        
        // Get global settings to determine the default model if not overridden
        $global_settings = $this->get_settings();
        $default_model_for_display = $chat_settings['model'];
        if (empty($default_model_for_display)) {
            $default_model_for_display = ($global_settings['api_provider'] === 'openai') ? $global_settings['openai_model'] : $global_settings['openrouter_model'];
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($chat_id > 0 ? 'Edit Chat' : 'Create New Chat'); ?></h1>
            
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Settings Form -->
                <div class="lg:col-span-2 bg-white p-6 rounded-lg shadow chat-settings-form" id="snn-chat-settings-form">
                    <form id="chat-settings-form" method="post">
                        <?php wp_nonce_field('snn_ai_chat_nonce', 'snn_ai_chat_nonce_field'); ?>
                        <input type="hidden" name="chat_id" value="<?php echo esc_attr($chat_id); ?>">
                        
                        <!-- Basic Information -->
                        <div class="mb-6 basic-info-section" id="snn-basic-info-section">
                            <h3 class="text-lg font-semibold mb-4">Basic Information</h3>
                            
                            <div class="mb-4">
                                <label for="chat_name" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The name for this chat configuration">
                                    Chat Name
                                </label>
                                <input type="text" id="chat_name" name="chat_name" value="<?php echo esc_attr($chat ? $chat->post_title : ''); ?>" class="w-full p-2 border border-gray-300 rounded-md chat-name-input focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <!-- API Source selection removed as per request. Model will implicitly use global API settings. -->
                            
                            <div class="mb-4">
                                <label for="model" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Select the AI model for this chat. Leave blank to use the global default.">
                                    Model
                                </label>
                                <input type="text" id="model" name="model" value="<?php echo esc_attr($chat_settings['model']); ?>" list="chat_models" class="w-full p-2 border border-gray-300 rounded-md model-input focus:ring-blue-500 focus:border-blue-500">
                                <datalist id="chat_models"></datalist>
                            </div>
                            <div class="model-details text-gray-600 text-sm mb-4" id="chat-model-details"></div>

                            <div class="mb-4">
                                <label for="initial_message" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The first message users will see when they start this chat">
                                    Initial Message
                                </label>
                                <input type="text" id="initial_message" name="initial_message" value="<?php echo esc_attr($chat_settings['initial_message']); ?>" class="w-full p-2 border border-gray-300 rounded-md initial-message-input focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="mb-4">
                                <label for="system_prompt" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="The system prompt that defines the AI's behavior and personality">
                                    System Prompt
                                </label>
                                <textarea id="system_prompt" name="system_prompt" rows="3" class="w-full p-2 border border-gray-300 rounded-md system-prompt-input focus:ring-blue-500 focus:border-blue-500"><?php echo esc_textarea($chat_settings['system_prompt']); ?></textarea>
                            </div>
                            
                            <div class="mb-4">
                                <label class="flex items-center text-gray-700">
                                    <input type="checkbox" name="keep_conversation_history" value="1" <?php checked($chat_settings['keep_conversation_history'], 1); ?> class="mr-2 conversation-history-checkbox">
                                    <span class="text-sm font-medium snn-tooltip" data-tippy-content="Whether to maintain conversation context between messages">
                                        Keep conversation history during session
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Styling & Appearance -->
                        <div class="mb-6 styling-section" id="snn-styling-section">
                            <h3 class="text-lg font-semibold mb-4">Styling & Appearance</h3>
                            
                            <div class="mb-4">
                                <label for="chat_position" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Where the chat widget will appear on the page">
                                    Chat Position
                                </label>
                                <select id="chat_position" name="chat_position" class="w-full p-2 border border-gray-300 rounded-md chat-position-select focus:ring-blue-500 focus:border-blue-500">
                                    <option value="bottom-right" <?php selected($chat_settings['chat_position'], 'bottom-right'); ?>>Bottom Right</option>
                                    <option value="bottom-left" <?php selected($chat_settings['chat_position'], 'bottom-left'); ?>>Bottom Left</option>
                                    <option value="top-right" <?php selected($chat_settings['chat_position'], 'top-right'); ?>>Top Right</option>
                                    <option value="top-left" <?php selected($chat_settings['chat_position'], 'top-left'); ?>>Top Left</option>
                                </select>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label for="primary_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Main color for the chat widget toggle button and user messages">
                                        Primary Color
                                    </label>
                                    <input type="color" id="primary_color" name="primary_color" value="<?php echo esc_attr($chat_settings['primary_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input">
                                </div>
                                
                                <div>
                                    <label for="secondary_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Background color for AI messages">
                                        Secondary Color
                                    </label>
                                    <input type="color" id="secondary_color" name="secondary_color" value="<?php echo esc_attr($chat_settings['secondary_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input">
                                </div>
                                
                                <div>
                                    <label for="text_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Default text color for the chat widget (e.g., header text, AI message text)">
                                        Text Color
                                    </label>
                                    <input type="color" id="text_color" name="text_color" value="<?php echo esc_attr($chat_settings['text_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                                <div>
                                    <label for="chat_widget_bg_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Background color of the main chat container.">
                                        Widget Background Color
                                    </label>
                                    <input type="color" id="chat_widget_bg_color" name="chat_widget_bg_color" value="<?php echo esc_attr($chat_settings['chat_widget_bg_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input">
                                </div>
                                <div>
                                    <label for="chat_input_bg_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Background color of the message input field.">
                                        Input Background Color
                                    </label>
                                    <input type="color" id="chat_input_bg_color" name="chat_input_bg_color" value="<?php echo esc_attr($chat_settings['chat_input_bg_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input">
                                </div>
                                <div>
                                    <label for="chat_input_text_color" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Text color of the message input field.">
                                        Input Text Color
                                    </label>
                                    <input type="color" id="chat_input_text_color" name="chat_input_text_color" value="<?php echo esc_attr($chat_settings['chat_input_text_color']); ?>" class="w-full h-10 border border-gray-300 rounded-md color-input">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="font_size" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Font size for chat messages">
                                        Font Size (px)
                                    </label>
                                    <input type="number" id="font_size" name="font_size" value="<?php echo esc_attr($chat_settings['font_size']); ?>" class="w-full p-2 border border-gray-300 rounded-md font-size-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div>
                                    <label for="border_radius" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Rounded corners for the chat widget">
                                        Border Radius (px)
                                    </label>
                                    <input type="number" id="border_radius" name="border_radius" value="<?php echo esc_attr($chat_settings['border_radius']); ?>" class="w-full p-2 border border-gray-300 rounded-md border-radius-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                                <div>
                                    <label for="widget_width" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Width of the chat widget">
                                        Widget Width (px)
                                    </label>
                                    <input type="number" id="widget_width" name="widget_width" value="<?php echo esc_attr($chat_settings['widget_width']); ?>" class="w-full p-2 border border-gray-300 rounded-md widget-width-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div>
                                    <label for="widget_height" class="block text-sm font-medium text-gray-700 mb-2 snn-tooltip" data-tippy-content="Height of the chat widget">
                                        Widget Height (px)
                                    </label>
                                    <input type="number" id="widget_height" name="widget_height" value="<?php echo esc_attr($chat_settings['widget_height']); ?>" class="w-full p-2 border border-gray-300 rounded-md widget-height-input focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Display Settings -->
                        <div class="mb-6 display-settings-section" id="snn-display-settings-section">
                            <h3 class="text-lg font-semibold mb-4">Display Settings</h3>
                            
                            <div class="mb-4">
                                <label class="flex items-center text-gray-700">
                                    <input type="checkbox" name="show_on_all_pages" value="1" <?php checked($chat_settings['show_on_all_pages'], 1); ?> class="mr-2 show-all-pages-checkbox">
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
                                        <input type="checkbox" name="show_on_home" value="1" <?php checked($chat_settings['show_on_home'], 1); ?> class="mr-2 template-condition-checkbox">
                                        <span class="text-sm">Home</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_front_page" value="1" <?php checked($chat_settings['show_on_front_page'], 1); ?> class="mr-2 template-condition-checkbox">
                                        <span class="text-sm">Front Page</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_posts" value="1" <?php checked($chat_settings['show_on_posts'], 1); ?> class="mr-2 template-condition-checkbox">
                                        <span class="text-sm">Posts</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_pages" value="1" <?php checked($chat_settings['show_on_pages'], 1); ?> class="mr-2 template-condition-checkbox">
                                        <span class="text-sm">Pages</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_categories" value="1" <?php checked($chat_settings['show_on_categories'], 1); ?> class="mr-2 template-condition-checkbox">
                                        <span class="text-sm">Categories</span>
                                    </label>
                                    <label class="flex items-center text-gray-700">
                                        <input type="checkbox" name="show_on_archives" value="1" <?php checked($chat_settings['show_on_archives'], 1); ?> class="mr-2 template-condition-checkbox">
                                        <span class="text-sm">Archives</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Usage Limits -->
                        <div class="mb-6 usage-limits-section" id="snn-usage-limits-section">
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
                        
                        <!-- User Information Collection -->
                        <div class="mb-6 user-info-section" id="snn-user-info-section">
                            <h3 class="text-lg font-semibold mb-4">User Information Collection</h3>
                            
                            <div class="mb-4">
                                <label class="flex items-center text-gray-700">
                                    <input type="checkbox" name="collect_user_info" value="1" <?php checked($chat_settings['collect_user_info'], 1); ?> class="mr-2 collect-user-info-checkbox">
                                    <span class="text-sm font-medium snn-tooltip" data-tippy-content="Require users to provide their name and email before they can start chatting">
                                        Collect user name and email before starting chat
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="flex space-x-4">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-md save-chat-btn hover:bg-blue-700 transition-colors duration-200" id="snn-save-chat-btn">
                                Save Chat
                            </button>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-chats')); ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-md cancel-btn hover:bg-gray-700 transition-colors duration-200">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
                
                <!-- Live Preview -->
                <div class="lg:col-span-1 bg-white p-6 rounded-lg shadow chat-preview-section" id="snn-chat-preview-section">
                    <h3 class="text-lg font-semibold mb-4">Live Preview</h3>
                    <?php if ($chat_id > 0) : ?>
                        <div class="border border-gray-300 rounded-lg overflow-hidden preview-container" id="snn-preview-container">
                            <iframe id="chat-preview-iframe" src="<?php echo esc_url(admin_url('admin.php?page=snn-ai-chat-preview&chat_id=' . $chat_id)); ?>" width="100%" height="800" frameborder="0" class="preview-iframe"></iframe>
                        </div>
                    <?php else : ?>
                        <div class="text-center p-4 border-2 border-dashed rounded-lg text-gray-600">
                            <p>Save the chat to enable the live preview.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public function chat_history_page() {
        ?>
        <div class="wrap">
            <h1>Chat History</h1>
            
            <div class="bg-white p-6 rounded-lg shadow chat-history-table" id="snn-chat-history-table">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Session ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Messages</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tokens</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            $chat_history = $this->get_chat_history();
                            foreach ($chat_history as $history) {
                                ?>
                                <tr class="history-row">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(substr((string)$history->session_id, 0, 12)); ?>...</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html($history->user_name ?: 'Anonymous'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html($history->user_email ?: 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html($history->message_count); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(number_format($history->total_tokens)); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo esc_html(date('M j, Y H:i', strtotime($history->created_at))); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <button class="text-blue-600 hover:text-blue-800 view-history-btn hover:underline transition-colors duration-200" data-session-id="<?php echo esc_attr($history->session_id); ?>">
                                            View
                                        </button>
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

    /**
     * New method to render the preview page for the iframe.
     */
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
        
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <?php 
            // Manually run actions to load enqueued scripts/styles for the preview
            wp_print_styles();
            wp_print_head_scripts();
            ?>
        </head>
        <body class="snn-ai-chat-preview-body">
            <?php 
            // Render the widget with its settings
            $this->render_chat_widget($chat, $chat_settings); 
            
            // Manually run footer actions
            wp_print_footer_scripts();
            ?>
        </body>
        </html>
        <?php
        exit; // Stop further admin page rendering
    }

    public function get_models() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        $provider = isset($_POST['provider']) ? sanitize_text_field((string)$_POST['provider']) : ''; // Cast to string
        $api_key = isset($_POST['api_key']) ? sanitize_text_field((string)$_POST['api_key']) : ''; // Cast to string
        
        if (empty($provider) || empty($api_key)) {
            wp_send_json_error('Provider or API key missing.');
        }

        if ($provider === 'openrouter') {
            $models = $this->fetch_openrouter_models($api_key);
        } else if ($provider === 'openai') {
            $models = $this->fetch_openai_models($api_key);
        } else {
            wp_send_json_error('Invalid provider');
        }
        
        wp_send_json_success($models);
    }

    public function get_model_details() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        $provider = !empty($_POST['provider']) ? sanitize_text_field((string)$_POST['provider']) : ''; // Cast to string
        $model = !empty($_POST['model']) ? sanitize_text_field((string)$_POST['model']) : ''; // Cast to string
        $api_key = !empty($_POST['api_key']) ? sanitize_text_field((string)$_POST['api_key']) : ''; // Cast to string
        
        if (empty($provider) || empty($model) || empty($api_key)) {
            wp_send_json_error('Provider, model, or API key missing.');
        }
        
        if ($provider === 'openrouter') {
            // OpenRouter doesn't have a dedicated details endpoint per model like this
            // We can fetch all and filter
            $models = $this->fetch_openrouter_models($api_key);
            $details = [];
            foreach($models as $m) {
                if ($m['id'] === $model) {
                    $details = $m;
                    break;
                }
            }
        } else if ($provider === 'openai') {
            $details = $this->fetch_openai_model_details($model, $api_key);
        } else {
            wp_send_json_error('Invalid provider');
        }
        
        wp_send_json_success($details);
    }

    public function save_chat_settings() {
        check_ajax_referer('snn_ai_chat_nonce', 'snn_ai_chat_nonce_field');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to save settings.');
        }

        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $chat_name = isset($_POST['chat_name']) ? sanitize_text_field((string)$_POST['chat_name']) : 'New Chat'; // Cast to string

        // Explicitly define and sanitize each setting for security and correctness.
        $settings = [];
        $defaults = $this->get_default_chat_settings();

        foreach ($defaults as $key => $default_value) {
            if (isset($_POST[$key])) {
                // Sanitize based on the expected type of the setting
                if (is_int($default_value)) {
                    $settings[$key] = intval($_POST[$key]);
                } elseif (str_contains((string)$key, '_color')) { // PHP 8+ friendly str_contains, added (string) cast defensively
                    $settings[$key] = sanitize_hex_color((string)$_POST[$key]); // Cast to string
                } elseif ($key === 'system_prompt' || $key === 'initial_message') { // Also sanitize initial_message as it can be long
                    $settings[$key] = sanitize_textarea_field((string)$_POST[$key]); // Cast to string
                } elseif ($key === 'specific_pages' || $key === 'exclude_pages') {
                    // Sanitize comma-separated IDs, ensuring they are integers
                    $ids = array_filter(array_map('intval', explode(',', (string)$_POST[$key])));
                    $settings[$key] = implode(',', $ids);
                } else {
                    $settings[$key] = sanitize_text_field((string)$_POST[$key]); // Cast to string
                }
            } else {
                // Handle checkboxes which are not present in POST when unchecked
                if (in_array($key, ['keep_conversation_history', 'show_on_all_pages', 'show_on_home', 'show_on_front_page', 'show_on_posts', 'show_on_pages', 'show_on_categories', 'show_on_archives', 'collect_user_info'])) {
                    $settings[$key] = 0;
                } else {
                    // For other fields not in POST, use their default values
                    $settings[$key] = $default_value;
                }
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
            $chat_id = wp_insert_post($post_data);
        }
        
        if ($chat_id && !is_wp_error($chat_id)) {
            update_post_meta($chat_id, '_snn_chat_settings', $settings);
            wp_send_json_success(array('chat_id' => $chat_id, 'redirect_url' => admin_url('admin.php?page=snn-ai-chat-chats&action=edit&id=' . $chat_id)));
        } else {
            wp_send_json_error('Failed to save chat.');
        }
    }

    public function delete_chat() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to delete chats.');
        }

        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        
        if ($chat_id > 0 && get_post_type($chat_id) === 'snn_ai_chat') {
            wp_delete_post($chat_id, true); // true = force delete
            wp_send_json_success();
        } else {
            wp_send_json_error('Invalid chat ID');
        }
    }

    public function handle_chat_api() {
        check_ajax_referer('snn_ai_chat_nonce', 'nonce');
        
        $message = isset($_POST['message']) ? sanitize_text_field((string)$_POST['message']) : ''; // Cast to string
        $session_id = isset($_POST['session_id']) ? sanitize_text_field((string)$_POST['session_id']) : ''; // Cast to string
        $chat_id = isset($_POST['chat_id']) ? intval($_POST['chat_id']) : 0;
        $user_name = isset($_POST['user_name']) ? sanitize_text_field((string)$_POST['user_name']) : ''; // Cast to string
        $user_email = isset($_POST['user_email']) ? sanitize_email((string)$_POST['user_email']) : ''; // Cast to string
        
        if (empty($message) || empty($session_id) || empty($chat_id)) {
            wp_send_json_error('Missing required data.');
        }

        // Rate limiting and usage checks
        if (!$this->check_rate_limits($session_id, $chat_id)) {
            wp_send_json_error(array('response' => 'Rate limit exceeded. Please try again later.'));
        }
        
        // Get chat settings
        $chat_settings_raw = get_post_meta($chat_id, '_snn_chat_settings', true);
        $chat_settings = wp_parse_args(is_array($chat_settings_raw) ? $chat_settings_raw : [], $this->get_default_chat_settings());
        
        // Get global API settings
        $api_settings = $this->get_settings();
        
        // Determine which API to use (always global provider for API calls)
        $api_provider = $api_settings['api_provider'];
        $model = !empty($chat_settings['model']) ? $chat_settings['model'] : ($api_provider === 'openai' ? $api_settings['openai_model'] : $api_settings['openrouter_model']);
        $api_key = ($api_provider === 'openai') ? $api_settings['openai_api_key'] : $api_settings['openrouter_api_key'];

        if (empty($api_key)) {
             wp_send_json_error(array('response' => 'API key is not configured.'));
        }

        // Prepare conversation history
        $conversation_history = [];
        if (!empty($chat_settings['system_prompt'])) {
             $conversation_history[] = array(
                 'role' => 'system',
                 'content' => $chat_settings['system_prompt']
               );
        }

        if (!empty($chat_settings['keep_conversation_history'])) {
            $previous_messages = $this->get_conversation_history($session_id);
            $conversation_history = array_merge($conversation_history, $previous_messages);
        }
        
        // Add user message
        $conversation_history[] = array(
            'role' => 'user',
            'content' => $message
        );
        
        // Send to AI API
        if ($api_provider === 'openrouter') {
            $response = $this->send_to_openrouter($conversation_history, $model, $api_key, $chat_settings);
        } else {
            $response = $this->send_to_openai($conversation_history, $model, $api_key, $chat_settings);
        }
        
        if ($response && isset($response['content'])) {
            // Save to database
            $this->save_chat_message($session_id, $chat_id, $message, $response['content'], $response['tokens'], $user_name, $user_email);
            
            wp_send_json_success(array(
                'response' => $response['content'],
                'tokens' => $response['tokens']
            ));
        } else {
            wp_send_json_error(array('response' => 'Failed to get AI response. Please check your API settings.'));
        }
    }

    public function render_frontend_chats() {
        $chats = $this->get_active_chats();
        
        foreach ($chats as $chat) {
            $chat_settings_raw = get_post_meta($chat->ID, '_snn_chat_settings', true);
            if (!is_array($chat_settings_raw)) { // Ensure it's an array
                $chat_settings_raw = [];
            }
            $chat_settings = wp_parse_args($chat_settings_raw, $this->get_default_chat_settings());

            if ($this->should_show_chat($chat_settings)) {
                $this->render_chat_widget($chat, $chat_settings);
            }
        }
    }

    private function render_chat_widget($chat, $settings) {
        $session_id = 'snn_' . time() . '_' . bin2hex(random_bytes(8));
        ?>
        <div class="snn-ai-chat-widget" id="snn-chat-<?php echo esc_attr($chat->ID); ?>" data-chat-id="<?php echo esc_attr($chat->ID); ?>" data-session-id="<?php echo esc_attr($session_id); ?>">
            <div class="snn-chat-toggle" id="snn-chat-toggle-<?php echo esc_attr($chat->ID); ?>">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M21 15C21 15.5304 20.7893 16.0391 20.4142 16.4142C20.0391 16.7893 19.5304 17 19 17H7L3 21V5C3 4.46957 3.21071 3.96086 3.58579 3.58579C3.96086 3.21071 4.46957 3 5 3H19C19.5304 3 20.0391 3.21071 20.4142 3.58579C20.7893 3.96086 21 4.46957 21 5V15Z" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </div>
            
            <div class="snn-chat-container" id="snn-chat-container-<?php echo esc_attr($chat->ID); ?>" style="display: none;">
                <div class="snn-chat-header">
                    <h3><?php echo esc_html($chat->post_title); ?></h3>
                    <button class="snn-chat-close" id="snn-chat-close-<?php echo esc_attr($chat->ID); ?>">
                        <svg width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M12 4L4 12M4 4L12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                
                <div class="snn-chat-messages" id="snn-chat-messages-<?php echo esc_attr($chat->ID); ?>">
                    <?php if (!empty($settings['collect_user_info'])) { ?>
                        <div class="snn-user-info-form" id="snn-user-info-form-<?php echo esc_attr($chat->ID); ?>">
                            <p>Please provide your information to start the chat:</p>
                            <input type="text" placeholder="Your Name" id="snn-user-name-<?php echo esc_attr($chat->ID); ?>" required>
                            <input type="email" placeholder="Your Email" id="snn-user-email-<?php echo esc_attr($chat->ID); ?>" required>
                            <button type="button" class="snn-start-chat-btn" id="snn-start-chat-btn-<?php echo esc_attr($chat->ID); ?>">Start Chat</button>
                        </div>
                    <?php } else { ?>
                        <div class="snn-chat-message snn-ai-message">
                            <div class="snn-message-content"><?php echo esc_html($settings['initial_message']); ?></div>
                        </div>
                    <?php } ?>
                </div>
                
                <div class="snn-chat-input-container">
                    <input type="text" class="snn-chat-input" id="snn-chat-input-<?php echo esc_attr($chat->ID); ?>" placeholder="Type your message..." <?php echo !empty($settings['collect_user_info']) ? 'disabled' : ''; ?>>
                    <button class="snn-chat-send" id="snn-chat-send-<?php echo esc_attr($chat->ID); ?>" <?php echo !empty($settings['collect_user_info']) ? 'disabled' : ''; ?>>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                           <path d="M22 2L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                           <path d="M22 2L15 22L11 13L2 9L22 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>
        
        <style>
            /* Widget Positioning */
            #snn-chat-<?php echo esc_attr($chat->ID); ?> {
                position: fixed;
                z-index: 99999;
                <?php
                switch ((string)$settings['chat_position']) { // Cast to string
                    case 'bottom-right': echo 'bottom: 20px; right: 20px;'; break;
                    case 'bottom-left': echo 'bottom: 20px; left: 20px;'; break;
                    case 'top-right': echo 'top: 20px; right: 20px;'; break;
                    case 'top-left': echo 'top: 20px; left: 20px;'; break;
                }
                ?>
            }

            /* Toggle Button */
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-toggle {
                background-color: <?php echo esc_attr((string)$settings['primary_color']); ?>;
                border-radius: 9999px; /* Fully rounded */
                width: 50px;
                height: 50px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                transition: background-color 0.3s ease;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-toggle:hover {
                background-color: <?php echo esc_attr($this->adjust_brightness((string)$settings['primary_color'], -20)); ?>; /* Darken on hover */
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-toggle svg {
                color: <?php echo esc_attr((string)$settings['text_color']); ?>;
            }

            /* Chat Container */
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-container {
                width: <?php echo esc_attr((string)$settings['widget_width']); ?>px;
                height: <?php echo esc_attr((string)$settings['widget_height']); ?>px;
                border-radius: <?php echo esc_attr((string)$settings['border_radius']); ?>px;
                background-color: <?php echo esc_attr((string)$settings['chat_widget_bg_color']); ?>;
                box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1);
                display: flex;
                flex-direction: column;
                overflow: hidden;
            }

            /* Chat Header */
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-header {
                background-color: <?php echo esc_attr((string)$settings['primary_color']); ?>;
                color: <?php echo esc_attr((string)$settings['text_color']); ?>;
                padding: 1rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-top-left-radius: <?php echo esc_attr((string)$settings['border_radius']); ?>px;
                border-top-right-radius: <?php echo esc_attr((string)$settings['border_radius']); ?>px;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-header h3 {
                margin: 0;
                font-size: 1.125rem; /* text-lg */
                font-weight: 600; /* font-semibold */
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-close {
                background: none;
                border: none;
                color: <?php echo esc_attr((string)$settings['text_color']); ?>;
                cursor: pointer;
                font-size: 1.25rem;
                line-height: 1;
                padding: 0.25rem;
                border-radius: 0.25rem;
                transition: background-color 0.3s ease;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-close:hover {
                background-color: rgba(255, 255, 255, 0.2);
            }

            /* Chat Messages Area */
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-messages {
                flex-grow: 1;
                padding: 1rem;
                overflow-y: auto;
                font-size: <?php echo esc_attr((string)$settings['font_size']); ?>px;
                color: <?php echo esc_attr((string)$settings['chat_text_color']); ?>; /* General chat text color */
            }

            /* Individual Chat Messages */
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-message {
                margin-bottom: 0.75rem;
                display: flex;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-message.snn-user-message {
                justify-content: flex-end;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-message.snn-ai-message {
                justify-content: flex-start;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-message-content {
                max-width: 80%;
                padding: 0.75rem 1rem;
                border-radius: 0.75rem; /* rounded-xl */
                word-wrap: break-word;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-message.snn-user-message .snn-message-content {
                background-color: <?php echo esc_attr((string)$settings['user_message_bg_color']); ?>;
                color: <?php echo esc_attr((string)$settings['user_message_text_color']); ?>;
                border-bottom-right-radius: 0.25rem; /* rounded-br-md */
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-message.snn-ai-message .snn-message-content {
                background-color: <?php echo esc_attr((string)$settings['ai_message_bg_color']); ?>;
                color: <?php echo esc_attr((string)$settings['ai_message_text_color']); ?>;
                border-bottom-left-radius: 0.25rem; /* rounded-bl-md */
            }

            /* User Info Form */
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-user-info-form {
                padding: 1rem;
                text-align: center;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-user-info-form p {
                margin-bottom: 1rem;
                color: <?php echo esc_attr((string)$settings['chat_text_color']); ?>;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-user-info-form input {
                width: 100%;
                padding: 0.75rem;
                margin-bottom: 0.75rem;
                border: 1px solid <?php echo esc_attr($this->adjust_brightness((string)$settings['chat_input_bg_color'], -10)); ?>;
                border-radius: 0.5rem;
                background-color: <?php echo esc_attr((string)$settings['chat_input_bg_color']); ?>;
                color: <?php echo esc_attr((string)$settings['chat_input_text_color']); ?>;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-user-info-form input::placeholder {
                color: <?php echo esc_attr($this->adjust_brightness((string)$settings['chat_input_text_color'], 50)); ?>;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-user-info-form button.snn-start-chat-btn {
                background-color: <?php echo esc_attr((string)$settings['primary_color']); ?>;
                color: <?php echo esc_attr((string)$settings['text_color']); ?>;
                padding: 0.75rem 1.5rem;
                border: none;
                border-radius: 0.5rem;
                cursor: pointer;
                transition: background-color 0.3s ease;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-user-info-form button.snn-start-chat-btn:hover {
                background-color: <?php echo esc_attr($this->adjust_brightness((string)$settings['primary_color'], -20)); ?>;
            }

            /* Chat Input Container */
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-input-container {
                display: flex;
                padding: 1rem;
                border-top: 1px solid <?php echo esc_attr($this->adjust_brightness((string)$settings['chat_widget_bg_color'], -10)); ?>;
                background-color: <?php echo esc_attr((string)$settings['chat_widget_bg_color']); ?>;
                border-bottom-left-radius: <?php echo esc_attr((string)$settings['border_radius']); ?>px;
                border-bottom-right-radius: <?php echo esc_attr((string)$settings['border_radius']); ?>px;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-input {
                flex-grow: 1;
                padding: 0.75rem 1rem;
                border: 1px solid <?php echo esc_attr($this->adjust_brightness((string)$settings['chat_input_bg_color'], -10)); ?>;
                border-radius: 0.5rem;
                outline: none;
                background-color: <?php echo esc_attr((string)$settings['chat_input_bg_color']); ?>;
                color: <?php echo esc_attr((string)$settings['chat_input_text_color']); ?>;
                margin-right: 0.5rem;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-input::placeholder {
                color: <?php echo esc_attr($this->adjust_brightness((string)$settings['chat_input_text_color'], 50)); ?>;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-send {
                background: none;
                border: none;
                color: <?php echo esc_attr((string)$settings['chat_send_button_color']); ?>;
                cursor: pointer;
                padding: 0.5rem;
                border-radius: 0.5rem;
                transition: background-color 0.3s ease;
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-send:hover {
                background-color: rgba(0, 0, 0, 0.05); /* Slightly darken on hover */
            }
            #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-send:disabled {
                opacity: 0.5;
                cursor: not-allowed;
            }

            /* Responsive adjustments for smaller screens */
            @media (max-width: 768px) {
                #snn-chat-<?php echo esc_attr($chat->ID); ?> {
                    bottom: 10px;
                    right: 10px;
                    left: auto; /* Reset left/top for mobile */
                    top: auto;
                }
                #snn-chat-<?php echo esc_attr($chat->ID); ?> .snn-chat-container {
                    width: calc(100vw - 20px); /* Full width minus margin */
                    height: calc(100vh - 100px); /* Adjust height for mobile */
                    max-width: 400px; /* Max width for chat container on mobile */
                    max-height: 600px; /* Max height for chat container on mobile */
                }
            }
        </style>
        <?php
    }
    
    // Helper methods
    private function get_dashboard_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Active chats
        $stats['active_chats'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'snn_ai_chat' AND post_status = 'publish'");
        
        // Total tokens
        $stats['total_tokens'] = $wpdb->get_var("SELECT SUM(tokens_used) FROM {$wpdb->prefix}snn_chat_messages") ?: 0;
        
        // Total sessions
        $stats['total_sessions'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_sessions");
        
        // Today's sessions
        $stats['today_sessions'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_sessions WHERE DATE(created_at) = %s", current_time('Y-m-d')));
        
        // Today's messages
        $stats['today_messages'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_messages WHERE DATE(created_at) = %s", current_time('Y-m-d')));
        
        // Today's tokens
        $stats['today_tokens'] = $wpdb->get_var($wpdb->prepare("SELECT SUM(tokens_used) FROM {$wpdb->prefix}snn_chat_messages WHERE DATE(created_at) = %s", current_time('Y-m-d'))) ?: 0;
        
        // This month's messages
        $stats['month_messages'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_messages WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d", current_time('Y'), current_time('m')));
        
        // This month's tokens
        $stats['month_tokens'] = $wpdb->get_var($wpdb->prepare("SELECT SUM(tokens_used) FROM {$wpdb->prefix}snn_chat_messages WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d", current_time('Y'), current_time('m'))) ?: 0;
        
        // This month's sessions
        $stats['month_sessions'] = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_sessions WHERE YEAR(created_at) = %d AND MONTH(created_at) = %d", current_time('Y'), current_time('m')));
        
        return $stats;
    }
    
    private function get_recent_activities() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT s.*, 
                   COUNT(m.id) as message_count,
                   SUM(m.tokens_used) as total_tokens
            FROM {$wpdb->prefix}snn_chat_sessions s
            LEFT JOIN {$wpdb->prefix}snn_chat_messages m ON s.session_id = m.session_id
            GROUP BY s.id
            ORDER BY s.created_at DESC
            LIMIT 20
        ");
    }
    
    private function get_settings() {
        return get_option('snn_ai_chat_settings', array(
            'api_provider' => 'openrouter',
            'openrouter_api_key' => '',
            'openrouter_model' => 'openai/gpt-4o-mini',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'default_system_prompt' => 'You are a helpful assistant.',
            'default_initial_message' => 'Hello! How can I help you today?'
        ));
    }
    
    private function save_settings() {
        if (!isset($_POST['snn_ai_chat_settings_nonce']) || !wp_verify_nonce(sanitize_text_field((string)wp_unslash($_POST['snn_ai_chat_settings_nonce'])), 'snn_ai_chat_settings')) { // Cast to string
            return;
        }
        
        $settings = array(
            'api_provider' => isset($_POST['api_provider']) ? sanitize_text_field((string)wp_unslash($_POST['api_provider'])) : 'openrouter', // Cast to string
            'openrouter_api_key' => isset($_POST['openrouter_api_key']) ? sanitize_text_field((string)wp_unslash($_POST['openrouter_api_key'])) : '', // Cast to string
            'openrouter_model' => isset($_POST['openrouter_model']) ? sanitize_text_field((string)wp_unslash($_POST['openrouter_model'])) : '', // Cast to string
            'openai_api_key' => isset($_POST['openai_api_key']) ? sanitize_text_field((string)wp_unslash($_POST['openai_api_key'])) : '', // Cast to string
            'openai_model' => isset($_POST['openai_model']) ? sanitize_text_field((string)wp_unslash($_POST['openai_model'])) : '', // Cast to string
            'default_system_prompt' => isset($_POST['default_system_prompt']) ? sanitize_textarea_field((string)wp_unslash($_POST['default_system_prompt'])) : '', // Cast to string
            'default_initial_message' => isset($_POST['default_initial_message']) ? sanitize_text_field((string)wp_unslash($_POST['default_initial_message'])) : '' // Cast to string
        );
        
        update_option('snn_ai_chat_settings', $settings);
        
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved successfully!</p></div>';
    }
    
    private function get_all_chats() {
        return get_posts(array(
            'post_type' => 'snn_ai_chat',
            'post_status' => 'publish',
            'numberposts' => -1
        ));
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
        ");
    }
    
    private function get_chat_stats($chat_id) {
        global $wpdb;
        
        $sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_sessions WHERE chat_id = %d
        ", $chat_id));
        
        return sprintf('%d sessions', $sessions);
    }
    
    private function get_default_chat_settings() {
        return array(
            'model' => '', // Now explicitly empty to use global by default
            'initial_message' => 'Hello! How can I help you today?',
            'system_prompt' => 'You are a helpful assistant.',
            'keep_conversation_history' => 1,
            'chat_position' => 'bottom-right',
            'primary_color' => '#3b82f6', // blue-500
            'secondary_color' => '#e5e7eb', // gray-200
            'text_color' => '#ffffff', // white
            'chat_widget_bg_color' => '#ffffff', // white
            'chat_text_color' => '#374151', // gray-700
            'user_message_bg_color' => '#3b82f6', // blue-500
            'user_message_text_color' => '#ffffff', // white
            'ai_message_bg_color' => '#e5e7eb', // gray-200
            'ai_message_text_color' => '#374151', // gray-700
            'chat_input_bg_color' => '#f9fafb', // gray-50
            'chat_input_text_color' => '#1f2937', // gray-900
            'chat_send_button_color' => '#3b82f6', // blue-500
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
            'collect_user_info' => 0
        );
    }
    
    /**
     * Rewritten display logic to be more robust and follow a clear order of operations.
     */
    private function should_show_chat($settings) {
        $post_id = get_queried_object_id();

        // 1. Highest priority: Check for exclusion. If the page is excluded, never show the chat.
        if (!empty($settings['exclude_pages'])) {
            $exclude_pages = array_map('intval', explode(',', (string)$settings['exclude_pages']));
            if (in_array($post_id, $exclude_pages, true)) {
                return false;
            }
        }

        // 2. If 'Show on all pages' is checked, show it.
        if (!empty($settings['show_on_all_pages'])) {
            return true;
        }

        // 3. Check for inclusion by specific page/post ID.
        if (!empty($settings['specific_pages'])) {
            $specific_pages = array_map('intval', explode(',', (string)$settings['specific_pages']));
            if (in_array($post_id, $specific_pages, true)) {
                return true;
            }
        }

        // 4. Check template conditions.
        if (!empty($settings['show_on_home']) && is_home()) return true;
        if (!empty($settings['show_on_front_page']) && is_front_page()) return true;
        if (!empty($settings['show_on_posts']) && is_singular('post')) return true;
        if (!empty($settings['show_on_pages']) && is_page()) return true;
        if (!empty($settings['show_on_categories']) && is_category()) return true;
        if (!empty($settings['show_on_archives']) && is_archive()) return true;

        // 5. If no condition is met, do not show the chat.
        return false;
    }
    
    private function fetch_openrouter_models($api_key) {
        $response = wp_remote_get('https://openrouter.ai/api/v1/models', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15, // Increased timeout for API calls
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
            'timeout' => 15, // Increased timeout for API calls
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
        $response = wp_remote_get('https://api.openai.com/v1/models/' . $model, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15, // Increased timeout for API calls
        ));
        
        if (is_wp_error($response)) {
            error_log('SNN AI Chat OpenAI Model Details Error: ' . $response->get_error_message());
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function check_rate_limits($session_id, $chat_id) {
        global $wpdb;
        
        $chat_settings_raw = get_post_meta($chat_id, '_snn_chat_settings', true);
        $chat_settings = wp_parse_args(is_array($chat_settings_raw) ? $chat_settings_raw : [], $this->get_default_chat_settings());
        
        $ip = $_SERVER['REMOTE_ADDR'];
        
        // Check rate limit per minute
        $minute_ago = date('Y-m-d H:i:s', time() - 60);
        $recent_messages = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}snn_chat_messages 
            WHERE session_id = %s AND created_at > %s
        ", $session_id, $minute_ago));
        
        if ($recent_messages >= $chat_settings['rate_limit_per_minute']) {
            return false;
        }
        
        // Check daily IP limits
        $today = current_time('Y-m-d');
        $daily_sessions = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT session_id) FROM {$wpdb->prefix}snn_chat_sessions 
            WHERE ip_address = %s AND DATE(created_at) = %s
        ", $ip, $today));
        
        if ($daily_sessions >= $chat_settings['max_chats_per_ip_daily']) {
            return false;
        }
        
        $daily_tokens = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(m.tokens_used) FROM {$wpdb->prefix}snn_chat_messages m
            JOIN {$wpdb->prefix}snn_chat_sessions s ON m.session_id = s.session_id
            WHERE s.ip_address = %s AND DATE(m.created_at) = %s
        ", $ip, $today));
        
        if ($daily_tokens >= $chat_settings['max_tokens_per_ip_daily']) {
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
        ", $session_id));
        
        $history = array();
        foreach ($messages as $msg) {
            $history[] = array('role' => 'user', 'content' => $msg->message);
            $history[] = array('role' => 'assistant', 'content' => $msg->response);
        }
        
        return $history;
    }
    
    private function send_to_openrouter($messages, $model, $api_key, $chat_settings) {
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $chat_settings['max_tokens_per_session']
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
                'content' => $data['choices'][0]['message']['content'],
                'tokens' => $data['usage']['total_tokens'] ?? 0
            );
        }
        
        error_log('SNN AI Chat OpenRouter API Response Error: ' . print_r($data, true));
        return false;
    }
    
    private function send_to_openai($messages, $model, $api_key, $chat_settings) {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode(array(
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $chat_settings['max_tokens_per_session']
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
                'content' => $data['choices'][0]['message']['content'],
                'tokens' => $data['usage']['total_tokens'] ?? 0
            );
        }
        
        error_log('SNN AI Chat OpenAI API Response Error: ' . print_r($data, true));
        return false;
    }
    
    private function save_chat_message($session_id, $chat_id, $message, $response, $tokens, $user_name, $user_email) {
        global $wpdb;
        
        // Create or update session
        $session_exists = $wpdb->get_var($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}snn_chat_sessions WHERE session_id = %s
        ", $session_id));
        
        if (!$session_exists) {
            $wpdb->insert(
                $wpdb->prefix . 'snn_chat_sessions',
                array(
                    'chat_id' => $chat_id,
                    'session_id' => $session_id,
                    'user_name' => $user_name,
                    'user_email' => $user_email,
                    'ip_address' => $_SERVER['REMOTE_ADDR']
                ),
                array('%d', '%s', '%s', '%s', '%s')
            );
        }
        
        // Save message
        $wpdb->insert(
            $wpdb->prefix . 'snn_chat_messages',
            array(
                'session_id' => $session_id,
                'message' => $message,
                'response' => $response,
                'tokens_used' => $tokens
            ),
            array('%s', '%s', '%s', '%d')
        );
    }

    /**
     * Adjusts the brightness of a given hex color.
     *
     * @param string $hex The hex color code (e.g., '#RRGGBB').
     * @param int $steps The amount to lighten (positive) or darken (negative) the color, from -255 to 255.
     * @return string The adjusted hex color code.
     */
    private function adjust_brightness($hex, $steps) {
        // Remove '#' if present
        $hex = ltrim((string)$hex, '#'); // Added (string) cast defensively

        // Handle shorthand hex codes
        if (strlen((string)$hex) === 3) { // Added (string) cast defensively
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $rgb = array_map('hexdec', str_split((string)$hex, 2)); // Added (string) cast defensively

        foreach ($rgb as &$value) {
            $value = max(0, min(255, $value + $steps));
        }

        return '#' . implode('', array_map(function($val) {
            return str_pad(dechex($val), 2, '0', STR_PAD_LEFT);
        }, $rgb));
    }
}

// Initialize the plugin
new SNN_AI_Chat();
