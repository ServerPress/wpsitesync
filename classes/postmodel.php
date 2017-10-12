<?php

class SyncPostModel
{
	private $_edit_user_id = FALSE;

	/**
	 * Returns a post object for a given post title
	 * @param string $title The post_title value to search for
	 * @param string $post_type The post_type to look for; defaults to 'post'
	 * @return WP_Post|NULL The WP_Post object if the title is found; otherwise NULL.
	 */
	public function get_post_by_title($title, $post_type = 'post')
	{
		global $wpdb;

		$sql = "SELECT `ID`
				FROM `{$wpdb->posts}`
				WHERE `post_title`=%s AND `post_type`=%s
				LIMIT 1";
		// TODO: check if need to santize title to match what WP does with post titles? title should already be sanitized though
		$res = $wpdb->get_results($wpdb->prepare($sql, $title, $post_type), OBJECT);
SyncDebug::log(__METHOD__.'() ' . $wpdb->last_query . ': ' . var_export($res, TRUE));

		if (1 === count($res)) {
			$post_id = $res[0]->ID;
SyncDebug::log('- post id=' . $post_id);
			$post = get_post($post_id, OBJECT);

			return $post;
		}
		return NULL;
	}

	/**
	 * Helper method to find potential match on Target site based on Search Mode setting on Source site.
	 * @param array $post_data Post data sent from Source site
	 * @param string $mode The match mode to use to perform post lookup. One of 'title', 'slug' or 'id'
	 * @return int The Target post ID for the matching post or 0 to indicate no match found.
	 */
	public function lookup_post($post_data, $mode = 'title')
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' mode=' . $mode);
		$target_post_id = 0;

		switch ($mode) {
		case 'title':
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' look up by title=' . $post_data['post_title']);
			$post = $this->get_post_by_title($post_data['post_title'], $post_data['post_type']);
			if (NULL !== $post)
				$target_post_id = $post->ID;
			break;
		case 'slug':
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' look up by slug=' . $post_data['post_name']);
			// TODO: use get_page_by_path() instead
			$args = array(
				'name' => $post_data['post_name'],
				'post_type' => $post_data['post_type'],
				'post_status' => 'any',
				'numberposts' => 1,
			);
			$posts = get_posts($args);
			if ($posts)
				$target_post_id = abs($posts[0]->ID);
			break;
		case 'id':
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' look up by ID=' . $post_data['ID']);
			$target_post_id = abs($post_data['ID']);
			break;
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target post id=' . $target_post_id);
		return $target_post_id;
	}

	/**
	 * Check if the specified post is currently being edited by another user
	 * @param int $post_id The post ID to be checked
	 * @return boolean TRUE if currently being edited; otherwise FALSE
	 */
	public function is_post_locked($post_id)
	{
		if (!function_exists('wp_check_post_lock'))
			require_once(ABSPATH . 'wp-admin/includes/post.php');

		if (FALSE !== ($user = wp_check_post_lock($post_id))) {
			$this->_edit_user_id = $user;
			return TRUE;
		}

		$this->_edit_user_id = FALSE;
		return FALSE;
	}

	/**
	 * Returns information on the user that has a post locked. Must be used directly after is_post_locked()
	 * @return array An array with ['user_id'], ['user_login'] and ['user_email'] elements filled in.
	 */
	public function get_post_lock_user()
	{
		$ret = array();

		if (FALSE !== $this->_edit_user_id) {
			$ret['user_id'] = $this->_edit_user_id;

			$user = get_user_by('id', $this->_edit_user_id);

			$ret['user_login'] = $user->user_login;
			$ret['user_email'] = $user->user_email;
		}

		return $ret;
	}
}

// EOF