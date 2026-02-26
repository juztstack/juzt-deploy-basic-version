<?php
/**
 * GitHub API Handler Class
 * 
 * Maneja toda la comunicación con la API de GitHub a través del servicio OAuth
 * 
 * @package WP_Versions_Plugins_Themes
 * @since 1.7.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class JUZT_DEPLOY_BASIC_GitHub_API {
    
    /**
     * Servicio OAuth
     */
    private $oauth_service;
    
    /**
     * Constructor
     */
    public function __construct() {
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-oauth-service.php';
        $this->oauth_service = new JUZT_DEPLOY_BASIC_OAuth_Service();
    }
    
    /**
     * Verificar si está autenticado
     */
    public function is_authenticated() {
        return $this->oauth_service->is_connected();
    }
    
    /**
     * Obtener información del usuario autenticado
     */
    public function get_user() {
        return $this->oauth_service->get_user_info();
    }
    
    /**
     * Obtener organizaciones del usuario
     */
    public function get_organizations() {
        return $this->oauth_service->get_organizations();
    }
    
    /**
     * Obtener repositorios de una organización o usuario
     */
    public function get_repositories($owner, $type = 'all', $per_page = 30, $page = 1) {
        // Los parámetros adicionales se pueden ignorar por ahora
        // ya que el servicio OAuth maneja la paginación internamente
        return $this->oauth_service->get_repositories($owner);
    }
    
    /**
     * Obtener un repositorio específico
     */
    public function get_repository($owner, $repo) {
        // Este método podría necesitar un endpoint específico en el servicio OAuth
        // Por ahora, retornamos error ya que no está implementado
        return array(
            'success' => false,
            'error' => 'Método get_repository no implementado en el servicio OAuth'
        );
    }
    
    /**
     * Obtener ramas de un repositorio
     */
    public function get_branches($owner, $repo) {
        return $this->oauth_service->get_branches($owner, $repo);
    }
    
    /**
     * Obtener rama por defecto de un repositorio
     */
    public function get_default_branch($owner, $repo) {
        $branches_result = $this->get_branches($owner, $repo);
        
        if (!$branches_result['success']) {
            return $branches_result;
        }
        
        // Buscar rama main o master
        foreach ($branches_result['data'] as $branch) {
            if (in_array($branch['name'], array('main', 'master'))) {
                return array(
                    'success' => true,
                    'data' => $branch['name']
                );
            }
        }
        
        // Si no hay main/master, devolver la primera
        if (!empty($branches_result['data'])) {
            return array(
                'success' => true,
                'data' => $branches_result['data'][0]['name']
            );
        }
        
        return array(
            'success' => false,
            'error' => 'No se encontraron ramas en el repositorio'
        );
    }
    
    /**
     * Detectar tipo de repositorio (theme/plugin)
     */
    public function detect_repo_type($owner, $repo, $branch = 'main') {
        return $this->oauth_service->detect_repo_type($owner, $repo, $branch);
    }
    
    /**
     * Obtener contenido del archivo composer.json (para detectar tipo)
     * DEPRECATED: Ahora se usa detect_repo_type del servicio OAuth
     */
    public function get_composer_json($owner, $repo, $branch = 'main') {
        return array(
            'success' => false,
            'error' => 'Método deprecated. Usa detect_repo_type() en su lugar.'
        );
    }
    
    /**
     * Obtener contenido del archivo style.css (para temas de WordPress)
     * DEPRECATED: Ahora se usa detect_repo_type del servicio OAuth
     */
    public function get_style_css($owner, $repo, $branch = 'main') {
        return array(
            'success' => false,
            'error' => 'Método deprecated. Usa detect_repo_type() en su lugar.'
        );
    }
    
    /**
     * Obtener contenido del archivo del plugin principal
     * DEPRECATED: Ahora se usa detect_repo_type del servicio OAuth
     */
    public function get_plugin_file($owner, $repo, $branch = 'main') {
        return array(
            'success' => false,
            'error' => 'Método deprecated. Usa detect_repo_type() en su lugar.'
        );
    }
    
    /**
     * Obtener información del rate limit
     */
    public function get_rate_limit_info() {
        // El servicio OAuth maneja los rate limits internamente
        return array(
            'remaining' => null,
            'reset' => null,
            'reset_time' => null,
            'note' => 'Rate limit manejado por el servicio OAuth'
        );
    }
    
    /**
     * Limpiar token de acceso
     */
    public function clear_token() {
        return $this->oauth_service->disconnect();
    }
    
    /**
     * Validar token de acceso
     */
    public function validate_token() {
        if (!$this->is_authenticated()) {
            return array(
                'success' => false,
                'error' => 'No hay conexión OAuth configurada'
            );
        }
        
        $user_result = $this->get_user();
        
        if ($user_result['success']) {
            return array(
                'success' => true,
                'user' => $user_result['data']
            );
        }
        
        return $user_result;
    }
    
    /**
     * Realizar petición directa (para compatibilidad)
     */
    private function make_request($endpoint, $method = 'GET', $data = null) {
        // Este método ya no se usa directamente
        // Todas las peticiones van a través del servicio OAuth
        return array(
            'success' => false,
            'error' => 'Peticiones directas no permitidas. Usa el servicio OAuth.'
        );
    }
    
    /**
     * Métodos de compatibilidad con versiones anteriores
     */
    
    /**
     * Establecer token de acceso (deprecated)
     */
    public function set_access_token($token) {
        return array(
            'success' => false,
            'error' => 'Método deprecated. Usa el servicio OAuth para autenticación.'
        );
    }
    
    /**
     * Obtener token de acceso (deprecated)
     */
    public function get_access_token() {
        return null; // Los tokens se manejan internamente en el servicio OAuth
    }
}