<?php
/*
Plugin Name: WPSiteSync for Content
Plugin URI: https://wpsitesync.com
Description: Provides features for easily Synchronizing Content between two WordPress sites.
Author: WPSiteSync
Author URI: http://wpsitesync.com
Version: 1.2
Text Domain: wpsitesynccontent
Domain path: /language

The PHP code portions are distributed under the GPL license. If not otherwise stated, all
images, manuals, cascading stylesheets and included JavaScript are NOT GPL.
*/

// this is only needed for systems that the .htaccess won't work on
defined('ABSPATH') or (header('Forbidden', TRUE, 403) || die('Restricted'));

if (!class_exists('WPSiteSyncContent', FALSE)) {
	/*
	 * Main plugin declaration
	 * @package WPSiteSync
	 * @author Dave Jesch
	 */
	class WPSiteSyncContent
	{
		const PLUGIN_VERSION = '1.2';
		const PLUGIN_NAME = 'WPSiteSyncContent';

		private static $_instance = NULL;
		const DEBUG = TRUE;

		/* options data */
		private static $_config = NULL;
		/* array of paths to use in autoloading */
		private static $_autoload_paths = array();

		const API_ENDPOINT = 'wpsitesync_api';		// name of endpoint: /wpsitesync_api/ - underscores less likely in name

		private function __construct()
		{
			// set up autoloading
			spl_autoload_register(array(&$this, 'autoload'));

			// activation hooks
			register_activation_hook(__FILE__, array(&$this, 'activate'));
			register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));

			add_action('plugins_loaded', array(&$this, 'endpoints_init'));
			// don't need the wp_ajax_noprov callback- AJAX calls are always within the admin
			add_action('wp_ajax_spectrom_sync', array(&$this, 'check_ajax_query'));

			add_action('plugins_loaded', array(&$this, 'plugins_loaded'));

			if (is_admin())
				SyncAdmin::get_instance();
		}

		/*
		 * retrieve singleton class instance
		 * @return instance reference to plugin
		 */
		public static function get_instance()
		{
			if (NULL === self::$_instance)
				self::$_instance = new self();
			return self::$_instance;
		}

		/**
		 * Returns the installation directory for this plugin.
		 * @return string The installation directory
		 */
		public static function get_plugin_path()
		{
			return plugin_dir_path(__FILE__);
		}

		/*
		 * autoloading callback function
		 * @param string $class name of class to autoload
		 * @return TRUE to continue; otherwise FALSE
		 */
		public function autoload($class)
		{
			$path = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'classes' . DIRECTORY_SEPARATOR;
			// setup the class name
			$classname = $class = strtolower($class);
			if ('sync' === substr($class, 0, 4))
				$classname = substr($class, 4);		// remove 'sync' prefix on class file name

			// check each path
			$classfile = $path . $classname . '.php';

			if (file_exists($classfile))
				require_once($classfile);
		}

		/*
		 * Adds a directory to the list of autoload directories. Can be used by add-ons
		 * to include additional directories to look for class files in.
		 * @param string $dirname the directory name to be added
		 */
		public static function add_autoload_directory($dirname)
		{
			if (substr($dirname, -1) != DIRECTORY_SEPARATOR)
				$dirname .= DIRECTORY_SEPARATOR;

			self::$_autoload_paths[] = $dirname;
		}

		/*
		 * called on plugin first activation
		 */
		public function activate()
		{
			// load the installation code
			require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'activate.php');
		}

		/**
		 * Runs on plugin deactivation
		 */
		public function deactivate()
		{
			require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'install' . DIRECTORY_SEPARATOR . 'deactivate.php');
		}

		/*
		 * return reference to asset, relative to the base plugin's /assets/ directory
		 * @param string $ref asset name to reference
		 * @return string href to fully qualified location of referenced asset
		 */
		// TOOD: move into utility class
		public static function get_asset($ref)
		{
			$ret = plugin_dir_url(__FILE__) . 'assets/' . $ref;
			return $ret;
		}

		/**
		 * Checks for an AJAX request and initializes the AJAX class to dispatch any found action.
		 */
		public function check_ajax_query()
		{
			if (defined('DOING_AJAX') && DOING_AJAX) {
				$ajax = new SyncAjax();
				$ajax->dispatch();
			}
		}

		/**
		 * Define the API endpoints
		 */
		public function endpoints_init()
		{
			$options = array (
				'callback' => array('SyncApiController', '__construct'),
				'name' => WPSiteSyncContent::API_ENDPOINT,
				'position' => EP_ROOT
			);

			new SyncApiModel($options);
		}

		/**
		 * Callback for the 'plugins_loaded' action. Load text doamin and notify other WPSiteSync add-ons that WPSiteSync is loaded.
		 */
		public function plugins_loaded()
		{
			load_plugin_textdomain('wpsitesynccontent', FALSE, plugin_basename(dirname(__FILE__)) . '/languages');
			do_action('spectrom_sync_init');
		}
	}
}

// Initialize the plugin
WPSiteSyncContent::get_instance();

// EOF
