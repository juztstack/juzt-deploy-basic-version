<?php

/**
 * Admin Interface Class
 * * Handles all the plugin's administration interface
 * * @package WP_Versions_Plugins_Themes
 * @since 1.7.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class JUZT_DEPLOY_BASIC_Admin_Interface
{

    /**
     * Class instances
     */
    private $repo_manager;
    private $oauth_service;

    /**
     * Current page
     */
    private $current_page;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Include necessary classes
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-repo-manager.php';
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-oauth-service.php';

        $this->oauth_service = new JUZT_DEPLOY_BASIC_OAuth_Service();
        $this->repo_manager = new JUZT_DEPLOY_BASIC_Repo_Manager();
    }

    /**
     * Render main admin page
     */
    public function render_admin_page()
    {
        $this->current_page = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

        echo '<div class="wrap wpvtp-admin" style="max-width: 1024px; margin: 20px 15px 20px 0px;">';
        echo '<h1 style="font-size: 42px; font-weight: 700;">Juzt Deploy - Basic version</h1>';
        echo '<p>A fast way to deploy themes and plugins from Github to Wordpress site.</p>';
        echo '<p>Created by <a href="https://github.com/jesusuzcategui" target="_blank">Jesus Uzcategui</a> and <a href="https://juztstack.com" target="_blank">Juzt Stack Project</a>.</p>';

        // Render tabs
        $this->render_navigation_tabs();

        // Render content based on tab
        switch ($this->current_page) {
            case 'install':
                $this->render_install_page();
                break;
            case 'commits_queue':
                $this->render_commits_queue_page();
                break;
            case 'settings':
                $this->render_settings_page();
                break;
            default:
                $this->render_dashboard_page();
                break;
        }

        echo '</div>';
    }

    /**
     * Render Git not available warning
     */
    private function render_git_warning()
    {
        echo '<div class="notice notice-error">';
        echo '<h2>Git Not Available</h2>';
        echo '<p>This plugin requires Git to be installed on the server.</p>';
        echo '</div>';
    }

    /**
     * Render navigation tabs
     */
    private function render_navigation_tabs()
    {
        $tabs = array(
            'dashboard' => 'Dashboard',
            'install' => 'Install Repository',
            'commits_queue' => 'Commits Queue',
            'settings' => 'Settings'
        );

        echo '<h2 class="nav-tab-wrapper">';
        foreach ($tabs as $tab => $label) {
            $active = ($this->current_page === $tab) ? ' nav-tab-active' : '';
            $url = admin_url('admin.php?page=juzt-deploy-basic&tab=' . $tab);
            echo '<a href="' . esc_url($url) . '" class="nav-tab' . $active . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';
    }

    /**
     * Render dashboard page
     */
    private function render_dashboard_page()
    {
        echo '<div class="wpvtp-dashboard">';

        if (!$this->oauth_service->is_connected()) {
            echo '<div class="notice notice-warning">';
            echo '<p>To use this plugin, you must first <a href="' . admin_url('admin.php?page=juzt-deploy-basic&tab=settings') . '">connect your GitHub account</a>.</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new JUZT_DEPLOY_BASIC_Repo_Manager();

        $current_mode = $repo_manager->get_git_mode();
        $mode_color = $current_mode === 'cli' ? '#46b450' : '#0073aa';
        $mode_label = $current_mode === 'cli' ? 'CLI Mode' : 'API Mode';
        $mode_icon = $current_mode === 'cli' ? '⚡' : '🌐';

        echo '<div class="wpvtp-dashboard-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
        echo '<h2>Dashboard</h2>';
        echo '<div style="background: ' . $mode_color . '; color: white; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600;">';
        echo $mode_icon . ' ' . $mode_label;
        echo '</div>';
        echo '</div>';

        // Get installed repositories
        $repos = $this->repo_manager->get_installed_repos();
        $stats = $this->repo_manager->get_repo_stats();

        $this->render_api_mode_warning();

        // Show statistics
        echo '<div class="wpvtp-stats" style="display: flex; gap: 20px; margin-bottom: 30px;">';
        echo '<div class="wpvtp-stat-box" style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #0073aa; min-width: 150px;">';
        echo '<h3 style="margin: 0 0 10px 0;">' . $stats['total'] . '</h3>';
        echo '<p style="margin: 0; color: #666;">Total Repositories</p>';
        echo '</div>';

        echo '<div class="wpvtp-stat-box" style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #00a32a; min-width: 150px;">';
        echo '<h3 style="margin: 0 0 10px 0;">' . $stats['themes'] . '</h3>';
        echo '<p style="margin: 0; color: #666;">Themes</p>';
        echo '</div>';

        echo '<div class="wpvtp-stat-box" style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #d63638; min-width: 150px;">';
        echo '<h3 style="margin: 0 0 10px 0;">' . $stats['plugins'] . '</h3>';
        echo '<p style="margin: 0; color: #666;">Plugins</p>';
        echo '</div>';
        echo '</div>';

        // Botón para descargar wp-content
        echo '<div style="margin: 20px 0;">';
        echo '<button type="button" id="wpvtp-download-wp-content" class="button button-primary button-large">';
        echo '📦 Download wp-content path';
        echo '</button>';
        echo '<p class="description" style="margin-top: 8px;">Download the WP Content path to use as local development with Juzt CLI</p>';
        echo '</div>';

        // Repository Table
        if (!empty($repos)) {
            echo '<div style="background: white; padding: 20px; border-radius: 8px; display: grid; gap: 20px; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">';
            foreach ($repos as $repo) {
                $repo_type_label = $repo['repo_type'] === 'theme' ? '🎨 Theme' : '🔌 Plugin';
                $status_label = '';
                $status_color = '';

                if (!$repo['exists']) {
                    $status_label = '❌ Does Not Exist';
                    $status_color = '#d63638';
                } elseif (!$repo['has_git']) {
                    $status_label = '🌐 API Mode';
                    $status_color = '#0073aa';
                } else {
                    $status_label = '✅ CLI Mode';
                    $status_color = '#00a32a';
                }

                echo '<div class="wpvtp-repo-card" style="background: #f8f9fa; padding: 15px; border-radius: 8px; border: 1px solid #e9ecef;">';
                echo '<h4 style="margin: 0 0 10px 0;">' . esc_html($repo['repo_name']) . '</h4>';
                echo '<p style="margin: 5px 0;"><strong>Type:</strong> ' . esc_html($repo_type_label) . '</p>';
                echo '<p style="margin: 5px 0;"><strong>Branch:</strong> <code>' . esc_html($repo['current_branch']) . '</code></p>';
                echo '<p style="margin: 5px 0;"><span style="color: ' . esc_attr($status_color) . ';">' . esc_html($status_label) . '</span></p>';
                echo '<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 15px;">';
                
                if ($repo['exists']):
                    echo '<button class="button button-small button-link-delete wpvtp-remove-repo" data-path="' . esc_attr($repo['local_path']) . '" data-name="' . esc_attr($repo['repo_name']) . '">Delete</button>';
                    echo '<button class="button button-small wpvtp-update-repo" data-path="' . esc_attr($repo['local_path']) . '">Update</button> ';
                    echo '<button class="wpvtp-btn wpvtp-btn-success wpvtp-push-all-btn" data-identifier="' . esc_attr($repo['folder_name']) . '"> 📤 Push</button>';
                    echo '<button class="button button-small wpvtp-switch-branch" data-path="' . esc_attr($repo['local_path']) . '" data-repo-url="' . esc_attr($repo['repo_url']) . '">Switch Branch</button> ';
                    if ($repo['repo_type'] === 'theme') {
                        $theme_handle = basename($repo['local_path']);
                        echo '<a href="' . home_url('?wpvtheme=' . $theme_handle) . '" target="_blank" class="button button-small">Preview</a> ';
                    }
                endif;

                echo '</div>';
                echo '</div>';
            }
            echo '</div>';
        } else {
            echo '<div class="notice notice-info">';
            echo '<p>You have no repositories installed yet. <a href="' . admin_url('admin.php?page=juzt-deploy-basic&tab=install') . '">Install your first repository</a>.</p>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Render install page
     */
    private function render_install_page()
    {
        echo '<div class="wpvtp-install">';
        echo '<h2>Install Repository from GitHub</h2>';

        if (!$this->oauth_service->is_connected()) {
            echo '<div class="notice notice-warning">';
            echo '<p>To install repositories, you must first <a href="' . admin_url('admin.php?page=juzt-deploy-basic&tab=settings') . '">connect your GitHub account</a>.</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        $this->render_api_mode_warning();

        // Installation Form (wizard)
        echo '<form id="wpvtp-install-form" style="background: white; padding: 30px; border-radius: 10px; margin: 20px 0;">';

        // Step 1: Organization
        echo '<div class="wpvtp-form-step" id="step-organization">';
        echo '<h3>1. Select Organization/User</h3>';
        echo '<select id="wpvtp-organization" style="width: 100%; max-width: 400px; padding: 8px;">';
        echo '<option value="">Loading organizations...</option>';
        echo '</select>';
        echo '</div>';

        // Step 2: Repository
        echo '<div class="wpvtp-form-step" id="step-repository" style="display: none; margin-top: 30px;">';
        echo '<h3>2. Select Repository</h3>';
        echo '<div style="display: flex; flex-direction: column; gap: 10px; width: 100%; max-width: 400px;">';
        echo '<input placeholder="Search by name" type="search" id="wpvtp-repository-search" style="width: 100%; padding: 8px;" disabled>';
        echo '<select id="wpvtp-repository" style="width: 100%; max-width: 400px; padding: 8px;" disabled>';
        echo '<option value="">First select an organization</option>';
        echo '</select>';
        echo '</div>';
        
        echo '<div id="wpvtp-repo-info" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 5px;">';
        echo '<h4>Repository Information</h4>';
        echo '<p><strong>Description:</strong> <span id="repo-description"></span></p>';
        echo '</div>';
        echo '</div>';

        // Step 3: Repository Type (NEW - MANUAL)
        echo '<div class="wpvtp-form-step" id="step-type" style="display: none; margin-top: 30px;">';
        echo '<h3>3. Select Type</h3>';
        echo '<p style="color: #666; margin-bottom: 15px;">What type of repository is it?</p>';
        echo '<select id="wpvtp-repo-type" style="width: 100%; max-width: 400px; padding: 8px;" disabled>';
        echo '<option value="">Select type...</option>';
        echo '<option value="theme">🎨 WordPress Theme</option>';
        echo '<option value="plugin">🔌 WordPress Plugin</option>';
        echo '</select>';
        echo '<p class="description" style="margin-top: 8px; color: #666; font-size: 13px;">Select whether this repository contains a WordPress theme or a plugin.</p>';
        echo '</div>';

        // Step 4: Branch
        echo '<div class="wpvtp-form-step" id="step-branch" style="display: none; margin-top: 30px;">';
        echo '<h3>4. Select Branch</h3>';
        echo '<select id="wpvtp-branch" style="width: 100%; max-width: 400px; padding: 8px;" disabled>';
        echo '<option value="">First select the type</option>';
        echo '</select>';
        echo '</div>';

        // Step 5: Custom Name
        echo '<div class="wpvtp-form-step" id="step-custom-name" style="display: none; margin-top: 30px;">';
        echo '<h3>5. Custom Name (Optional)</h3>';
        echo '<input type="text" id="wpvtp-custom-name" style="width: 100%; max-width: 400px; padding: 8px;" placeholder="Leave empty to use the repository name">';
        echo '<p class="description" style="margin-top: 8px; color: #666; font-size: 13px;">For themes: This name will appear as "Theme Name" in style.css. Useful for differentiating multiple versions.</p>';
        echo '</div>';

        // Step 6: Confirmation
        echo '<div class="wpvtp-form-step" id="step-confirm" style="display: none; margin-top: 30px;">';
        echo '<h3>6. Confirm Installation</h3>';
        echo '<div id="wpvtp-install-summary"></div>';
        echo '<button type="submit" class="button button-primary button-large" style="margin-top: 20px;">Install Repository</button>';
        echo '</div>';

        echo '</form>';

        // Installation results
        echo '<div id="wpvtp-install-results" style="display: none; padding: 20px; border-radius: 8px; margin: 20px 0;"></div>';

        echo '</div>';
    }

    /**
     * Procesar guardado de configuración de Git mode
     */
    private function handle_git_mode_save()
    {
        if (!isset($_POST['save_git_mode']) || !isset($_POST['JUZT_DEPLOY_BASIC_git_mode_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['JUZT_DEPLOY_BASIC_git_mode_nonce'], 'JUZT_DEPLOY_BASIC_save_git_mode')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $force_mode = sanitize_text_field($_POST['force_git_mode']);

        if (!in_array($force_mode, array('auto', 'cli', 'api'))) {
            return;
        }

        update_option('JUZT_DEPLOY_BASIC_force_git_mode', $force_mode);

        $auto_commit = sanitize_text_field($_POST['auto_commit'] ?? 'no');

        update_option('JUZT_DEPLOY_BASIC_auto_commit', $auto_commit);

        // Re-detectar el modo
        if ($force_mode === 'auto') {
            $this->repo_manager->detect_git_mode();
        } else {
            update_option('JUZT_DEPLOY_BASIC_git_mode', $force_mode);
            $reason = $force_mode === 'cli' ? 'Forced to CLI mode' : 'Forced to API mode';
            update_option('JUZT_DEPLOY_BASIC_git_mode_reason', $reason);
        }

        // Redirigir para limpiar POST
        $url = admin_url('admin.php?page=juzt-deploy-basic&tab=settings');
        $url = add_query_arg(array(
            'JUZT_DEPLOY_BASIC_message' => urlencode('Git mode saved successfully'),
            'JUZT_DEPLOY_BASIC_message_type' => 'success'
        ), $url);

        wp_redirect($url);
        exit;
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        $this->handle_git_mode_save();

        echo '<div class="wpvtp-settings">';
        echo '<h1>GitHub Settings</h1>';
        echo '<p>If you need install the Github App on another Orgs or User, <a href="https://github.com/apps/wordpress-theme-versions" target="_blank"/>Click here</a>.</p>';

        // Show messages
        if (isset($_GET['JUZT_DEPLOY_BASIC_message'])) {
            $message = urldecode($_GET['JUZT_DEPLOY_BASIC_message']);
            $type = isset($_GET['JUZT_DEPLOY_BASIC_message_type']) ? $_GET['JUZT_DEPLOY_BASIC_message_type'] : 'info';
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        $is_connected = $this->oauth_service->is_connected();

        echo '<div style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #dee2e6; margin: 20px 0; max-width: 600px;">';

        if ($is_connected) {
            // Connected User
            $user_info = $this->oauth_service->get_connected_user();
            $token = get_option('JUZT_DEPLOY_BASIC_oauth_token');
            $refresh_token = get_option('JUZT_DEPLOY_BASIC_refresh_token');

            echo '<div class="wpvtp-connection-status connected" style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 8px;">';
            echo '<h3>✅ Connected to GitHub</h3>';

            if ($user_info) {
                echo '<div style="display: flex; align-items: center; margin: 15px 0;">';
                if (isset($user_info['avatar_url'])) {
                    echo '<img src="' . esc_url($user_info['avatar_url']) . '" style="width: 50px; height: 50px; border-radius: 50%; margin-right: 15px;">';
                }
                echo '<div>';
                echo '<p style="margin: 5px 0;"><strong>User:</strong> ' . esc_html($user_info['login']) . '</p>';
                echo '<p style="margin: 5px 0;"><strong>Name:</strong> ' . esc_html($user_info['name'] ?: 'Not specified') . '</p>';
                echo '</div>';
                echo '</div>';
            }

            // DEBUG INFO - Only show in development
            if (defined('WP_DEBUG') && WP_DEBUG) {
                echo '<div style="background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 4px; font-size: 12px;">';
                echo '<strong>Debug Info:</strong><br>';
                echo 'Token: ' . (substr($token, 0, 10) . '...') . '<br>';
                echo 'Refresh Token: ' . (substr($refresh_token, 0, 10) . '...') . '<br>';
                echo '</div>';
            }

            echo '<button type="button" class="button button-secondary" onclick="disconnectGitHub()">Disconnect from GitHub</button>';
            echo '</div>';
        } else {
            // User not connected
            echo '<h3>🔗 Connect with GitHub</h3>';
            echo '<p>To use this plugin, you need to connect your GitHub account.</p>';
            echo '<p>This will allow you to access your repositories and organizations.</p>';

            echo '<div style="margin: 20px 0;">';
            echo '<a href="' . esc_url($this->oauth_service->get_authorization_url()) . '" class="button button-primary button-large">🔗 Connect with GitHub App</a>';
            echo '</div>';

            echo '<div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 20px;">';
            echo '<h4>🔒 Privacy Information</h4>';
            echo '<ul style="margin: 10px 0;">';
            echo '<li>We only access your authorized repositories</li>';
            echo '<li>We do not store your GitHub password</li>';
            echo '<li>You can revoke access at any time</li>';
            echo '<li>The connection is secure and encrypted</li>';
            echo '<li>Using GitHub App for enhanced security</li>';
            echo '</ul>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';

        echo '<div style="background: white; padding: 20px; border-radius: 8px; margin-top: 20px;max-width: 862px;">';
        echo '<h3>Git Mode Configuration</h3>';

        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new JUZT_DEPLOY_BASIC_Repo_Manager();

        $current_mode = $repo_manager->get_git_mode();
        $force_mode = get_option('JUZT_DEPLOY_BASIC_force_git_mode', 'auto');
        $mode_reason = get_option('JUZT_DEPLOY_BASIC_git_mode_reason', 'Not detected');

        // Badge del modo actual
        $mode_color = $current_mode === 'cli' ? '#46b450' : '#0073aa';
        $mode_label = $current_mode === 'cli' ? 'CLI Mode' : 'API Mode';
        echo '<p><strong>Current Mode:</strong> <span style="background: ' . $mode_color . '; color: white; padding: 4px 12px; border-radius: 4px; font-size: 12px;">' . $mode_label . '</span></p>';
        echo '<p style="color: #666; font-size: 13px; margin-top: 5px;">' . esc_html($mode_reason) . '</p>';

        echo '<form method="post" action="" style="margin-top: 20px;">';
        wp_nonce_field('JUZT_DEPLOY_BASIC_save_git_mode', 'JUZT_DEPLOY_BASIC_git_mode_nonce');

        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label for="force_git_mode">Force Git Mode</label></th>';
        echo '<td>';
        echo '<select name="force_git_mode" id="force_git_mode" class="regular-text">';
        echo '<option value="auto"' . selected($force_mode, 'auto', false) . '>Auto Detect</option>';
        echo '<option value="cli"' . selected($force_mode, 'cli', false) . '>Force CLI</option>';
        echo '<option value="api"' . selected($force_mode, 'api', false) . '>Force API</option>';
        echo '</select>';
        echo '<p class="description">Auto detect recommended. Force CLI requires git installed. API works everywhere but slower commits.</p>';
        echo '</td>';
        echo '</tr>';
        echo '<tr>';
        echo '<th><label for="auto_commit">Activate autocommit</label></th>';
        echo '<td>';
        echo '<select name="auto_commit" id="auto_commit" class="regular-text">';
        echo '<option value="yes"' . selected(get_option('JUZT_DEPLOY_BASIC_auto_commit', 'no'), 'yes', false) . '>Yes</option>';
        echo '<option value="no"' . selected(get_option('JUZT_DEPLOY_BASIC_auto_commit', 'no'), 'no', false) . '>No</option>';
        echo '</select>';
        echo '<p class="description">Auto detect recommended. Force CLI requires git installed. API works everywhere but slower commits.</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" name="save_git_mode" class="button button-primary">Save Git Mode</button>';
        echo '<button type="button" class="button" onclick="location.reload()">Redetect</button>';
        echo '</p>';
        echo '</form>';

        // Información comparativa
        echo '<div style="margin-top: 30px; padding: 15px; background: #f0f0f1; border-radius: 4px;">';
        echo '<h4 style="margin-top: 0;">Mode Comparison</h4>';
        echo '<table style="width: 100%; border-collapse: collapse;">';
        echo '<tr style="border-bottom: 1px solid #ddd;"><th style="text-align: left; padding: 8px;">Feature</th><th style="text-align: center; padding: 8px;">CLI Mode</th><th style="text-align: center; padding: 8px;">API Mode</th></tr>';
        echo '<tr style="border-bottom: 1px solid #ddd;"><td style="padding: 8px;">Clone Speed</td><td style="text-align: center;">⚡ Fast</td><td style="text-align: center;">🐢 Slower</td></tr>';
        echo '<tr style="border-bottom: 1px solid #ddd;"><td style="padding: 8px;">Update Speed</td><td style="text-align: center;">⚡ Fast</td><td style="text-align: center;">🐢 Slower</td></tr>';
        echo '<tr style="border-bottom: 1px solid #ddd;"><td style="padding: 8px;">Commits</td><td style="text-align: center;">✅ Full Support</td><td style="text-align: center;">✅ Single File</td></tr>';
        echo '<tr style="border-bottom: 1px solid #ddd;"><td style="padding: 8px;">Requirements</td><td style="text-align: center;">⚠️ Git + exec()</td><td style="text-align: center;">✅ None</td></tr>';
        echo '<tr><td style="padding: 8px;">Host Compatibility</td><td style="text-align: center;">❌ Limited</td><td style="text-align: center;">✅ Universal</td></tr>';
        echo '</table>';
        echo '</div>';

        echo '</div>';
    }

    private function render_commits_queue_page()
    {
        echo '<div class="wpvtp-commits-queue">';
        echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">';
        echo '<h2>Commits Queue</h2>';
        echo '<button type="button" class="button button-small button-link-delete wpvtp-bulk-delete" disabled>Delete</button>';
        echo '</div>';

        global $wpdb;
        $table_name = $wpdb->prefix . 'JUZT_DEPLOY_BASIC_commits_queue';

        $commits = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );

        if (empty($commits)) {
            echo '<div class="notice notice-info"><p>Commits no has enqueue</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>';
        echo '<th><input type="checkbox" class="wpvtp-select-all-commits"></th>';
        echo '<th>ID</th>';
        echo '<th>Path</th>';
        echo '<th>Message</th>';
        echo '<th>Status</th>';
        echo '<th>Attempts</th>';
        echo '<th>Date</th>';
        echo '<th>Error</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ($commits as $commit) {
            $status_class = $commit['status'] === 'completed' ? 'success' : ($commit['status'] === 'failed' ? 'error' : 'warning');
            $status_icon = $commit['status'] === 'completed' ? '✅' : ($commit['status'] === 'failed' ? '❌' : '⏳');

            echo '<tr>';
            echo '<td style="max-width: 50px;"><input type="checkbox" class="wpvtp-commit-checkbox" data-id="' . $commit['id'] . '" value="' . $commit['id'] . '"></td>';
            echo '<td style="max-width: 100px;">' . $commit['id'] . '</td>';
            echo '<td style="max-width: 200px;"><code>' . basename($commit['theme_path']) . '</code></td>';
            echo '<td style="max-width: 200px;">' . esc_html(substr($commit['commit_message'], 0, 50)) . '</td>';
            echo '<td style="max-width: 100px;"><span style="color: ' . ($status_class === 'success' ? 'green' : ($status_class === 'error' ? 'red' : 'orange')) . ';">' . $status_icon . ' ' . $commit['status'] . '</span></td>';
            echo '<td style="max-width: 50px;">' . $commit['attempts'] . '</td>';
            echo '<td style="max-width: 50px;">' . human_time_diff(strtotime($commit['created_at'])) . ' ago</td>';
            echo '<td style="max-width: 200px;">' . ($commit['last_error'] ? '<small>' . esc_html(substr($commit['last_error'], 0, 100)) . '</small>' : '-') . '</td>';
            echo '<td>';

            if ($commit['status'] !== 'completed') {
                echo '<button class="button button-small wpvtp-retry-commit" data-id="' . $commit['id'] . '">Re try</button> ';
            }

            echo '<button class="button button-small button-link-delete wpvtp-delete-commit" data-id="' . $commit['id'] . '">Delete</button>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_api_mode_warning()
    {
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new JUZT_DEPLOY_BASIC_Repo_Manager();

        if ($repo_manager->get_git_mode() === 'api') {
            echo '<div class="notice notice-info" style="margin-top: 20px;">';
            echo '<p><strong>ℹ️ Running in API Mode:</strong> You\'re using GitHub API instead of Git CLI. This works on any hosting but may be slower for large repositories. Commits are optimized for single-file changes.</p>';
            echo '</div>';
        }
    }
}
