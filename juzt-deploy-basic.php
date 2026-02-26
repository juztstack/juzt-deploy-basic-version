<?php

/**
 * Plugin Name: Juzt Deploy Basic
 * Plugin URI: https://github.com/jesusuzcategui/juzt-deploy-basic
 * Description: WordPress theme and plugin version control. Allows you to preview cloned themes without activating them.
 * Version: 1.16.0
 * Author: Jesus Uzcategui
 * Author URI: https://github.com/jesusuzcategui
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: juzt-deploy-basic
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

$plugin_data = get_file_data(__FILE__, array(
    'Name' => 'Plugin Name',
    'Version' => 'Version',
    'RequiresWP' => 'Requires at least',
    'RequiresPHP' => 'Requires PHP'
));

// Definir constantes del plugin
define('JUZT_DEPLOY_BASIC_VERSION', $plugin_data['Version']);
define('JUZT_DEPLOY_BASIC_PLUGIN_FILE', __FILE__);
define('JUZT_DEPLOY_BASIC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('JUZT_DEPLOY_BASIC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('JUZT_DEPLOY_BASIC_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Verificar versión mínima de WordPress
if (version_compare(get_bloginfo('version'), '5.0', '<')) {
    add_action('admin_notices', 'JUZT_DEPLOY_BASIC_wordpress_version_notice');
    return;
}

// Verificar versión mínima de PHP
if (version_compare(PHP_VERSION, '7.4', '<')) {
    add_action('admin_notices', 'JUZT_DEPLOY_BASIC_php_version_notice');
    return;
}

/**
 * Aviso de versión de WordPress no compatible
 */
function JUZT_DEPLOY_BASIC_wordpress_version_notice()
{
    echo '<div class="notice notice-error"><p>';
    echo __('Juzt deploy requiere WordPress 5.0 o superior.', 'juzt-deploy-basic');
    echo '</p></div>';
}

/**
 * Aviso de versión de PHP no compatible
 */
function JUZT_DEPLOY_BASIC_php_version_notice()
{
    echo '<div class="notice notice-error"><p>';
    echo __('Juzt Deploy requiere PHP 7.4 o superior.', 'juzt-deploy-basic');
    echo '</p></div>';
}

/**
 * Clase principal del plugin
 */
class WP_Versions_Themes_Plugins
{

    /**
     * Instancia singleton
     */
    private static $instance = null;
    private $cron_job;

    /**
     * Obtener instancia singleton
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor privado para singleton
     */
    private function __construct()
    {
        $this->init();
    }

    /**
     * Inicializar plugin
     */
    private function init()
    {
        // Hook de inicialización
        add_action('init', array($this, 'load_plugin_textdomain'));

        // Hooks de admin
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

            // Los handlers AJAX ahora se registran en la clase JUZT_DEPLOY_BASIC_AJAX_Handlers
            require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-ajax-handlers.php';
            new JUZT_DEPLOY_BASIC_AJAX_Handlers();

            // NUEVO: Ejecutar migración si es necesario
            add_action('admin_init', array($this, 'maybe_run_migration'));
        }

        // Hook para preview de temas
        add_action('setup_theme', array($this, 'handle_theme_preview'), 1);

        // AJAX para salir del preview
        add_action('wp_ajax_JUZT_DEPLOY_BASIC_exit_preview', array($this, 'ajax_exit_preview'));
        add_action('wp_ajax_nopriv_JUZT_DEPLOY_BASIC_exit_preview', array($this, 'ajax_exit_preview'));
        add_action('JUZT_DEPLOY_BASIC_auto_commit', array($this, 'handle_auto_commit'), 10, 2);

        // Hooks de activación y desactivación
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Nuevo handler para el callback del middleware
        add_action('admin_init', array($this, 'handle_middleware_callback'));

        add_filter('commit_from_plugin', array($this, 'commit_from_plugin') ,10,4);

        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-cronjob.php';

        $this->cron_job = new JUZT_DEPLOY_BASIC_CronJob('every_four_hours', array($this, 'refresh_oauth_token'));


    }

    public function refresh_oauth_token()
    {
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-oauth-service.php';
        $oauth_service = new JUZT_DEPLOY_BASIC_OAuth_Service();
        $oauth_service->refresh_access_token();
    }

    /**
     * Add Commit filter
     */

    public function commit_from_plugin($result, $theme_path, $commit_message, $file_path = null){
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new JUZT_DEPLOY_BASIC_Repo_Manager();

        error_log('🔄 from direct plugin called - Stack: ' . wp_debug_backtrace_summary());
        error_log('🔄 from direct plugin called - theme_path: ' . $file_path);
        return $repo_manager->queue_commit($theme_path, $commit_message, $file_path);
    }

    /**
     * NUEVO: Ejecutar migración si es necesario
     */
    /**
     * Verificar y ejecutar migraciones/actualizaciones de BD
     */
    public function maybe_run_migration()
    {
        $current_version = get_option('JUZT_DEPLOY_BASIC_db_version', '0');
        $required_version = '1.8.0'; // Cambiar este número cuando hagas cambios en la BD

        // Si la versión de la BD es menor que la requerida, actualizar
        if (version_compare($current_version, $required_version, '<')) {
            $this->update_database_structure();
        }
    }
    /**
     * Actualizar estructura de la base de datos
     */
    private function update_database_structure()
    {
        global $wpdb;

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $table_name = $wpdb->prefix . 'github_repos';
        $table_name_commits = $wpdb->prefix . 'JUZT_DEPLOY_BASIC_commits_queue';
        $charset_collate = $wpdb->get_charset_collate();

        // Tabla de repositorios
        $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        repo_name varchar(255) NOT NULL,
        repo_url varchar(500) NOT NULL,
        local_path varchar(500) NOT NULL,
        folder_name varchar(255) NULL,
        current_branch varchar(255) NOT NULL,
        repo_type varchar(20) NOT NULL,
        last_update datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY local_path (local_path)
    ) $charset_collate;";

        // Tabla de cola de commits
        $sql_commit = "CREATE TABLE $table_name_commits (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        theme_path varchar(255) NOT NULL,
        commit_message text NOT NULL,
        file_path varchar(500) NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        attempts int(11) NOT NULL DEFAULT 0,
        last_error text NULL,
        created_at datetime NOT NULL,
        processed_at datetime NULL,
        PRIMARY KEY (id),
        KEY status (status),
        KEY created_at (created_at)
    ) $charset_collate;";

        // dbDelta actualizará las tablas automáticamente si hay cambios
        dbDelta($sql);
        dbDelta($sql_commit);

        // Migrar datos antiguos si es necesario
        $this->migrate_repo_data();

        // Actualizar versión de la BD
        update_option('JUZT_DEPLOY_BASIC_db_version', '1.9.0');

        // Mostrar notificación
        add_action('admin_notices', function () {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Juzt Deploy:</strong> Base de datos actualizada correctamente.</p>';
            echo '</div>';
        });
    }

    /**
     * Migrar datos de versiones antiguas
     */
    private function migrate_repo_data()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'github_repos';

        // Verificar si la columna folder_name existe
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$table_name} LIKE 'folder_name'"
        );

        // Si existe la columna, migrar registros sin folder_name
        if (!empty($column_exists)) {
            $repos = $wpdb->get_results(
                "SELECT * FROM {$table_name} WHERE folder_name IS NULL OR folder_name = ''",
                ARRAY_A
            );

            foreach ($repos as $repo) {
                $folder_name = basename($repo['local_path']);

                $wpdb->update(
                    $table_name,
                    array('folder_name' => $folder_name),
                    array('id' => $repo['id']),
                    array('%s'),
                    array('%d')
                );
            }

            if (count($repos) > 0) {
                error_log('WPVTP: Migrados ' . count($repos) . ' registros a la nueva estructura');
            }
        }
    }

    /**
     * Manejar callback desde el middleware
     */
    public function handle_middleware_callback()
    {
        //var_dump($_GET); // Debug: Verificar parámetros recibidos
        if (!isset($_GET['page']) || $_GET['page'] !== 'juzt-deploy-basic' || !isset($_GET['access_token']) || !isset($_GET['refresh_token'])) {
            return;
        }


        try {
            $session_token = sanitize_text_field($_GET['access_token']);
            $refresh_token = sanitize_text_field($_GET['refresh_token']);
            update_option('JUZT_DEPLOY_BASIC_oauth_token', $session_token);
            update_option('JUZT_DEPLOY_BASIC_refresh_token', $refresh_token);
            update_option('JUZT_DEPLOY_BASIC_token_last_refresh', time());

            // Redirigir a la página de settings para limpiar la URL del token
            $url = admin_url('admin.php?page=juzt-deploy-basic&tab=settings');
            $url = add_query_arg(array(
                'JUZT_DEPLOY_BASIC_message' => urlencode('Conectado exitosamente a GitHub'),
                'JUZT_DEPLOY_BASIC_message_type' => 'success'
            ), $url);

            wp_redirect($url);
            exit;
        } catch (Exception $e) {
            error_log('WPVTP: EXCEPCIÓN en handle_middleware_callback: ' . $e->getMessage());
            $this->redirect_with_error('Error interno durante la autenticación: ' . $e->getMessage());
        }
    }

    /**
     * Función helper para redirigir con error
     */
    private function redirect_with_error($error_message)
    {
        $url = admin_url('admin.php?page=juzt-deploy-basic&tab=settings');
        $url = add_query_arg(array(
            'JUZT_DEPLOY_BASIC_message' => urlencode($error_message),
            'JUZT_DEPLOY_BASIC_message_type' => 'error'
        ), $url);

        wp_redirect($url);
        exit;
    }

    /**
     * Cargar idiomas del plugin
     */
    public function load_plugin_textdomain()
    {
        load_plugin_textdomain(
            'juzt-deploy-basic',
            false,
            dirname(JUZT_DEPLOY_BASIC_PLUGIN_BASENAME) . '/languages'
        );
    }

    /**
     * Agregar menú de administración
     */
    public function add_admin_menu()
    {
        add_menu_page(
            __('Juzt Deploy', 'juzt-deploy-basic'), // Page title
            __('Juzt Deploy', 'juzt-deploy-basic'), // Menu title
            'manage_options', // Capability
            'juzt-deploy-basic', // Menu slug
            array($this, 'admin_page'), // Function
            'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path d="M10 2.5s-3.5 4.5-3.5 9c0 2 1.5 3.5 3.5 3.5s3.5-1.5 3.5-3.5c0-4.5-3.5-9-3.5-9zm-1.2 13.3c-1.5.8-2 2.2-2.3 3.2h1.5l.5-1.5.5 1.5h2l.5-1.5.5 1.5h1.5c-.3-1-.8-2.4-2.3-3.2-.8 0-2.4 0-3.4 0z"/></svg>'),
            30 // Position
        );
    }

    /**
     * Manejador para la acción del builder.
     *
     * @param string $repo_path      La ruta local del repositorio.
     * @param string $commit_message El mensaje para el commit.
     */
    public function handle_auto_commit($repo_path, $commit_message)
    {
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new JUZT_DEPLOY_BASIC_Repo_Manager();
        $result = $repo_manager->commit_and_push_changes($repo_path, $commit_message);

        // Opcional: Manejar el resultado del commit (ej: logear o mostrar una notificación)
        if ($result['success']) {
            error_log('WP Versions: Commit exitoso para el repositorio ' . $repo_path);
        } else {
            error_log('WP Versions: Error al hacer commit: ' . $result['error']);
        }
    }

    /**
     * Encolar scripts y estilos del admin
     */
    public function enqueue_admin_scripts($hook)
    {
        // Solo cargar en nuestra página
        if ('toplevel_page_juzt-deploy-basic' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'wpvtp-admin-style',
            JUZT_DEPLOY_BASIC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            JUZT_DEPLOY_BASIC_VERSION
        );

        wp_enqueue_script(
            'wpvtp-admin-script',
            JUZT_DEPLOY_BASIC_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            JUZT_DEPLOY_BASIC_VERSION,
            true
        );

        wp_localize_script('wpvtp-admin-script', 'JUZT_DEPLOY_BASIC_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('JUZT_DEPLOY_BASIC_nonce'),
            'admin_url' => admin_url('admin.php?page=juzt-deploy-basic'),
        ));
    }

    /**
     * Página principal de administración
     */
    public function admin_page()
    {
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-admin-interface.php';
        $admin_interface = new JUZT_DEPLOY_BASIC_Admin_Interface();
        $admin_interface->render_admin_page();
    }

    /**
     * Manejar preview de temas
     */
    public function handle_theme_preview()
    {
        // No aplicar preview en admin
        if (is_admin()) {
            return;
        }

        // ✅ NUEVO: Verificar headers ANTES de session_start
        if (headers_sent($file, $line)) {
            error_log("Headers already sent in $file on line $line");
            return;
        }

        // Si no hay parámetro wpvtheme y no hay sesión activa, salir
        if (!isset($_GET['wpvtheme'])) {
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            if (isset($_SESSION['JUZT_DEPLOY_BASIC_preview_theme'])) {
                $theme_handle = $_SESSION['JUZT_DEPLOY_BASIC_preview_theme'];
                session_write_close();
                error_log('WPVTP Preview: Aplicando tema desde sesión: ' . $theme_handle);
                $this->apply_theme_preview($theme_handle);
                // ✅ AGREGAR ESTA LÍNEA - Mostrar barra también cuando viene de sesión
                //add_action('wp_footer', array($this, 'add_preview_bar'));
            }
            return;
        }

        $theme_handle = sanitize_text_field($_GET['wpvtheme']);
        error_log('WPVTP Preview: Theme handle recibido: ' . $theme_handle);

        // Verificar que el theme existe
        $theme_path = get_theme_root() . '/' . $theme_handle;
        error_log('WPVTP Preview: Buscando tema en: ' . $theme_path);
        error_log('WPVTP Preview: Directorio existe: ' . (is_dir($theme_path) ? 'SI' : 'NO'));

        if (!is_dir($theme_path)) {
            error_log('WPVTP Preview: ERROR - Directorio del tema no existe');
            return;
        }

        // Verificar archivos del tema
        $style_exists = file_exists($theme_path . '/style.css');
        $index_exists = file_exists($theme_path . '/index.php');
        error_log('WPVTP Preview: style.css existe: ' . ($style_exists ? 'SI' : 'NO'));
        error_log('WPVTP Preview: index.php existe: ' . ($index_exists ? 'SI' : 'NO'));

        if (!$style_exists && !$index_exists) {
            error_log('WPVTP Preview: ERROR - Tema no tiene archivos requeridos');
            return;
        }

        // Continuar con el preview...
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['JUZT_DEPLOY_BASIC_preview_theme'] = $theme_handle;
        session_write_close();

        error_log('WPVTP Preview: Aplicando tema: ' . $theme_handle);
        $this->apply_theme_preview($theme_handle);



        add_action('wp_head', function () use ($theme_handle) {
            error_log('WPVTP Preview: Tema final aplicado - stylesheet: ' . get_stylesheet());
            error_log('WPVTP Preview: Tema final aplicado - template: ' . get_template());
            error_log('WPVTP Preview: Esperado: ' . $theme_handle);
        });

        add_action('wp_footer', array($this, 'add_preview_bar'));
    }

    /**
     * Aplicar filtros para preview de tema
     */
    private function apply_theme_preview($theme_handle)
    {
        error_log('WPVTP Preview: Aplicando filtros para tema: ' . $theme_handle);

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');
        }

        // Aplicar filtros con prioridad muy alta para que se ejecuten antes
        add_filter('stylesheet', function () use ($theme_handle) {
            return $theme_handle;
        }, 1);

        add_filter('template', function () use ($theme_handle) {
            return $theme_handle;
        }, 1);

        // Forzar recarga de datos del tema
        add_filter('pre_option_stylesheet', function () use ($theme_handle) {
            return $theme_handle;
        });

        add_filter('pre_option_template', function () use ($theme_handle) {
            return $theme_handle;
        });
    }

    /**
     * Agregar barra de preview
     */
    public function add_preview_bar()
    {
        if (isset($_GET['from_studio'])) {
            return;
        }

        if (!isset($_SESSION['JUZT_DEPLOY_BASIC_preview_theme'])) {
            return;
        }

        $theme_handle = $_SESSION['JUZT_DEPLOY_BASIC_preview_theme'];
        $admin_url = admin_url('admin.php?page=juzt-deploy-basic');
        $exit_url = remove_query_arg('wpvtheme');

        echo '<div id="wpvtp-preview-bar" style="
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #6433ff;
            color: white;
            padding: 10px;
            z-index: 999999;
            font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif;
            box-shadow:0 1px 2px rgba(0,0,0,0.8);
            font-size:12px;
        ">';
        echo '<div style="display: flex; align-items: center; justify-content: space-between;">';
        echo '<div>';
        echo '<strong>Preview:</strong> ' . esc_html($theme_handle);
        echo '</div>';
        echo '<div>';
        echo '<a href="' . esc_url($admin_url) . '" style="color: white; text-decoration: none; margin-right: 15px;">Manage</a>';
        echo '<a href="' . esc_url($exit_url) . '" style="color: white; text-decoration: none;" onclick="JUZT_DEPLOY_BASIC_exit_preview()">Exit</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<script>
        function JUZT_DEPLOY_BASIC_exit_preview() {
            fetch("' . admin_url('admin-ajax.php') . '", {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: "action=JUZT_DEPLOY_BASIC_exit_preview&nonce=' . wp_create_nonce('JUZT_DEPLOY_BASIC_exit_preview') . '"
            }).then(() => {
                setTimeout(function(){
                    window.location.href = "' . esc_url($exit_url) . '";
                }, 3000);
            });
        }
        
        // Ajustar body margin para la barra
        document.body.style.marginTop = "50px";
        </script>';
    }

    /**
     * Activación del plugin
     */
    public function activate()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/class-repo-manager.php';
        $repo_manager = new JUZT_DEPLOY_BASIC_Repo_Manager();
        $repo_manager->detect_git_mode();

        // Usar el nuevo método de actualización
        $this->update_database_structure();

        // Crear directorio de assets si no existe
        $upload_dir = wp_upload_dir();
        $JUZT_DEPLOY_BASIC_dir = $upload_dir['basedir'] . '/juzt-deploy-basic';
        if (!file_exists($JUZT_DEPLOY_BASIC_dir)) {
            wp_mkdir_p($JUZT_DEPLOY_BASIC_dir);
        }

        flush_rewrite_rules();
    }

    /**
     * Desactivación del plugin
     */
    public function deactivate()
    {
        // Limpiar sesiones de preview
        if (session_status() !== PHP_SESSION_NONE) {
            unset($_SESSION['JUZT_DEPLOY_BASIC_preview_theme']);
        }

        // Limpiar datos OAuth temporales
        delete_transient('JUZT_DEPLOY_BASIC_oauth_nonce');
        //crone
        wp_clear_scheduled_hook('JUZT_DEPLOY_BASIC_auto_refresh_token');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * AJAX: Salir del preview
     */
    public function ajax_exit_preview()
    {
        check_ajax_referer('JUZT_DEPLOY_BASIC_exit_preview', 'nonce');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        unset($_SESSION['JUZT_DEPLOY_BASIC_preview_theme']);
        session_write_close();

        wp_send_json_success();
    }
}

// Inicializar plugin
function JUZT_DEPLOY_BASIC_init()
{
    return WP_Versions_Themes_Plugins::get_instance();
}

// Cargar plugin
JUZT_DEPLOY_BASIC_init();

// Mejorar el sistema de preview de temas
add_action('init', function () {
    if (session_status() === PHP_SESSION_NONE) {
        if (headers_sent($file, $line)) {
        session_start();
        }
    }

    // Mantener preview en navegación solo si no hay parámetro wpvtheme
    if (isset($_SESSION['JUZT_DEPLOY_BASIC_preview_theme']) && !isset($_GET['wpvtheme'])) {
        $theme_handle = $_SESSION['JUZT_DEPLOY_BASIC_preview_theme'];
        session_write_close();

        // Verificar que el tema aún existe
        $theme_path = get_theme_root() . '/' . $theme_handle;
        if (!is_dir($theme_path)) {
            unset($_SESSION['JUZT_DEPLOY_BASIC_preview_theme']);
            return;
        }

        add_filter('stylesheet', function () use ($theme_handle) {
            return $theme_handle;
        }, 20);

        add_filter('template', function () use ($theme_handle) {
            return $theme_handle;
        }, 20);
    }
});
