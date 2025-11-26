<?php
/**
 * GitHub OAuth Service Class
 *
 * Maneja toda la comunicación con el servicio OAuth (middleware).
 * Actualizado con soporte para GitHub Apps e Installation Tokens.
 *
 * @package WP_Versions_Plugins_Themes
 * @since 1.7.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class WPVTP_OAuth_Service
{
    /**
     * URL del servicio middleware
     */
    const OAUTH_SERVICE_URL = 'Add Middleware URL';

    /**
     * Propiedades de la clase
     */
    private $session_token;
    private $refresh_token;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->session_token = get_option('wpvtp_oauth_token');
        $this->refresh_token = get_option('wpvtp_refresh_token');
        
        if (!wp_next_scheduled('wpvtp_auto_refresh_token')) {
            wp_schedule_event(time(), 'hourly', 'wpvtp_auto_refresh_token');
        }
        
        // Hook del cron
        add_action('wpvtp_auto_refresh_token', array($this, 'auto_refresh_token_if_needed'));
    }

    /**
     * Genera la URL de autorización para el middleware.
     *
     * @return string
     */
    public function get_authorization_url()
    {
        $return_url = urlencode(admin_url('admin.php?page=wp-versions-themes-plugins&tab=settings'));
        return self::OAUTH_SERVICE_URL . '/auth/github?return_url=' . $return_url;
    }

    /**
     * Realiza una petición al servicio middleware.
     *
     * @param string $endpoint El endpoint del middleware.
     * @param string $method El método HTTP.
     * @param array $data Los datos para la petición.
     * @return array
     */
    public function make_request($endpoint, $method = 'GET', $data = null)
    {
        // Verificar y refrescar token si es necesario
        if (empty($this->session_token) && !empty($this->refresh_token)) {
            $refresh_result = $this->refresh_access_token();
            if (!$refresh_result['success']) {
                return $refresh_result;
            }
            $this->session_token = $refresh_result['data']['session_token'];
        }

        if (empty($this->session_token)) {
            return array(
                'success' => false,
                'error' => 'No hay token de sesión. Vuelve a conectar con GitHub.'
            );
        }

        $url = self::OAUTH_SERVICE_URL . $endpoint;

        $headers = array(
            'Authorization' => 'Bearer ' . $this->session_token,
            'Content-Type' => 'application/json'
        );

        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 30
        );

        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
        }
        
        // Log de depuración
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WPVTP: Intentando conectar a: ' . $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => 'Error de conexión con el servicio: ' . $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data_response = json_decode($body, true);

        if ($code >= 200 && $code < 300) {
            return array(
                'success' => true,
                'data' => $data_response
            );
        } else {
            // Manejar token expirado o inválido
            if ($code === 401) {
                $this->disconnect();
                return array(
                    'success' => false,
                    'error' => 'Sesión expirada. Vuelve a conectar con GitHub.',
                    'code' => 401
                );
            }

            return array(
                'success' => false,
                'error' => isset($data_response['error']) ? $data_response['error'] : 'Error del servicio (HTTP ' . $code . '): ' . $body
            );
        }
    }

    /**
     * Refresca el token de acceso usando el refresh token.
     *
     * @return array
     */
    public function refresh_access_token()
    {
        if (empty($this->refresh_token)) {
            return array(
                'success' => false,
                'error' => 'No hay refresh token disponible.'
            );
        }

        $url = self::OAUTH_SERVICE_URL . '/auth/github/refresh';
        $args = array(
            'method' => 'POST',
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array('refresh_token' => $this->refresh_token)),
            'timeout' => 30
        );

        $response = wp_remote_post($url, $args);

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => 'Error de conexión al refrescar token: ' . $response->get_error_message()
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data_response = json_decode($body, true);

        if ($code === 200 && isset($data_response['session_token'])) {
            // Almacenar el nuevo token
            update_option('wpvtp_oauth_token', $data_response['session_token']);
            update_option('wpvtp_refresh_token', $data_response['refresh_token']);
            $this->session_token = $data_response['session_token'];
            $this->refresh_token = $data_response['refresh_token'];
            return array(
                'success' => true,
                'data' => $data_response
            );
        } else {
            return array(
                'success' => false,
                'error' => isset($data_response['error']) ? $data_response['error'] : 'Error al refrescar token (HTTP ' . $code . ')'
            );
        }
    }

    /**
     * Obtiene información del usuario y sus organizaciones.
     *
     * @return array
     */
    public function get_user_info()
    {
        return $this->make_request('/api/github/user');
    }

    /**
     * ⭐ NUEVO: Obtiene TODOS los repositorios accesibles usando GitHub App Installations.
     * Este es el método principal que debes usar para obtener repositorios.
     * 
     * Incluye:
     * - Repositorios públicos
     * - Repositorios privados personales
     * - Repositorios privados de organizaciones (donde la app está instalada)
     *
     * @return array Array con estructura:
     *   [
     *     'success' => true,
     *     'data' => [
     *       'installations' => [
     *         [
     *           'installation' => [...],
     *           'repositories' => [...]
     *         ]
     *       ],
     *       'total_installations' => 2,
     *       'total_repositories' => 15
     *     ]
     *   ]
     */
    public function get_all_repositories()
    {
        $result = $this->make_request('/api/github/installations/repositories');
        
        if (!$result['success']) {
            return $result;
        }

        // El endpoint ya devuelve la estructura correcta
        return array(
            'success' => true,
            'data' => $result['data']['data'] // Doble 'data' porque make_request ya envuelve la respuesta
        );
    }

    /**
     * Obtiene las instalaciones de la GitHub App para el usuario.
     * 
     * @return array Array con las instalaciones
     */
    public function get_installations()
    {
        return $this->make_request('/api/github/installations');
    }

    /**
     * Obtiene los repositorios de una instalación específica.
     * 
     * @param int $installation_id ID de la instalación
     * @return array
     */
    public function get_installation_repositories($installation_id)
    {
        return $this->make_request('/api/github/installations/' . $installation_id . '/repositories');
    }

    /**
     * Obtiene los repositorios de una organización o usuario.
     * 
     * NOTA: Este método se mantiene por compatibilidad con código existente,
     * pero se recomienda usar get_all_repositories() en su lugar, ya que ese
     * método incluye repositorios privados de organizaciones.
     *
     * @param string $owner El nombre del propietario del repositorio.
     * @param string $type El tipo de propietario ('org' o 'user').
     * @return array
     */
    public function get_repositories($owner, $type = 'user')
    {
        return $this->make_request('/api/github/repos/' . urlencode($owner) . '/' . urlencode($type));
    }

    /**
     * Obtiene las ramas de un repositorio.
     * 
     * El servidor automáticamente usa el token apropiado (user o installation)
     * según sea necesario.
     *
     * @param string $owner El nombre del propietario del repositorio.
     * @param string $repo El nombre del repositorio.
     * @return array
     */
    public function get_branches($owner, $repo)
    {
        return $this->make_request('/api/github/repos/' . urlencode($owner) . '/' . urlencode($repo) . '/branches');
    }

    /**
     * Obtiene información de un repositorio específico.
     * 
     * @param string $owner Propietario del repositorio
     * @param string $repo Nombre del repositorio
     * @return array
     */
    public function get_repository($owner, $repo)
    {
        return $this->make_request('/api/github/repos/' . urlencode($owner) . '/' . urlencode($repo));
    }

    /**
     * Limpia el caché de installation tokens en el servidor.
     * Útil si experimentas problemas con permisos.
     * 
     * @return array
     */
    public function clear_cache()
    {
        return $this->make_request('/api/github/cache/clear', 'POST');
    }

    /**
     * Desconecta al usuario del servicio.
     *
     * @return array
     */
    public function disconnect()
    {
        delete_option('wpvtp_oauth_token');
        delete_option('wpvtp_refresh_token');
        delete_option('wpvtp_github_user');
        return array('success' => true, 'message' => 'Desconectado de GitHub exitosamente');
    }

    /**
     * Verifica si el usuario está conectado.
     *
     * @return bool
     */
    public function is_connected()
    {
        $token = get_option('wpvtp_oauth_token');
        return !empty($token);
    }

    /**
     * Obtiene la información del usuario conectado.
     *
     * @return array|null
     */
    public function get_connected_user()
    {
        return get_option('wpvtp_github_user', null);
    }

    /**
     * Extrae una lista plana de repositorios desde la respuesta de get_all_repositories().
     * 
     * Útil para compatibilidad con código que espera un array simple de repositorios.
     * 
     * @param array $all_repos_response Respuesta de get_all_repositories()
     * @return array Array plano de repositorios
     */
    public function flatten_repositories($all_repos_response)
    {
        if (!$all_repos_response['success']) {
            return array();
        }

        $flat_repos = array();
        
        if (isset($all_repos_response['data']['installations'])) {
            foreach ($all_repos_response['data']['installations'] as $installation) {
                if (!empty($installation['repositories'])) {
                    $flat_repos = array_merge($flat_repos, $installation['repositories']);
                }
            }
        }

        return $flat_repos;
    }

    /**
     * Obtiene repositorios agrupados por owner (organización o usuario).
     * Útil para mostrar repos organizados en el UI.
     * 
     * @return array Array asociativo [owner_name => [repos]]
     */
    public function get_repositories_by_owner()
    {
        $result = $this->get_all_repositories();
        
        if (!$result['success']) {
            return array();
        }

        $grouped = array();
        
        if (isset($result['data']['installations'])) {
            foreach ($result['data']['installations'] as $installation) {
                $owner = $installation['installation']['account']['login'];
                $grouped[$owner] = $installation['repositories'];
            }
        }

        return $grouped;
    }
    
    public function handle_oauth_callback()
    {
        if (!isset($_GET['session_token'])) {
            return;
        }
    
        $access_token = sanitize_text_field($_GET['session_token']);
        $refresh_token = isset($_GET['refresh_token']) ? sanitize_text_field($_GET['refresh_token']) : '';
    
        update_option('wpvtp_oauth_token', $access_token);
        
        if (!empty($refresh_token)) {
            update_option('wpvtp_refresh_token', $refresh_token);
        }
    }
    
    public function auto_refresh_token_if_needed()
    {
        $last_refresh = get_option('wpvtp_token_last_refresh', 0);
        $refresh_interval = 7 * 60 * 60 + 50 * 60; // 7 horas 50 minutos en segundos
        
        // Si han pasado más de 7h 50min desde el último refresh
        if ((time() - $last_refresh) >= $refresh_interval) {
            $result = $this->refresh_access_token();
            
            if ($result['success']) {
                update_option('wpvtp_token_last_refresh', time());
                error_log('WPVTP: Token refrescado automáticamente');
            } else {
                error_log('WPVTP: Error al refrescar token automáticamente: ' . $result['error']);
            }
        }
    }
}
