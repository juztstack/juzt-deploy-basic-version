<?php
/**
 * Progress Tracker Class
 * 
 * @package WP_Versions_Themes_Plugins
 * @since 1.7.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPVTP_Progress_Tracker
{
    private $job_id;
    
    public function __construct($job_id = null)
    {
        $this->job_id = $job_id ?: uniqid('wpvtp_job_', true);
    }
    
    public function get_job_id()
    {
        return $this->job_id;
    }
    
    public function update($step, $message, $progress = 0)
    {
        $data = array(
            'step' => $step,
            'message' => $message,
            'progress' => $progress,
            'timestamp' => time()
        );
        
        set_transient('wpvtp_progress_' . $this->job_id, $data, 300); // 5 minutos
    }
    
    public function complete($message = 'Completado')
    {
        $data = array(
            'step' => 'completed',
            'message' => $message,
            'progress' => 100,
            'timestamp' => time()
        );
        
        set_transient('wpvtp_progress_' . $this->job_id, $data, 300);
    }
    
    public function error($message)
    {
        $data = array(
            'step' => 'error',
            'message' => $message,
            'progress' => 0,
            'timestamp' => time()
        );
        
        set_transient('wpvtp_progress_' . $this->job_id, $data, 300);
    }
    
    public static function get_progress($job_id)
    {
        return get_transient('wpvtp_progress_' . $job_id);
    }
    
    public function cleanup()
    {
        delete_transient('wpvtp_progress_' . $this->job_id);
    }
}