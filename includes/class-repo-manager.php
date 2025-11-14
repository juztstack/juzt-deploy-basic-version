<?php

/**
 * Repository Manager Class
 * 
 * Maneja todas las operaciones Git locales y gestión de repositorios
 * 
 * @package WP_Versions_Plugins_Themes
 * @since 1.7.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class WPVTP_Repo_Manager
{

    /**
     * Tabla de base de datos
     */
    private $table_name;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'github_repos';

        require_once WPVTP_PLUGIN_DIR . 'includes/class-git-interface.php';
        require_once WPVTP_PLUGIN_DIR . 'includes/class-git-cli.php';
        require_once WPVTP_PLUGIN_DIR . 'includes/class-git-api.php';

        add_action('wpvtp_queue_commit', array($this, 'handle_queue_commit_action'), 10, 2);
        add_filter('wpvtp_queue_commit', array($this, 'handle_queue_commit_filter'), 10, 4);
    }

    // AGREGAR nuevo método:
    public function handle_queue_commit_filter($result, $theme_path, $commit_message, $file_path = null)
    {
        return $this->queue_commit($theme_path, $commit_message, $file_path);
    }

    public function handle_queue_commit_action($theme_path, $commit_message)
    {
        $this->queue_commit($theme_path, $commit_message);
    }

    /**
     * Verificar si Git está disponible en el sistema
     */
    public function is_git_available()
    {
        $output = array();
        $return_var = 0;

        exec('git --version 2>&1', $output, $return_var);

        return $return_var === 0;
    }

    /**
     * Obtener versión de Git
     */
    public function get_git_version()
    {
        if (!$this->is_git_available()) {
            return false;
        }

        $output = array();
        exec('git --version 2>&1', $output);

        return isset($output[0]) ? $output[0] : false;
    }

    /**
     * Detectar modo Git disponible
     */
    public function detect_git_mode()
    {
        $mode = 'api'; // Por defecto API

        // Verificar si exec está disponible
        if (!function_exists('exec')) {
            update_option('wpvtp_git_mode', 'api');
            update_option('wpvtp_git_mode_reason', 'exec() function disabled');
            return 'api';
        }

        // Verificar si exec puede ejecutarse
        $disabled = explode(',', ini_get('disable_functions'));
        if (in_array('exec', $disabled)) {
            update_option('wpvtp_git_mode', 'api');
            update_option('wpvtp_git_mode_reason', 'exec() in disable_functions');
            return 'api';
        }

        // Verificar si git está instalado
        $output = array();
        $return_var = 1;
        @exec('git --version 2>&1', $output, $return_var);

        if ($return_var === 0) {
            $mode = 'cli';
            update_option('wpvtp_git_mode_reason', 'Git CLI available: ' . implode(' ', $output));
        } else {
            update_option('wpvtp_git_mode_reason', 'Git command not found');
        }

        update_option('wpvtp_git_mode', $mode);
        return $mode;
    }

    /**
     * Obtener modo Git actual
     */
    public function get_git_mode()
    {
        $force_mode = get_option('wpvtp_force_git_mode', 'auto');

        if ($force_mode !== 'auto') {
            return $force_mode;
        }

        $detected_mode = get_option('wpvtp_git_mode');

        if (!$detected_mode) {
            $detected_mode = $this->detect_git_mode();
        }

        return $detected_mode;
    }

    private function get_git_instance()
    {
        $mode = $this->get_git_mode();

        if ($mode === 'cli') {
            return new WPVTP_Git_CLI();
        } else {
            return new WPVTP_Git_API();
        }
    }

    /**
     * Generar handle para carpeta (nombre + rama)
     */
    public function generate_folder_handle($repo_name, $branch)
    {
        $handle = sanitize_title($repo_name);
        $branch_handle = sanitize_title($branch);

        // Si la rama es main/master, no agregar sufijo
        if (in_array($branch_handle, array('main', 'master'))) {
            return $handle;
        }

        return $handle . '-' . $branch_handle;
    }

    /**
     * NUEVO: Resolver path absoluto desde información relativa
     * 
     * @param string $folder_name Nombre de la carpeta
     * @param string $type Tipo de repositorio (theme/plugin)
     * @return string Path absoluto según el entorno actual
     */
    public function resolve_local_path($folder_name, $type)
    {
        if ($type === 'theme') {
            return get_theme_root() . '/' . $folder_name;
        } else {
            return WP_PLUGIN_DIR . '/' . $folder_name;
        }
    }

    /**
     * NUEVO: Obtener path relativo desde un path absoluto
     * 
     * @param string $absolute_path Path absoluto
     * @param string $type Tipo de repositorio
     * @return string Nombre de la carpeta
     */
    public function get_relative_path($absolute_path, $type)
    {
        if ($type === 'theme') {
            return basename($absolute_path);
        } else {
            return basename($absolute_path);
        }
    }

    /**
     * NUEVO: Migrar registros antiguos con paths absolutos a paths relativos
     */
    public function migrate_old_paths()
    {
        global $wpdb;

        // Primero verificar si la columna folder_name existe
        $column_exists = $wpdb->get_results(
            "SHOW COLUMNS FROM {$this->table_name} LIKE 'folder_name'"
        );

        // Si no existe, agregarla
        if (empty($column_exists)) {
            $wpdb->query(
                "ALTER TABLE {$this->table_name} ADD COLUMN folder_name varchar(255) NULL AFTER local_path"
            );
        }

        // Migrar datos existentes
        $repos = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} WHERE folder_name IS NULL OR folder_name = ''",
            ARRAY_A
        );

        foreach ($repos as $repo) {
            // Extraer folder_name del local_path
            $folder_name = basename($repo['local_path']);

            $wpdb->update(
                $this->table_name,
                array('folder_name' => $folder_name),
                array('id' => $repo['id']),
                array('%s'),
                array('%d')
            );
        }

        return array(
            'success' => true,
            'migrated' => count($repos),
            'message' => sprintf('Se migraron %d registros', count($repos))
        );
    }

    /**
     * Clonar repositorio con nombre personalizado
     */
    public function clone_repository($repo_url, $branch, $type, $repo_name, $custom_name = '', $access_token = '', $job_id = null)
    {
        require_once WPVTP_PLUGIN_DIR . 'includes/class-progress-tracker.php';
        $progress = new WPVTP_Progress_Tracker($job_id);

        $progress->update('validating', 'Validando configuración...', 10);

        $git = $this->get_git_instance();

        if (!$git->is_available()) {
            $progress->error('Git no está disponible en el sistema');
            return array(
                'success' => false,
                'error' => __('Git no está disponible en el sistema', 'wp-versions-themes-plugins')
            );
        }

        $display_name = !empty($custom_name) ? $custom_name : $repo_name;
        $folder_name = !empty($custom_name) ? $custom_name : $repo_name;
        $folder_handle = $this->generate_folder_handle($folder_name, $branch);

        $destination = $this->resolve_local_path($folder_handle, $type);

        if (is_dir($destination)) {
            $progress->error('El directorio ya existe');
            return array(
                'success' => false,
                'error' => sprintf(__('El directorio %s ya existe', 'wp-versions-themes-plugins'), $folder_handle)
            );
        }

        $progress->update('downloading', 'Descargando repositorio...', 30);

        $result = $git->clone_repository($repo_url, $branch, $destination, $access_token);

        if (!$result['success']) {
            if (is_dir($destination)) {
                $this->remove_directory($destination);
            }
            $progress->error('Error al clonar: ' . $result['error']);
            return array(
                'success' => false,
                'error' => __('Error al clonar repositorio: ', 'wp-versions-themes-plugins') . $result['error']
            );
        }

        $progress->update('configuring', 'Configurando archivos...', 70);

        if ($type === 'theme') {
            $this->update_theme_name($destination, $display_name, $branch);
        }

        if ($type === 'plugin' && !empty($custom_name)) {
            $this->update_plugin_name($destination, $display_name, $repo_name);
        }

        $progress->update('saving', 'Guardando en base de datos...', 90);

        $this->save_repo_to_database($display_name, $repo_url, $folder_handle, $branch, $type);

        $progress->complete('Repositorio clonado exitosamente');

        return array(
            'success' => true,
            'message' => __('Repositorio clonado exitosamente', 'wp-versions-themes-plugins'),
            'path' => $destination,
            'handle' => $folder_handle,
            'display_name' => $display_name,
            'job_id' => $progress->get_job_id()
        );
    }

    /**
     * Actualizar repositorio (git pull)
     */
    public function update_repository($identifier, $access_token = '', $job_id = null)
    {
        require_once WPVTP_PLUGIN_DIR . 'includes/class-progress-tracker.php';
        $progress = new WPVTP_Progress_Tracker($job_id);

        $progress->update('validating', 'Validando repositorio...', 10);

        $git = $this->get_git_instance();

        $repo_info = $this->get_repo_by_identifier($identifier);

        if (!$repo_info) {
            $progress->error('No se encontró el repositorio');
            return array(
                'success' => false,
                'error' => __('No se encontró el repositorio', 'wp-versions-themes-plugins')
            );
        }

        $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);

        $progress->update('updating', 'Descargando actualizaciones...', 50);

        $result = $git->update_repository($local_path, $access_token);

        if (!$result['success']) {
            $progress->error('Error al actualizar: ' . $result['error']);
            return array(
                'success' => false,
                'error' => __('Error al actualizar repositorio: ', 'wp-versions-themes-plugins') . $result['error']
            );
        }

        $progress->update('saving', 'Guardando cambios...', 90);

        $this->update_repo_timestamp($repo_info['folder_name'], $repo_info['repo_type']);

        $progress->complete('Repositorio actualizado exitosamente');

        return array(
            'success' => true,
            'message' => __('Repositorio actualizado exitosamente', 'wp-versions-themes-plugins'),
            'job_id' => $progress->get_job_id()
        );
    }

    /**
     * Cambiar rama (git checkout)
     */
    public function switch_branch($identifier, $new_branch, $job_id = null)
    {
        require_once WPVTP_PLUGIN_DIR . 'includes/class-progress-tracker.php';
        $progress = new WPVTP_Progress_Tracker($job_id);

        $progress->update('validating', 'Validando rama...', 10);

        $git = $this->get_git_instance();

        $repo_info = $this->get_repo_by_identifier($identifier);

        if (!$repo_info) {
            $progress->error('No se encontró el repositorio');
            return array(
                'success' => false,
                'error' => __('No se encontró el repositorio', 'wp-versions-themes-plugins')
            );
        }

        $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);

        $access_token = get_option('wpvtp_oauth_token');

        $progress->update('switching', 'Cambiando a rama ' . $new_branch . '...', 50);

        $result = $git->switch_branch($local_path, $new_branch, $access_token);

        if (!$result['success']) {
            $progress->error('Error al cambiar rama: ' . $result['error']);
            return array(
                'success' => false,
                'error' => __('Error al cambiar rama: ', 'wp-versions-themes-plugins') . $result['error']
            );
        }

        $progress->update('saving', 'Guardando cambios...', 90);

        $this->update_repo_branch($repo_info['folder_name'], $repo_info['repo_type'], $new_branch);

        $progress->complete('Cambiado a rama ' . $new_branch . ' exitosamente');

        return array(
            'success' => true,
            'message' => sprintf(__('Cambiado a rama %s exitosamente', 'wp-versions-themes-plugins'), $new_branch),
            'job_id' => $progress->get_job_id()
        );
    }

    /**
     * Obtener rama actual de un repositorio
     */
    public function get_current_branch($identifier)
    {
        $git = $this->get_git_instance();

        $repo_info = $this->get_repo_by_identifier($identifier);

        if (!$repo_info) {
            return false;
        }

        $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);

        return $git->get_current_branch($local_path);
    }

    /**
     * NUEVO: Obtener información del repositorio por identificador
     * El identificador puede ser el folder_name o el local_path (para compatibilidad)
     */
    private function get_repo_by_identifier($identifier)
    {
        global $wpdb;

        // Primero intentar por folder_name
        $repo = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE folder_name = %s",
                $identifier
            ),
            ARRAY_A
        );

        // Si no se encuentra, intentar por local_path (compatibilidad con registros antiguos)
        if (!$repo) {
            $repo = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_name} WHERE local_path = %s",
                    $identifier
                ),
                ARRAY_A
            );

            // Si se encontró por local_path, extraer el folder_name
            if ($repo && !isset($repo['folder_name'])) {
                $repo['folder_name'] = basename($repo['local_path']);
            }
        }

        return $repo;
    }

    /**
     * Obtener repositorios instalados (actualizado para usar paths dinámicos)
     */
    public function get_installed_repos()
    {
        global $wpdb;

        $repos = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} ORDER BY created_at DESC",
            ARRAY_A
        );

        foreach ($repos as &$repo) {
            // Si no tiene folder_name, extraerlo del local_path (migración)
            if (!isset($repo['folder_name']) || empty($repo['folder_name'])) {
                $repo['folder_name'] = basename($repo['local_path']);
            }

            // Resolver el path actual del entorno
            $local_path = $this->resolve_local_path($repo['folder_name'], $repo['repo_type']);
            $repo['local_path'] = $local_path; // Para compatibilidad con el frontend

            // Verificar existencia
            $repo['exists'] = is_dir($local_path);
            $repo['has_git'] = is_dir($local_path . '/.git');

            // Si existe, obtener la rama actual
            if ($repo['has_git']) {
                $current_branch = $this->get_current_branch($repo['folder_name']);
                if ($current_branch) {
                    $repo['current_branch'] = $current_branch;
                }
            }
        }

        return $repos;
    }

    /**
     * Eliminar repositorio
     */
    public function remove_repository($identifier)
    {
        global $wpdb;

        $repo_info = $this->get_repo_by_identifier($identifier);

        if (!$repo_info) {
            return array(
                'success' => false,
                'error' => __('No se encontró el repositorio en la base de datos', 'wp-versions-themes-plugins')
            );
        }

        $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);

        // Eliminar directorio físico si existe
        if (is_dir($local_path)) {
            if (!$this->remove_directory($local_path)) {
                return array(
                    'success' => false,
                    'error' => __('Error al eliminar directorio físico', 'wp-versions-themes-plugins')
                );
            }
        }

        // Remover de base de datos usando folder_name
        $deleted = $wpdb->delete(
            $this->table_name,
            array('folder_name' => $repo_info['folder_name']),
            array('%s')
        );

        // Si no se eliminó, intentar por local_path (compatibilidad)
        if ($deleted === false || $deleted === 0) {
            $deleted = $wpdb->delete(
                $this->table_name,
                array('local_path' => $identifier),
                array('%s')
            );
        }

        if ($deleted === false) {
            return array(
                'success' => false,
                'error' => __('Error al eliminar registro de base de datos', 'wp-versions-themes-plugins')
            );
        }

        return array(
            'success' => true,
            'message' => __('Repositorio eliminado exitosamente', 'wp-versions-themes-plugins')
        );
    }

    /**
     * Actualizar nombre de tema en style.css
     */
    private function update_theme_name($theme_path, $theme_name, $branch)
    {
        $style_css = $theme_path . '/style.css';

        if (!file_exists($style_css)) {
            return false;
        }

        $content = file_get_contents($style_css);

        // Crear nombre final con sufijo de rama si no es main/master
        $branch_suffix = (!in_array($branch, array('main', 'master'))) ? ' (' . ucfirst($branch) . ')' : '';
        $final_theme_name = $theme_name . $branch_suffix;

        // Buscar y reemplazar el Theme Name
        $pattern = '/^(\s*Theme Name:\s*)(.+)$/m';
        $replacement = '${1}' . $final_theme_name;

        $new_content = preg_replace($pattern, $replacement, $content, 1);

        if ($new_content !== $content) {
            file_put_contents($style_css, $new_content);
            return true;
        }

        return false;
    }

    /**
     * Actualizar nombre de plugin en archivo principal
     */
    private function update_plugin_name($plugin_path, $plugin_name, $original_repo_name)
    {
        // Buscar el archivo principal del plugin
        $main_plugin_file = $this->find_main_plugin_file($plugin_path, $original_repo_name);

        if (!$main_plugin_file) {
            return false;
        }

        $content = file_get_contents($main_plugin_file);

        // Buscar y reemplazar el Plugin Name
        $pattern = '/^(\s*\*\s*Plugin Name:\s*)(.+)$/m';
        $replacement = '${1}' . $plugin_name;

        $new_content = preg_replace($pattern, $replacement, $content, 1);

        if ($new_content !== $content) {
            file_put_contents($main_plugin_file, $new_content);
            return true;
        }

        return false;
    }

    /**
     * Encontrar archivo principal del plugin
     */
    private function find_main_plugin_file($plugin_path, $repo_name)
    {
        // Primero buscar archivo con el nombre del repositorio
        $expected_file = $plugin_path . '/' . $repo_name . '.php';
        if (file_exists($expected_file)) {
            $content = file_get_contents($expected_file);
            if (preg_match('/Plugin Name:/i', $content)) {
                return $expected_file;
            }
        }

        // Buscar todos los archivos PHP en la raíz
        $php_files = glob($plugin_path . '/*.php');

        foreach ($php_files as $file) {
            $content = file_get_contents($file);
            // Verificar si contiene header de plugin de WordPress
            if (preg_match('/Plugin Name:/i', $content)) {
                return $file;
            }
        }

        return false;
    }

    /**
     * Guardar repositorio en base de datos (actualizado para guardar folder_name)
     */
    private function save_repo_to_database($repo_name, $repo_url, $folder_name, $branch, $type)
    {
        global $wpdb;

        return $wpdb->insert(
            $this->table_name,
            array(
                'repo_name' => $repo_name,
                'repo_url' => $repo_url,
                'folder_name' => $folder_name, // NUEVO: guardar nombre de carpeta en lugar de path absoluto
                'local_path' => $this->resolve_local_path($folder_name, $type), // Mantener por compatibilidad
                'current_branch' => $branch,
                'repo_type' => $type,
                'created_at' => current_time('mysql'),
                'last_update' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );
    }

    /**
     * Actualizar timestamp de repositorio (actualizado)
     */
    private function update_repo_timestamp($folder_name, $type)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            array('last_update' => current_time('mysql')),
            array(
                'folder_name' => $folder_name,
                'repo_type' => $type
            ),
            array('%s'),
            array('%s', '%s')
        );
    }

    /**
     * Actualizar rama de repositorio (actualizado)
     */
    private function update_repo_branch($folder_name, $type, $branch)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table_name,
            array(
                'current_branch' => $branch,
                'last_update' => current_time('mysql')
            ),
            array(
                'folder_name' => $folder_name,
                'repo_type' => $type
            ),
            array('%s', '%s'),
            array('%s', '%s')
        );
    }

    /**
     * Remover directorio recursivamente
     */
    private function remove_directory($dir)
    {
        if (!is_dir($dir)) {
            return true;
        }

        $files = array_diff(scandir($dir), array('.', '..'));

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->remove_directory($path);
            } else {
                unlink($path);
            }
        }

        return rmdir($dir);
    }

    /**
     * Obtener estadísticas de repositorios
     */
    public function get_repo_stats()
    {
        global $wpdb;

        $stats = $wpdb->get_results(
            "SELECT 
                repo_type, 
                COUNT(*) as count 
            FROM {$this->table_name} 
            GROUP BY repo_type",
            ARRAY_A
        );

        $result = array(
            'total' => 0,
            'themes' => 0,
            'plugins' => 0
        );

        foreach ($stats as $stat) {
            $result['total'] += $stat['count'];
            $result[$stat['repo_type'] . 's'] = $stat['count'];
        }

        return $result;
    }

    public function commit_and_push_changes($identifier, $message = 'Cambios automáticos desde TemplateBuilder', $file_path = null)
    {
        $git = $this->get_git_instance();

        if (strpos($identifier, '/') !== false && is_dir($identifier)) {
            $local_path = $identifier;
        } else {
            $repo_info = $this->get_repo_by_identifier($identifier);

            if (!$repo_info) {
                return ['success' => false, 'error' => 'No se encontró el repositorio.'];
            }

            $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);
        }

        $access_token = get_option('wpvtp_oauth_token');

        if (empty($access_token)) {
            return ['success' => false, 'error' => 'Access token required'];
        }

        // Si no se especifica archivo, agregar todos los cambios (solo CLI)
        if (!$file_path && $this->get_git_mode() === 'cli') {
            $file_path = '.';
        }

        if (!$file_path) {
            return ['success' => false, 'error' => 'File path required for API mode'];
        }

        $result = $git->commit_and_push($local_path, $file_path, $message, $access_token);

        return $result;
    }

    /**
     * NUEVO: Agregar token a URL de GitHub para repos privados
     */
    private function add_token_to_url($repo_url, $access_token)
    {
        if (empty($access_token)) {
            return $repo_url;
        }

        // Reemplazar https://github.com/ con https://TOKEN@github.com/
        $authenticated_url = str_replace(
            'https://github.com/',
            'https://' . $access_token . '@github.com/',
            $repo_url
        );

        return $authenticated_url;
    }

    /**
     * NUEVO: Configurar Git credential helper para el repositorio
     */
    private function configure_git_credentials($repo_path, $access_token)
    {
        if (empty($access_token) || !is_dir($repo_path)) {
            return;
        }

        $old_cwd = getcwd();
        chdir($repo_path);

        // Configurar credential helper para almacenar el token
        exec('git config credential.helper store');

        // Configurar el token en el remote origin
        $remote_url = $this->get_remote_url($repo_path);
        if ($remote_url) {
            $authenticated_url = $this->add_token_to_url($remote_url, $access_token);
            exec('git remote set-url origin ' . escapeshellarg($authenticated_url));
        }

        chdir($old_cwd);
    }

    /**
     * NUEVO: Obtener URL remota del repositorio
     */
    private function get_remote_url($repo_path)
    {
        if (!is_dir($repo_path . '/.git')) {
            return false;
        }

        $old_cwd = getcwd();
        chdir($repo_path);

        $output = array();
        exec('git remote get-url origin 2>&1', $output);

        chdir($old_cwd);

        return isset($output[0]) ? trim($output[0]) : false;
    }

    /**
     * Crear ZIP de wp-content
     * 
     * @param string $zip_name Nombre del archivo ZIP (sin extensión)
     * @return array
     */
    public function create_wp_content_zip($zip_name)
    {
        // Sanitizar nombre
        $zip_name = sanitize_file_name($zip_name);

        if (empty($zip_name)) {
            return array(
                'success' => false,
                'error' => 'Nombre de archivo inválido'
            );
        }

        // Verificar que la clase ZipArchive existe
        if (!class_exists('ZipArchive')) {
            return array(
                'success' => false,
                'error' => 'ZipArchive no está disponible en el servidor'
            );
        }

        // Paths
        $wp_content_path = WP_CONTENT_DIR;
        $temp_dir = get_temp_dir();
        $zip_filename = $zip_name . '.zip';
        $zip_filepath = $temp_dir . $zip_filename;

        // Eliminar ZIP anterior si existe
        if (file_exists($zip_filepath)) {
            unlink($zip_filepath);
        }

        // Crear ZIP
        $zip = new ZipArchive();
        if ($zip->open($zip_filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            return array(
                'success' => false,
                'error' => 'No se pudo crear el archivo ZIP'
            );
        }

        // Agregar archivos al ZIP
        $this->add_directory_to_zip($zip, $wp_content_path, 'wp-content');

        $zip->close();

        // Verificar que se creó
        if (!file_exists($zip_filepath)) {
            return array(
                'success' => false,
                'error' => 'Error al crear el archivo ZIP'
            );
        }

        return array(
            'success' => true,
            'filepath' => $zip_filepath,
            'filename' => $zip_filename,
            'size' => filesize($zip_filepath)
        );
    }

    /**
     * Agregar directorio recursivamente al ZIP
     * 
     * @param ZipArchive $zip
     * @param string $source_path
     * @param string $zip_path
     */
    private function add_directory_to_zip($zip, $source_path, $zip_path)
    {
        $source_path = rtrim($source_path, '/');
        $zip_path = rtrim($zip_path, '/');

        // Crear directorio en el ZIP
        $zip->addEmptyDir($zip_path);

        // Obtener archivos y directorios
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source_path),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            // Skip directorios (ya se agregan con addEmptyDir)
            if ($file->isDir()) {
                continue;
            }

            $file_path = $file->getRealPath();
            $relative_path = substr($file_path, strlen($source_path) + 1);

            // Agregar archivo al ZIP
            $zip->addFile($file_path, $zip_path . '/' . $relative_path);
        }
    }

    public function queue_commit($theme_path, $commit_message, $file_path = null)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvtp_commits_queue';

        $inserted = $wpdb->insert(
            $table_name,
            array(
                'theme_path' => $theme_path,
                'commit_message' => $commit_message,
                'file_path' => $file_path,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%d', '%s')
        );

        if ($inserted) {
            $commit_id = $wpdb->insert_id;

            $this->process_commit_queue_item($commit_id);

            return array(
                'success' => true,
                'commit_id' => $commit_id
            );
        }

        return array(
            'success' => false,
            'error' => 'Error al encolar commit'
        );
    }

    /**
     * Verificar y configurar token antes de push
     */
    private function configure_push_authentication($repo_path)
    {
        $access_token = get_option('wpvtp_oauth_token');

        if (empty($access_token)) {
            return false;
        }

        $old_cwd = getcwd();
        chdir($repo_path);

        // Configurar credential helper en modo cache
        exec('git config credential.helper "cache --timeout=3600"');

        // Obtener URL actual
        $output = [];
        exec('git remote get-url origin 2>&1', $output);
        $current_url = isset($output[0]) ? trim($output[0]) : '';

        // Limpiar URL (remover token viejo si existe)
        $clean_url = preg_replace('/https:\/\/[^@]+@/', 'https://', $current_url);

        // Agregar token nuevo
        $authenticated_url = str_replace(
            'https://github.com/',
            'https://x-access-token:' . $access_token . '@github.com/',
            $clean_url
        );

        // Actualizar remote
        exec('git remote set-url origin ' . escapeshellarg($authenticated_url));

        chdir($old_cwd);
        return true;
    }

    /**
     * Procesar item de la cola de commits
     */
    public function process_commit_queue_item($commit_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpvtp_commits_queue';

        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $commit_id),
            ARRAY_A
        );

        if (!$item) {
            return array('success' => false, 'error' => 'Item no encontrado');
        }

        $wpdb->update(
            $table_name,
            array('attempts' => $item['attempts'] + 1),
            array('id' => $commit_id),
            array('%d'),
            array('%d')
        );

        $result = $this->commit_and_push_changes(
            $item['theme_path'],
            $item['commit_message'],
            $item['file_path']
        );

        if ($result['success']) {
            $wpdb->update(
                $table_name,
                array(
                    'status' => 'completed',
                    'processed_at' => current_time('mysql')
                ),
                array('id' => $commit_id),
                array('%s', '%s'),
                array('%d')
            );
        } else {
            $status = $item['attempts'] >= 3 ? 'failed' : 'pending';

            $wpdb->update(
                $table_name,
                array(
                    'status' => $status,
                    'last_error' => $result['error']
                ),
                array('id' => $commit_id),
                array('%s', '%s'),
                array('%d')
            );
        }

        return $result;
    }
}
