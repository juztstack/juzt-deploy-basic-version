<?php

/**
 * Admin Interface Class
 * * Maneja toda la interfaz de administración del plugin
 * * @package WP_Versions_Themes_Plugins
 * @since 1.3.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class WPVTP_Admin_Interface
{

    /**
     * Instancias de clases
     */
    private $repo_manager;
    private $oauth_service;

    /**
     * Página actual
     */
    private $current_page;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Incluir clases necesarias
        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        require_once WPVTP_PLUGIN_DIR . 'includes/class-oauth-service.php';

        $this->oauth_service = new WPVTP_OAuth_Service();
        $this->repo_manager = new WPVTP_Repo_Manager();
    }

    /**
     * Renderizar página principal de administración
     */
    public function render_admin_page()
    {
        $this->current_page = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'dashboard';

        echo '<div class="wrap wpvtp-admin">';
        echo '<h1>WP Versions Themes & Plugins</h1>';

        // Verificar Git
        if (!$this->repo_manager->is_git_available()) {
            $this->render_git_warning();
            echo '</div>';
            return;
        }

        // Renderizar tabs
        $this->render_navigation_tabs();

        // Renderizar contenido según tab
        switch ($this->current_page) {
            case 'install':
                $this->render_install_page();
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
     * Renderizar warning de Git no disponible
     */
    private function render_git_warning()
    {
        echo '<div class="notice notice-error">';
        echo '<h2>Git no disponible</h2>';
        echo '<p>Este plugin requiere que Git esté instalado en el servidor.</p>';
        echo '</div>';
    }

    /**
     * Renderizar tabs de navegación
     */
    private function render_navigation_tabs()
    {
        $tabs = array(
            'dashboard' => 'Dashboard',
            'install' => 'Instalar Repositorio',
            'settings' => 'Configuración'
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
     * Renderizar página de dashboard
     */
    private function render_dashboard_page()
    {
        echo '<div class="wpvtp-dashboard">';
        echo '<h2>Dashboard</h2>';

        if (!$this->oauth_service->is_connected()) {
            echo '<div class="notice notice-warning">';
            echo '<p>Para usar este plugin, primero debes <a href="' . admin_url('admin.php?page=wp-versions-themes-plugins&tab=settings') . '">conectar tu cuenta de GitHub</a>.</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        // Obtener repositorios instalados
        $repos = $this->repo_manager->get_installed_repos();
        $stats = $this->repo_manager->get_repo_stats();

        // Mostrar estadísticas
        echo '<div class="wpvtp-stats" style="display: flex; gap: 20px; margin-bottom: 30px;">';
        echo '<div class="wpvtp-stat-box" style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #0073aa; min-width: 150px;">';
        echo '<h3 style="margin: 0 0 10px 0;">' . $stats['total'] . '</h3>';
        echo '<p style="margin: 0; color: #666;">Total Repositorios</p>';
        echo '</div>';

        echo '<div class="wpvtp-stat-box" style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #00a32a; min-width: 150px;">';
        echo '<h3 style="margin: 0 0 10px 0;">' . $stats['themes'] . '</h3>';
        echo '<p style="margin: 0; color: #666;">Temas</p>';
        echo '</div>';

        echo '<div class="wpvtp-stat-box" style="background: white; padding: 20px; border-radius: 8px; border-left: 4px solid #d63638; min-width: 150px;">';
        echo '<h3 style="margin: 0 0 10px 0;">' . $stats['plugins'] . '</h3>';
        echo '<p style="margin: 0; color: #666;">Plugins</p>';
        echo '</div>';
        echo '</div>';

        // Tabla de repositorios
        if (!empty($repos)) {
            echo '<h3>Repositorios Instalados</h3>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>Nombre</th>';
            echo '<th>Tipo</th>';
            echo '<th>Rama Actual</th>';
            echo '<th>Última Actualización</th>';
            echo '<th>Estado</th>';
            echo '<th>Acciones</th>';
            echo '</tr></thead>';
            echo '<tbody>';

            foreach ($repos as $repo) {
                echo '<tr>';
                echo '<td><strong>' . esc_html($repo['repo_name']) . '</strong></td>';
                echo '<td>' . ($repo['repo_type'] === 'theme' ? '🎨 Tema' : '🔌 Plugin') . '</td>';
                echo '<td><code>' . esc_html($repo['current_branch']) . '</code></td>';
                echo '<td>' . human_time_diff(strtotime($repo['last_update'])) . ' ago</td>';

                if (!$repo['exists']) {
                    echo '<td><span style="color: #d63638;">❌ No existe</span></td>';
                } elseif (!$repo['has_git']) {
                    echo '<td><span style="color: #dba617;">⚠️ Sin Git</span></td>';
                } else {
                    echo '<td><span style="color: #00a32a;">✅ OK</span></td>';
                }

                echo '<td>';
                if ($repo['exists'] && $repo['has_git']) {
                    echo '<button class="button button-small wpvtp-update-repo" data-path="' . esc_attr($repo['local_path']) . '">Actualizar</button> ';
                    echo '<button class="button button-small wpvtp-switch-branch" data-path="' . esc_attr($repo['local_path']) . '" data-repo-url="' . esc_attr($repo['repo_url']) . '">Cambiar Rama</button> ';

                    if ($repo['repo_type'] === 'theme') {
                        $theme_handle = basename($repo['local_path']);
                        echo '<a href="' . home_url('?wpvtheme=' . $theme_handle) . '" target="_blank" class="button button-small">Preview</a> ';
                    }
                }
                echo '<button class="button button-small button-link-delete wpvtp-remove-repo" data-path="' . esc_attr($repo['local_path']) . '" data-name="' . esc_attr($repo['repo_name']) . '">Eliminar</button>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<div class="notice notice-info">';
            echo '<p>No tienes repositorios instalados aún. <a href="' . admin_url('admin.php?page=wp-versions-themes-plugins&tab=install') . '">Instala tu primer repositorio</a>.</p>';
            echo '</div>';
        }

        echo '</div>';
    }

    /**
     * Renderizar página de instalación
     */
    private function render_install_page()
    {
        echo '<div class="wpvtp-install">';
        echo '<h2>Instalar Repositorio desde GitHub</h2>';

        if (!$this->oauth_service->is_connected()) {
            echo '<div class="notice notice-warning">';
            echo '<p>Para instalar repositorios, primero debes <a href="' . admin_url('admin.php?page=wp-versions-themes-plugins&tab=settings') . '">conectar tu cuenta de GitHub</a>.</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        // Formulario de instalación (wizard)
        echo '<form id="wpvtp-install-form" style="background: white; padding: 30px; border-radius: 10px; margin: 20px 0;">';

        // Paso 1: Organización
        echo '<div class="wpvtp-form-step" id="step-organization">';
        echo '<h3>1. Selecciona Organización/Usuario</h3>';
        echo '<select id="wpvtp-organization" style="width: 100%; max-width: 400px; padding: 8px;">';
        echo '<option value="">Cargando organizaciones...</option>';
        echo '</select>';
        echo '</div>';

        // Paso 2: Repositorio
        echo '<div class="wpvtp-form-step" id="step-repository" style="display: none; margin-top: 30px;">';
        echo '<h3>2. Selecciona Repositorio</h3>';
        echo '<select id="wpvtp-repository" style="width: 100%; max-width: 400px; padding: 8px;" disabled>';
        echo '<option value="">Primero selecciona una organización</option>';
        echo '</select>';
        echo '<div id="wpvtp-repo-info" style="display: none; margin-top: 15px; padding: 15px; background: #f8f9fa; border-radius: 5px;">';
        echo '<h4>Información del Repositorio</h4>';
        echo '<p><strong>Descripción:</strong> <span id="repo-description"></span></p>';
        echo '<p><strong>Tipo detectado:</strong> <span id="repo-type"></span></p>';
        echo '</div>';
        echo '</div>';

        // Paso 3: Rama
        echo '<div class="wpvtp-form-step" id="step-branch" style="display: none; margin-top: 30px;">';
        echo '<h3>3. Selecciona Rama</h3>';
        echo '<select id="wpvtp-branch" style="width: 100%; max-width: 400px; padding: 8px;" disabled>';
        echo '<option value="">Primero selecciona un repositorio</option>';
        echo '</select>';
        echo '</div>';

        // Paso 4: Nombre personalizado (NUEVO)
        echo '<div class="wpvtp-form-step" id="step-custom-name" style="display: none; margin-top: 30px;">';
        echo '<h3>4. Nombre Personalizado (Opcional)</h3>';
        echo '<input type="text" id="wpvtp-custom-name" style="width: 100%; max-width: 400px; padding: 8px;" placeholder="Déjalo vacío para usar el nombre del repositorio">';
        echo '<p class="description" style="margin-top: 8px; color: #666; font-size: 13px;">Para temas: Este nombre aparecerá como "Theme Name" en el style.css. Útil para diferenciar múltiples versiones.</p>';
        echo '</div>';

        // Paso 5: Confirmación
        echo '<div class="wpvtp-form-step" id="step-confirm" style="display: none; margin-top: 30px;">';
        echo '<h3>5. Confirmar Instalación</h3>';
        echo '<div id="wpvtp-install-summary"></div>';
        echo '<button type="submit" class="button button-primary button-large" style="margin-top: 20px;">Instalar Repositorio</button>';
        echo '</div>';

        echo '</form>';

        // Resultados de instalación
        echo '<div id="wpvtp-install-results" style="display: none; padding: 20px; border-radius: 8px; margin: 20px 0;"></div>';

        echo '</div>';
    }

    /**
     * En class-admin-interface.php, actualizar la página de settings
     */
    public function render_settings_page()
    {
        echo '<div class="wpvtp-settings">';
        echo '<h1>Configuración GitHub</h1>';

        // Mostrar mensajes
        if (isset($_GET['wpvtp_message'])) {
            $message = urldecode($_GET['wpvtp_message']);
            $type = isset($_GET['wpvtp_message_type']) ? $_GET['wpvtp_message_type'] : 'info';
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }

        $is_connected = $this->oauth_service->is_connected();

        echo '<div style="background: white; padding: 25px; border-radius: 10px; border: 1px solid #dee2e6; margin: 20px 0; max-width: 600px;">';

        if ($is_connected) {
            // Usuario conectado
            $user_info = $this->oauth_service->get_connected_user();
            $token = get_option('wpvtp_oauth_token');
            $refresh_token = get_option('wpvtp_refresh_token');

            echo '<div class="wpvtp-connection-status connected" style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 8px;">';
            echo '<h3>✅ Conectado a GitHub</h3>';

            if ($user_info) {
                echo '<div style="display: flex; align-items: center; margin: 15px 0;">';
                if (isset($user_info['avatar_url'])) {
                    echo '<img src="' . esc_url($user_info['avatar_url']) . '" style="width: 50px; height: 50px; border-radius: 50%; margin-right: 15px;">';
                }
                echo '<div>';
                echo '<p style="margin: 5px 0;"><strong>Usuario:</strong> ' . esc_html($user_info['login']) . '</p>';
                echo '<p style="margin: 5px 0;"><strong>Nombre:</strong> ' . esc_html($user_info['name'] ?: 'No especificado') . '</p>';
                echo '</div>';
                echo '</div>';
            }

            // DEBUG INFO - Solo mostrar en desarrollo
            if (defined('WP_DEBUG') && WP_DEBUG) {
                echo '<div style="background: #f8f9fa; padding: 10px; margin: 10px 0; border-radius: 4px; font-size: 12px;">';
                echo '<strong>Debug Info:</strong><br>';
                echo 'Token: ' . (substr($token, 0, 10) . '...') . '<br>';
                echo 'Refresh Token: ' . (substr($refresh_token, 0, 10) . '...') . '<br>';
                echo '</div>';
            }

            echo '<button type="button" class="button button-secondary" onclick="disconnectGitHub()">Desconectar de GitHub</button>';
            echo '</div>';
        } else {
            // Usuario no conectado
            echo '<h3>🔗 Conectar con GitHub</h3>';
            echo '<p>Para usar este plugin, necesitas conectar tu cuenta de GitHub.</p>';
            echo '<p>Esto te permitirá acceder a tus repositorios y organizaciones.</p>';

            echo '<div style="margin: 20px 0;">';
            echo '<a href="' . esc_url($this->oauth_service->get_authorization_url()) . '" class="button button-primary button-large">🔗 Conectar con GitHub App</a>';
            echo '</div>';

            echo '<div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin-top: 20px;">';
            echo '<h4>🔒 Información de Privacidad</h4>';
            echo '<ul style="margin: 10px 0;">';
            echo '<li>Solo accedemos a tus repositorios autorizados</li>';
            echo '<li>No almacenamos tu contraseña de GitHub</li>';
            echo '<li>Puedes revocar el acceso en cualquier momento</li>';
            echo '<li>La conexión es segura y encriptada</li>';
            echo '<li>Usando GitHub App para mayor seguridad</li>';
            echo '</ul>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }
}