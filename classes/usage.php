<?php

/**
 * Implements a weekly push of usage information to the ServerPress.com site
 */

class SyncUsage
{
	const EVENT_HOOK = 'spectrom_sync_usage_report';
	const EVENT_INTERVAL = 'weekly';

	public function __construct()
	{
//SyncDebug::log(__METHOD__.'()');
		add_action('wp_loaded', array($this, 'check_schedule'));
		add_filter('cron_schedules', array($this, 'filter_cron_schedules'));
	}

	/**
	 * Checks to see if cron event has been scheduled and if not, schedules it
	 */
	public function check_schedule()
	{
//SyncDebug::log(__METHOD__.'()');
		$time = wp_next_scheduled(self::EVENT_HOOK);
		if (FALSE === $time) {
			// no event currently scheduled- add it
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' scheduling for ' . time());
			wp_schedule_event(time(), self::EVENT_INTERVAL, self::EVENT_HOOK);
		}
		add_action(self::EVENT_HOOK, array($this, 'cron_callback'));
	}

	/**
	 * Callback function that performs cron based task. Sends data on plugin usage to ServerPress.com
	 */
	public function cron_callback()
	{
SyncDebug::log(__METHOD__.'()');
		$log_model = new SyncLogModel();
		$sent = $log_model->get_count('send');
		$recv = $log_model->get_count('recv');

		$license = get_option('spectrom_sync_licensing', array());

		$ext = apply_filters('spectrom_sync_active_extensions', array(), 1);
		$plugins = array();
		foreach ($ext as $slug => $plugin) {
			$info = array(
				'plugin_slug' => str_replace('_', '-', $slug),
				'plugin_version' => $plugin['version'],
				'plugin_license' => isset($license[$slug]) ? $license[$slug] : '',
			);
			$plugins[] = $info;
		}

		$data = array(
			'usage' => array(
				'site_key' => SyncOptions::get('site_key'),
				'domain' => parse_url(site_url(), PHP_URL_HOST),
				'sent' => $sent,
				'recd' => $recv,
				'target_domain' => parse_url(SyncOptions::get('target'), PHP_URL_HOST),
			),
			'plugins' => $plugins,
		);
		$json = json_encode($data);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sending...');

		$res = wp_remote_post('https://serverpress.com?wpss_stats', array(
			'body' => $json,
			'headers' => array(
				'Content-Type' => 'application/json; charset=utf-8',
			),
		));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' received: ' . var_export($res, TRUE));
	}

	/**
	 * Add our 'once weekly' schedule name to the list of available schedules
	 * @param array $schedules Array of schedule names
	 * @return array Filtered list of schedule names
	 */
	public function filter_cron_schedules($schedules)
	{
		if (!isset($schedules[self::EVENT_INTERVAL]))
			$schedules[self::EVENT_INTERVAL] = array(
				'interval' => 60 * 60 * 24 * 7,
				'display' => __('Once Weekly', 'wpsitesynccontent'),
			);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sched: ' . var_export($schedules, TRUE));

		return $schedules;
	}
}

// EOF
