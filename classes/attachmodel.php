<?php

class SyncAttachModel
{
	public function search_by_guid($guid)
	{
		global $wpdb;

		$sql = "SELECT *
				FROM `{$wpdb->posts}`
				WHERE `post_type`='attachment' AND `guid`=%s";
		$query = $wpdb->prepare($sql, $guid);
		$res = $wpdb->get_results($query, OBJECT);
SyncDebug::log(__METHOD__.'() sql=' . $query);
SyncDebug::log(' - res=' . var_export($res, TRUE));
		return $res;
	}
}

// EOF