<?php
/*
Plugin Name: WPSiteSync for Content
Plugin URI: https://wpsitesync.com
Description: Provides features for easily Synchronizing Content between two WordPress sites.
Author: WPSiteSync
Author URI: http://wpsitesync.com
Version: 1.3.2
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
		const PLUGIN_VERSION = '1.3.2';
		const PLUGIN_NAME = 'WPSiteSyncContent';

		private static $_instance = NULL;
		const DEBUG = TRUE;

		private static $_license = NULL;				// instance of licensing module
		private static $_autoload_paths = array();		// array of paths to use in autoloading

		const API_ENDPOINT = 'wpsitesync_api';			// name of endpoint: /wpsitesync_api/ - underscores less likely in name

		private $_performing_upgrade = FALSE;			// set to TRUE during plugin update process

		private function __construct()
		{
			// set up autoloading
			spl_autoload_register(array(&$this, 'autoload'));

			// activation hooks
			register_activation_hook(__FILE__, array(&$this, 'activate'));
			register_deactivation_hook(__FILE__, array(&$this, 'deactivate'));

			add_action('plugins_loaded', array(&$this, 'endpoints_init'), 1);
			// don't need the wp_ajax_noprov callback- AJAX calls are always within the admin
			add_action('wp_ajax_spectrom_sync', array(&$this, 'check_ajax_query'));

			add_action('plugins_loaded', array(&$this, 'plugins_loaded'));

			// the following are needed during add-on updates to fix problem with long file names on Windows
			add_filter('wp_unique_filename', array($this, 'filter_unique_filename'), 10, 4);
			add_filter('upgrader_pre_download', array($this, 'filter_upgrader_download'), 10, 3);

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
		 * Return instance of licensing object
		 * @return SyncLicensing instance of the licensing object
		 */
		public function get_license()
		{
			// this is just in case somebody calls this before the 'spectrom_sync_init' action is fired
			if (NULL === self::$_license)
				self::$_license = new SyncLicensing();
			return self::$_license;
		}

		/**
		 * Callback for the 'plugins_loaded' action. Load text doamin and notify other WPSiteSync add-ons that WPSiteSync is loaded.
		 */
		public function plugins_loaded()
		{
			self::$_license = new SyncLicensing();

//SyncDebug::log(__METHOD__.'()');
//SyncDebug::log(__METHOD__.'() url=' . $_SERVER['REQUEST_URI']);
//SyncDebug::log(__METHOD__.'() post=' . var_export($_POST, TRUE));
//SyncDebug::log(__METHOD__.'() req=' . var_export($_REQUEST, TRUE));
			load_plugin_textdomain('wpsitesynccontent', FALSE, plugin_basename(dirname(__FILE__)) . '/languages');
			do_action('spectrom_sync_init');
			self::check_updates();
//SyncDebug::log(__METHOD__.'() - saving licenses');
			self::$_license->save_licenses();
		}

		/**
		 * setup checks for plugin update notifications
		 */
		public static function check_updates()
		{
			// load updater class
			if (!class_exists('EDD_SL_Plugin_Updater', FALSE)) {
				$file = dirname(__FILE__) . '/install/pluginupdater.php';
				require_once($file);
			}

			$update_data = self::$_license->get_update_data();

			// setup the updater instance for each add-on
			foreach ($update_data['extensions'] as $extension) {
//SyncDebug::log(__METHOD__.'() creating updater instance for ' . $extension['name']);
				$edd_updater = new EDD_SL_Plugin_Updater($update_data['store_url'], $extension['file'], array(
					'version'	=> $extension['version'],						// current version number
					'license'	=> $extension['license'],						// license key
					'item_name'	=> $extension['name'],							// name of this plugin
					'author'	=> 'WPSiteSync',								// author of this plugin
					'url'		=> home_url(),
				));
			}
		}

		/**
		 * Callback function to filter the filename created in wp_tmpname()
		 * @param string $filename The filename being created
		 * @param string $ext The file's extension
		 * @param string $dir The file's directory
		 * @param callback $unique_filename_callback
		 * @return string The file name to use
		 */
		public function filter_unique_filename($filename, $ext, $dir, $unique_filename_callback)
		{
SyncDebug::log(__METHOD__.'() filename=' . $filename . ' ext=' . $ext . ' dir=' . $dir);
			// if WP is upgrading a plugin and the filename is too long, shorten it
			if ($this->_performing_upgrade && (strlen($filename) > 120 && '.tmp' === $ext)) {
				$filename = md5($filename) . $ext;
SyncDebug::log(__METHOD__.'() modifying filename=' . $filename);
			}
			return $filename;
		}

		/**
		 * Callback for 'upgrader_pre_download' filter called in WP_Upgrader->download_package().
		 * Used to signal the filter_unique_filename() method to modify the filename if it's too long
		 * @param boolean $result Result value
		 * @param string $package The package URL
		 * @param WP_Upgrader $wp_upgrader The instance of the WP_Upgrader class
		 * @return boolean The unmodified $result value
		 */
		public function filter_upgrader_download($result, $package, $wp_upgrader)
		{
SyncDebug::log(__METHOD__.'() package=' . var_export($package, TRUE));
			// use this filter from WP_Upgrader->download_package() to signal that a download package filename needs to be checked
			$this->_performing_upgrade = TRUE;
			return $result;
		}
	}
}

// Initialize the plugin
WPSiteSyncContent::get_instance();

// EOF
