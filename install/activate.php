<?php

/*
 * Performs installation process
 * @package Sync
 * @author Dave Jesch
 */

class SyncActivate
{
	// these items are stored under the 'spectrom_sync_settings' option
	protected $default_config = array(
		'url' => '',
		'username' => '',
		'password' => '',
		'site_key' => ''
	);

	/*
	 * called on plugin activation; performs all installation tasks
	 */
	public function plugin_activation()
	{
		$this->create_database_tables();
		$this->create_options();

		return TRUE;
	}

	/**
	 * Returns array containing information on database tables
	 * @return array Database information
	 */
	public static function get_table_data()
	{
		$aRet = array(
			// table names will be prefixed with "{$wpdb->prefix}spectrom_"
			'sync_log' =>
				"CREATE TABLE `sync_log` (
					`id` 				INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`post_id` 			BIGINT(20) UNSIGNED NOT NULL,
					`post_title` 		TEXT NOT NULL,
					`push_date` 		TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
					`operation`			VARCHAR(32) NOT NULL,
					`source_user` 		BIGINT(20) UNSIGNED NOT NULL,
					`source_site` 		VARCHAR(200) NOT NULL,
					`source_site_key`	VARCHAR(40) NOT NULL,
					`target_user`		BIGINT(20) UNSIGNED NOT NULL,

					PRIMARY KEY (`id`),
					INDEX `post_id` (`post_id`)
				)",
			'sync' =>
				"CREATE TABLE `sync` (
					`sync_id` 			INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`site_key` 			VARCHAR(60) NOT NULL,
					`source_content_id` BIGINT(20) UNSIGNED NOT NULL,
					`target_content_id`	BIGINT(20) UNSIGNED NOT NULL,
					`target_site_key`	VARCHAR(60) NULL DEFAULT '',
					`content_type`		VARCHAR(32) NOT NULL DEFAULT 'post',
					`last_update`		DATETIME NOT NULL,
					`wp_version`		VARCHAR(20) NOT NULL,
					`sync_version`		VARCHAR(20) NOT NULL,

					PRIMARY KEY (`sync_id`),
					INDEX `source_content_id` (`source_content_id`),
					INDEX `target_content_id` (`target_content_id`),
					INDEX `content_type` (`content_type`)
				)",
			'sources' =>
				"CREATE TABLE `sync_sources` (
					`id`				INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`domain`			VARCHAR(200) NOT NULL,
					`site_key`			VARCHAR(60) NOT NULL DEFAULT '',
					`auth_name`			VARCHAR(60) NOT NULL,
					`token`				VARCHAR(60) NOT NULL,
					`allowed`			TINYINT(1) UNSIGNED NOT NULL DEFAULT '1',
					PRIMARY KEY (`id`),
					INDEX `site_key` (`site_key`),
					INDEX `allowed` (`allowed`)
				)",
			// TODO: merge this into the `sync` table
			'sync_media' =>
				"CREATE TABLE `sync_media` (
					`id`				INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
					`site_key` 			VARCHAR(60) NOT NULL,
					`remote_media_name` VARCHAR(255) NOT NULL,
					`local_media_name`	VARCHAR(255) NOT NULL,

					PRIMARY KEY (`id`),
					INDEX `site_key` (`site_key`)
				)"
		);

		return $aRet;
	}

	/**
	 * Creates the default options and generates a site_key value if activated for the first time.
	 */
	protected function create_options()
	{
		$sync = WPSiteSyncContent::get_instance();
		$model = new SyncModel();
		// TODO: use SyncOptions class
		$opts = get_option('spectrom_sync_settings');
		$this->default_config['site_key'] = $model->generate_site_key();

		if (FALSE !== $opts) {
			$this->default_config = array_merge($opts, $this->default_config);
			update_option('spectrom_sync_settings', $this->default_config, FALSE, TRUE);
		} else {
			add_option('spectrom_sync_settings', $this->default_config, FALSE, TRUE);
		}
	}

	/**
	 * Runs dbDelta based on the table data from get_table_data() to create the database tables.
	 */
	protected function create_database_tables()
	{
SyncDebug::log(__METHOD__.'()');
		global $wpdb;
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

//		$charset_collate = '';
//		if (!empty($wpdb->charset))
//			$charset_collate = " DEFAULT CHARACTER SET {$wpdb->charset} ";

		// determine default collation for tables being created
//		$collate = NULL;
//		if (defined('DB_COLLATE'))
//			$collate = DB_COLLATE;							// if the constant is declared, use it
//		if ('utf8_unicode_ci' === $collate)					// fix for CREATE TABLEs on WPEngine
//			$collate = 'utf8mb4_unicode_ci';
//		if (empty($collate) && !empty($wpdb->collate))		// otherwise allow wpdb class to specify
//			$collate = $wpdb->collate;
//		if (!empty($collate))
//			$charset_collate .= " COLLATE {$collate} ";
		$charset_collate = $wpdb->get_charset_collate();

		$aTables = $this->get_table_data();
		foreach ($aTables as $table => $sql) {
			$sql = str_replace('CREATE TABLE `', 'CREATE TABLE `' . $wpdb->prefix . 'spectrom_', $sql);
			$sql .= $charset_collate;
SyncDebug::log(__METHOD__.'() sql=' . $sql);

			ob_start();
			$ret = dbDelta($sql);
			$res = ob_get_clean();
SyncDebug::log(__METHOD__.'() dbDelta() results: ' . $res);
		}
SyncDebug::log(__METHOD__.'() - done');
	}
}

$install = new SyncActivate();
$res = $install->plugin_activation();
if (!$res) {
	// error during installation - disable
	deactivate_plugins(plugin_basename(dirname(__FILE__)));
}

// EOF
