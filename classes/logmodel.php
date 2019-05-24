<?php

/**
 * Performs logging of all push activities on Target site
 */
class SyncLogModel
{
	const LOG_TABLE = 'spectrom_sync_log';
	private $_log_table = NULL;

/*
		`id` 				INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
		`post_id` 			BIGINT(20) UNSIGNED NOT NULL,
		`post_title` 		TEXT NOT NULL,
		`push_date` 		TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
		`operation`			VARCHAR(32) NOT NULL,
		`source_user` 		BIGINT(20) NOT NULL,
		`source_site` 		VARCHAR(200) NOT NULL,
		`source_site_key`	VARCHAR(40) NOT NULL,
		`type`				varchar(4) NOT NULL DEFAULT 'recv'
 */

	public function __construct()
	{
		global $wpdb;
		$this->_log_table = $wpdb->prefix . self::LOG_TABLE;
	}

	/**
	 * Saves log data to the database.
	 * @param array $data The sync data.
	 * @return boolean TRUE or FALSE on success.
	 */
	public function log($data)
	{
		global $wpdb;
		// TODO: get the source user id from SyncApiController
		// TODO: ensure source_site is present. remove after ApiController guarantees presence of Site Key
		if (empty($data['source_site']))
			$data['source_site'] = '';
		if (!isset($data['source_user']))
			$data['source_user'] = 0;
		if (!isset($data['post_title']))
			$data['post_title'] = '';
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' logging: ' . var_export($data, TRUE));
		return $wpdb->insert($this->_log_table, $data);
	}

	/**
	 * Return a count of the number of entries in the log table of a given type
	 * @param string $type either 'send' for sent calls to this site or 'recv' for recevied calls by this site
	 * @return int
	 */
	public function get_count($type = 'recv')
	{
		if (empty($type))
			$type = 'recv';
		else
			$type = sanitize_key($type);

		$sql = "SELECT COUNT(*)
				FROM `{$this->_log_table}`
				WHERE `type`='{$type}'";
		global $wpdb;
		$res = $wpdb->get_col($sql);
		if (NULL === $res)
			return 0;
		return $res[0];
	}
}

// EOF