<?php

/**
 * Git CLI Implementation
 * 
 * @package WP_Versions_Themes_Plugins
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
  exit;
}

require_once WPVTP_PLUGIN_DIR . 'includes/class-git-interface.php';

class WPVTP_Git_CLI extends WPVTP_Git_Interface
{
  public function is_available()
  {
    if (!function_exists('exec')) {
      return false;
    }

    $output = array();
    $return_var = 0;
    @exec('git --version 2>&1', $output, $return_var);

    return $return_var === 0;
  }

  public function clone_repository($repo_url, $branch, $destination, $access_token = '')
  {
    if (!empty($access_token)) {
      $repo_url = str_replace(
        'https://github.com/',
        'https://x-access-token:' . $access_token . '@github.com/',
        $repo_url
      );
    }

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
      return array(
        'success' => false,
        'error' => implode("\n", $output)
      );
    }

    return array('success' => true);
  }

  public function update_repository($repo_path, $access_token = '')
  {
    if (!empty($access_token)) {
      $this->configure_remote_token($repo_path, $access_token);
    }

    $old_cwd = getcwd();
    chdir($repo_path);

    $output = array();
    $return_var = 0;
    exec('git pull origin 2>&1', $output, $return_var);

    chdir($old_cwd);

    if ($return_var !== 0) {
      return array(
        'success' => false,
        'error' => implode("\n", $output)
      );
    }

    return array('success' => true);
  }

  public function switch_branch($repo_path, $new_branch, $access_token = '')
  {
    $old_cwd = getcwd();
    chdir($repo_path);

    exec('git fetch origin 2>&1', $output, $return_var);

    if ($return_var !== 0) {
      chdir($old_cwd);
      return array(
        'success' => false,
        'error' => implode("\n", $output)
      );
    }

    exec('git branch --list ' . escapeshellarg($new_branch) . ' 2>&1', $branch_check);
    $branch_exists = !empty($branch_check);

    if ($branch_exists) {
      exec('git checkout ' . escapeshellarg($new_branch) . ' 2>&1', $output, $return_var);
    } else {
      exec('git checkout -b ' . escapeshellarg($new_branch) . ' origin/' . escapeshellarg($new_branch) . ' 2>&1', $output, $return_var);
    }

    chdir($old_cwd);

    if ($return_var !== 0) {
      return array(
        'success' => false,
        'error' => implode("\n", $output)
      );
    }

    return array('success' => true);
  }

  public function get_current_branch($repo_path)
  {
    if (!is_dir($repo_path . '/.git')) {
      return false;
    }

    $old_cwd = getcwd();
    chdir($repo_path);

    $output = array();
    exec('git branch --show-current 2>&1', $output);

    chdir($old_cwd);

    return isset($output[0]) ? trim($output[0]) : false;
  }

  public function commit_and_push($repo_path, $file_path, $commit_message, $access_token = '')
  {
    if (!empty($access_token)) {
      $this->configure_remote_token($repo_path, $access_token);
    }

    $old_cwd = getcwd();
    chdir($repo_path);

    $github_app_id = get_option('wpvtp_github_app_id', '1953130');
    $github_app_name = get_option('wpvtp_github_app_name', 'wordpress-theme-versions');

    exec('git config user.name "' . $github_app_name . '[bot]"');
    exec('git config user.email "' . $github_app_id . '+' . $github_app_name . '[bot]@users.noreply.github.com"');

    exec('git add ' . escapeshellarg($file_path));

    $output = [];
    $command = sprintf('git commit -m %s', escapeshellarg($commit_message));
    exec($command, $output, $return_var);

    if ($return_var !== 0 && strpos(implode("\n", $output), 'nothing to commit') !== false) {
      chdir($old_cwd);
      return array('success' => true, 'message' => 'No changes to commit');
    }

    $current_branch = $this->get_current_branch($repo_path);

    exec('git push origin ' . escapeshellarg($current_branch) . ' 2>&1', $output, $return_var);

    chdir($old_cwd);

    if ($return_var !== 0) {
      return array(
        'success' => false,
        'error' => implode("\n", $output)
      );
    }

    return array('success' => true);
  }

  private function configure_remote_token($repo_path, $access_token)
  {
    $old_cwd = getcwd();
    chdir($repo_path);

    exec('git config credential.helper store');

    $output = [];
    exec('git remote get-url origin 2>&1', $output);
    $current_url = isset($output[0]) ? trim($output[0]) : '';

    if ($current_url) {
      $clean_url = preg_replace('/https:\/\/[^@]+@/', 'https://', $current_url);
      $authenticated_url = str_replace(
        'https://github.com/',
        'https://x-access-token:' . $access_token . '@github.com/',
        $clean_url
      );
      exec('git remote set-url origin ' . escapeshellarg($authenticated_url));
    }

    chdir($old_cwd);
  }
}
