<?php

class SyncModel
{
	const SYNC_TABLE = 'spectrom_sync';

	private $_sync_table = NULL;
	private static $_taxonomies = array();
	private $_edit_user_id = FALSE;

	public function __construct()
	{
		global $wpdb;

		$this->_sync_table = $wpdb->prefix . self::SYNC_TABLE;
	}

	/**
	 * Return the table name, prefixed.
	 * @return string
	 */
	public function get_table($table)
	{
		global $wpdb;

		return $wpdb->prefix . $table;
	}

	/**
	 * Removes the sync record for the specific id and content type
	 * @param int $target_id The ID of the content to remove
	 * @param string $content_type The content type, defaults to 'post'
	 */
	public function remove_sync_data($target_id, $content_type = 'post')
	{
		global $wpdb;
		$sql = "DELETE FROM `{$this->_sync_table}`
			WHERE `target_content_id`=%d AND `content_type`=%s
			LIMIT 1";
		$sql = $wpdb->prepare($sql, $target_id, $content_type);
SyncDebug::log(__METHOD__.'() sql=' . $sql);
		$wpdb->query($sql);
	}

	/**
	 * Removes all WPSiteSync data for a given post ID
	 * @param int $post_id The ID of the Content to remove. Can be used on Source or Target; uses site key to distinguish context.
	 */
	public function remove_all_sync_data($post_id)
	{
		$site_key = SyncOptions::get('site_key');

		global $wpdb;
		$sql = "DELETE FROM `{$this->_sync_table}`
			WHERE (`source_content_id`=%d AND `site_key`=%s) OR
				(`target_content_id`=%d AND `target_site_key`=%s)";
		$sql = $wpdb->prepare($sql, $post_id, $site_key, $post_id, $site_key);
SyncDebug::log(__METHOD__.'() sql=' . $sql);
		$wpdb->query($sql);
	}

	/**
	 * Saves a sync record to the database.
	 * @param array $data The sync data.
	 * @return boolean TRUE or FALSE on success.
	 */
	public function save_sync_data($data)
	{
		global $wpdb;

		// sanitize the `content_type` data
		if (!isset($data['content_type']))
			$data['content_type'] = 'post';					// default content type to 'post'
		else
			$data['content_type'] = sanitize_key($data['content_type']);

		// set the `last_updated` data
		$data['last_update'] = current_time('mysql');
		// set the wp version if not already set
		if (empty($data['wp_version'])) {
			global $wp_version;
			$data['wp_version'] = $wp_version;
		}
		// set the sync version if not already set
		if (empty($data['sync_version']))
			$data['sync_version'] = WPSiteSyncContent::PLUGIN_VERSION;

		// Check for an existing record
		$sync_data = $this->get_sync_data($data['source_content_id'],
			(isset($data['site_key']) ? $data['site_key'] : NULL),
			$data['content_type']);

		if (NULL !== $sync_data) {
SyncDebug::log(__METHOD__.'() updating ' . $data['source_content_id']);
			$wpdb->update($this->_sync_table, $data, array('sync_id' => $sync_data->sync_id));
		} else {
SyncDebug::log(__METHOD__.'() inserting ' . $data['source_content_id']);
			$res = $wpdb->insert($this->_sync_table, $data);
			// TODO: when insert fails, display error message/recover
//SyncDebug::log(__METHOD__.'() res=' . var_export($res, TRUE));
//if (FALSE === $res)
//	SyncDebug::log(__METHOD__.'() sql=' . $wpdb->last_query);
		}
	}

	/**
	 * Gets sync data based on site_key and the post ID from the Source site
	 * @param int $source_id The post ID coming from the Source site
	 * @param string $site_key The site_key associated with the sync operation
	 * @param string $type The content type being searched, defaults to 'post'
	 * @return mixed Returns NULL if no result is found, else an object
	 */
	public function get_sync_data($source_id, $site_key = NULL, $type = 'post')
	{
		global $wpdb;

		if (NULL === $site_key)
			$site_key = SyncOptions::get('site_key');

		$where = '';
		if (NULL !== $type) {
			$type = sanitize_key($type);
			$where = " AND `content_type`='{$type}' ";
		}

		$query = "SELECT *
				FROM `{$this->_sync_table}`
				WHERE `source_content_id`=%d AND `site_key`=%s {$where}
				LIMIT 1";
$sql = $wpdb->prepare($query, $source_id, $site_key);
SyncDebug::log(__METHOD__.'() sql: ' . $sql);
		return $wpdb->get_row($sql);
	}

	/**
	 * Gets sync data based on site_key and the post ID from the Target site
	 * @param int $target_id The post ID coming from the Target
	 * @param int $target_site_key The site_key associated with the sync operation
	 * @param string $type The content type being searched, defaults to 'post'
	 * @return mixed Returns NULL if no result is found, else an object matching the Target post ID and Site Key
	 */
	public function get_sync_target_data($target_id, $target_site_key = NULL, $type = 'post')
	{
		if (NULL === $site_key)
			$site_key = SyncOptions::get('site_key');

		$where = '';
		if (NULL !== $type) {
			$type = sanitize_key($type);
			$where = " AND `content_type`='{$type}' ";
		}

		global $wpdb;
		$query = "SELECT *
					FROM `{$this->_sync_table}`
					WHERE `target_content_id`=%d AND `target_site_key`=%s {$where}
					LIMIT 1";
$sql = $wpdb->prepare($query, $target_id, $target_site_key);
SyncDebug::log(__METHOD__.'() sql: ' . $sql);
		return $wpdb->get_row($sql);
	}

	/**
	 * Find the Target's post ID given the Source's post ID and the Target's Site Key
	 * @param int $source_post_id Post ID of the Content on the Source
	 * @param string $target_site_key The Target's Site Key
	 * @param string $type The post type, defaults to 'post'
	 * @return object representing the found Sync data record or NULL if not found
	 */
	public function get_sync_target_post($source_post_id, $target_site_key, $type = 'post')
	{
		$where = '';
		if (NULL !== $type) {
			$type = sanitize_key($type);
			$where =" AND `content_type`='{$type}' ";
		}

		global $wpdb;
		$query = "SELECT *
					FROM `{$this->_sync_table}`
					WHERE `source_content_id`=%d AND `target_site_key`=%s {$where}";
		$sql = $wpdb->prepare($query, $source_post_id, $target_site_key);
		$ret = $wpdb->get_row($sql);
SyncDebug::log(__METHOD__.'() sql=' . $sql . ' returned ' . var_export($ret, TRUE));
		return $ret;
	}

	/**
	 * Updates an existing record in the table with new information
	 * @global type $wpdb
	 * @param type $where
	 * @param type $update
	 */
	public function update($where, $update)
	{
		global $wpdb;
		$wpdb->update($this->_sync_table, $update, $where);
	}

	/**
	 * Build the array of post data to be used in a Sync call
	 * @param int $post_id The post ID
	 * @param string $request_type The type of operation being performed
	 * @return array An array of the post information
	 */
	// TODO: move to utility class
	public function build_sync_data($post_id, $request_type = 'push')
	{
		// TODO: need to add permalink setting data to content being built

		// Create an array of data containing references to all information associated with the indicated post ID.
		$push_data = array();

		// This will include content from the wp_post table, the wp_postmeta table, as well as information on any registered Favorite Image, or 
		$args = array(
			'p' => $post_id,
			'post_type' => apply_filters('spectrom_sync_allowed_post_types', array('post', 'page')),
			'post_status' => array('publish', 'pending', 'draft', 'future', 'private', 'trash'),
			'posts_per_page' => 1,
		);

		$query = new WP_Query($args);
		// TODO: add failure checking
SyncDebug::log(__METHOD__.'() post id=' . $post_id);

		if (0 === $query->found_posts)
			return $push_data;

		$push_data['post_data'] = (array) $query->posts[0];

		// other images connected to the current post ID.
		if (function_exists('get_attached_media'))
			$push_data['post_media'] = get_attached_media('', $post_id);

		// TOOD: these are handled via headers
		// also include the version number of WordPress
		global $wp_version;
		$push_data['wp_version'] = $wp_version;
		// also include the version number of Sync
		$push_data['sync_version'] = WPSiteSyncContent::PLUGIN_VERSION;

		// also include the hostname of the Source site
		$url = parse_url(get_bloginfo('url'), PHP_URL_HOST);
		$push_data['origin'] = $url;

		// also include the ‘site_key’ value generated on initial installation.
		$push_data['site_key'] = SyncOptions::get('site_key');

		// include 'stickiness'
		if (is_sticky($post_id))
			$push_data['sticky'] = 1;

		// get the post's meta content
		$push_data['post_meta'] = $this->_build_post_meta($post_id);

		// add taxonomy information
		$push_data['taxonomies'] = $this->_build_tax_data($post_id, $push_data['post_data']['post_type']); // $query->posts[0]->post_type

		// add featured image data
		$post_thumbnail_id = get_post_thumbnail_id($post_id);
		$push_data['thumbnail'] = $post_thumbnail_id;

		// use filter to add additional information to the data array
		// Note: this has been moved to SyncApiRequest::api()
//		$push_data = apply_filters('spectrom_sync_api_request', $push_data, $request_type, $request_type);
// send the data array through a filter using: apply_filters(‘spectrom_sync_push_data’, $array, $postid);
// this filter allows future add-ons to modify the contents of the array before it is sent to the Target site.

		return $push_data;
	}

	/**
	 * Build a list of the post meta data for the post
	 * @param int $post_id The post ID of the Content being Sync'd
	 * @return array The post meta data
	 */
	private function _build_post_meta($post_id)
	{
		// any postmeta data associated with the current post ID that is prefixed with ‘_spectrom_sync_’ is not to be collected
		$post_meta = get_post_meta($post_id);
		if ($post_meta) {
			$skip_keys = array('_edit_lock', '_edit_last');
			foreach ($post_meta as $key => $value) {
				// remove any '_spectrom_sync_' meta data and the '_edit...' meta data
				if ('_spectrom_sync_' === substr($key, 0, 15) || in_array($key, $skip_keys)) {
					unset($post_meta[$key]);
					continue;
				}
			}
		} else
			$post_meta = array();

		return $post_meta;
	}

	/**
	 * Build a list of the taxonomy data for the post
	 * @param int $post_id The post ID of the Content being Sync'd
	 * @return array An array holding the taxonomy information
	 */
	public function _build_tax_data($post_id, $post_type)
	{
SyncDebug::log(__METHOD__.'() post id #' . $post_id . ' post_type=' . $post_type);
		// https://codex.wordpress.org/Function_Reference/get_taxonomies
		$args = array();
		$taxonomies = $this->get_all_taxonomies(); // get_taxonomies($args, 'objects');
//SyncDebug::log(__METHOD__.'() post tax: ' . var_export($taxonomies, TRUE));

		// get a list of all taxonomy terms associated with the post type
		$tax_names = $this->get_all_tax_names($post_type);
SyncDebug::log(__METHOD__.'() names: ' . var_export($tax_names, TRUE));

		// set up the scaffolding for the returned data object
		$tax_data = array(
			'hierarchical' => array(),					// hierarchical taxonomy information
			'flat' => array(),							// flat taxonomy information
			'lineage' => array(),						// parents of the hierarchical taxonomies
		);

		// get the terms assigned to the post
		$post_terms = wp_get_post_terms($post_id, $tax_names);
		if (is_wp_error($post_terms))
			$post_terms = array();
SyncDebug::log(__METHOD__.'() post terms: ' . var_export($post_terms, TRUE));
		// add the term information to the data object being returned
		foreach ($post_terms as $term_data) {
			$tax_name = $term_data->taxonomy;
			if ($taxonomies[$tax_name]->hierarchical) {
				// this is a hierarchical taxonomy
				$tax_data['hierarchical'][] = $term_data;

				// look up the full list of term parents
				$parent = $term_data->parent;
				while (0 !== $parent) {
					$term = get_term_by('id', $parent, $tax_name, OBJECT);
					$tax_data['lineage'][$tax_name][] = $term;
					$parent = $term->parent;
				}
			} else {
				$tax_data['flat'][] = $term_data;
			}
		}

//SyncDebug::log(__METHOD__.'() returning taxonomy information: ' . var_export($tax_data, TRUE));

		return $tax_data;
	}

	/**
	 * Return a list of all registered taxonomy names
	 * @param $post_type The name of the Post Type to retrieve taxonomy names for or NULL for all Post Types
	 * @return array All taxonomy names
	 */
	public function get_all_tax_names($post_type = NULL)
	{
		$tax_names = array();

		$taxonomies = $this->get_all_taxonomies(); // get_taxonomies(array(), 'objects');
		foreach ($taxonomies as $tax_name => $tax) {
			if (NULL === $post_type || in_array($post_type, $tax->object_type))
				$tax_names[] = $tax_name;
		}

		return $tax_names;
	}

	/**
	 * Retrieves a list of all taxonomies to be checked during Sync process
	 * @return array An array of information describing the taxonomies
	 */
	public function get_all_taxonomies()
	{
		if (0 === count(self::$_taxonomies)) {
			$taxonomies = get_taxonomies(array('_builtin' => TRUE), 'objects');
			$taxonomies = apply_filters('spectrom_sync_tax_list', $taxonomies);
			self::$_taxonomies = $taxonomies;
		}
		return self::$_taxonomies;
	}

	/**
	 * Generates a hash to be used as the site_key option value
	 * @return string The MD5 hash.
	 */
	public function generate_site_key()
	{
		$url = parse_url(site_url(), PHP_URL_HOST);
		$plugin = WPSiteSyncContent::get_instance();
		return md5($url . $plugin->get_plugin_path());
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
