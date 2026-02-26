<?php
/**
 * GitHub OAuth Handler Class
 * 
 * Maneja la autenticación OAuth con GitHub
 * 
 * @package WP_Versions_Plugins_Themes
 * @since 1.7.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class JUZT_DEPLOY_BASIC_GitHub_OAuth {
    
    /**
     * GitHub OAuth URLs
     */
    const OAUTH_AUTHORIZE_URL = 'https://github.com/login/oauth/authorize';
    const OAUTH_TOKEN_URL = 'https://github.com/login/oauth/access_token';
    
    /**
     * Scopes necesarios
     */
    const REQUIRED_SCOPES = 'repo,user:email';
    
    /**
     * Client ID y Secret (estos deberían ser configurables)
     */
    private $client_id;
    private $client_secret;
    
    /**
     * Constructor
     */
    public function __construct() {
        // Por ahora usamos credenciales de desarrollo
        // En producción, estas deberían ser configurables
        $this->client_id = get_option('JUZT_DEPLOY_BASIC_github_client_id', '');
        $this->client_secret = get_option('JUZT_DEPLOY_BASIC_github_client_secret', '');
        
        // Hooks para manejar callbacks
        add_action('admin_init', array($this, 'handle_oauth_callback'));
    }
    
    /**
     * Configurar credenciales OAuth
     */
    public function set_oauth_credentials($client_id, $client_secret) {
        $this->client_id = $client_id;
        $this->client_secret = $client_secret;
        
        update_option('JUZT_DEPLOY_BASIC_github_client_id', $client_id);
        update_option('JUZT_DEPLOY_BASIC_github_client_secret', $client_secret);
    }
    
    /**
     * Verificar si OAuth está configurado
     */
    public function is_oauth_configured() {
        return !empty($this->client_id) && !empty($this->client_secret);
    }
    
    /**
     * Generar URL de autorización
     */
    public function get_authorization_url() {
        $state = wp_create_nonce('JUZT_DEPLOY_BASIC_oauth_state');
        update_option('JUZT_DEPLOY_BASIC_oauth_state', $state, false);
        
        $redirect_uri = admin_url('admin.php?page=juzt-deploy-basic&JUZT_DEPLOY_BASIC_oauth=callback');
        
        $params = array(
            'client_id' => $this->client_id,
            'redirect_uri' => $redirect_uri,
            'scope' => self::REQUIRED_SCOPES,
            'state' => $state,
            'allow_signup' => 'false'
        );
        
        return self::OAUTH_AUTHORIZE_URL . '?' . http_build_query($params);
    }
    
    /**
     * Manejar callback de OAuth
     */
    public function handle_oauth_callback() {
        if (!isset($_GET['JUZT_DEPLOY_BASIC_oauth']) || $_GET['JUZT_DEPLOY_BASIC_oauth'] !== 'callback') {
            return;
        }
        
        if (!isset($_GET['code'])) {
            $this->handle_oauth_error('No se recibió código de autorización');
            return;
        }
        
        if (!isset($_GET['state']) || !wp_verify_nonce($_GET['state'], 'JUZT_DEPLOY_BASIC_oauth_state')) {
            $this->handle_oauth_error('Estado de OAuth inválido');
            return;
        }
        
        $code = sanitize_text_field($_GET['code']);
        $this->exchange_code_for_token($code);
    }
    
    /**
     * Intercambiar código por token de acceso
     */
    private function exchange_code_for_token($code) {
        $redirect_uri = admin_url('admin.php?page=juzt-deploy-basic&JUZT_DEPLOY_BASIC_oauth=callback');
        
        $data = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'code' => $code,
            'redirect_uri' => $redirect_uri
        );
        
        $args = array(
            'method' => 'POST',
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'juzt-deploy-basic/' . JUZT_DEPLOY_BASIC_VERSION
            ),
            'body' => $data,
            'timeout' => 30
        );
        
        $response = wp_remote_post(self::OAUTH_TOKEN_URL, $args);
        
        if (is_wp_error($response)) {
            $this->handle_oauth_error('Error de conexión: ' . $response->get_error_message());
            return;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            $this->handle_oauth_error('Error de OAuth: ' . $data['error_description']);
            return;
        }
        
        if (isset($data['access_token'])) {
            $this->handle_oauth_success($data['access_token']);
        } else {
            $this->handle_oauth_error('No se recibió token de acceso');
        }
    }
    
    /**
     * Manejar éxito de OAuth
     */
    private function handle_oauth_success($access_token) {
        // Guardar token
        update_option('JUZT_DEPLOY_BASIC_github_token', $access_token);
        
        // Limpiar estado temporal
        delete_option('JUZT_DEPLOY_BASIC_oauth_state');
        
        // Validar token inmediatamente
        require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-github-api.php';
        $github_api = new JUZT_DEPLOY_BASIC_GitHub_API($access_token);
        $user_result = $github_api->get_user();
        
        if ($user_result['success']) {
            // Guardar información del usuario
            update_option('JUZT_DEPLOY_BASIC_github_user', $user_result['data']);
            
            $message = sprintf(
                'Conectado exitosamente como %s (%s)',
                $user_result['data']['name'] ?: $user_result['data']['login'],
                $user_result['data']['login']
            );
            
            $this->redirect_with_message($message, 'success');
        } else {
            $this->handle_oauth_error('Token recibido pero inválido: ' . $user_result['error']);
        }
    }
    
    /**
     * Manejar error de OAuth
     */
    private function handle_oauth_error($message) {
        delete_option('JUZT_DEPLOY_BASIC_oauth_state');
        $this->redirect_with_message('Error de autenticación: ' . $message, 'error');
    }
    
    /**
     * Redireccionar con mensaje
     */
    private function redirect_with_message($message, $type) {
        $redirect_url = admin_url('admin.php?page=juzt-deploy-basic&tab=settings');
        $redirect_url = add_query_arg(array(
            'JUZT_DEPLOY_BASIC_message' => urlencode($message),
            'JUZT_DEPLOY_BASIC_message_type' => $type
        ), $redirect_url);
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Desconectar de GitHub
     */
    public function disconnect() {
        delete_option('JUZT_DEPLOY_BASIC_github_token');
        delete_option('JUZT_DEPLOY_BASIC_github_user');
        delete_option('JUZT_DEPLOY_BASIC_oauth_state');
        
        return array(
            'success' => true,
            'message' => 'Desconectado de GitHub exitosamente'
        );
    }
    
    /**
     * Obtener información del usuario conectado
     */
    public function get_connected_user() {
        return get_option('JUZT_DEPLOY_BASIC_github_user', null);
    }
    
    /**
     * Verificar si está conectado
     */
    public function is_connected() {
        $token = get_option('JUZT_DEPLOY_BASIC_github_token');
        $user = get_option('JUZT_DEPLOY_BASIC_github_user');
        
        return !empty($token) && !empty($user);
    }
}