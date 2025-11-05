<?php

/**
 * Repository Manager Class
 * 
 * Maneja todas las operaciones Git locales y gestión de repositorios
 * 
 * @package WP_Versions_Themes_Plugins
 * @since 1.2.0
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
    public function clone_repository($repo_url, $branch, $type, $repo_name, $custom_name = '')
    {
        if (!$this->is_git_available()) {
            return array(
                'success' => false,
                'error' => __('Git no está disponible en el sistema', 'wp-versions-themes-plugins')
            );
        }

        // Usar nombre personalizado si se proporciona, sino usar nombre del repo
        $display_name = !empty($custom_name) ? $custom_name : $repo_name;

        // Generar handle para la carpeta (siempre basado en el nombre del repo para consistencia)
        // Si hay nombre personalizado, usarlo para la carpeta también
        $folder_name = !empty($custom_name) ? $custom_name : $repo_name;
        $folder_handle = $this->generate_folder_handle($folder_name, $branch);

        // Determinar ruta de destino usando el método resolve
        $destination = $this->resolve_local_path($folder_handle, $type);

        // Verificar si ya existe
        if (is_dir($destination)) {
            return array(
                'success' => false,
                'error' => sprintf(__('El directorio %s ya existe', 'wp-versions-themes-plugins'), $folder_handle)
            );
        }

        // Ejecutar git clone
        $output = array();
        $return_var = 0;

        $command = sprintf(
            'git clone -b %s %s %s 2>&1',
            escapeshellarg($branch),
            escapeshellarg($repo_url),
            escapeshellarg($destination)
        );

        exec($command, $output, $return_var);

        if ($return_var !== 0) {
            // Limpiar directorio si se creó parcialmente
            if (is_dir($destination)) {
                $this->remove_directory($destination);
            }

            return array(
                'success' => false,
                'error' => __('Error al clonar repositorio: ', 'wp-versions-themes-plugins') . implode("\n", $output)
            );
        }

        // Verificar que el clone fue exitoso
        if (!is_dir($destination . '/.git')) {
            return array(
                'success' => false,
                'error' => __('El repositorio se clonó pero no contiene información de Git', 'wp-versions-themes-plugins')
            );
        }

        // Si es tema, actualizar nombre en style.css
        if ($type === 'theme') {
            $this->update_theme_name($destination, $display_name, $branch);
        }

        // Si es plugin, actualizar nombre en el archivo principal del plugin
        if ($type === 'plugin' && !empty($custom_name)) {
            $this->update_plugin_name($destination, $display_name, $repo_name);
        }

        // Guardar en base de datos - AHORA GUARDA FOLDER_NAME EN LUGAR DE PATH ABSOLUTO
        $this->save_repo_to_database($display_name, $repo_url, $folder_handle, $branch, $type);

        return array(
            'success' => true,
            'message' => __('Repositorio clonado exitosamente', 'wp-versions-themes-plugins'),
            'path' => $destination,
            'handle' => $folder_handle,
            'display_name' => $display_name
        );
    }

    /**
     * Actualizar repositorio (git pull)
     */
    public function update_repository($identifier)
    {
        // Resolver el path real desde el identificador
        $repo_info = $this->get_repo_by_identifier($identifier);
        
        if (!$repo_info) {
            return array(
                'success' => false,
                'error' => __('No se encontró el repositorio', 'wp-versions-themes-plugins')
            );
        }

        $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);

        if (!is_dir($local_path . '/.git')) {
            return array(
                'success' => false,
                'error' => __('El directorio no contiene un repositorio Git válido', 'wp-versions-themes-plugins')
            );
        }

        $output = array();
        $return_var = 0;

        // Cambiar al directorio del repositorio
        $old_cwd = getcwd();
        chdir($local_path);

        // Ejecutar git pull
        exec('git pull origin 2>&1', $output, $return_var);

        // Restaurar directorio de trabajo
        chdir($old_cwd);

        if ($return_var !== 0) {
            return array(
                'success' => false,
                'error' => __('Error al actualizar repositorio: ', 'wp-versions-themes-plugins') . implode("\n", $output)
            );
        }

        // Actualizar timestamp en base de datos
        $this->update_repo_timestamp($repo_info['folder_name'], $repo_info['repo_type']);

        return array(
            'success' => true,
            'message' => __('Repositorio actualizado exitosamente', 'wp-versions-themes-plugins'),
            'output' => $output
        );
    }

    /**
     * Cambiar rama (git checkout)
     */
    public function switch_branch($identifier, $new_branch)
    {
        // Resolver el path real desde el identificador
        $repo_info = $this->get_repo_by_identifier($identifier);
        
        if (!$repo_info) {
            return array(
                'success' => false,
                'error' => __('No se encontró el repositorio', 'wp-versions-themes-plugins')
            );
        }

        $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);

        if (!is_dir($local_path . '/.git')) {
            return array(
                'success' => false,
                'error' => __('El directorio no contiene un repositorio Git válido', 'wp-versions-themes-plugins')
            );
        }

        $output = array();
        $return_var = 0;

        // Cambiar al directorio del repositorio
        $old_cwd = getcwd();
        chdir($local_path);

        // Fetch para obtener ramas remotas actualizadas
        exec('git fetch origin 2>&1', $output, $return_var);

        if ($return_var !== 0) {
            chdir($old_cwd);
            return array(
                'success' => false,
                'error' => __('Error al obtener ramas remotas: ', 'wp-versions-themes-plugins') . implode("\n", $output)
            );
        }

        // Verificar si la rama existe localmente
        exec('git branch --list ' . escapeshellarg($new_branch) . ' 2>&1', $branch_check);
        $branch_exists_locally = !empty($branch_check);

        // Cambiar a la rama
        if ($branch_exists_locally) {
            exec('git checkout ' . escapeshellarg($new_branch) . ' 2>&1', $output, $return_var);
        } else {
            exec('git checkout -b ' . escapeshellarg($new_branch) . ' origin/' . escapeshellarg($new_branch) . ' 2>&1', $output, $return_var);
        }

        // Restaurar directorio de trabajo
        chdir($old_cwd);

        if ($return_var !== 0) {
            return array(
                'success' => false,
                'error' => __('Error al cambiar rama: ', 'wp-versions-themes-plugins') . implode("\n", $output)
            );
        }

        // Actualizar rama actual en base de datos
        $this->update_repo_branch($repo_info['folder_name'], $repo_info['repo_type'], $new_branch);

        return array(
            'success' => true,
            'message' => sprintf(__('Cambiado a rama %s exitosamente', 'wp-versions-themes-plugins'), $new_branch),
            'output' => $output
        );
    }

    /**
     * Obtener rama actual de un repositorio
     */
    public function get_current_branch($identifier)
    {
        $repo_info = $this->get_repo_by_identifier($identifier);
        
        if (!$repo_info) {
            return false;
        }

        $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);

        if (!is_dir($local_path . '/.git')) {
            return false;
        }

        $output = array();
        $old_cwd = getcwd();
        chdir($local_path);

        exec('git branch --show-current 2>&1', $output);

        chdir($old_cwd);

        return isset($output[0]) ? trim($output[0]) : false;
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

    public function commit_and_push_changes($identifier, $message = 'Cambios automáticos desde TemplateBuilder')
    {
        $repo_info = $this->get_repo_by_identifier($identifier);
        
        if (!$repo_info) {
            return ['success' => false, 'error' => 'No se encontró el repositorio.'];
        }

        $local_path = $this->resolve_local_path($repo_info['folder_name'], $repo_info['repo_type']);

        if (!$this->is_git_available() || !is_dir($local_path . '/.git')) {
            return ['success' => false, 'error' => 'No es un repositorio Git válido.'];
        }

        $old_cwd = getcwd();
        chdir($local_path);

        // 1. Configurar la identidad del usuario para este repositorio
        exec('git config user.name "TemplateBuilder User"');
        exec('git config user.email "templatebuilderwp@gmail.com"');

        // 2. Añadir todos los cambios
        exec('git add -A');

        // 3. Realizar el commit
        $output = [];
        $return_var = 0;
        $command = sprintf('git commit -m %s', escapeshellarg($message));
        exec($command, $output, $return_var);

        if ($return_var !== 0) {
            chdir($old_cwd);
            // Manejar el caso de "nothing to commit"
            if (strpos(implode("\n", $output), 'nothing to commit') !== false) {
                return ['success' => true, 'message' => 'No hay cambios para registrar.'];
            }
            return ['success' => false, 'error' => 'Error al registrar commit: ' . implode("\n", $output)];
        }

        // 4. Empujar los cambios a la rama actual
        $current_branch = $this->get_current_branch($repo_info['folder_name']);
        $output = [];
        $return_var = 0;
        $command = sprintf('git push origin %s', escapeshellarg($current_branch));
        exec($command, $output, $return_var);

        chdir($old_cwd);

        if ($return_var !== 0) {
            return ['success' => false, 'error' => 'Error al empujar los cambios: ' . implode("\n", $output)];
        }

        return ['success' => true, 'message' => 'Cambios registrados y empujados exitosamente.'];
    }
}