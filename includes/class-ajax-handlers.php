<?php

/**
 * AJAX Handlers Class
 *
 * Maneja todas las peticiones AJAX del plugin.
 * Actualizado con soporte para GitHub Apps e Installation Tokens.
 *
 * @package WP_Versions_Plugins_Themes
 * @since 1.7.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class WPVTP_AJAX_Handlers
{

    /**
     * Instancias de clases
     */
    private $oauth_service;
    private $repo_manager;

    /**
     * Constructor
     */
    public function __construct()
    {
        // Incluir clases necesarias
        require_once WPVTP_PLUGIN_DIR . 'includes/class-oauth-service.php';
        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';

        $this->oauth_service = new WPVTP_OAuth_Service();
        $this->repo_manager = new WPVTP_Repo_Manager();

        // Registrar hooks AJAX
        add_action('wp_ajax_wpvtp_get_organizations', array($this, 'get_organizations'));
        add_action('wp_ajax_wpvtp_get_repositories', array($this, 'get_repositories'));
        add_action('wp_ajax_wpvtp_get_branches', array($this, 'get_branches'));
        add_action('wp_ajax_wpvtp_detect_repo_type', array($this, 'detect_repo_type'));
        add_action('wp_ajax_wpvtp_clone_repository', array($this, 'clone_repository'));
        add_action('wp_ajax_wpvtp_update_repository', array($this, 'update_repository'));
        add_action('wp_ajax_wpvtp_switch_branch', array($this, 'switch_branch'));
        add_action('wp_ajax_wpvtp_remove_repository', array($this, 'remove_repository'));
        add_action('wp_ajax_wpvtp_disconnect_github', array($this, 'ajax_disconnect_github'));
        add_action('wp_ajax_wpvtp_download_wp_content', array($this, 'download_wp_content'));
        add_action('wp_ajax_wpvtp_serve_zip', array($this, 'serve_zip_file'));
        add_action('wp_ajax_wpvtp_retry_commit', array($this, 'retry_commit'));
        add_action('wp_ajax_wpvtp_delete_commit', array($this, 'delete_commit'));
        add_action('wp_ajax_wpvtp_get_progress', array($this, 'get_progress'));
        add_action('wp_ajax_wpvtp_push_all_changes', array($this, 'push_all_changes'));
        add_action('wp_ajax_wpvtp_get_repositories_by_installation', array($this, 'get_repositories_by_installation'));
        add_action('wp_ajax_wpvtp_search_repositories_by_name', array($this, 'wpvtp_search_repositories_by_name'));
        add_action('wp_ajax_wpvtp_bulk_delete_commits', array($this, 'bulk_delete_commits'));
    }

    public function wpvtp_search_repositories_by_name()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        $term = sanitize_text_field($_POST['term']);
        $owner = sanitize_text_field($_POST['owner']);

        if (!$this->oauth_service->is_connected()) {
            wp_send_json_error('No hay token de GitHub configurado.');
            return;
        }

        $result = $this->oauth_service->search_repositories_by_name($owner, $term );

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    public function get_repositories_by_installation(){
        //check_ajax_referer('wpvtp_nonce', 'nonce');

        $installation_id = intval($_POST['installation_id']);

        if (!$this->oauth_service->is_connected()) {
            wp_send_json_error('No hay token de GitHub configurado.');
            return;
        }

        $result = $this->oauth_service->get_repositories_by_installation($installation_id);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * Reintentar commit desde la cola
     */
    public function retry_commit()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        $commit_id = intval($_POST['commit_id']);

        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new WPVTP_Repo_Manager();

        $result = $repo_manager->process_commit_queue_item($commit_id);

        wp_send_json($result);
    }

    /**
     * Eliminar commit de la cola
     */
    public function delete_commit()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        global $wpdb;
        $commit_id = intval($_POST['commit_id']);
        $table_name = $wpdb->prefix . 'wpvtp_commits_queue';

        $deleted = $wpdb->delete($table_name, array('id' => $commit_id), array('%d'));

        if ($deleted) {
            wp_send_json_success('Commit eliminado');
        } else {
            wp_send_json_error('Error al eliminar');
        }
    }

    /**
     * Obtener organizaciones y el usuario personal.
     * 
     * ACTUALIZADO: Ahora usa las instalaciones de la GitHub App en lugar de
     * las organizaciones del usuario. Esto permite ver solo las organizaciones
     * donde la app está instalada.
     */
    public function get_organizations()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        if (!$this->oauth_service->is_connected()) {
            wp_send_json_error('No hay token de GitHub configurado');
            return;
        }

        // Obtener información del usuario
        $user_result = $this->oauth_service->get_user_info();

        if (!$user_result['user']['success'] || !$user_result['orgs']['success']) {
            wp_send_json_error('Error al obtener información del usuario: ' . $user_result['error']);
            return;
        }

        // Obtener instalaciones de la GitHub App
        $installations_result = $this->oauth_service->get_installations();


        if (!$installations_result['success']) {
            // Si falla, usar las organizaciones del usuario como fallback
            $organizations = isset($user_result['data']['orgs']) ? $user_result['data']['orgs'] : array();
        } else {
            // Convertir instalaciones a formato de organizaciones
            $organizations = array();

            if (isset($installations_result['data']['installations'])) {
                foreach ($installations_result['data']['installations'] as $installation) {
                    $account = $installation['account'];
                    $organizations[] = array(
                        'login' => $account['login'],
                        'type' => $account['type'],
                        'avatar_url' => isset($user_result['user']['data']['avatar_url']) ? $user_result['user']['data']['avatar_url'] : '',
                        'description' => $installation['target_type'] === 'User' ? 'Tu cuenta personal' : 'Organización',
                        'installation_id' => $installation['id'] // Guardar ID de instalación
                    );
                }
            }
        }

        // Agregar el usuario personal si no está en las instalaciones
        $user_login = $user_result['user']['data']['login'];
        $user_exists = false;

        foreach ($organizations as $org) {
            if ($org['login'] === $user_login) {
                $user_exists = true;
                break;
            }
        }

        if (!$user_exists) {
            array_unshift($organizations, array(
                'login' => $user_login,
                'type' => 'User',
                'avatar_url' => isset($user_result['user']['data']['avatar_url']) ? $user_result['user']['data']['avatar_url'] : '',
                'description' => 'Tu cuenta personal'
            ));
        }

        // Guardar información del usuario para uso posterior
        update_option('wpvtp_github_user', $user_result['user']['data']);

        wp_send_json_success($organizations);
    }

    /**
     * Obtener repositorios.
     * 
     * ACTUALIZADO: Ahora usa el nuevo método get_all_repositories() que incluye
     * repositorios privados de organizaciones mediante GitHub App Installations.
     * 
     * Para mantener compatibilidad con el frontend, filtra los repositorios
     * por el owner seleccionado.
     */
    public function get_repositories()
    {
        //check_ajax_referer('wpvtp_nonce', 'nonce');

        $owner = sanitize_text_field($_POST['owner']);
        $type = sanitize_text_field($_POST['type']);

        if (!$this->oauth_service->is_connected()) {
            wp_send_json_error('No hay token de GitHub configurado.');
            return;
        }

        // Obtener TODOS los repositorios usando el nuevo método
        $result = $this->oauth_service->get_all_repositories();

        if (!$result['success']) {
            wp_send_json_error($result['error']);
            return;
        }

        // Filtrar repositorios por el owner seleccionado
        $filtered_repos = array();

        if (isset($result['data']['installations'])) {
            foreach ($result['data']['installations'] as $installation) {
                $installation_owner = $installation['installation']['account']['login'];

                if ($installation_owner === $owner) {
                    $filtered_repos = $installation['repositories'];
                    break;
                }
            }
        }

        // Si no encontramos repos en las instalaciones, intentar con el método tradicional
        // (útil para repos públicos o cuando el usuario no tiene la app instalada)
        if (empty($filtered_repos)) {
            $fallback_result = $this->oauth_service->get_repositories($owner, $type);

            if ($fallback_result['success']) {
                $filtered_repos = $fallback_result['data'];
            }
        }

        // Enriquecer información de los repositorios
        $enriched_repos = array();
        foreach ($filtered_repos as $repo) {
            $enriched_repos[] = array(
                'id' => $repo['id'],
                'name' => $repo['name'],
                'full_name' => $repo['full_name'],
                'private' => $repo['private'],
                'description' => isset($repo['description']) ? $repo['description'] : '',
                'clone_url' => $repo['clone_url'],
                'default_branch' => $repo['default_branch'],
                'owner' => array(
                    'login' => $repo['owner']['login'],
                    'type' => isset($repo['owner']['type']) ? $repo['owner']['type'] : 'User'
                ),
                // Información adicional útil
                'html_url' => isset($repo['html_url']) ? $repo['html_url'] : '',
                'created_at' => isset($repo['created_at']) ? $repo['created_at'] : '',
                'updated_at' => isset($repo['updated_at']) ? $repo['updated_at'] : '',
                'language' => isset($repo['language']) ? $repo['language'] : null,
            );
        }

        wp_send_json_success($enriched_repos);
    }

    /**
     * Obtener ramas de un repositorio.
     */
    public function get_branches()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        $owner = sanitize_text_field($_POST['owner']);
        $repo = sanitize_text_field($_POST['repo']);

        if (!$this->oauth_service->is_connected()) {
            wp_send_json_error('No hay token de GitHub configurado.');
            return;
        }

        $result = $this->oauth_service->get_branches($owner, $repo);

        if ($result['success']) {
            wp_send_json_success($result['data']);
        } else {
            wp_send_json_error($result['error']);
        }
    }

    public function bulk_delete_commits(){
        check_ajax_referer('wpvtp_nonce', 'nonce');

        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvtp_commits_queue';

        $commit_ids = isset($_POST['commit_ids']) ? $_POST['commit_ids'] : array();

        $commit_ids = json_decode(stripslashes($commit_ids), true);

        if (empty($commit_ids) || !is_array($commit_ids)) {
            wp_send_json_error('No se proporcionaron IDs de commits válidos.');
            return;
        }

        // Sanitizar IDs
        $commit_ids = array_map('intval', $commit_ids);
        $placeholders = implode(',', array_fill(0, count($commit_ids), '%d'));

        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM $table_name WHERE id IN ($placeholders)", ...$commit_ids)
        );

        if ($deleted !== false) {
            wp_send_json_success('Commits eliminados: ' . $deleted);
        } else {
            wp_send_json_error('Error al eliminar commits.');
        }
    }

    /**
     * Clonar repositorio.
     */
    public function clone_repository()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new WPVTP_Repo_Manager();

        $repo_url = esc_url_raw($_POST['repo_url']);
        $branch = sanitize_text_field($_POST['branch']);
        $repo_type = sanitize_text_field($_POST['repo_type']);
        $repo_name = sanitize_text_field($_POST['repo_name']);
        $custom_name = isset($_POST['custom_name']) ? sanitize_text_field($_POST['custom_name']) : '';

        $access_token = get_option('wpvtp_oauth_token');

        // Generar job_id único
        $job_id = uniqid('clone_', true);

        $result = $repo_manager->clone_repository($repo_url, $branch, $repo_type, $repo_name, $custom_name, $access_token, $job_id);

        wp_send_json($result);
    }

    /**
     * Actualizar repositorio.
     */
    public function update_repository()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new WPVTP_Repo_Manager();

        $identifier = sanitize_text_field($_POST['identifier']);
        $access_token = get_option('wpvtp_oauth_token');

        $job_id = uniqid('update_', true);

        $result = $repo_manager->update_repository($identifier, $access_token, $job_id);

        wp_send_json($result);
    }

    /**
     * Cambiar rama de un repositorio.
     */
    public function switch_branch()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new WPVTP_Repo_Manager();

        $identifier = sanitize_text_field($_POST['identifier']);
        $new_branch = sanitize_text_field($_POST['new_branch']);

        $job_id = uniqid('switch_', true);

        $result = $repo_manager->switch_branch($identifier, $new_branch, $job_id);

        wp_send_json($result);
    }

    /**
     * Eliminar repositorio.
     */
    public function remove_repository()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new WPVTP_Repo_Manager();

        $identifier = sanitize_text_field($_POST['identifier']);  // ← CAMBIADO

        $result = $repo_manager->remove_repository($identifier);

        wp_send_json($result);
    }

    /**
     * Detectar tipo de repositorio.
     * 
     * DEPRECATED: Este método ya no se usa, la detección ahora se hace en el frontend.
     */
    public function detect_repo_type()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');
        wp_send_json_error('Este método ya no se usa, la detección ahora se hace en el frontend.');
    }

    /**
     * Desconectar de GitHub.
     */
    public function ajax_disconnect_github()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        // Limpiar tokens y datos del usuario
        delete_option('wpvtp_oauth_token');
        delete_option('wpvtp_refresh_token');
        delete_option('wpvtp_github_user');

        wp_send_json_success(array('message' => 'Desconectado de GitHub exitosamente'));
    }

    /**
     * Descargar wp-content como ZIP
     */
    public function download_wp_content()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        $zip_name = isset($_POST['zip_name']) ? sanitize_text_field($_POST['zip_name']) : '';

        if (empty($zip_name)) {
            wp_send_json_error('Nombre de archivo requerido');
            return;
        }

        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new WPVTP_Repo_Manager();

        $result = $repo_manager->create_wp_content_zip($zip_name);

        if ($result['success']) {
            // Devolver URL temporal para descargar
            wp_send_json_success(array(
                'download_url' => admin_url('admin-ajax.php?action=wpvtp_serve_zip&file=' . urlencode(basename($result['filepath'])) . '&nonce=' . wp_create_nonce('wpvtp_download')),
                'filename' => $result['filename'],
                'size' => size_format($result['size'])
            ));
        } else {
            wp_send_json_error($result['error']);
        }
    }

    /**
     * Servir archivo ZIP para descarga
     */
    public function serve_zip_file()
    {
        $nonce = isset($_GET['nonce']) ? $_GET['nonce'] : '';
        $file = isset($_GET['file']) ? sanitize_file_name($_GET['file']) : '';

        if (!wp_verify_nonce($nonce, 'wpvtp_download')) {
            wp_die('Acceso no autorizado');
        }

        // Usar el mismo directorio que create_wp_content_zip()
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'wpvtp-downloads/';
        $filepath = $temp_dir . $file;

        error_log('WPVTP: Attempting to serve ZIP: ' . $filepath);
        error_log('WPVTP: File exists: ' . (file_exists($filepath) ? 'yes' : 'no'));

        if (!file_exists($filepath)) {
            error_log('WPVTP: File not found at: ' . $filepath);
            error_log('WPVTP: Directory contents: ' . print_r(glob($temp_dir . '*'), true));
            wp_die('Archivo no encontrado. Path: ' . esc_html($filepath));
        }

        // Headers para descarga
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Pragma: no-cache');
        header('Expires: 0');

        // Limpiar buffer de salida
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Enviar archivo
        readfile($filepath);

        // Eliminar archivo temporal después de enviarlo
        @unlink($filepath);

        exit;
    }

    public function get_progress()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        $job_id = sanitize_text_field($_POST['job_id']);

        require_once WPVTP_PLUGIN_DIR . 'includes/class-progress-tracker.php';
        $progress = WPVTP_Progress_Tracker::get_progress($job_id);

        if ($progress) {
            wp_send_json_success($progress);
        } else {
            wp_send_json_error('No progress found');
        }
    }

    /**
     * Push all changes
     */
    public function push_all_changes()
    {
        check_ajax_referer('wpvtp_nonce', 'nonce');

        $identifier = sanitize_text_field($_POST['identifier']);
        $commit_message = isset($_POST['commit_message'])
            ? sanitize_text_field($_POST['commit_message'])
            : 'Update from local development';

        require_once WPVTP_PLUGIN_DIR . 'includes/class-repo-manager.php';
        $repo_manager = new WPVTP_Repo_Manager();

        $result = $repo_manager->push_all_changes($identifier, $commit_message);

        wp_send_json($result);
    }
}

// Inicializar handlers AJAX
new WPVTP_AJAX_Handlers();
