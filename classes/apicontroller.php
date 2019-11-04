<?php

/**
 * Routes incoming calls to the API.
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
	private $_response = NULL;						// reference to SyncApiResponse instance that Controller uses for API responses
	private $_source_urls = NULL;					// list of Source URLs for domain transposition
	private $_target_urls = NULL;					// list of Target URLs for domain transposition
	private $_parent_action = NULL;					// the parent action for the current API call

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
		$this->_response = $response;
		$this->_parent_action = isset($args['parent_action']) ? $args['parent_action'] : NULL;

		if (isset($args['site_key']))
			$response->nosend = TRUE;

		$this->source_site_key = isset($args['site_key']) ? $args['site_key'] : $this->get_header(self::HEADER_SITE_KEY);

		$this->source = untrailingslashit(isset($args['source']) ? $args['source'] : $this->get_header(self::HEADER_SOURCE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' action=' . $action . ' source=' . $this->source . ' key=' . $this->source_site_key);

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' verifying nonce');
		// TODO: skip nonce verification when isset($args['action'])? this would avoid nonce checks when controller is run on Source
		// do nonce verification here so that ALL api calls will fail if the nonce doesn't check out. Otherwise, some add-on may be able to skip it.
		if ('auth' !== $action &&
			(self::REQUIRE_NONCES && !wp_verify_nonce($this->get('_spectrom_sync_nonce'), $this->get('site_key')))) {
SyncDebug::log(__METHOD__.'() failed nonce check ' . __LINE__);
SyncDebug::log(' sync_nonce=' . $this->get('_spectrom_sync_nonce') . '  site_key=' . $this->get('site_key'));
			$response->error_code(SyncApiRequest::ERROR_SESSION_EXPIRED);
			$response->send();		// calls die()
		}

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking auth argument args=' . var_export($args, TRUE));
		if (isset($args['auth']) && 0 === $args['auth']) {
//SyncDebug::log(__METHOD__.'() skipping authentication as per args');
			$this->_auth = 0;
		} else {
			if ('auth' !== $action) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking credentials');
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

		case 'push_complete':
			$this->_process_gutenberg($response);
			break;

		default:
SyncDebug::log(__METHOD__."() sending action '{$action}' to filter 'spectrom_sync_api'");
			// let add-ons have a chance to process the request
			// handle with filter. Callbacks need to return TRUE. So if FALSE is returned it's an invalic request
			$res = apply_filters('spectrom_sync_api', FALSE, $action, $response);
			if (FALSE === $res)
				$response->error_code(SyncApiRequest::ERROR_UNRECOGNIZED_REQUEST);
		}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' completed API handling');

		// make sure there are no errors
		if (!$response->has_errors()) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' response has no errors');
			// allow add-ons to do post-processing on api actions
			do_action('spectrom_sync_api_process', $action, $response, $this);
		}

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sending response to Source');
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
	 * Get the SyncApiResponse object being used by the Controller
	 * @return SyncApiResponse The response object used for the current API call.
	 */
	public function get_api_response()
	{
		return $this->_response;
	}

	/**
	 * Returns the parent action that initiated the current API request
	 * @return NULL|string NULL if no parent action specified; otherwise string representing the parent action
	 */
	public function get_parent_action()
	{
		return $this->_parent_action;
	}

	/**
	 * Generate arrays of replacement strings allowing domain fixups for SSL and non-SSL source references.
	 * These arrays can be used in str_replace() calls when fixing Source domains to Target domains
	 * @param array $source_urls Variable to assign array of search arrays
	 * @param array $target_urls Variable to assign array of replacement array
	 */
	public function get_fixup_domains(&$source_urls, &$target_urls)
	{
		$source_urls = array($this->source);
		if ('http' === parse_url($this->source, PHP_URL_SCHEME))
			$source_urls[] = str_ireplace('http://', 'https://', $this->source);
		else
			$source_urls[] = str_ireplace('https://', 'http://', $this->source);
		$source_urls[] = urlencode($source_urls[0]);			// add url encoded references #156
		$source_urls[] = urlencode($source_urls[1]);

		$target_url = untrailingslashit(site_url());
		$target_urls = array($target_url, $target_url);
		$target_urls[] = urlencode($target_urls[0]);			// add url encoded references #156
		$target_urls[] = urlencode($target_urls[0]);
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
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - post_data=' . var_export($post_data, TRUE));

		$this->source_post_id = abs($post_data['ID']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' syncing post data Source ID#'. $this->source_post_id . ' - "' . $post_data['post_title'] . '"');

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

		$post_model = new SyncPostModel();

		// Get post by title, if new
		if (NULL === $post) {
			$mode = $this->get_header(self::HEADER_MATCH_MODE, 'title');
//SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - still no post found - use lookup_post() mode=' . $mode);
			$target_post_id = $post_model->lookup_post($post_data, $mode);
		}

		// allow add-ons to modify the ultimate target post id
		$target_post_id = apply_filters('spectrom_sync_push_target_id', $target_post_id, $this->source_post_id, $this->source_site_key);
		if (0 !== $target_post_id)
			$post = get_post($target_post_id);

SyncDebug::log('- found post: ' . var_export($post, TRUE));


		// do not allow the push if it's not a recognized post type
		if (!in_array($post_data['post_type'], apply_filters('spectrom_sync_allowed_post_types', array('post', 'page')))) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking post type: ' . $post_data['post_type']);
#			$response->error_code(SyncApiRequest::ERROR_INVALID_POST_TYPE);
#			return;
		}

		// check if post is currently being edited
		if (0 !== $target_post_id && $post_model->is_post_locked($target_post_id)) {
			$user = $post_model->get_post_lock_user();
			$response->error_code(SyncApiRequest::ERROR_CONTENT_LOCKED, $user['user_login']);
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

		$this->_fixup_target_urls($post_data);
#		// change references to the Source URL to Target URL
#		$this->get_fixup_domains($this->_source_urls, $this->_target_urls);
#		// now change all occurances of Source domain(s) to Target domain
#		$post_data['post_content'] = str_ireplace($this->_source_urls, $this->_target_urls, $post_data['post_content']);
#		$post_data['post_excerpt'] = str_ireplace($this->_source_urls, $this->_target_urls, $post_data['post_excerpt']);
#SyncDebug::log(__METHOD__.'():' . __LINE__ . ' converting URLs (' . implode(',', $this->_source_urls) . ') -> ' . $this->_target_urls[0]);
#//		$post_data['post_content'] = str_replace($this->post('origin'), $url['host'], $post_data['post_content']);
#		// TODO: check if we need to update anything else like `guid`, `post_content_filtered`

		// set the user for post creation/update #70
		if (isset($this->_user->ID))
			wp_set_current_user($this->_user->ID);

		// add/update post
		if (NULL !== $post) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' check permission for updating post id#' . $post->ID);
			// make sure the user performing API request has permission to perform the action
			// TODO: use 'edit_post' instead? since we're specifying post ID
			if ($this->has_permission('edit_posts', $post->ID)) {
//SyncDebug::log(' - has permission');
				$target_post_id = $post_data['ID'] = $post->ID;
//				$this->_process_gutenberg($post_data);			// handle Gutenberg content- moved to 'push_complete' API call
				$this->_process_shortcodes($post_data);							// handle shortcodes
				unset($post_data['guid']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating post ' . $post_data['ID']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' content: ' . $post_data['post_content']);
				$res = wp_update_post($post_data, TRUE); // ;here;
				if (is_wp_error($res)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' error in wp_update_post() ' . $res->get_error_message());
					$response->error_code(SyncApiRequest::ERROR_CONTENT_UPDATE_FAILED, $res->get_error_message());
				}
			} else {
				$response->error_code(SyncApiRequest::ERROR_NO_PERMISSION);
				$response->send();
			}
		} else {
			// NULL === $post, need to create new content
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' check permission for creating new post from source id#' . $post_data['ID']);
			if ($this->has_permission('edit_posts')) {
				// copy to new array so ID can be unset
//				$this->_process_gutenberg($post_data);			// handle Gutenberg content- moved to 'push_complete' API call
				$this->_process_shortcodes($post_data);							// handle shortcodes
				$new_post_data = $post_data;
				unset($new_post_data['ID']);
				unset($new_post_data['guid']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' content: ' . $post_data['post_content']);
				$target_post_id = wp_insert_post($new_post_data); // ;here;
			} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' user does not have permission to update content');
				$response->error_code(SyncApiRequest::ERROR_NO_PERMISSION);
				$response->send();
			}
		}
		// Note: from this point on, we know that we have permission to add/update the content

		$this->post_id = $target_post_id;
SyncDebug::log(__METHOD__ . '():' . __LINE__. '  performing sync');

		// save the source and target post information for later reference
		$model = new SyncModel();
		$save_sync = array(
			'site_key' => $this->source_site_key,
			'source_content_id' => $this->source_post_id,
			'target_content_id' => $this->post_id,
			'content_type' => 'post', // $content_type,
		);
		$model->save_sync_data($save_sync);

		// log the Push operation in the ‘spectrom_sync_push’ table
		$logger = new SyncLogModel();
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' starting logger');
		$logger->log(
			array(
				'post_id' => $target_post_id,
				'post_title' => $post_data['post_title'],
				'operation' => 'push',
				'source_user' => $this->post('user_id'),
				'source_site' => $this->source, // post('origin'),
				'source_site_key' => $this->source_site_key,
				'target_user' => get_current_user_id(),
				'type' => 'recv',
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
		// TODO: move postmeta processing into _process_postmeta() method
		foreach ($post_meta as $meta_key => $meta_value) {
			foreach ($meta_value as $value) // loop through meta_value array
//$_v = $value;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' key=' . $meta_key . ' (' . gettype($value) . ') value=' . var_export($_v, TRUE));
//$_v = stripslashes($_v);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' key=' . $meta_key . ' (' . gettype($value) . ') value=' . var_export($_v, TRUE));
//$_v = maybe_unserialize($_v);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' new value=' . var_export($_v, TRUE));
				// change Source URL references to Target URL references in meta data
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta key: "' . $meta_key . '" meta data: ' . $value);
				$value = stripslashes($value);
				if (is_serialized($value)) {
					if (NULL === $ser)
						$ser = new SyncSerialize();
///					$fix_data = str_replace($this->source, site_url(), $value);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fix data: ' . $fix_data);
///					$fix_data = $ser->fix_serialized_data($fix_data);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' fixing serialized data: ' . $fix_data);
					$value = $ser->parse_data($value, array($this, 'fixup_url_references'));
				} else {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' not fixing serialized data');
					$value = str_replace($this->source, site_url(), $value);
				}
#				if ('_wp_page_template' === $meta_key && class_exists('Elementor\Plugin', FALSE)) {
#					// #184: bug in Elementor- modules/page-templates/module.php:345 $common is not initialized
#					// when the WPSiteSync API isued. This forces initialization so "Call to a member function
#					// get_component()" doesn't fail.
#					$elementor = Elementor\Plugin::instance();
#					if (!$elementor->common)
#						$elementor->init_common();
#				}
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

		// this lets add-ons know that the Push operation is complete. Ex: CPT add-on can handle additional taxonomies to update
SyncDebug::log(__METHOD__.'():'.__LINE__ . " calling action 'spectrom_sync_push_content'");
		do_action('spectrom_sync_push_content', $target_post_id, $post_data, $response);

$temp_post = get_post($target_post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' push complete, content=' . $temp_post->post_content);
	}

	/**
	 * Handles references to Gutenberg Shared Blocks and fixes ID references to use Target IDs.
	 * @param SyncApiResponse $response API Response object
	 */
	private function _process_gutenberg($response)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post=' . var_export($_POST, TRUE));
		// https://premium.wpmudev.org/blog/a-tour-of-the-gutenberg-editor-for-wordpress/

		$sync_model = new SyncModel();				// this will be needed during processing of Block data

		// look for Block Markers in the format of:
		// <!-- wp:block {"ref":{post_id} /-->
		// <!-- wp:cover {"url":"http://domain.com/wp-content/uploads/{year}/{month}/{imagename}","id":{post_id}} -->
		// <!-- wp:audio {"id":{post_id}} -->
		// <!-- wp:video {"id":{post_id}} -->
		// <!-- wp:image {"id":{post_id}} -->
		// <!-- wp:gallery {"ids":[{post_id1},{post_id2},{post_id3}]} -->
		// <!-- wp:file {"id":{post_id},"href":"{file-uri}"} -->

		$id_refs = $this->post_raw('id_refs');
		if (!is_array($id_refs))					// check to ensure a filled array was passed
			$id_refs = array();						// if not, initialize to an empty array
		$pcnt = FALSE;

		// build a list of Gutenberg post content that needs to be processed
		$gb_posts = array();
		$source_post_id = $this->post_int('post_id', 0);		// post ID provided in API call
		$gb_posts[] = $source_post_id;
		foreach ($id_refs as $ref_id => $data) {
			if (!in_array($ref_id, $gb_posts) && 'wp:block' === $data[0])
				$gb_posts[] = $ref_id;
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' gb posts=' . implode(',', $gb_posts));


		foreach ($gb_posts as $source_post_id) {
			$source_post_id = abs($source_post_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing Source Post ID ' . $source_post_id);

			$sync_data = $sync_model->get_sync_data($source_post_id, $this->source_site_key, 'post');
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' search for post id ' . $source_post_id . ' res=' . var_export($sync_data, TRUE));
			if (NULL === $sync_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot find source post id ' . $source_post_id);
				$response->error_code(SyncApiRequest::ERROR_POST_NOT_FOUND, $source_post_id);
				return;
			}
			$target_post_id = $sync_data->target_content_id;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target post id=' . $target_post_id);

			$gb_post = get_post($target_post_id);
			if (NULL === $gb_post) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: post not found');
				$response->error_code(SyncApiRequest::ERROR_CONTENT_UPDATE_FAILED, sprintf(__('Post ID %1$d not found', 'wpsitesynccontent'), $target_post_id));
				return;
			}
			$content = $gb_post->post_content;
			foreach ($id_refs as $ref_id => $data) {
				if (abs($ref_id) === $source_post_id && 'wp:block' === $data[0]) {
					// If the content is a Shared Block it could have been changed on the Source.
					// Reset content to what is sent via the API.
					$content = stripslashes($data[1]['post_content']);
					$this->_fixup_target_urls($content);				// fix Source URL references #196
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' using content from "push_complete" API call');
				}
			}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' processing ' . strlen($content) . ' bytes of content'); // : ' . $content);

			$error = FALSE;								// set innitial error condition
			$offset = 0;								// pointer into string for where search currently is
			$updated = FALSE;							// set to TRUE if an update to post_content is needed
			$len = strlen($content);					// length of content

			// first, adjust any Unicode encoded quotes that stripslashes() may have messed up #215
			$quote_content = str_replace('u0022', '\\u0022', $content);
			if ($quote_content !== $content) {
				$updated = TRUE;
				$content = $quote_content;
				unset($quote_content);
			}

			do {
				$pos = strpos($content, '<!-- wp:', $offset);
				if (FALSE !== $pos) {
					// found a beginning marker: "<!-- wp:"
					$source_ref_id = 0;

					$pos_space = strpos($content, ' ', $pos + 5);	// space after block name
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' space=' . $pos_space . ' [' . substr($content, $pos_space - 3, 10) . ']');
					$block_name = substr($content, $pos + 5, $pos_space - $pos - 5);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' block_name=[' . $block_name . ']');
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found Gutenberg Block Marker "'. $block_name . '" at offset ' . $pos . ' [' . substr($content, max($pos - 3, 0), 10) . ']');

					// find start and end points of the json data within the block marker
					$start = $pos_space + 1;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ': start=' . $start . ' [' . substr($content, $start - 3, 10) . ']');
					// look for json object after block name
					if ('{' === substr($content, $start, 1)) {
						// there is json data within the Block Marker - decode it
						$end = strpos($content, '-->', $start);
						// this looks for an self-ending block marker: '/-->'
						if (FALSE !== $end && '/' === substr($content, $end - 1, 1))
							--$end;
						// $start and $end now point to the braces containing the json data

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' end=' . $end . ' [' . substr($content, $end - 3, 10) . ']');
						$json = NULL;								// initialize decoded json object
						if (FALSE !== $end) {
							$end -= 2;
							$json = substr($content, $start, $end - $start + 1);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' json=[' . $json . ']');
							if (empty($json))						// if json string is empty
								$json = NULL;						// reset to NULL
						} else {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' could not find end of block marker. off=' . $offset . ' data=' . substr($content, $start, 30));
						}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' json=[' . $json . ']');

						// if there is json data, decode and process it
						if (NULL !== $json) {
							$new_obj_len = strlen($json);

							// Convert data to json object. This method is (hopefully) forward compatible so that
							// when/if additional data is added to the object we won't destroy it or not be able to
							// find what we're looking for.
							$obj = json_decode($json);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found json string: "' . $json . '"');

							// handle each Block Marker individually
							switch ($block_name) {
							case 'wp:block':							// Shared Block reference - post reference
								$source_ref_id = abs($obj->ref);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found Shared Block reference: ' . $source_ref_id . ' at pos ' . $start);
								if (0 !== $source_ref_id) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found reference to post id ' . $source_ref_id);
									// look up source post ID to see what the Target ID is
									$sync_data = $sync_model->get_sync_data($source_ref_id, $this->source_site_key);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found sync data: ' . var_export($sync_data, TRUE));

									if (NULL === $sync_data) {
										// no post found, need to create it
										$ref_data = $id_refs[$source_ref_id];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ref data=' . var_export($ref_data, TRUE));
										// TODO: $ref_data[0] should be $block_name
										$target_data = $ref_data[1];
										unset($target_data['ID']);					// remove the post ID
										unset($target_data['guid']);				// remove guid
										$target_data['post_content'] = stripslashes($target_data['post_content']);
										// fix source/target urls #196
										$this->_fixup_target_urls($target_data);
										$target_ref = wp_insert_post($target_data);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' have target post ID ' . $target_ref);
										if (is_wp_error($target_ref)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' error on Shared Block creation: ' . $target_ref->getMessage());
											$target_ref = 0;
											// TODO: determine if there is a way to recover
										} else {
											$target_ref = abs($target_ref);
											// create an entry in the spectrom_sync table for later reference
											$sync_data = array(
												'site_key' => $this->source_site_key,
												'source_content_id' => $source_ref_id,
												'target_content_id' => $target_ref,			// ID of recently created entry for Shared Block
												'content_type' => 'post',					// it's in the wp_posts table
												'target_site_key' => SyncOptions::get('site_key'),
											);
											$sync_model->save_sync_data($sync_data);
										}
									} else {
										// found an entry for source post ID, use the previously saved Target ID
										$target_ref = abs($sync_data->target_content_id);
										// update Shared Post data in case it was changed on the Source
										$target_data = array(
											'ID' => $target_ref,
											'post_content' => stripslashes($id_refs[$source_ref_id][1]['post_content']),
											'post_modified' => $id_refs[$source_ref_id][1]['post_modified'],
											'post_modified_gmt' => $id_refs[$source_ref_id][1]['post_modified_gmt'],
										);
										// fix source/target urls #196
										$this->_fixup_target_urls($target_data);
										wp_update_post($target_data);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updated post #' . $target_ref . ' with content=' . $target_data['post_content']);
									}
									// update post ID reference with Target's ID then update Gutenberg Shared Block marker
									$obj->ref = $target_ref;
									$new_obj_data = json_encode($obj);
									$new_obj_len = strlen($new_obj_data);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' injecting new Gutenberg Shared Block object: "' . $new_obj_data . '" into content');
									$content = substr($content, 0, $start) . $new_obj_data . substr($content, $end + 1);
									$updated = TRUE;
								} // 0 !== $source_ref_id
								// TODO: error recovery
								break;

							case 'wp:cover':							// Cover Block - image reference
								$source_ref_id = abs($obj->id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found cover block reference: ' . $source_ref_id . ' at pos ' . $start);
								if (0 !== $source_ref_id) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found reference to post id ' . $source_ref_id);
									// look up source post ID to see what the Target ID is
									$sync_data = $sync_model->get_sync_data($source_ref_id, $this->source_site_key);
									if (NULL === $sync_data) {
										// no id found
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' cannot find Target ID for Source Content ID ' . $source_ref_id);
									} else {
										$target_ref_id = abs($sync_data->target_content_id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target ref id: ' . $target_ref_id);
										$att_post = get_post($target_ref_id);
//										$obj->url = $att_post->guid;		// no need to update url, that was fixed in 'push' operation
										$obj->id = $target_ref_id;
										$new_obj_data = json_encode($obj);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' injecting new Gutenberg Cover Block object: "' . $new_obj_data . '" into content');
										$new_obj_len = strlen($new_obj_data);
										$content = substr($content, 0, $start) . $new_obj_data . substr($content, $end + 1);
										// Note: wp:cover blocks don't add a class="wp-image-{id}"
										$updated = TRUE;
									}
								}
								// TODO: error recovery
								break;

							case 'wp:audio':						// Audio Block- resource reference
							case 'wp:video':						// Video Block- resource reference
							case 'wp:image':						// Image Block- resource reference
								$source_ref_id = abs($obj->id);
								if (0 !== $source_ref_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found reference to post id ' . $source_ref_id);
									// look up Source post ID to see what the Target ID is
									$sync_data = $sync_model->get_sync_data($source_ref_id, $this->source_site_key);
									if (NULL === $sync_data) {
										// no id found
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot find Target ID for Source Content ID ' . $source_ref_id);
										// TODO: error recovery
									} else {
										$target_ref_id = abs($sync_data->target_content_id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target ref id: ' . $target_ref_id);
										$att_post = get_post($target_ref_id);
//										$obj->url = $att_post->guid;		// no need to update url, that was fixed in 'push' operation
										$obj->id = $target_ref_id;
										$new_obj_data = json_encode($obj);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' injecting new Gutenberg ' . $block_name . ' Block object: "' . $new_obj_data . '" into content');
										$new_obj_len = strlen($new_obj_data);
										$content = substr($content, 0, $start) . $new_obj_data . substr($content, $end + 1);

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' update source/target IDs source=' . $source_ref_id . ' target=' . $target_ref_id);
										$from = array(
											'class="wp-image-' . $source_ref_id . '"',
											'" /></figure>',
										);
										$to = array(
											'class="wp-image-' . $target_ref_id . '"',
											'"/></figure>'
										);
										$content = $this->gutenberg_modify_block_contents($content, $pos, $block_name,
											$from,
											$to);

#										$from = 'class="wp-image-' . $source_ref_id . '"';
#										$to = 'class="wp-image-' . $target_ref_id . '"';
#//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating attributes [' . $from . '] to [' . $to . ']');
#										$content = str_replace($from, $to, $content);
#
#										$from = '" /></figure>';
#										$to = '"/></figure>';
#//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating HTML [' . $from . '] to [' . $to . ']');
#										$content = str_replace($from, $to, $content);

										$updated = TRUE;
									}
								} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref id (' . $source_ref_id . ') does not match an image');
								}
								// TODO: error recovery
								break;

							case 'wp:media-text':
								$source_ref_id = abs($obj->mediaId);
								if (0 !== $source_ref_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found reference to media id ' . $source_ref_id);
									// look up Source attachment ID to see what the Target ID is
									$sync_data = $sync_model->get_sync_data($source_ref_id, $this->source_site_key);
									if (NULL === $sync_data) {
										// no id found
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot find Target ID for Source media ID ' . $source_ref_id);
									} else {
										$target_ref_id = abs($sync_data->target_content_id);
//										$att_post = get_post($target_ref_id);
										$obj->mediaId = $target_ref_id;
										$new_obj_data = json_encode($obj);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' injecting new Gutenberg Media-Text Block object: "' . $new_obj_data . '" into content');
										$new_obj_len = strlen($new_obj_data);
										$content = substr($content, 0, $start) . $new_obj_data . substr($content, $end + 1);

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' update source/target IDs source=' . $source_ref_id . ' target=' . $target_ref_id);
										$from = array('class="wp-image-' . $source_ref_id . '"');
										$to = array('class="wp-image-' . $target_ref_id . '"');
										if (isset($obj->mediaWidth)) {
											$pcnt = TRUE;		// signal fixup code later on #214
											$from[] = 'style="grid-template-columns:' . $obj->mediaWidth . '% auto"';
											$to[] = 'style="grid-template-columns:' . $obj->mediaWidth . '{sync_pcnt} auto"';
										}
										$content = $this->gutenberg_modify_block_contents($content, $pos, $block_name,
											$from,
											$to);

#										$from = 'class="wp-image-' . $source_ref_id . '"';
#										$to = 'class="wp-image-' . $target_ref_id . '"';
#//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating attributes [' . $from . '] to [' . $to . ']');
#										$content = str_replace($from, $to, $content);

										$updated = TRUE;
									}
								} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref id (' . $source_ref_id . ') does not match an image');
								}
								break;

							case 'wp:gallery':						// Gallery Block- multiple image references
								$source_ids = $obj->ids;			// array of Gellery Image IDs
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ids=' . implode(',', $source_ids));
								$new_ids = array();					// initialize array of new ids
								foreach ($source_ids as $source_ref_id) {
									$source_ref_id = abs($source_ref_id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref id=' . $source_ref_id);
									if (0 !== $source_ref_id) {
										// look up source post ID to see what the Target ID is
										$sync_data = $sync_model->get_sync_data($source_ref_id, $this->source_site_key);
										if (NULL === $sync_data) {
											// no id found
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot find Target ID for Source Content ID ' . $source_ref_id);
										} else {
											$target_ref_id = abs($sync_data->target_content_id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' target ref id: ' . $target_ref_id);
											$att_post = get_post($target_ref_id);
//											$obj->url = $att_post->guid;		// no need to update url, that was fixed in 'push' operation
											$new_ids[] = $target_ref_id;		// add to list of new ids
										}
									}
									// TODO: error recovery
								}
								$from = array();
								$to = array();
								if (0 !== count($new_ids)) {
									$obj->ids = $new_ids;
									$new_obj_data = json_encode($obj);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' injecting new Gutenberg Gallery Block object: "' . $new_obj_data . '" into content');
									$new_obj_len = strlen($new_obj_data);
									$content = substr($content, 0, $start) . $new_obj_data . substr($content, $end + 1);
									// fixup data-id attribute references
									for ($idx = 0; $idx < count($source_ids); ++$idx) {
										if ($idx < count($new_ids)) {
											$from[] = 'data-id="' . $source_ids[$idx] . '"';
											$to[] = 'data-id="' . $new_ids[$idx] . '"';
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating attributes [' . $from . '] to [' . $to . ']');
#											$content = str_replace($from, $to, $content);

											$from[] = 'class="wp-image-' . $source_ids[$idx] . '"';
											$to[] = 'class="wp-image-' . $new_ids[$idx] . '"';
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating attributes [' . $from . '] to [' . $to . ']');
#											$content = str_replace($from, $to, $content);
											$from[] = '?attachment_id=' . $source_ids[$idx] . '"';
											$to[] = '?attachment_id=' . $new_ids[$idx] . '"';
										}
									}
									$content = $this->gutenberg_modify_block_contents($content, $pos, $block_name,
										$from,
										$to);

									$updated = TRUE;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updated content=' . $content);
								}
								break;

							case 'wp:file':
								$source_ref_id = abs($obj->id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found File Block reference: ' . $source_ref_id . ' at pos ' . $start);
								if (0 !== $source_ref_id) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found reference to post id ' . $source_ref_id);
									// look up source post ID to see what the Target ID is
									$sync_data = $sync_model->get_sync_data($source_ref_id, $this->source_site_key);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found sync data: ' . var_export($sync_data, TRUE));

									if (NULL === $sync_data) {
										// no post found, exit with error since there was a problem with the upload
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref id (' . $source_ref_id . ') does not match a target ID');
										$error = TRUE;
									} else {
										// found an entry for source post ID, use the previously saved Target ID
										$target_ref = abs($sync_data->target_content_id);

										// update post ID reference with Target's ID then update Gutenberg Shared Block marker
										$obj->id = $target_ref;
										$new_obj_data = json_encode($obj);
										$new_obj_len = strlen($new_obj_data);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' injecting new Gutenberg File Block object: "' . $new_obj_data . '" into content');
										$content = substr($content, 0, $start) . $new_obj_data . substr($content, $end + 1);
										$updated = TRUE;
									}
								} // 0 !== $source_ref_id
								// TODO: error recovery
								break;

							default:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' unrecognized block type "' . $block_name . '" - sending through filter');
								// give others a chance to process this block
								$new_content = apply_filters('spectrom_sync_process_gutenberg_block', $content, $block_name, $json, $target_post_id, $start, $end, $pos);
								// have to guess at new length of json object based on difference between old and new content
								$new_obj_len = strlen($json) + (strlen($new_content) - strlen($content));
								if ($content !== $new_content) {		// check to see if add-ons made any modifications
									$content = $new_content;
									$updated = TRUE;
								}
								unset($new_content);
								break;
							} // switch
						} // NULL !== $obj

						if (0 !== $new_obj_len)
							$offset = $end + ($new_obj_len - strlen($json)) + 3;// point to end of json data + 3
						else
							$offset = $end + 3;									// point to end marker + 3
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' moving offset pointer to ' . $offset . ' [' . substr($content, $offset - 3, 10) . ']');
//						$offset += $pos + 8 + strlen($block_name) + ($end - $start + 1) + 5;		// move offset past end of Block Marker comment
					} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no json object in block');
						// adjust $offset to point to end of Block Marker
						$end = strpos($content, '-->', $start - 1);
						if (FALSE !== $end)
							$offset = $end;
						else
							$offset = $pos_space;
					}
				} else { // FALSE !== $pos
					$offset = $len + 1;			// indicate end of string/processing
				}
			} while ($offset < $len && !$error);

			// if there were changes made to the content and no error occured- update the post_content with the changes
			if ($updated && !$error) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' updating ID=' . $target_post_id); //  . ' with content: ' . $content);
				$gb_post->post_content = $content;
#				$res = wp_update_post(array('ID' => $target_post_id, 'post_content' => $content), TRUE);

				global $wpdb;
//				$sql = $wpdb->prepare("UPDATE `{$wpdb->posts}` SET `post_content`=%s WHERE `ID`=%d", $content, $target_post_id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post_content=' . $content);

				if ($pcnt) {
					// there was a % used on a grid-template-columns style -- this is a hack #214
					$esc_content = str_replace('{sync_pcnt}', '%', esc_sql($content));
				} else {
					$esc_content = esc_sql($content);
				}

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' esc content=' . $esc_content);
				$sql = "UPDATE `{$wpdb->posts}` SET `post_content`='" . $esc_content . "' WHERE `ID`={$target_post_id} LIMIT 1";
				$res = $wpdb->query($sql);
//clean_post_cache($target_post_id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sql=' . $sql . ' res=' . var_export($res, TRUE));
//$test_post = get_post($target_post_id);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' re-read content:' . $test_post->post_content);

				if (is_wp_error($res)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: cannot update post #' . $target_post_id . ' ' . var_export($res, TRUE));
				} else {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' update successful');
				}
			} else {
				if (!$error) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no Gutenberg Block markers to update');
				}
			}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' done processing Gutenberg content');
		} // foreach
	}

	/**
	 * Replace content within the confines of the current Gutenberg Block
	 * @param string $content Entire content being manipulated
	 * @param int $block_start Offset of the start of the Block Marker
	 * @param int $block_name Name of the Gutenberg Block (i.e. 'wp:image' or 'wp:media-text')
	 * @param string|array $from The string or strings to modify from
	 * @param string|array $to The string or strings to modify to
	 * @return The modified content
	 */
	private function gutenberg_modify_block_contents($content, $block_start, $block_name, $from, $to)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' starting content:' . PHP_EOL . $content);

SyncDebug::log(__METHOD__.'():' . __LINE__ . ' checking from ' . $block_start . ' "' . substr($content, $block_start - 3, 20) . '"');
		$end_marker = '<!-- /' . $block_name . ' -->';
		$block_end = strpos($content, $end_marker, $block_start);
		if (FALSE !== $block_end) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found end block marker at ' . $block_end . ' "' . substr($content, $block_end - 3, 20) . '"');
			$block_end += strlen($end_marker);
			$sub_block = substr($content, $block_start, $block_end - $block_start);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sub block is ' . strlen($sub_block) . ' bytes [' . $sub_block . ']');
			if (is_array($from))
				$sub_block = $this->_array_replace($from, $to, $sub_block);
			else
				$sub_block = str_replace($from, $to, $sub_block);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' replacement block is ' . strlen($sub_block) . ' bytes [' . $sub_block . ']');
			$content = substr($content, 0, $block_start) . $sub_block . substr($content, $block_end);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' replacement content:' . PHP_EOL . $content);
		}
		return $content;
	}

	/**
	 * Similar to str_replace() but performs a single pass through the $subject string to avoid multiple replacements of search strings.
	 * @param array $search Array of items to search for
	 * @param array $replace Array of items to replace $search items with. Note: number of items in $search and $replace arrays must match.
	 * @param string $subject The subject string to perform replacesments within
	 * @return string The $subject string with all occurances of the $search items replaced with matching items from the $replace array
	 */
	private function _array_replace($search, $replace, $subject)
	{
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' replacing:');
for ($idx = 0; $idx < count($search); ++$idx)
SyncDebug::log('  [' . $search[$idx] . '] with [' . $replace[$idx] . ']');
//		if (count($search) !== count($replace))
//			throw new Exception('array sizes do not match');
#		$len = strlen($subject);
		$minlen = min(array_map('strlen', $search)) + 1;
#		$len -= $minlen;
		$count = count($search);
//echo 'len=', $len, PHP_EOL;

		// have to use strlen($subject) because length of $subject can shrink if replacement strings are shorter
		// so we need to recalculate the length each time through the loop
		for ($idx = 0; $idx < strlen($subject) - $minlen; ++$idx) {
			for ($stridx = 0; $stridx < $count; ++$stridx) {
				$str = $search[$stridx];
				if (substr($subject, $idx, strlen($str)) === $str) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' offset=' . $idx . ' found [' . $str . '] replacing with [' . $replace[$stridx] . ']');
					$subject = substr($subject, 0, $idx) . $replace[$stridx] . substr($subject, $idx + strlen($str));
					$idx += strlen($replace[$stridx]) - 1;				// move past the end of the replancement string
					break;												// exit the inner for() loop
				}
			}
		}
		return $subject;
	}

	/**
	 * Handles content references in shortcodes and fixes ID references to use Target IDs.
	 * @param array $post_data Content from the Source that is being Pushed.
	 */
	private function _process_shortcodes(&$post_data)
	{
/*
		[caption id="attachment_1995" align="aligncenter" width="808"]
			<img class="wp-image-1995 size-full" src="http://{domain}/wp-content/uploads/2015/09/shutterstock_137446907.jpg"
			alt="shutterstock_137446907" width="808" height="577" /> Photo: Shutterstock
		[/caption]
add_shortcode('wp_caption', 'img_caption_shortcode');
add_shortcode('caption', 'img_caption_shortcode');

add_shortcode('gallery', 'gallery_shortcode');
add_shortcode( 'playlist', 'wp_playlist_shortcode' );
add_shortcode( 'audio', 'wp_audio_shortcode' );
add_shortcode( 'video', 'wp_video_shortcode' );
add_shortcode( 'embed', array( 'WP_Embed', 'shortcode' ) );
 */
	}

	/**
	 * Callback for SyncSerialize->parse_data() when parsing the serialized data. Change old Source domain to Target domain.
	 * @param SyncSerializeEntry $entry The data representing the current node processed by Serialization parser.
	 */
	public function fixup_url_references($entry)
	{
		$entry->content = str_ireplace($this->_source_urls, $this->_target_urls, $entry->content);
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
			// TODO: add who is currently editing the content
			'content' => substr(strip_tags($post_data->post_content), 0, 120), // strip_tags(get_the_excerpt($target_post_id)),
		);

		// featured image info
		$feat_img = get_post_thumbnail_id($target_post_id);
		if (!empty($feat_img)) {
			$img_info = wp_get_attachment_image_src($feat_img, 'full');
			if (!empty($img_info)) {
				$path = parse_url($img_info[0], PHP_URL_PATH);
				$data['feat_img'] = '#' . $feat_img . ' ' . substr($path, strrpos($path, '/') + 1);
			}
		}

		$data = apply_filters('spectrom_sync_get_info_data', $data, $target_post_id);

		// move data from filtered array into response object
		foreach ($data as $key => $value) {
			$response->set($key, $value);
		}
		$response->success(TRUE);
	}

	/**
	 * Changes references to the Source domain to point to the Target domain
	 * @param array $the_post An array containing the post data to fix
	 * @returns nothing. Parameter is passed by reference and modified by this method.
	 */
	private function _fixup_target_urls(&$the_post)
	{
		// check if _source_urls property has been initialized
		if (NULL === $this->_source_urls)
			$this->get_fixup_domains($this->_source_urls, $this->_target_urls);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' converting URLs (' . implode(',', $this->_source_urls) . ') -> ' . $this->_target_urls[0]);

		// now change all occurances of Source domain(s) to Target domain
		if (is_array($the_post)) {
			// if it's an array, check both post_content and post_excerpt
			if (isset($the_post['post_content']))
				$the_post['post_content'] = str_ireplace($this->_source_urls, $this->_target_urls, $the_post['post_content']);
			if (isset($the_post['post_excerpt']))
				$the_post['post_excerpt'] = str_ireplace($this->_source_urls, $this->_target_urls, $the_post['post_excerpt']);
			// TODO: check if we need to update anything else like `guid`, `post_content_filtered`
		} else if (is_string($the_post)) {
			// if it's a string just fix it
			$the_post = str_ireplace($this->_source_urls, $this->_target_urls, $the_post);
		} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' incorrect parameter type ' . get_type($the_post));
		}
	}

	/**
	 * Handle taxonomy information for the push request
	 * @param int $post_id The Post ID being updated via the push request
	 */
	private function _process_taxonomies($post_id)
	{
SyncDebug::log(__METHOD__.'(' . $post_id . ')');

		$sync_model = new SyncModel();

		/**
		 * $taxonomies - this is the taxonomy data sent from the Source site via the push API
		 */

		$taxonomies = $this->post_raw('taxonomies', array());
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found taxonomy information: ' . var_export($taxonomies, TRUE));

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
				$source_term_id = abs($term_info['term_id']);
				$target_term_id = NULL;

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
//SyncDebug::log(__METHOD__.'():' . __LINE__ . " wp_insert_term('{$term_info['name']}', {$tax_type}, " . var_export($args, TRUE) . ')');
					$ret = wp_insert_term($term_info['name'], $tax_type, $args);
					if (!is_wp_error($ret)) {
						// save the term for later reference
						$target_term_id = abs($ret['term_id']);
					}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' insert term [flat] result: ' . var_export($ret, TRUE));
				} else {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' term already exists');
					$target_term_id = abs($term->term_id);
				}
				if (!isset($term_info['ref_only']))
					$ret = wp_add_object_terms($post_id, $term_info['slug'], $tax_type);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' add [flat] object terms result: ' . var_export($ret, TRUE));

				if (NULL !== $target_term_id) {			// was setting up new term successful?
					// record source/target term IDs for later use
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' save taxonomy data: source term=' . $source_term_id . ' target term=' . $target_term_id);
					$save_sync = array(
						'site_key' => $this->source_site_key,
						'source_content_id' => $source_term_id,
						'target_content_id' => $target_term_id,
						'content_type' => 'term',						// IDs represent taxonomy terms
						'target_site_key' => SyncOptions::get('site_key'),
					);
					$sync_model->save_sync_data($save_sync);
				}
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
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found ' . count($terms) . ' hierarchical taxonomy terms to process');
			foreach ($terms as $term_info) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' examining: ' . var_export($term_info, TRUE));
				$tax_type = $term_info['taxonomy'];			// get taxonomy name from API contents
				$term_id = $this->process_hierarchical_term($term_info, $taxonomies);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' process_hierarchical_term() responded with ' . $term_id);
				if (0 !== $term_id) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' adding term #' . $term_id . ' to object ' . $post_id);
					if (!isset($term_info['ref_only']))
						$ret = wp_add_object_terms($post_id, $term_id, $tax_type);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' add [hier] object terms result: ' . var_export($ret, TRUE));

					// record source/target term IDs for later use
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' save taxonomy data: source term=' . $term_info['term_id'] . ' target term=' . $term_id);
					$save_sync = array(
						'site_key' => $this->source_site_key,
						'source_content_id' => abs($term_info['term_id']),
						'target_content_id' => $term_id,
						'content_type' => 'term',						// IDs represent taxonomy terms
						'target_site_key' => SyncOptions::get('site_key'),
					);
					$sync_model->save_sync_data($save_sync);
				}
			}
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
//SyncDebug::log(__METHOD__.'() checking term #' . $post_term->term_id . ' "' . $post_term->slug . '" [' . $post_term->taxonomy . ']');
			$found = FALSE;							// assume $post_term is not found in $taxonomies data provided via API call
//SyncDebug::log(__METHOD__.'() checking hierarchical terms');
			if (isset($taxonomies['hierarchical']) && is_array($taxonomies['hierarchical'])) {
				foreach ($taxonomies['hierarchical'] as $term) {
					if ($term['slug'] === $post_term->slug && $term['taxonomy'] === $post_term->taxonomy) {
//SyncDebug::log(__METHOD__.'() found post term in hierarchical list');
						$found = TRUE;
						break;
					}
				}
			}
			if (!$found) {
				// not found in hierarchical taxonomies, look in flat taxonomies
//SyncDebug::log(__METHOD__.'() checking flat terms');
				if (isset($taxonomies['flat']) && is_array($taxonomies['flat'])) {
					foreach ($taxonomies['flat'] as $term) {
						if ($term['slug'] === $post_term->slug && $term['taxonomy'] === $post_term->taxonomy) {
//SyncDebug::log(__METHOD__.'() found post term in flat list');
							$found = TRUE;
							break;
						}
					}
				}
			}
			// check to see if $post_term was included in $taxonomies data provided via the API call
			if ($found) {
//SyncDebug::log(__METHOD__.'() post term found in taxonomies list- not removing it');
			} else {
				// if the $post_term assigned to the post is NOT in the $taxonomies list, it needs to be removed
//SyncDebug::log(__METHOD__.'() ** removing term #' . $post_term->term_id . ' ' . $post_term->slug . ' [' . $post_term->taxonomy . ']');
				wp_remove_object_terms($post_id, abs($post_term->term_id), $post_term->taxonomy);
			}

			// Note: no need to remove term info saved via SyncModel- term IDs don't change, only the post IDs referring to them
		}
	}

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

		// TODO: check if already loaded
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
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' featured=' . var_export($featured, TRUE) . ' path=' . $path);

		// check file type
		$img_type = wp_check_filetype($path);
		if (FALSE === $img_type['ext'] || FALSE === $img_type['type']) {
			$pos = strrpos($path, '.');
			if (FALSE !== $pos) {
				$img_type = array(
					'ext' => substr($path, $pos + 1),
					'type' => 'application/data',
				);
			}
			// if $img_type doesn't get built, it's set to ['ext'=>FALSE, 'type']=>FALSE] by wp_check_filetype()
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' img type=' . var_export($img_type, TRUE));
		// TODO: add validating method to SyncAttachModel class
		add_filter('spectrom_sync_upload_media_allowed_mime_type', array($this, 'filter_allowed_mime_types'), 10, 2);	// allows known mime types
		add_filter('user_has_cap', array($this, 'filter_has_cap'), 10, 4);												// enables the 'unfiltered_upload' capability
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found image type=' . $img_type['ext'] . '=' . $img_type['type']);
		if (FALSE === apply_filters('spectrom_sync_upload_media_allowed_mime_type', FALSE, $img_type)) {
			$response->error_code(SyncApiRequest::ERROR_INVALID_IMG_TYPE);
			$response->send();
		}

		$ext = pathinfo($path, PATHINFO_EXTENSION);

		// TODO: move this to SyncAttachModel
		$attach_model = new SyncAttachModel();
//		$res = $attach_model->get_id_by_name(basename($path, '.' . $ext));
		$res = $attach_model->search($path);
		$attachment_id = 0;
		if (FALSE !== $res)
			$attachment_id = $res;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' attachment id=' . $attachment_id);
		// TODO: need to assume error and only set to success(TRUE) when file successfully processed
		$response->success(TRUE);

		// convert source post id to target post id
		$source_post_id = abs($this->post_int('post_id'));
		$target_post_id = 0;
		$model = new SyncModel();
		$content_type = apply_filters('spectrom_sync_upload_media_content_type', 'post');
		$sync_data = $model->get_sync_data($source_post_id, $this->source_site_key, $content_type);
		if (NULL !== $sync_data)
			$target_post_id = abs($sync_data->target_content_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source id=' . $source_post_id . ' target id=' . $target_post_id);

		$this->media_id = 0;
		$this->local_media_name = '';
		add_filter('wp_handle_upload', array($this, 'handle_upload'));
		$has_error = FALSE;

		// set this up for wp_handle_upload() calls
		$overrides = array(
			'test_form' => FALSE,			// really needed because we're not submitting via a form
			'test_size' => FALSE,			// don't worry about the size
			'unique_filename_callback' => array($this, 'unique_filename_callback'),
			'action' => 'wp_handle_upload',
		);

		// Check if attachment exists
		if (0 !== $attachment_id) { // $get_posts->post_count > 0) { // NULL !== $get_posts->posts[0]) {
			// found the attachment- need to update the existing attachment with content from Source
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found attachment id ' . $attachment_id . ' posts');
			// TODO: check if files need to be updated / replaced / deleted
			// TODO: handle overwriting/replacing image files of the same name
//			$file = media_handle_upload('sync_file_upload', $this->post('post_id', 0), array(), $overrides);
//			$response->notice_code(SyncApiRequest::NOTICE_FILE_EXISTS);
			$this->media_id = $attachment_id;

			// if it's the featured image, set that
			if ($featured && 0 !== $target_post_id)
				set_post_thumbnail($target_post_id, $attachment_id);
		} else {
			// no attachment found- need to create a new one
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found no attachments (' . $attachment_id . ')');
			// setup '$time' value based on Source location, data or Target's post_date
			if ('uploads' === substr($_POST['img_path'], -7)) {
				// no subdirectories specified on Source, continue this on Target
				$time = '/';
			} else if (isset($_POST['img_year']) && isset($_POST['img_month'])) {
				// use POST values for year and month determination, if provided #158
				$time = str_pad($_POST['img_year'], 4, '0', STR_PAD_LEFT) . '/' . str_pad($_POST['img_month'], 2, '0', STR_PAD_LEFT);
			} else {
				$time = str_replace('\\', '/', substr($_POST['img_path'], -7));
				// if the year/month values were not provided or they're not digits, fix time value
				if (1 !== preg_match('/^([0-9]{4})\/([0-9]{2})$/', $time)) {
					$target_post = get_post($target_post_id);
					if (NULL === $target_post)
						$time = date('Y/m');		// use today's year/month for the time since we don't know the original date
					else
						// use Target post's post_date
						$time = substr($target_post->post_date, 0, 4) . '/' . substr($target_post->post_date, 5, 2);
				}
			}
SyncDebug::log(__METHOD__.'() time=' . $time);
			$_POST['action'] = 'wp_handle_upload';		// shouldn't have to do this with $overrides['test_form'] = FALSE
//			$file = media_handle_upload('sync_file_upload', $this->post('post_id', 0), $time);
			$file = wp_handle_upload($_FILES['sync_file_upload'], $overrides, $time);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' media_handle_upload() returned ' . var_export($file, TRUE));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' wp_handle_upload() returned ' . var_export($file, TRUE));

			if (!is_array($file) || isset($file['error'])) {
				$has_error = TRUE;
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
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' insert attachment parameters: ' . var_export($attachment, TRUE));
				$attachment_id = wp_insert_attachment($attachment, $file['file'], $target_post_id);	// insert post attachment
SyncDebug::log(__METHOD__.'():' . __LINE__ . " wp_insert_attachment([,{$target_post_id}], '{$file['file']}', {$target_post_id}) returned {$attachment_id}");
				if ($attachment_id) {
					$attach = wp_generate_attachment_metadata($attachment_id, $file['file']);	// generate metadata for new attacment
					update_post_meta($attachment_id, '_wp_attachment_image_alt', $this->post('attach_alt', ''), TRUE);
SyncDebug::log(__METHOD__.'():' . __LINE__ . " wp_generate_attachment_metadata({$attachment_id}, '{$file['file']}') returned " . var_export($attach, TRUE));
					wp_update_attachment_metadata($attachment_id, $attach);
					$response->set('post_id', $this->post('post_id'));
					$this->media_id = $attachment_id;

					// if it's the featured image, set that
					if ($featured && 0 !== $target_post_id) {
SyncDebug::log(__METHOD__."() set_post_thumbnail({$target_post_id}, {$attachment_id})");
						set_post_thumbnail($target_post_id, $attachment_id /*abs($file)*/);
					}
				} else {
					$has_error = TRUE;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' inserting attachment failed');
					$response->error_code(SyncApiRequest::ERROR_FILE_UPLOAD,  $file['file']);
				}
			} // handle_upload() results
		} // 0 !== $attachment_id

		if (!$has_error) {
			// image handling successful- perform logging
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' media successfully handled');
			// set this post as featured image, if specified.
			if ($this->post('featured', 0))
				set_post_thumbnail($target_post_id /*$this->post('post_id')*/, $this->media_id);

			// TODO: sunset this
			$media_data = array(
				'id' => $this->media_id,
				'site_key' => $this->source_site_key, // SyncOptions::get('site_key'),
				'remote_media_name' => $path,
				'local_media_name' => $this->local_media_name,
			);
			$media = new SyncMediaModel();
			$media->log($media_data);

			// save to the sync table for later reference
			$sync_data = array(
				'site_key' => $this->source_site_key,
				'source_content_id' => abs($this->post_int('attach_id')),
				'target_content_id' => $attachment_id,
				'content_type' => $content_type,
				'target_site_key' => SyncOptions::get('site_key'),
			);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' save reference for source media id=' . $sync_data['source_content_id'] . ' target media id=' . $attachment_id);
			$model->save_sync_data($sync_data);

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

		// get list of known types from WP
		$mime = get_allowed_mime_types();
		$keys = array_keys($mime);
		$res = '|' . implode('|', $keys) . '|htm|html|';
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' looking for ext "' . $img_type['ext'] . '" in=' . $res);
		if (FALSE !== strpos($res, '|' . $img_type['ext'] . '|'))
			return TRUE;

		// allow PDF, MP3 and MP4 files
//		if (in_array($img_type['ext'], array('pdf', 'mp3', 'mp4')))
//			return TRUE;

		return $default;
	}

	/**
	 * Filter the WP_User::has_cap() capabilties. Used during wp_handle_upload() call to allow unfiltered uploads.
	 * Filter is only in place during the 'upload_media' API call after request has been authenticated.
	 * @param array $allcaps The list of capabilities to allow
	 * @param array $caps Capabilities
	 * @param array $args Parameters passed to has_cap()
	 * @param WP_User $wpuser User instance making the check
	 * @return array Modified array with the 'unfiltered_upload' capability enabled
	 */
	public function filter_has_cap($allcaps, $caps, $args, $wpuser)
	{
		// add the 'unfiltered_upload' so we can override what files can be Pushed to the Target
		$allcaps['unfiltered_upload'] = TRUE;
		return $allcaps;
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
//SyncDebug::log(__METHOD__."('{$dir}', '{$name}', '{$ext}')");
		if (FALSE !== stripos($name, $ext)) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning "' . $name . '"');
			return $name;
		}
		// this forces re-use of uploaded image names #54
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning "' . $name . $ext . '"');
		return $name . $ext;
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
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' build lineage for taxonomy: ' . $tax_type);

		// first, build a lineage list of the taxonomy terms
		$lineage = array();
		$lineage[] = $term_info;            // always add the current term to the lineage
		$parent = abs($term_info['parent']);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' looking for parent term #' . $parent);
		if (isset($taxonomies['lineage'][$tax_type])) {
			while (0 !== $parent) {
				foreach ($taxonomies['lineage'][$tax_type] as $tax_term) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' checking lineage for #' . $tax_term['term_id'] . ' - ' . $tax_term['slug']);
					if ($tax_term['term_id'] == $parent) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' - found term ' . $tax_term['slug'] . ' as a child of ' . $parent);
						$lineage[] = $tax_term;
						$parent = abs($tax_term['parent']);
						break;
					}
				}
			}
		} else {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' no taxonomy lineage found for: ' . $tax_type);
		}
		$lineage = array_reverse($lineage);                // swap array order to start loop with top-most term first
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' taxonomy lineage: ' . var_export($lineage, TRUE));

		// next, make sure each term in the hierarchy exists - we'll end on the taxonomy id that needs to be assigned
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' setting taxonomy terms for taxonomy "' . $tax_type . '"');
		$generation = $parent = 0;
		foreach ($lineage as $tax_term) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' checking term #' . $tax_term['term_id'] . ' "' . $tax_term['slug'] . '" parent=' . $tax_term['parent']);
			$term = NULL;
			if (0 === $parent) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' getting top level taxonomy ' . $tax_term['slug'] . ' in taxonomy ' . $tax_type);
				$term = get_term_by('slug', $tax_term['slug'], $tax_type, OBJECT);
				if (is_wp_error($term) || FALSE === $term) {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' ERROR: cannot find term by slug ' . var_export($term, TRUE));
					$term = NULL;                    // term not found, set to NULL so code below creates it
				}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' no parent but found term: ' . var_export($term, TRUE));
			} else {
				$child_terms = get_term_children($parent, $tax_type);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found ' . count($child_terms) . ' term children for #' . $parent);
				if (!is_wp_error($child_terms)) {
					// loop through the children until we find one that matches
					foreach ($child_terms as $child_term_id) {
						$term_child = get_term_by('id', $child_term_id, $tax_type);
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' term child: ' . $term_child->slug);
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
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' term does not exist- adding name ' . $tax_term['name'] . ' under "' . $tax_type . '" args=' . var_export($args, TRUE));
				$ret = wp_insert_term($tax_term['name'], $tax_type, $args);
				if (is_wp_error($ret)) {
					$term_id = 0;
					$parent = 0;
				} else {
					$term_id = abs($ret['term_id']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' added term id #' . $term_id);
					$parent = $term_id;            // set the parent to this term id so next loop iteraction looks for term's children
				}
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' insert term [hier] result: ' . var_export($ret, TRUE));
			} else {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' found term: ' . var_export($term, TRUE));
				if (isset($term->term_id)) {
					$term_id = abs($term->term_id);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found term id #' . $term_id);
					$parent = $term_id;                            // indicate parent for next loop iteration
				} else {
SyncDebug::log(__METHOD__ . '():' . __LINE__ . ' ERROR: invalid term object');
				}
			}
			++$generation;
		}

		// the loop exits with $term_id set to 0 (error) or the child-most term_id to be assigned to the object
		return $term_id;
	}
}

// EOF
