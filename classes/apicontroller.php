<?php

/**
 * Routes incoming calls to the API.
 *
 * @package Sync
 */
class SyncApiController extends SyncInput implements SyncApiHeaders
{
	const REQUIRE_NONCES = FALSE;

	private static $_instance = NULL;

	protected $media_id = 0;						// id of the media being handled
	protected $local_media_name = '';
	public $source_site_key = NULL;					// the Source site's key

	private $_headers = NULL;						// stores request headers
	private $_user = NULL;							// authenticated user making request
	private $_auth = 1;								// perform authentication checks

	public $source = NULL;							// the URL of the Source site for the request
	public $source_post_id = 0;						// the post id on the target
	public $post_id = 0;							// the post id being updated
	public $args = array();

	/**
	 * Construct for the Api Controller
	 * Example arguments that may be included:
	 *  ['action'] = The API action to perform, such as 'push', 'pull', etc.
	 *  ['site_key'] = Source system's site key
	 *  ['source'] = Source system's URL
	 *  ['response'] = The SyncApiResponse object from a previouse API request
	 * @param array $args Values to be used in processing the request. If provided, will use these values, otherwise will use "normal" value from Target site.
	 */
	public function __construct($args)
	{
//SyncDebug::log(__METHOD__.'() args=' . var_export($args, TRUE));
		self::$_instance = $this;
		$this->args = $args;

		$action = isset($args['action']) ? $args['action'] : sanitize_key($this->get('action', ''));

		// TODO: verify nonce here so add-ons and APIs don't need to do it themselves

		// use response passed as argument if provided
		if (isset($args['response']))
			$response = $args['response'];
		else
			$response = new SyncApiResponse(TRUE);

		if (isset($args['site_key']))
			$response->nosend = TRUE;

		$this->source_site_key = isset($args['site_key']) ? $args['site_key'] : $this->get_header(self::HEADER_SITE_KEY);

		$this->source = untrailingslashit(isset($args['source']) ? $args['source'] : $this->get_header(self::HEADER_SOURCE));
SyncDebug::log(__METHOD__.'() action=' . $action . ' source=' . $this->source . ' key=' . $this->source_site_key);

SyncDebug::log(__METHOD__.'() - verifying nonce');
		// TODO: skip nonce verification when isset($args['action'])? this would avoid nonce checks when controller is run on Source
		// do nonce verification here so that ALL api calls will fail if the nonce doesn't check out. Otherwise, some add-on may be able to skip it.
		if ('auth' !== $action &&
			(self::REQUIRE_NONCES && !wp_verify_nonce($this->get('_spectrom_sync_nonce'), $this->get('site_key')))) {
SyncDebug::log(__METHOD__.'() failed nonce check ' . __LINE__);
SyncDebug::log(' sync_nonce=' . $this->get('_spectrom_sync_nonce') . '  site_key=' . $this->get('site_key'));
			$response->error_code(SyncApiRequest::ERROR_SESSION_EXPIRED);
//			$response->success(FALSE);
//			$response->error_code(SyncApiRequest::ERROR_SESSION_EXPIRED);
			$response->send();		// calls die()
		}

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking auth argument args=' . var_export($args, TRUE));
		if (isset($args['auth']) && 0 === $args['auth']) {
//SyncDebug::log(__METHOD__.'() skipping authentication as per args');
			$this->_auth = 0;
		} else {
			if ('auth' !== $action) {
SyncDebug::log(__METHOD__.'() checking credentials');
				$auth = new SyncAuth();
				$user = $auth->check_credentials($response);
				// check to see if credentials passed
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' auth failed ' . var_export($user, TRUE));
				if ($response->has_errors())
					$response->send();
			}
		}

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' check action: ' . $action);
		switch ($action) {
		case '':
			$response->error_code(SyncApiRequest::ERROR_UNRECOGNIZED_REQUEST);
			break;

		case 'auth':
			// handles authentication operations
			$auth = new SyncAuth();
			$auth->check_credentials($response);
			break;

		case 'push':
			// handles push operations
			$this->push($response);
			break;

		case 'upload_media':
			// handles media upload operations
			$this->upload_media($response);
			break;

		case 'getinfo':
			$this->get_info($response);
			break;

		default:
SyncDebug::log(__METHOD__."() sending action '{$action}' to filter 'spectrom_sync_api'");
			// let add-ons have a chance to process the request
			// handle with filter. Callbacks need to return TRUE. So if FALSE is returned it's an invalic request
			$res = apply_filters('spectrom_sync_api', FALSE, $action, $response);
			if (FALSE === $res)
				$response->error_code(SyncApiRequest::ERROR_UNRECOGNIZED_REQUEST);
		}

		// make sure there are no errors
		if (!$response->has_errors()) {
			// allow add-ons to do post-processing on api actions
			do_action('spectrom_sync_api_process', $action, $response, $this);
		}

		$response->send();		// calls die()
	}

	/**
	 * Stores the WP_User object into class property
	 * @param WP_User $user The user object to store in this instance
	 */
	public function set_user($user)
	{
		if (NULL === $this->_user)
			$this->_user = $user;
	}

	/**
	 * Determines if user making API request has the specific capability.
	 * @param string $cap The capability name to check
	 * @param NULL|id $id The id of a meta capability object or NULL
	 * @return boolean TRUE if the API user has sufficient permission to perform action; otherwise FALSE.
	 */
	public function has_permission($cap, $id = NULL)
	{
//SyncDebug::log(__METHOD__."('{$cap}')");
		if (0 === $this->_auth)			// are we explicitly skpping authentication checks?
			return TRUE;				// _auth is set to 0 when controller is created with $args['auth'] => 0

		if (NULL === $id) {
//$res = $this->_user->has_cap($cap);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' has_cap(' . $cap . ') returning ' . var_export($res, TRUE));
			return $this->_user->has_cap($cap);
		}
//$res = $this->_user->has_cap($cap, $id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' has_cap(' . $cap . ') returning ' . var_export($res, TRUE));
		return $this->_user->has_cap($cap, $id);
	}

	/**
	 * Returns the last created instance of the Controller object. Needed by some API requests in order to obtain Site Key, etc.
	 * @return object A SyncApiController instance
	 */
	public static function get_instance()
	{
		return self::$_instance;
	}

	/**
	 * Returns the specified Apache request header
	 * @param string $name The name of the request header to retrieve
	 * @return string|NULL The requested header value or NULL if the named header is not found
	 */
	public function get_header($name)
	{
		if (NULL === $this->_headers) {
			if (!function_exists('apache_request_headers')) {
//SyncDebug::log(__METHOD__.'() using _get_request_headers()');
				$hdrs = $this->_get_request_headers();
			} else {
//SyncDebug::log(__METHOD__.'() using apache_request_headers()');
				$hdrs = apache_request_headers();
			}
			$this->_headers = array();
			foreach ($hdrs as $key => $value) {
				$this->_headers[strtolower($key)] = $value;
			}
//SyncDebug::log(__METHOD__.'() read request headers: ' . var_export($this->_headers, TRUE));
		}

		// TODO: fallback in case site key isn't found in headers. need to resolve
		if (self::HEADER_SITE_KEY === $name && empty($this->_headers[$name]))
			return '';

		if (isset($this->_headers[$name]))
			return $this->_headers[$name];
		return NULL;
	}

	/**
	 * Implementation of get_request_headers() in case it is not present on host
	 * @return array An array containing the request headers
	 */
	private function _get_request_headers()
	{
		$arh = array();
		$rx_http = '/\AHTTP_/';

		foreach ($_SERVER as $key => $val) {
			if (preg_match($rx_http, $key)) {
				$arh_key = preg_replace($rx_http, '', $key);
				$rx_matches = array();
				// do some nasty string manipulations to restore the original letter case
				// this should work in most cases
				$rx_matches = explode('_', $arh_key);
				if (count($rx_matches) > 0 && strlen($arh_key) > 2) {
					foreach ($rx_matches as $ak_key => $ak_val)
						$rx_matches[$ak_key] = ucfirst($ak_val);
					$arh_key = implode('-', $rx_matches);
				}
				$arh_key = strtolower($arh_key);
				$arh[$arh_key] = $val;
			}
		}
//SyncDebug::log(__METHOD__.'() headers: ' . var_export($arh, TRUE));
		return $arh;
	}

	/**
	 * Handles 'push' requests from Source site. Creates/updates post and metadata.
	 * @param SyncApiResponse $response
	 */
	public function push(SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__.'():'.__LINE__);
SyncDebug::log(' post data: ' . var_export($_POST, TRUE));
//SyncDebug::log(' request data: ' . var_export($_REQUEST, TRUE));
		// TODO: need to assume failure, not success - then set to success when successful
		$response->success(TRUE);

		// TODO: check the post_author and make sure it exists, otherwise return SyncApiRequest::ERROR_USER_NOT_FOUND
		// TODO: check for permalink differences. If found, return SyncApiRequest::ERROR_PERMALINK_MISMATCH

		// TODO: validate post_data contents - need to have post_title, post_id, post_modified and a few others. If not, return SyncApiRequest::ERROR_POST_DATA_INCOMPLETE

		$post_data = $this->post_raw('post_data', array());
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - post_data=' . var_export($post_data, TRUE));

		$this->source_post_id = abs($post_data['ID']);
//		if (0 === $this->source_post_id && isset($post_data['post_id']))
//			$this->source_post_id = abs($post_data['post_id']);
SyncDebug::log('- syncing post data Source ID#'. $this->source_post_id . ' - "' . $post_data['post_title'] . '"');

		// Check if a post_id was specified, indicating an update to a previously synced post
		$target_post_id = $this->post_int('target_post_id', 0);

		// let add-ons know we're about to process a Push operation
		do_action('spectrom_sync_pre_push_content', $post_data, $this->source_post_id, $target_post_id, $response);

		// allow add-ons to modify the content type
		$content_type = apply_filters('spectrom_sync_push_content_type', 'post', $target_post_id, $this);

		$post = NULL;
		if (0 !== $target_post_id) {
SyncDebug::log(' - target post id provided in API: ' . $target_post_id);
			$post = get_post($target_post_id);
		}

		// use Source's post id to lookup Target id
		if (NULL === $post) {
SyncDebug::log(' - look up target id from source id: ' . $this->source_post_id);
			$model = new SyncModel();
			// use source's site_key for the lookup
			// TODO: use a better variable name than $sync_data
			$sync_data = $model->get_sync_data($this->source_post_id, $this->source_site_key, $content_type);
SyncDebug::log('   sync_data: ' . var_export($sync_data, TRUE));
			if (NULL !== $sync_data) {
SyncDebug::log(' - found target post #' . $sync_data->target_content_id);
				$post = get_post($sync_data->target_content_id);
				$target_post_id = $sync_data->target_content_id;
			}
		} else {
			$this->post_id = $target_post_id;
		}
###$post = NULL; ###
		// Get post by title, if new
		if (NULL === $post) {
			$mode = $this->get_header(self::HEADER_MATCH_MODE, 'title');
//SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - still no post found - use lookup_post() mode=' . $mode);
			$post_model = new SyncPostModel();
			$target_post_id = $post_model->lookup_post($post_data, $mode);
		}

		// allow add-ons to modify the ultimate target post id
		$target_post_id = apply_filters('spectrom_sync_push_target_id', $target_post_id, $this->source_post_id, $this->source_site_key);
		if (0 !== $target_post_id)
			$post = get_post($target_post_id);

SyncDebug::log('- found post: ' . var_export($post, TRUE));


		// do not allow the push if it's not a recognized post type
		if (!in_array($post_data['post_type']/*$post->post_type*/, apply_filters('spectrom_sync_allowed_post_types', array('post', 'page')))) {
SyncDebug::log(' - checking post type: ' . $post_data['post_type']/*$post->post_type*/);
			$response->error_code(SyncApiRequest::ERROR_INVALID_POST_TYPE);
			return;
		}

		// check parent page- don't allow if parent doesn't exist
		if (0 !== abs($post_data['post_parent'])) {
			$model = new SyncModel();			// does this already exist?
SyncDebug::log(__METHOD__.'() looking up parent post #' . $post_data['post_parent']);
//			$parent_post = $model->get_sync_target_data(abs($post_data['post_parent']), $this->post('site_key'));
			$parent_post = $model->get_sync_data(abs($post_data['post_parent']), $this->source_site_key, $content_type);
			if (NULL === $parent_post) {
				// cannot find parent post on Target system- cannot allow push operation to continue
				$response->error_code(SyncApiRequest::ERROR_UNRESOLVED_PARENT);
				return;
			}
			// fixup the Source's parent post id with the Target's id value
SyncDebug::log(__METHOD__.'() setting parent post to #' . $parent_post->target_content_id);
			$post_data['post_parent'] = abs($parent_post->target_content_id);
		}

		// change references to source URL to target URL
		$post_data['post_content'] = str_replace($this->source, site_url(), $post_data['post_content']);
		$post_data['post_excerpt'] = str_replace($this->source, site_url(), $post_data['post_excerpt']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' converting URLs ' . $this->source . ' -> ' . site_url());
//		$post_data['post_content'] = str_replace($this->post('origin'), $url['host'], $post_data['post_content']);
		// TODO: check if we need to update anything else like `guid`, `post_content_filtered`

		// set the user for post creation/update #70
		if (isset($this->_user->ID))
			wp_set_current_user($this->_user->ID);

		// add/update post
		if (NULL !== $post) {
SyncDebug::log(' ' . __LINE__ . ' - check permission for updating post id#' . $post->ID);
			// make sure the user performing API request has permission to perform the action
			if ($this->has_permission('edit_posts', $post->ID)) {
//SyncDebug::log(' - has permission');
				$target_post_id = $post_data['ID'] = $post->ID;
				$res = wp_update_post($post_data, TRUE); // ;here;
				if (is_wp_error($res)) {
					$response->error_code(SyncApiRequest::ERROR_CONTENT_UPDATE_FAILED, $res->get_error_message());
				}
			} else {
				$response->error_code(SyncApiRequest::ERROR_NO_PERMISSION);
				$response->send();
			}
		} else {
SyncDebug::log(' - check permission for creating new post from source id#' . $post_data['ID']);
			if ($this->has_permission('edit_posts')) {
				// copy to new array so ID can be unset
				$new_post_data = $post_data;
				unset($new_post_data['ID']);
				$target_post_id = wp_insert_post($new_post_data); // ;here;
			} else {
				$response->error_code(SyncApiRequest::ERROR_NO_PERMISSION);
				$response->send();
			}
		}
		$this->post_id = $target_post_id;
SyncDebug::log(__METHOD__ . '():' . __LINE__. '  performing sync');

		// save the source and target post information for later reference
		$model = new SyncModel();
		$save_sync = array(
			'site_key' => $this->source_site_key,
			'source_content_id' => $this->source_post_id,
			'target_content_id' => $this->post_id,
			'content_type' => $content_type,
		);
		$model->save_sync_data($save_sync);

		// log the Push operation in the ‘spectrom_sync_push’ table
		$logger = new SyncLogModel();
		$logger->log(
			array(
				'post_id' => $target_post_id,
				'post_title' => $post_data['post_title'],
				'operation' => 'push',
				'source_user' => $this->post('user_id'),
				'source_site' => $this->source, // post('origin'),
				'source_site_key' => $this->source_site_key,
				'target_user' => get_current_user_id(),
			)
		);

		$response->set('post_id', $target_post_id);
//		$response->set('site_key', $this->get('site_key'));			// get site's key
//SyncDebug::log(__METHOD__.'() adding Target site key ' . SyncOptions::get('site_key') . ' to response data');
		$response->set('site_key', SyncOptions::get('site_key'));
		// sync metadata
		// TODO: note, this is in $_POST['post_data']['post_meta']
		$post_meta = $this->post_raw('post_meta', array());

		// handle stickiness
		$sticky = $this->post_int('sticky', 0);
		if (1 === $sticky)
			stick_post($target_post_id);
		else
			unstick_post($target_post_id);

		// TODO: need to handle deletes - postmeta that doesn't exist in Source any more but does on Target
		// TOOD: probably better to remove all postmeta, then add_post_meta() for each item found
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' handling meta data');
		$ser = NULL;
		foreach ($post_meta as $meta_key => $meta_value) {
			foreach ($meta_value as $value) // loop through meta_value array
//$_v = $value;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' key=' . $meta_key . ' (' . gettype($value) . ') value=' . var_export($_v, TRUE));
//$_v = stripslashes($_v);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' key=' . $meta_key . ' (' . gettype($value) . ') value=' . var_export($_v, TRUE));
//$_v = maybe_unserialize($_v);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' new value=' . var_export($_v, TRUE));
				// change Source URL references to Target URL references in meta data
				$temp_val = maybe_unserialize(stripslashes ($value));
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta key: "' . $meta_key . '" meta data: ' . $value);
//SyncDebug::log(' -- ' . var_export($temp_val, TRUE));
				if (is_array($temp_val)) {
					if (NULL === $ser)
						$ser = new SyncSerialize();
					$fix_data = str_replace($this->source, site_url(), $value);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fix data: ' . $fix_data);
					$fix_data = $ser->fix_serialized_data($fix_data);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fixing serialized data: ' . $fix_data);
					$value = $fix_data;
				} else {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' not fixing serialized data');
					$value = str_replace($this->source, site_url(), $value);
				}
				update_post_meta($target_post_id, $meta_key, maybe_unserialize(stripslashes($value)));
		}

		// handle taxonomy information
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' handling taxonomies');
		$this->_process_taxonomies($target_post_id);

		// check post thumbnail
		$thumbnail = $this->post('thumbnail', '');
		if ('' === $thumbnail) {
			// remove the thumbnail -- it's no longer attached on the Source
			delete_post_thumbnail($target_post_id);
		}

		// let the CPT add-on know that there may be additional taxonomies to update
SyncDebug::log(__METHOD__.'():'.__LINE__ . " calling action 'spectrom_sync_push_content'");
		do_action('spectrom_sync_push_content', $target_post_id, $post_data, $response);
	}

	/**
	 * Handles 'getinfo' requests from Source site. Returns information on a post.
	 * @param SyncApiResponse $response
	 */
	public function get_info(SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - post=' . var_export($_POST, TRUE));
		$input = new SyncInput();
		$target_post_id = $input->post_int('post_id', 0);

		if (0 === $target_post_id) {
			$response->error_code(SyncApiRequest::ERROR_POST_NOT_FOUND);
			return;
		}

		$post_data = get_post($target_post_id, OBJECT);
		if (NULL === $post_data) {
			// TODO: look up by post name provided in API
			$response->error_code(SyncApiRequest::ERROR_POST_NOT_FOUND);
			return;
		}

		// get author name
		$author = '';
		if (isset($post_data->post_author)) {
			$author_id = abs($post_data->post_author);
			$user = get_user_by('id', $author_id);
			if (FALSE !== $user)
				$author = $user->user_login;
		}

		// build data to be returned
		$data = array(
			'target_post_id' => $target_post_id,
			'post_title' => $post_data->post_title,
			'post_author' => $author,
			'modified' => $post_data->post_modified_gmt,
			'content' => substr(strip_tags($post_data->post_content), 0, 120), // strip_tags(get_the_excerpt($target_post_id)),
		);
		$data = apply_filters('spectrom_sync_get_info_data', $data, $target_post_id);

		// move data from filtered array into response object
		foreach ($data as $key => $value) {
			$response->set($key, $value);
		}
		$response->success(TRUE);
	}

	/**
	 * Handle taxonomy information for the push request
	 * @param int $post_id The Post ID being updated via the push request
	 */
	private function _process_taxonomies($post_id)
	{
SyncDebug::log(__METHOD__.'(' . $post_id . ')');

		/**
		 * $taxonomies - this is the taxonomy data sent from the Source site via the push API
		 */

		$taxonomies = $this->post_raw('taxonomies', array());
SyncDebug::log(__METHOD__.'() found taxonomy information: ' . var_export($taxonomies, TRUE));

		// update category and tag descriptions

		//
		// process the flat taxonomies
		//
		/**
		 * $tags - reference to the $taxonomies['tags'] array while processing flat taxonomies (or tags)
		 * $terms - reference to the $taxonomies['hierarchical'] array while processing hierarchical taxonomies (or categories)
		 * $term_info - foreach() iterator value while processing taxonomy data; an array of the taxonomy information from Source site
		 * $tax_type - the name of the taxonomy item being processed, 'category' or 'post_tag' for example (used in both flat and hierarchical processing)
		 * $term - the searched taxonomy term object when looking up the taxonomy slug/$tax_type on local system
		 */
		if (isset($taxonomies['flat']) && !empty($taxonomies['flat'])) {
			$tags = $taxonomies['flat'];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found ' . count($tags) . ' taxonomy tags');
			foreach ($tags as $term_info) {
				$tax_type = $term_info['taxonomy'];
				$term = get_term_by('slug', $term_info['slug'], $tax_type, OBJECT);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found taxonomy ' . $tax_type . ': ' . var_export($term, TRUE));
				if (FALSE === $term) {
					// term not found - create it
					$args = array(
						'description'=> $term_info['description'],
						'slug' => $term_info['slug'],
						'taxonomy' => $term_info['taxonomy'],
					);
SyncDebug::log(__METHOD__.'():' . __LINE__ . " wp_insert_term('{$term_info['name']}', {$tax_type}, " . var_export($args, TRUE) . ')');
					$ret = wp_insert_term($term_info['name'], $tax_type, $args);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' insert term [flat] result: ' . var_export($ret, TRUE));
				} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' term already exists');
				}
				$ret = wp_add_object_terms($post_id, $term_info['slug'], $tax_type);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' add [flat] object terms result: ' . var_export($ret, TRUE));
			}
		}

		//
		// process the hierarchical taxonomies
		//
		/**
		 * $lineage - an array of parent taxonomies that indicate the full lineage of the term that needs to be assigned
		 * $parent - the integer parent term_id to look for in $taxonomies['lineage'] in order to find items when building the $lineage array
		 * $tax_term - the foreach() iterator while searching $taxonomies['lineage'] for parent taxonomy terms
		 * $child_terms - the term children for each taxonomy; used when searching through Target terms to find correct child within hierarchy
		 * $term_id - foreach() iterator while looking through $child_terms
		 * $term_child - child term indicated by $term_id; used to match with $tax_term['slug'] to match child taxonomies
		 */
		if (isset($taxonomies['hierarchical']) && !empty($taxonomies['hierarchical'])) {
			$terms = $taxonomies['hierarchical'];
			foreach ($terms as $term_info) {
				$tax_type = $term_info['taxonomy'];			// get taxonomy name from API contents
				$term_id = $this->process_hierarchical_term($term_info, $taxonomies);
				if (0 !== $term_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding term #' . $term_id . ' to object ' . $post_id);
					$ret = wp_add_object_terms($post_id, $term_id, $tax_type);
SyncDebug::log(__METHOD__.'() add [hier] object terms result: ' . var_export($ret, TRUE));
				}
			} // END FOREACH
		}

		//
		// remove any terms that exist for the post, but are not in the taxonmy data sent from Source
		//
		/**
		 * $post - the post being updated; needed for wp_get_post_terms() call to look up taxonomies assigned to $post_id
		 * $assigned_terms - the taxonomies that are assigned to the $post; used to check for items that may need to be removed
		 * $post_term - foreach() iterator object for the $assigned_terms loop
		 * $found - boolean used to track whether or not the $post_term was included in $taxonomies sent via API request. if FALSE, term needs to be removed
		 */
		// get the posts' list of assigned terms
		$post = get_post($post_id, OBJECT);
		$model = new SyncModel();
		$assigned_terms = wp_get_post_terms($post_id, $model->get_all_tax_names($post->post_type));
SyncDebug::log(__METHOD__.'() looking for terms to remove');
		foreach ($assigned_terms as $post_term) {
SyncDebug::log(__METHOD__.'() checking term #' . $post_term->term_id . ' "' . $post_term->slug . '" [' . $post_term->taxonomy . ']');
			$found = FALSE;							// assume $post_term is not found in $taxonomies data provided via API call
SyncDebug::log(__METHOD__.'() checking hierarchical terms');
			if (isset($taxonomies['hierarchical']) && is_array($taxonomies['hierarchical'])) {
				foreach ($taxonomies['hierarchical'] as $term) {
					if ($term['slug'] === $post_term->slug && $term['taxonomy'] === $post_term->taxonomy) {
SyncDebug::log(__METHOD__.'() found post term in hierarchical list');
						$found = TRUE;
						break;
					}
				}
			}
			if (!$found) {
				// not found in hierarchical taxonomies, look in flat taxonomies
SyncDebug::log(__METHOD__.'() checking flat terms');
				if (isset($taxonomies['flat']) && is_array($taxonomies['flat'])) {
					foreach ($taxonomies['flat'] as $term) {
						if ($term['slug'] === $post_term->slug && $term['taxonomy'] === $post_term->taxonomy) {
SyncDebug::log(__METHOD__.'() found post term in flat list');
							$found = TRUE;
							break;
						}
					}
				}
			}
			// check to see if $post_term was included in $taxonomies data provided via the API call
			if ($found) {
SyncDebug::log(__METHOD__.'() post term found in taxonomies list- not removing it');
			} else {
				// if the $post_term assigned to the post is NOT in the $taxonomies list, it needs to be removed
SyncDebug::log(__METHOD__.'() ** removing term #' . $post_term->term_id . ' ' . $post_term->slug . ' [' . $post_term->taxonomy . ']');
				wp_remove_object_terms($post_id, abs($post_term->term_id), $post_term->taxonomy);
			}
		}
	}

	/**
	 * Returns a post object for a given post title
	 * @param string $title The post_title value to search for
	 * @return WP_Post|NULL The WP_Post object if the title is found; otherwise NULL.
	 */
	// TODO: move this to a model class - doesn't belong in a controller class
/*	private function get_post_by_title($title)
	{
		global $wpdb;

		$sql = "SELECT `ID`
				FROM `{$wpdb->posts}`
				WHERE `post_title`=%s
				LIMIT 1";
		$res = $wpdb->get_results($wpdb->prepare($sql, $title), OBJECT);
SyncDebug::log(__METHOD__.'() ' . $wpdb->last_query . ': ' . var_export($res, TRUE));

		if (1 == count($res)) {
			$post_id = $res[0]->ID;
SyncDebug::log('- post id=' . $post_id);
			$post = get_post($post_id, OBJECT);

			return $post;
		}
		return NULL;
	} */

	/**
	 * Handles media uploads. Assigns attachment to posts.
	 * @param  SyncApiResponse $response
	 */
	public function upload_media(SyncApiResponse $response)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' max upload size=' . wp_max_upload_size() . ' file size=' . (isset($_FILES['sync_file_upload']['size']) ? $_FILES['sync_file_upload']['size'] : '-'));
		// permissions check - make sure current_user_can('upload_files')
		if (!$this->has_permission('upload_files')) {
			$response->error_code(SyncApiRequest::ERROR_NO_PERMISSION);
			$response->send();
		}

		require_once(ABSPATH . 'wp-admin/includes/image.php');
		require_once(ABSPATH . 'wp-admin/includes/file.php');
		require_once(ABSPATH . 'wp-admin/includes/media.php');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' FILES=' . var_export($_FILES, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' POST=' . var_export($_POST, TRUE));

		if (!isset($_FILES['sync_file_upload'])) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no file upload information provided');
			$response->error_code(SyncApiRequest::ERROR_UPLOAD_NO_CONTENT);
			return;
		}

		// TODO: check uploaded file contents to ensure it's an image
		// https://en.wikipedia.org/wiki/List_of_file_signatures

		$featured = isset($_POST['featured']) ? abs($_POST['featured']) : 0;
		$path = $_FILES['sync_file_upload']['name'];

		// check file type
		$img_type = wp_check_filetype($path);
		// TODO: add validating method to SyncAttachModel class
		add_filter('spectrom_sync_upload_media_allowed_mime_type', array($this, 'filter_allowed_mime_types'), 10, 2);
SyncDebug::log(__METHOD__.'() found image type=' . $img_type['ext'] . '=' . $img_type['type']);
		if (FALSE === apply_filters('spectrom_sync_upload_media_allowed_mime_type', FALSE, $img_type)) {
			$response->error_code(SyncApiRequest::ERROR_INVALID_IMG_TYPE);
			$response->send();
		}

		$ext = pathinfo($path, PATHINFO_EXTENSION);

//		$args = array(
//			'post_per_page' => 1,
//			'post_type'		=> 'attachment',
//			'name'			=> basename($path, '.' . $ext), // basename($path, $ext),
//		);
//		$get_posts = new WP_Query($args);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' query results: ' . var_export($get_posts, TRUE));

		// TODO: move this to SyncAttachModel
		global $wpdb;
		$sql = "SELECT `ID`
				FROM `{$wpdb->posts}`
				WHERE `post_name`=%s AND `post_type`='attachment'";
		$res = $wpdb->get_col($stmt = $wpdb->prepare($sql, basename($path, '.' . $ext)));
		$attachment_id = 0;
		if (0 != count($res))
			$attachment_id = abs($res[0]);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' id=' . $attachment_id . ' sql=' . $stmt . ' res=' . var_export($res, TRUE));
		// TODO: need to assume error and only set to success(TRUE) when file successfully processed
		$response->success(TRUE);

		// convert source post id to target post id
		$source_post_id = abs($_POST['post_id']);
		$target_post_id = 0;
		$model = new SyncModel();
		$content_type = apply_filters('spectrom_sync_upload_media_content_type', 'post');
		$sync_data = $model->get_sync_data($source_post_id, $this->source_site_key, $content_type);
		if (NULL !== $sync_data)
			$target_post_id = abs($sync_data->target_content_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source id=' . $source_post_id . ' target id=' . $target_post_id);

		$this->media_id = 0;
		$this->local_media_name = '';
		add_filter('wp_handle_upload', array(&$this, 'handle_upload'));
		$has_error = FALSE;

		// set this up for wp_handle_upload() calls
		$overrides = array(
			'test_form' => FALSE,			// really needed because we're not submitting via a form
			'test_size' => FALSE,			// don't worry about the size
			'unique_filename_callback' => array(&$this, 'unique_filename_callback'),
			'action' => 'wp_handle_upload',
		);

		// Check if attachment exists
		if (0 !== $attachment_id) { // $get_posts->post_count > 0) { // NULL !== $get_posts->posts[0]) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found id ' . $attachment_id . ' posts');
			// TODO: check if files need to be updated / replaced / deleted
			// TODO: handle overwriting/replacing image files of the same name
//			$file = media_handle_upload('sync_file_upload', $this->post('post_id', 0), array(), $overrides);
//			$response->notice_code(SyncApiRequest::NOTICE_FILE_EXISTS);
			$this->media_id = $attachment_id;

			// if it's the featured image, set that
			if ($featured && 0 !== $target_post_id)
				set_post_thumbnail($target_post_id, $attachment_id);
		} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found no posts');
			$time = str_replace('\\', '/', substr($_POST['img_path'], -7));
SyncDebug::log(__METHOD__.'() time=' . $time);
			$_POST['action'] = 'wp_handle_upload';		// shouldn't have to do this with $overrides['test_form'] = FALSE
//			$file = media_handle_upload('sync_file_upload', $this->post('post_id', 0), $time);
			$file = wp_handle_upload($_FILES['sync_file_upload'], $overrides, $time);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' media_handle_upload() returned ' . var_export($file, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' wp_handle_upload() returned ' . var_export($file, TRUE));

//			if (is_wp_error($file)) {
			if (!is_array($file) || isset($file['error'])) {
				$has_error = TRUE;
//				$response->error_code(SyncApiRequest::ERROR_FILE_UPLOAD, $file->get_error_message());
				$response->error_code(SyncApiRequest::ERROR_FILE_UPLOAD, $file['error']);
			} else {
				$upload_dir = wp_upload_dir();
SyncDebug::log(__METHOD__.'() upload dir=' . var_export($upload_dir, TRUE));
				$upload_file = $upload_dir['baseurl'] . '/' . $time . '/' . basename($file['file']);
				$attachment = array (		// create attachment for our post
					'post_title' => $this->post('attach_title', pathinfo($file['file'], PATHINFO_FILENAME)), // basename($file['file']),
					'post_name' => $this->post('attach_name', pathinfo($file['file'], PATHINFO_FILENAME)), // basename($file['file']),
					'post_content' => $this->post('attach_desc', ''),			// '',
					'post_excerpt' => $this->post('attach_caption', ''),
					'post_status' => 'inherit',
					'post_mime_type' => $file['type'],	// type of attachment
					'post_parent' => $target_post_id,	// post id
//					'guid' => $upload_dir['url'] . '/' . basename($file['file']),
					'guid' => $upload_file,
				);
SyncDebug::log(__METHOD__.'() insert attachment parameters: ' . var_export($attachment, TRUE));
				$attach_id = wp_insert_attachment($attachment, $file['file'], $target_post_id);	// insert post attachment
SyncDebug::log(__METHOD__."() wp_insert_attachment([,{$target_post_id}], '{$file['file']}', {$target_post_id}) returned {$attach_id}");
				$attach = wp_generate_attachment_metadata($attach_id, $file['file']);	// generate metadata for new attacment
				update_post_meta($attach_id, '_wp_attachment_image_alt', $this->post('attach_alt', ''), TRUE);
SyncDebug::log(__METHOD__."() wp_generate_attachment_metadata({$attach_id}, '{$file['file']}') returned " . var_export($attach, TRUE));
				wp_update_attachment_metadata($attach_id, $attach);
				$response->set('post_id', $this->post('post_id'));
				$this->media_id = $attach_id;

				// if it's the featured image, set that
				if ($featured && 0 !== $target_post_id) {
SyncDebug::log(__METHOD__."() set_post_thumbnail({$target_post_id}, {$attach_id})");
					set_post_thumbnail($target_post_id, $attach_id /*abs($file)*/);
				}
			}
		}

		if (!$has_error) {
SyncDebug::log(__METHOD__.'() image successfully handled');
			// Set this post as featured image, if specified.
			if ($this->post('featured', 0))
				set_post_thumbnail($target_post_id /*$this->post('post_id')*/, $this->media_id);

			$media_data = array(
				'id' => $this->media_id,
				'site_key' => $this->source_site_key, // SyncOptions::get('site_key'),
				'remote_media_name' => $path,
				'local_media_name' => $this->local_media_name,
			);

			$media = new SyncMediaModel();
			$media->log($media_data);

			// notify add-ons about media
			do_action('spectrom_sync_media_processed', $target_post_id, $attachment_id, $this->media_id);
		}
	}

	/**
	 * Filter the mime types allowed in upload_media()
	 * @param boolean $default Current allowed state
	 * @param array $img_type The mime type information with array keys of ['type'] and ['ext']
	 * @return boolean TRUE to allow this mime type; otherwise FALSE
	 */
	public function filter_allowed_mime_types($default, $img_type)
	{
		// TODO: use get_allowed_mime_types()
		// if the type contains 'image/'
		if (FALSE !== stripos($img_type['type'], 'image/'))
			return TRUE;
		// allow PDF files
		if ('pdf' === $img_type['ext'])
			return TRUE;

		return $default;
	}

	/**
	 * Looks up the media filename and if found, replaces the name with the local system's name of the file
	 * Callback used by the media_handle_upload() call
	 * @param string $dir Directory name
	 * @param string $name File name
	 * @param string $ext Extension name
	 * @return string the filename of media item, adjusted to the previously used name if
	 */
	public function unique_filename_callback($dir, $name, $ext)
	{
SyncDebug::log(__METHOD__."('{$dir}', '{$name}', '{$ext}')");
		if (FALSE !== stripos($name, $ext)) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning "' . $name . '"');
			return $name;
		}
		// this forces re-use of uploaded image names #54
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning "' . $name . $ext . '"');
		return $name . $ext;
		$filename = $name . $ext;

//		$model = new SyncModel();
//		if ($media = $model->get_media_data($filename)) {
		$model = new SyncMediaModel();
		// TODO: I think this needs the Source site's `site_key` value
		if ($media = $model->get_data($filename)) {
			$this->media_id = $media->id;
			$filename = $media->local_media_name;
		}

		return $filename;
	}

	/**
	 * Callback for the 'wp_handle_upload' filter. Stores the media name
	 * @param array $info Array of uploaded data
	 * @param string $context Type of upload action; 'upload' or 'sideload'
	 * @return array Modified info array
	 */
	public function handle_upload($info, $context = '')
	{
		// TODO: use parse_url() instead
		$parts = explode('/', $info['url']);
		$this->local_media_name = array_pop($parts);
		return $info;
	}

	/**
	 * Process hierarchical term. Searches for and creates taxonomy lineages in order to find child most term id that matches hierarchy.
	 * @param array $term_info Array of term info from the Source site
	 * @param array $taxonomies Array of taxonomies sent via API POST request
	 * @return int 0 to indicate error or the child-most term_id to be assigned to the target
	 */
	public function process_hierarchical_term($term_info, $taxonomies)
	{
		$tax_type = $term_info['taxonomy'];
SyncDebug::log(__METHOD__ . '() build lineage for taxonomy: ' . $tax_type);

		// first, build a lineage list of the taxonomy terms
		$lineage = array();
		$lineage[] = $term_info;            // always add the current term to the lineage
		$parent = abs($term_info['parent']);
SyncDebug::log(__METHOD__ . '() looking for parent term #' . $parent);
		if (isset($taxonomies['lineage'][$tax_type])) {
			while (0 !== $parent) {
				foreach ($taxonomies['lineage'][$tax_type] as $tax_term) {
SyncDebug::log(__METHOD__ . '() checking lineage for #' . $tax_term['term_id'] . ' - ' . $tax_term['slug']);
					if ($tax_term['term_id'] == $parent) {
SyncDebug::log(__METHOD__ . '() - found term ' . $tax_term['slug'] . ' as a child of ' . $parent);
						$lineage[] = $tax_term;
						$parent = abs($tax_term['parent']);
						break;
					}
				}
			}
		} else {
SyncDebug::log(__METHOD__ . '() no taxonomy lineage found for: ' . $tax_type);
		}
		$lineage = array_reverse($lineage);                // swap array order to start loop with top-most term first
SyncDebug::log(__METHOD__ . '() taxonomy lineage: ' . var_export($lineage, TRUE));

		// next, make sure each term in the hierarchy exists - we'll end on the taxonomy id that needs to be assigned
SyncDebug::log(__METHOD__ . '() setting taxonomy terms for taxonomy "' . $tax_type . '"');
		$generation = $parent = 0;
		foreach ($lineage as $tax_term) {
SyncDebug::log(__METHOD__ . '() checking term #' . $tax_term['term_id'] . ' ' . $tax_term['slug'] . ' parent=' . $tax_term['parent']);
			$term = NULL;
			if (0 === $parent) {
SyncDebug::log(__METHOD__ . '() getting top level taxonomy ' . $tax_term['slug'] . ' in taxonomy ' . $tax_type);
				$term = get_term_by('slug', $tax_term['slug'], $tax_type, OBJECT);
				if (is_wp_error($term) || FALSE === $term) {
SyncDebug::log(__METHOD__ . '() error=' . var_export($term, TRUE));
					$term = NULL;                    // term not found, set to NULL so code below creates it
				}
SyncDebug::log(__METHOD__ . '() no parent but found term: ' . var_export($term, TRUE));
			} else {
				$child_terms = get_term_children($parent, $tax_type);
SyncDebug::log(__METHOD__ . '() found ' . count($child_terms) . ' term children for #' . $parent);
				if (!is_wp_error($child_terms)) {
					// loop through the children until we find one that matches
					foreach ($child_terms as $term_id) {
						$term_child = get_term_by('id', $term_id, $tax_type);
SyncDebug::log(__METHOD__ . '() term child: ' . $term_child->slug);
						if ($term_child->slug === $tax_term['slug']) {
							// found the child term
							$term = $term_child;
							break;
						}
					}
				}
			}

			// see if the term needs to be created
			if (NULL === $term) {
				// term not found - create it
				$args = array(
					'description' => $tax_term['description'],
					'slug' => $tax_term['slug'],
					'taxonomy' => $tax_term['taxonomy'],
					'parent' => $parent,                    // indicate parent for next loop iteration
				);
SyncDebug::log(__METHOD__ . '() term does not exist- adding name ' . $tax_term['name'] . ' under "' . $tax_type . '" args=' . var_export($args, TRUE));
				$ret = wp_insert_term($tax_term['name'], $tax_type, $args);
				if (is_wp_error($ret)) {
					$term_id = 0;
					$parent = 0;
				} else {
					$term_id = abs($ret['term_id']);
					$parent = $term_id;            // set the parent to this term id so next loop iteraction looks for term's children
				}
SyncDebug::log(__METHOD__ . '() insert term [hier] result: ' . var_export($ret, TRUE));
			} else {
SyncDebug::log(__METHOD__ . '() found term: ' . var_export($term, TRUE));
				if (isset($term->term_id)) {
					$term_id = $term->term_id;
					$parent = $term_id;                            // indicate parent for next loop iteration
				} else {
SyncDebug::log(__METHOD__ . '() ERROR: invalid term object');
				}
			}
			++$generation;
		}

		// the loop exits with $term_id set to 0 (error) or the child-most term_id to be assigned to the object
		return $term_id;
	}
}

// EOF
