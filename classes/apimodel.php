<?php

/**
 * Registers new REST API as endpoint
 */
class SyncApiModel
{
	protected $options;

	/**
	 * Callback for 'plugins_loaded'. Reads options and registers endpoint actions and filters
	 * @param array $options Options to use for API model
	 */
	public function __construct(Array $options)
	{
		$default_options = array(
			'callback'	=> NULL,
			'name'		=> 'api',
			'position'	=> EP_ROOT
		);

		$this->options = wp_parse_args($options, $default_options);

		// check for maintenance mode plugins
		$this->_maintenance_check();

		add_action('init', array(&$this, 'register_api'), 1000);

		// endpoints work on the front end only
		if (is_admin())
			return;

//SyncDebug::log(__METHOD__.'() request uri=' . $_SERVER['REQUEST_URI']);
		add_filter('request', array(&$this, 'set_query_var'));
		// hook in late to allow other plugins to operate earlier
		add_action('template_redirect', array(&$this, 'render'), 100);
	}

	/**
	 * Callback for 'init' action. Adds endpoint and deals with other code flushing our rules away.
	 */
	public function register_api()
	{
		add_rewrite_endpoint(
			$this->options['name'],
			$this->options['position']
		);
		$this->fix_failed_registration(
			$this->options['name'],
			$this->options['position']
		);
	}

	/**
	 * Callback for 'init'. Fixes rules flushed by other plugins
	 * @param string $name Rewrite endpoint name
	 * @param int $position Rewrite endpoint position
	 */
	protected function fix_failed_registration($name, $position)
	{
		global $wp_rewrite;

		if (empty($wp_rewrite->endpoints))
			return flush_rewrite_rules(FALSE);

		foreach ($wp_rewrite->endpoints as $endpoint)
			if ($endpoint[0] === $position && $endpoint[1] === $name)
				return;

		flush_rewrite_rules(FALSE);
	}

	/**
	 * Callback for 'request' filter. Sets the endpoint variable to TRUE
	 * If the endpoint was called without further parameters it does not evaluate to TRUE otherwise
	 * @param array $vars Array of request variables for current query
	 * @return array Modifed request variables
	 */
	public function set_query_var(array $vars)
	{
//SyncDebug::log(__METHOD__.'() vars=' . var_export($vars, TRUE));
		if (!empty($vars[$this->options['name']]))
			return $vars;

		// If a static page is set as front page, the WP endpoint API does strange things. This attempts to fix that.
		if (isset($vars[$this->options['name']]) ||
			(isset($vars['pagename']) && $this->options['name'] === $vars['pagename']) ||
			(isset($vars['page']) && isset($vars['name']) && $this->options['name'] === $vars['name'])) {
			// in some cases WP misinterprets the request as a page request and returns a 404
			$vars['page'] = $vars['pagename'] = $vars['name'] = FALSE;
			$vars[$this->options['name']] = TRUE;
		}
		return $vars;
	}

	/**
	 * Callback for 'template_redirect'. Prepare API requests and passes them to the callback
	 */
	public function render()
	{
		// TODO: this needs to be redone - get_query_var() returns a TRUE not a parseable string
		$api = get_query_var($this->options['name']);
SyncDebug::log(__METHOD__.'() api=' . var_export($api, TRUE));
		$api = trim($api, '/');

		if ('' === $api)
			return;

		$parts = explode('/', $api);
//SyncDebug::log(__METHOD__.'() parts=' . var_export($parts, TRUE));

		// spectrom - using json only for now
		// $type = array_shift($parts);
		$values = $this->get_api_values(implode('/', $parts));
		$callback = $this->options['callback'];

		// TODO: this can be simplified. assume only Sync callback are made, which will always be array type
		if (is_string($callback)) {
//SyncDebug::log(__METHOD__.'() string callback');
			call_user_func($callback, $values);
		} else if (is_array($callback)) {
//SyncDebug::log(__METHOD__.'() array callback');
			if ('__construct' === $callback[1])
				new $callback[0]($values);
			else if (is_callable($callback))
				call_user_func($callback, $values);
		} else {
//SyncDebug::log(__METHOD__.'() no callback');
			// TODO: use exception
			trigger_error(
				sprintf(__('Cannot call your callback: %s', 'wpsitesynccontent'), var_export($callback, TRUE)),
				E_USER_ERROR
			);
		}

		// WP will render main page if we leave this out
		// TODO: shouldn't be needed. SyncApiController calls die() when sending results
		exit;
	}

	/**
	 * Parse request URI into associative array.
	 * @param string $request The URL for the current page request
	 * @return array The URL string returned as key=value pairs
	 */
	protected function get_api_values($request)
	{
SyncDebug::log(__METHOD__.'() request=' . var_export($request, TRUE));
SyncDebug::log(__METHOD__.'() request uri=' . $_SERVER['REQUEST_URI']);

		// TODO: called by join()ing URL parts. would it be easier to pass as array?
		$keys = $values = array();
		$count = 0;
		$request = trim($request, '/');
		$tok = strtok($request, '/');

		while (FALSE !== $tok) {
			0 === ++$count % 2 ? $keys[] = $tok : $values[] = $tok;
			$tok = strtok('/');
		}
SyncDebug::log(__METHOD__.'() keys=' . var_export($keys, TRUE));
SyncDebug::log(__METHOD__.'() vals=' . var_export($values, TRUE));

		// fix odd requests
//		if (count($keys) !== count($values))
//			$values[] = '';
		while (count($keys) < count($values))
			$keys[] = 'a';
		while (count($values) < count($keys))
			$values[] = '';

		return array_combine($keys, $values);
	}

	/**
	 * Check for maintenance mode plugins and disable their actions that may interfere with WPSiteSync's API
	 */
	private function _maintenance_check()
	{
		// first, check to see if the current request is for a WPSiteSync API call
		if (!isset($_GET['pagename']) || WPSiteSyncContent::API_ENDPOINT !== $_GET['pagename'])
			return;

		// now we know it's a WPSiteSync API call. Detect and disable any maintenance mode type plugins

		// look for 'WP Maintenance Mode' plugin - https://wordpress.org/plugins/wp-maintenance-mode/ - 400,000 installs
		if (class_exists('WP_Maintenance_Mode', FALSE)) {
			add_filter('option_wpmm_settings', array($this, 'filter_wpmm_options'), 10, 2);
			return;
		}
		// look for 'Maintenance' plugin - https://wordpress.org/plugins/maintenance/ - 300,000 installs
		if (class_exists('maintenance', FALSE)) {
			add_filter('option_maintenance_options', array($this, 'filter_maintenance_options'), 10, 2);
			return;
		}
		// look for 'Coming Soon' plugin - https://wordpress.org/plugins/coming-soon/ - 300,000 installs
		if (class_exists('SEED_CSP4', FALSE)) {
			add_filter('seed_csp4_get_settings', array($this, 'filter_coming_soon_options'), 10 ,1);
			return;
		}

//die('inside ' . __METHOD__. '():' . __LINE__ . ' set=' . var_export($settings, TRUE));
	}

	/**
	 * Filter for WP Maintenance Mode plugins' configuration settings. Used to deactivate plugin behavior
	 * @param array $value The settings to filter; called from get_option()
	 * @param string $option The option name, in this case always 'maintenance_options'
	 * @return array The modified settings for WP Maintenance Mode; with the maintenance mode deactivated.
	 */
	public function filter_wpmm_options($value, $option = '')
	{
		$value['general']['status'] = 0;
		return $value;
	}

	/**
	 * Filter for the Maintenance plugin's configuration settings. Used to turn off maintenance mode.
	 * @param array $value The settings to filter; called from get_option()
	 * @param string $option The option name, in this case always 'maintenance_options'
	 * @return array The modified settings for Maintenance; with the maintenance mode turned off.
	 */
	public function filter_maintenance_options($value, $option = '')
	{
		if (isset($value['state']) && !empty($value['state']))
			$value['state'] = 0;	// this makes load_maintenance_page() not load the maintenance page
		return $value;
	}

	/**
	 * Filter for the Coming Soon plugin's configuration settings. Used to turn off it's features
	 * @param array $settings The Coming Soon plugins settings
	 * @return array The modified settings, with the 'status' value set to 0 to disable it
	 */
	public function filter_coming_soon_options($settings)
	{
		$settings['status'] = '0';
		return $settings;
	}
}

// EOF