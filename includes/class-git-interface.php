<?php

/**
 * Git Interface Abstract Class
 * 
 * @package WP_Versions_Themes_Plugins
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
  exit;
}

abstract class JUZT_DEPLOY_BASIC_Git_Interface
{
  /**
   * Clonar repositorio
   */
  abstract public function clone_repository($repo_url, $branch, $destination, $access_token = '');

  /**
   * Actualizar repositorio (pull)
   */
  abstract public function update_repository($repo_path, $access_token = '');

  /**
   * Cambiar rama
   */
  abstract public function switch_branch($repo_path, $new_branch, $access_token = '');

  /**
   * Obtener rama actual
   */
  abstract public function get_current_branch($repo_path);

  /**
   * Commit y push de cambios
   */
  abstract public function commit_and_push($repo_path, $file_path, $commit_message, $access_token = '');

  /**
   * Verificar disponibilidad
   */
  abstract public function is_available();
}
