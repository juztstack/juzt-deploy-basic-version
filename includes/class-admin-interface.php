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

class WPVTP_Admin_Interface
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
        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        require_once WPVTP_PLUGIN_DIR . 'includes/class-oauth-service.php';

        $this->oauth_service = new WPVTP_OAuth_Service();
        $this->repo_manager = new WPVTP_Repo_Manager();
    }

    /**
     * Render main admin page
     */
    public function render_admin_page()
    {
        $this->current_page = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

        echo '<div class="wrap wpvtp-admin">';
        echo '<h1>Juzt Deploy</h1>';
        echo '<p>A fast way to deploy themes and plugins from Github to Wordpress site.</p>';
        echo '<p>Created by Jesus Uzcategui and Juzt Stack Project.</p>';

        // Check Git availability
        if (!$this->repo_manager->is_git_available()) {
            $this->render_git_warning();
            echo '</div>';
            return;
        }

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
            $url = admin_url('admin.php?page=wp-versions-themes-plugins&tab=' . $tab);
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
        echo '<h2>Dashboard</h2>';

        if (!$this->oauth_service->is_connected()) {
            echo '<div class="notice notice-warning">';
            echo '<p>To use this plugin, you must first <a href="' . admin_url('admin.php?page=wp-versions-themes-plugins&tab=settings') . '">connect your GitHub account</a>.</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        // Get installed repositories
        $repos = $this->repo_manager->get_installed_repos();
        $stats = $this->repo_manager->get_repo_stats();

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
            echo '<h3>Installed Repositories</h3>';
            echo '<table class="fixed wp-list-table widefat striped">';
            echo '<thead><tr>';
            echo '<th>Name</th>';
            echo '<th>Type</th>';
            echo '<th>Current Branch</th>';
            echo '<th>Last Updated</th>';
            echo '<th>Status</th>';
            echo '<th>Actions</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($repos as $repo) {
                $repo_type_label = $repo['repo_type'] === 'theme' ? '🎨 Theme' : '🔌 Plugin';
                $status_label = '';
                $status_color = '';
                
                if (!$repo['exists']) {
                    $status_label = '❌ Does Not Exist';
                    $status_color = '#d63638';
                } elseif (!$repo['has_git']) {
                    $status_label = '⚠️ No Git';
                    $status_color = '#dba617';
                } else {
                    $status_label = '✅ OK';
                    $status_color = '#00a32a';
                }

                echo '<tr>';
                echo '<td><strong>' . esc_html($repo['repo_name']) . '</strong></td>';
                echo '<td>' . $repo_type_label . '</td>';
                echo '<td><code>' . esc_html($repo['current_branch']) . '</code></td>';
                echo '<td>' . human_time_diff(strtotime($repo['last_update'])) . ' ago</td>';
                echo '<td><span style="color: ' . esc_attr($status_color) . ';">' . $status_label . '</span></td>';

                echo '<td>';
                if ($repo['exists'] && $repo['has_git']) {
                    echo '<button class="button button-small wpvtp-update-repo" data-path="' . esc_attr($repo['local_path']) . '">Update</button> ';
                    echo '<button class="button button-small wpvtp-switch-branch" data-path="' . esc_attr($repo['local_path']) . '" data-repo-url="' . esc_attr($repo['repo_url']) . '">Switch Branch</button> ';

                    if ($repo['repo_type'] === 'theme') {
                        $theme_handle = basename($repo['local_path']);
                        echo '<a href="' . home_url('?wpvtheme=' . $theme_handle) . '" target="_blank" class="button button-small">Preview</a> ';
                    }
                }
                echo '<button class="button button-small button-link-delete wpvtp-remove-repo" data-path="' . esc_attr($repo['local_path']) . '" data-name="' . esc_attr($repo['repo_name']) . '">Delete</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<div class="notice notice-info">';
            echo '<p>You have no repositories installed yet. <a href="' . admin_url('admin.php?page=wp-versions-themes-plugins&tab=install') . '">Install your first repository</a>.</p>';
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
            echo '<p>To install repositories, you must first <a href="' . admin_url('admin.php?page=wp-versions-themes-plugins&tab=settings') . '">connect your GitHub account</a>.</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

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
        echo '<select id="wpvtp-repository" style="width: 100%; max-width: 400px; padding: 8px;" disabled>';
        echo '<option value="">First select an organization</option>';
        echo '</select>';
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
     * Render settings page
     */
    public function render_settings_page()
    {
        echo '<div class="wpvtp-settings">';
        echo '<h1>GitHub Settings</h1>';

        // Show messages
        if (isset($_GET['wpvtp_message'])) {
            $message = urldecode($_GET['wpvtp_message']);
            $type = isset($_GET['wpvtp_message_type']) ? $_GET['wpvtp_message_type'] : 'info';
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        $is_connected = $this->oauth_service->is_connected();

        echo '<div style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #dee2e6; margin: 20px 0; max-width: 600px;">';

        if ($is_connected) {
            // Connected User
            $user_info = $this->oauth_service->get_connected_user();
            $token = get_option('wpvtp_oauth_token');
            $refresh_token = get_option('wpvtp_refresh_token');

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
    }
    
    private function render_commits_queue_page()
    {
        echo '<div class="wpvtp-commits-queue">';
        echo '<h2>Commits Queue</h2>';
    
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvtp_commits_queue';
        
        $commits = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT 100",
            ARRAY_A
        );
    
        if (empty($commits)) {
            echo '<div class="notice notice-info"><p>Commits no has enqueue</p></div>';
            echo '</div>';
            return;
        }
    
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
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
            echo '<td>' . $commit['id'] . '</td>';
            echo '<td><code>' . basename($commit['theme_path']) . '</code></td>';
            echo '<td>' . esc_html(substr($commit['commit_message'], 0, 50)) . '</td>';
            echo '<td><span style="color: ' . ($status_class === 'success' ? 'green' : ($status_class === 'error' ? 'red' : 'orange')) . ';">' . $status_icon . ' ' . $commit['status'] . '</span></td>';
            echo '<td>' . $commit['attempts'] . '</td>';
            echo '<td>' . human_time_diff(strtotime($commit['created_at'])) . ' ago</td>';
            echo '<td>' . ($commit['last_error'] ? '<small>' . esc_html(substr($commit['last_error'], 0, 100)) . '</small>' : '-') . '</td>';
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
}