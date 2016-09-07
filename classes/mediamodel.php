<?php

/**
 * Models the `spectrom_sync_media` table.
 * Table stores information on Target site for items pushed to it, allowing for better management when syncing pre-existing data.
 */
class SyncMediaModel
{
	const MEDIA_TABLE = 'spectrom_sync_media';
	private $_media_table = NULL;

	public function __construct()
	{
		global $wpdb;
		$this->_media_table = $wpdb->prefix . self::MEDIA_TABLE;
	}

	/**
	 * Saves a sync media record to the database.
	 * @param array $data The sync data.
	 * @return boolean TRUE on successful logging; otherwise FALSE.
	 */
	public function log($data)
	{
		global $wpdb;

		if (isset($data['id']) && $data['id'] > 0)
			$ret = $wpdb->update($this->_media_table, $data, array('id' => $data['id']));
		else
			$ret = $wpdb->insert($this->_media_table, $data);

		// condition handles case where update() returns numeric number of items updated
		return (FALSE === $ret) ? FALSE : TRUE;
	}

	/**
	 * Gets sync media data based on site_key and remote media filename
	 * @param int $remote_media_name The remote media filename
	 * @param int $site_key The site_key associated with the sync operation or NULL to use current site's key
	 * @return mixed Returns NULL if no result is found, else an object
	 */
	public function get_data($remote_media_name, $site_key = NULL)
	{
		global $wpdb;

		if (NULL === $site_key)
			$site_key = SyncOptions::get('site_key');

		$query = "SELECT *
				FROM `{$this->_media_table}`
				WHERE `site_key`=%s AND `remote_media_name`=%s
				LIMIT 1";
		$sql = $wpdb->prepare($query, $site_key, $remote_media_name);
SyncDebug::log(__METHOD__.'() sql: ' . $sql);
		return $wpdb->get_row($sql);
	}
}
