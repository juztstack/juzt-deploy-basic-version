<?php

/**
 * Git API Implementation
 * 
 * @package WP_Versions_Themes_Plugins
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
  exit;
}

require_once JUZT_DEPLOY_BASIC_PLUGIN_DIR . 'includes/class-git-interface.php';

class JUZT_DEPLOY_BASIC_Git_API extends JUZT_DEPLOY_BASIC_Git_Interface
{
  public function is_available()
  {
    return true; // API siempre disponible
  }

  public function clone_repository($repo_url, $branch, $destination, $access_token = '')
  {
    // Extraer owner/repo de la URL
    preg_match('/github\.com\/([^\/]+)\/([^\/\.]+)/', $repo_url, $matches);

    if (!isset($matches[1]) || !isset($matches[2])) {
      return array('success' => false, 'error' => 'Invalid GitHub URL');
    }

    $owner = $matches[1];
    $repo = $matches[2];

    // Descargar ZIP
    $zip_url = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$branch}";

    $headers = array('Accept' => 'application/vnd.github+json');
    if (!empty($access_token)) {
      $headers['Authorization'] = 'Bearer ' . $access_token;
    }

    // Usar directorio temporal en el mismo volumen (wp-content/uploads)
    $upload_dir = wp_upload_dir();
    $temp_base = trailingslashit($upload_dir['basedir']) . 'wpvtp-temp/';

    // Crear directorio temporal si no existe
    if (!file_exists($temp_base)) {
      wp_mkdir_p($temp_base);
    }

    $temp_zip = $temp_base . 'repo-' . uniqid() . '.zip';
    $temp_extract = $temp_base . 'extract-' . uniqid() . '/';

    $response = wp_remote_get($zip_url, array(
      'headers' => $headers,
      'timeout' => 300,
      'stream' => true,
      'filename' => $temp_zip
    ));

    if (is_wp_error($response)) {
      return array('success' => false, 'error' => $response->get_error_message());
    }

    // Verificar que el archivo se descargó
    if (!file_exists($temp_zip)) {
      return array('success' => false, 'error' => 'Failed to download repository');
    }

    // Extraer ZIP
    WP_Filesystem();
    global $wp_filesystem;

    $unzip_result = unzip_file($temp_zip, $temp_extract);

    if (is_wp_error($unzip_result)) {
      @unlink($temp_zip);
      return array('success' => false, 'error' => $unzip_result->get_error_message());
    }

    // GitHub crea carpeta con formato: owner-repo-commitsha
    $extracted_dirs = glob($temp_extract . '*');

    if (empty($extracted_dirs) || !is_dir($extracted_dirs[0])) {
      @unlink($temp_zip);
      $wp_filesystem->rmdir($temp_extract, true);
      return array('success' => false, 'error' => 'No files extracted');
    }

    $extracted_dir = $extracted_dirs[0];

    // Crear directorio de destino
    if (!file_exists($destination)) {
      wp_mkdir_p($destination);
    }

    // Copiar archivos recursivamente en lugar de mover
    $copy_result = $this->copy_directory($extracted_dir, $destination);

    // Limpiar archivos temporales
    @unlink($temp_zip);
    $wp_filesystem->rmdir($temp_extract, true);

    if (!$copy_result) {
      return array('success' => false, 'error' => 'Failed to copy files to destination');
    }

    // Guardar metadata
    $this->save_repo_metadata($destination, $owner, $repo, $branch);

    return array('success' => true);
  }
  /**
   * Copiar directorio recursivamente
   * 
   * @param string $source Directorio origen
   * @param string $destination Directorio destino
   * @return bool
   */
  private function copy_directory($source, $destination)
  {
    WP_Filesystem();
    global $wp_filesystem;

    if (!is_dir($source)) {
      return false;
    }

    // Crear directorio destino si no existe
    if (!$wp_filesystem->is_dir($destination)) {
      $wp_filesystem->mkdir($destination);
    }

    $dir = opendir($source);
    if (!$dir) {
      return false;
    }

    while (($file = readdir($dir)) !== false) {
      if ($file === '.' || $file === '..') {
        continue;
      }

      $source_path = trailingslashit($source) . $file;
      $dest_path = trailingslashit($destination) . $file;

      if (is_dir($source_path)) {
        // Copiar subdirectorio recursivamente
        if (!$this->copy_directory($source_path, $dest_path)) {
          closedir($dir);
          return false;
        }
      } else {
        // Copiar archivo
        if (!$wp_filesystem->copy($source_path, $dest_path, true)) {
          closedir($dir);
          return false;
        }
      }
    }

    closedir($dir);
    return true;
  }

  public function update_repository($repo_path, $access_token = '')
  {
    $metadata = $this->get_repo_metadata($repo_path);

    if (!$metadata) {
      return array('success' => false, 'error' => 'No repository metadata found');
    }

    // Eliminar archivos actuales excepto metadata
    WP_Filesystem();
    global $wp_filesystem;

    $files = glob($repo_path . '/*');
    foreach ($files as $file) {
      if (basename($file) !== '.JUZT_DEPLOY_BASIC_metadata') {
        if (is_dir($file)) {
          $wp_filesystem->rmdir($file, true);
        } else {
          @unlink($file);
        }
      }
    }

    // Clonar de nuevo
    return $this->clone_repository(
      "https://github.com/{$metadata['owner']}/{$metadata['repo']}",
      $metadata['branch'],
      $repo_path,
      $access_token
    );
  }

  public function switch_branch($repo_path, $new_branch, $access_token = '')
  {
    $metadata = $this->get_repo_metadata($repo_path);

    if (!$metadata) {
      return array('success' => false, 'error' => 'No repository metadata found');
    }

    // Actualizar metadata con nueva rama
    $metadata['branch'] = $new_branch;
    $this->save_repo_metadata($repo_path, $metadata['owner'], $metadata['repo'], $new_branch);

    // Re-clonar con nueva rama
    return $this->update_repository($repo_path, $access_token);
  }

  public function get_current_branch($repo_path)
  {
    $metadata = $this->get_repo_metadata($repo_path);
    return $metadata ? $metadata['branch'] : false;
  }

  public function commit_and_push($repo_path, $file_path, $commit_message, $access_token = '')
  {
    $metadata = $this->get_repo_metadata($repo_path);

    if (!$metadata) {
      return array('success' => false, 'error' => 'No repository metadata');
    }

    if (empty($access_token)) {
      return array('success' => false, 'error' => 'Access token required for commits');
    }

    $owner = $metadata['owner'];
    $repo = $metadata['repo'];
    $branch = $metadata['branch'];

    // ✅ Normalizar paths (sin trailing slash)
    $repo_path = rtrim($repo_path, '/');
    $file_path = rtrim($file_path, '/');

    // ✅ Convertir a path relativo
    $relative_path = str_replace($repo_path . '/', '', $file_path);

    // ✅ IMPORTANTE: Quitar slash inicial si existe
    $relative_path = ltrim($relative_path, '/');

    // ✅ Debug (eliminar en producción)
    error_log('Repo path: ' . $repo_path);
    error_log('File path: ' . $file_path);
    error_log('Relative path: ' . $relative_path);

    // Verificar que el path relativo no esté vacío
    if (empty($relative_path)) {
      return array('success' => false, 'error' => 'Invalid relative path');
    }

    // 1. Obtener SHA actual del archivo
    $get_url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$relative_path}?ref={$branch}";

    $get_response = wp_remote_get($get_url, array(
      'headers' => array(
        'Authorization' => 'Bearer ' . $access_token,
        'Accept' => 'application/vnd.github+json',
        'User-Agent' => 'WordPress-Plugin'
      )
    ));

    $sha = null;
    if (!is_wp_error($get_response)) {
      $body = json_decode(wp_remote_retrieve_body($get_response), true);
      $sha = isset($body['sha']) ? $body['sha'] : null;
    }

    // 2. Leer contenido del archivo
    if (!file_exists($file_path)) {
      return array(
        'success' => false,
        'error' => 'File not found: ' . $file_path
      );
    }

    $content = file_get_contents($file_path);
    $content_base64 = base64_encode($content);

    // 3. Commit vía API
    $put_url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$relative_path}";

    $payload = array(
      'message' => $commit_message,
      'content' => $content_base64,
      'branch' => $branch
    );

    if ($sha) {
      $payload['sha'] = $sha;
    }

    $put_response = wp_remote_request($put_url, array(
      'method' => 'PUT',
      'headers' => array(
        'Authorization' => 'Bearer ' . $access_token,
        'Accept' => 'application/vnd.github+json',
        'Content-Type' => 'application/json',
        'User-Agent' => 'WordPress-Plugin'
      ),
      'body' => json_encode($payload)
    ));

    if (is_wp_error($put_response)) {
      return array('success' => false, 'error' => $put_response->get_error_message());
    }

    $status_code = wp_remote_retrieve_response_code($put_response);
    $response_body = json_decode(wp_remote_retrieve_body($put_response), true);

    if ($status_code < 200 || $status_code >= 300) {
      return array(
        'success' => false,
        'error' => isset($response_body['message']) ? $response_body['message'] : 'Commit failed',
        'details' => $response_body // ✅ Más info para debug
      );
    }

    return array(
      'success' => true,
      'commit' => $response_body
    );
  }

  private function save_repo_metadata($repo_path, $owner, $repo, $branch)
  {
    $metadata = array(
      'owner' => $owner,
      'repo' => $repo,
      'branch' => $branch,
      'mode' => 'api'
    );

    file_put_contents($repo_path . '/.JUZT_DEPLOY_BASIC_metadata', json_encode($metadata));
  }

  private function get_repo_metadata($repo_path)
  {
    $metadata_file = $repo_path . '/.JUZT_DEPLOY_BASIC_metadata';

    if (!file_exists($metadata_file)) {
      return false;
    }

    $content = file_get_contents($metadata_file);
    return json_decode($content, true);
  }
}
