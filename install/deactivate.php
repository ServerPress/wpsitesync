<?php

/*
 * Performs uninstallation process
 * @package Sync
 * @author Dave Jesch
 */

class SyncDeactivate
{
	/*
	 * called on plugin activation; performs all uninstallation tasks
	 */
	public function plugin_deactivation()
	{
		do_action('spectrom_sync_uninstall');		// let add-ons know that we're going away

		if ('1' === SyncOptions::get('remove', '0')) {
			$this->remove_database_tables();
			$this->remove_options();
			$this->remove_transients();
		}

		return TRUE;
	}

	/**
	 * Returns array containing information on database tables
	 * @return array Database information
	 */
	public static function get_table_data()
	{
		// table names will be prefixed with "{$wpdb->prefix}spectrom_"
		$tables = array(
			'sync_log',
			'sync',
			'sync_sources',
			'sync_media',
		);

		return $tables;
	}

	/**
	 * Removes all settings from the options table
	 */
	protected function remove_options()
	{
		$options = array(
			'spectrom_sync_activated',
			'spectrom_sync_settings',
			'spectrom_sync_licensing',
		);

		foreach ($options as $option)
			delete_option($option);
	}

	/**
	 * Removes all transients
	 */
	protected function remove_transients()
	{
		$trans_keys = array(
			'wpsitesync_extension_list',
		);

		foreach ($trans_keys as $trans_key) {
			delete_transient($trans_key);
		}
	}

	/**
	 * Drops all database tables known to WPSiteSync
	 */
	protected function remove_database_tables()
	{
SyncDebug::log(__METHOD__.'()');
		global $wpdb;

		$tables = $this->get_table_data();
		foreach ($tables as $table) {
			$sql = "DROP TABLE IF EXISTS `{$wpdb->prefix}spectrom_{$table}`";
			$wpdb->query($sql);
		}
SyncDebug::log(__METHOD__.'() - done');
	}
}

$uninstall = new SyncDeactivate();
$uninstall->plugin_deactivation();

// EOF
