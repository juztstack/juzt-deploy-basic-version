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

require_once WPVTP_PLUGIN_DIR . 'includes/class-git-interface.php';

class WPVTP_Git_API extends WPVTP_Git_Interface
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

    $response = wp_remote_get($zip_url, array(
      'headers' => $headers,
      'timeout' => 300,
      'stream' => true,
      'filename' => get_temp_dir() . 'repo.zip'
    ));

    if (is_wp_error($response)) {
      return array('success' => false, 'error' => $response->get_error_message());
    }

    $zip_file = get_temp_dir() . 'repo.zip';

    // Extraer ZIP
    WP_Filesystem();
    global $wp_filesystem;

    $unzip_result = unzip_file($zip_file, get_temp_dir() . 'repo_extract');

    if (is_wp_error($unzip_result)) {
      @unlink($zip_file);
      return array('success' => false, 'error' => $unzip_result->get_error_message());
    }

    // GitHub crea carpeta con formato: owner-repo-commitsha
    $extracted_dirs = glob(get_temp_dir() . 'repo_extract/*');

    if (empty($extracted_dirs)) {
      @unlink($zip_file);
      return array('success' => false, 'error' => 'No files extracted');
    }

    $extracted_dir = $extracted_dirs[0];

    // Mover a destino
    if (!$wp_filesystem->move($extracted_dir, $destination, true)) {
      @unlink($zip_file);
      return array('success' => false, 'error' => 'Failed to move files');
    }

    // Guardar metadata
    $this->save_repo_metadata($destination, $owner, $repo, $branch);

    // Limpiar
    @unlink($zip_file);
    $wp_filesystem->rmdir(get_temp_dir() . 'repo_extract', true);

    return array('success' => true);
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
      if (basename($file) !== '.wpvtp_metadata') {
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

    // Convertir path absoluto a relativo
    $relative_path = str_replace($repo_path . '/', '', $file_path);

    // 1. Obtener SHA actual del archivo
    $get_url = "https://api.github.com/repos/{$owner}/{$repo}/contents/{$relative_path}?ref={$branch}";

    $get_response = wp_remote_get($get_url, array(
      'headers' => array(
        'Authorization' => 'Bearer ' . $access_token,
        'Accept' => 'application/vnd.github+json'
      )
    ));

    $sha = null;
    if (!is_wp_error($get_response)) {
      $body = json_decode(wp_remote_retrieve_body($get_response), true);
      $sha = isset($body['sha']) ? $body['sha'] : null;
    }

    // 2. Leer contenido del archivo
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
        'Content-Type' => 'application/json'
      ),
      'body' => json_encode($payload)
    ));

    if (is_wp_error($put_response)) {
      return array('success' => false, 'error' => $put_response->get_error_message());
    }

    $status_code = wp_remote_retrieve_response_code($put_response);

    if ($status_code < 200 || $status_code >= 300) {
      $body = json_decode(wp_remote_retrieve_body($put_response), true);
      return array('success' => false, 'error' => isset($body['message']) ? $body['message'] : 'Commit failed');
    }

    return array('success' => true);
  }

  private function save_repo_metadata($repo_path, $owner, $repo, $branch)
  {
    $metadata = array(
      'owner' => $owner,
      'repo' => $repo,
      'branch' => $branch,
      'mode' => 'api'
    );

    file_put_contents($repo_path . '/.wpvtp_metadata', json_encode($metadata));
  }

  private function get_repo_metadata($repo_path)
  {
    $metadata_file = $repo_path . '/.wpvtp_metadata';

    if (!file_exists($metadata_file)) {
      return false;
    }

    $content = file_get_contents($metadata_file);
    return json_decode($content, true);
  }
}
