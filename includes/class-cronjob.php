<?php

/**
 * Cron Job Class
 * 
 * Authenticacion de Cron Job para ejecutar tareas programadas en WordPress.
 * 
 * @package WP_Versions_Plugins_Themes
 * @since 1.7.0
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

class WPVTP_CronJob
{
    const CRON_HOOK = 'wpvtp_cron_hook';
    protected $cron_interval;
    protected $callback;

    public function __construct($interval, $callback)
    {
        $this->cron_interval = $interval;
        $this->callback = $callback;

        add_action(self::CRON_HOOK, [$this, 'execute']);
        add_action('init', [$this, 'schedule']);
        add_filter('cron_schedules', [$this, 'add_intervals']);
    }

    public function add_intervals($schedules){
        $schedules['every_five_minutes'] = [
            'interval' => 300, // 5 minutos en segundos
            'display' => __('Cada 5 minutos')
        ];
        $schedules['every_four_hours'] = [
            'interval' => 14400, // 4 horas en segundos
            'display' => __('Cada 4 horas')
        ];
        return $schedules;
    }

    public function schedule()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), $this->cron_interval, self::CRON_HOOK);
        }
    }

    public function unschedule()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }

    public function execute()
    {
        if(is_callable($this->callback)) {
            call_user_func($this->callback);
        } else {
            error_log('WPVTP CronJob: Callback no es una función válida.'); 
        }
    }
}