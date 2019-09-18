<?php

/*
 * Controls post edit page and activation.
 * @package Sync
 * @author Dave Jesch
 */
class SyncAdmin
{
	private static $_instance = NULL;

	const CONTENT_TIMEOUT = 180;						// (3 * 60) = 3 minutes

	const META_DETAILS = '_spectrom_sync_details_';		// used for caching get_details information

	private function __construct()
	{
		// Hook here, admin_notices won't work on plugin activation since there's a redirect.
		add_action('admin_notices', array($this, 'configure_notice'));
		add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
		add_action('wp_dashboard_setup', array($this, 'dashboard_init'), 50);
		add_action('add_meta_boxes', array($this, 'add_sync_metabox'));
		add_filter('plugin_action_links_wpsitesynccontent/wpsitesynccontent.php', array($this, 'plugin_action_links'));

		add_action('before_delete_post', array($this, 'before_delete_post'));

		if (is_multisite())
			add_action('admin_init', array($this, 'check_network_activation'));

		// TODO: only init if running settings page
		SyncSettings::get_instance();
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
	 * Displays the configuration prompt after activating the plugin.
	 */
	public function configure_notice()
	{
		// add check for minimum user role setting #122
		if (1 != get_option('spectrom_sync_activated') && SyncOptions::has_cap()) {
			// Make sure this runs only once.
			add_option('spectrom_sync_activated', 1);
			$notice = __('You just installed WPSiteSync for Content and it needs to be configured. Please go to the <a href="%s">WPSiteSync for Content Settings page</a>.', 'wpsitesynccontent');
			echo '<div class="update-nag fade">';
			printf($notice, admin_url('options-general.php?page=sync'));
			echo '</div>';
		}
	}

	/**
	 * Checks that the WPSiteSync plugin has been activated on all sites
	 */
	public function check_network_activation()
	{
		// if option does not exist, plugin was not set to be network active
		if (FALSE === get_site_option('spectrom_sync_activated', FALSE))
			return FALSE;

		// load installer class to perform activation
		include_once(dirname(__DIR__) . '/install/activate.php');
		$activate = new SyncActivate();
		$activate->plugin_activate_check();
	}

	/**
	 * Registers js and css to be used.
	 */
	public function admin_enqueue_scripts($hook_suffix)
	{
		// check for minimum user role settings #122
		if (!SyncOptions::has_cap())
			return;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' registering "sync"');
		wp_register_script('sync', WPSiteSyncContent::get_asset('js/sync.js'), array('jquery'), WPSiteSyncContent::PLUGIN_VERSION, TRUE);
		wp_register_script('sync-settings', WPSiteSyncContent::get_asset('js/settings.js'), array('jquery'), WPSiteSyncContent::PLUGIN_VERSION, TRUE);

		wp_register_style('sync-admin', WPSiteSyncContent::get_asset('css/sync-admin.css'), array(), WPSiteSyncContent::PLUGIN_VERSION, 'all');

		$screen = get_current_screen();
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' screen id=' . $screen->id . ' action=' . $screen->action);
		// load resources only on Sync settings page or page/post editor
		if (/*'post' === $screen->id || 'page' === $screen->id || */
			'settings_page_sync' === $screen->id ||
			in_array($screen->id, array('post', 'edit-post', 'page', 'edit-page')) || 
			in_array($screen->id, apply_filters('spectrom_sync_allowed_post_types', array('post', 'page')))) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' allowed post type');
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' hook suffix=' . $hook_suffix);
			// check for post editor page page; or post new page and Gutenberg is present
			if (('post.php' === $hook_suffix && 'add' !== $screen->action) ||
				('post-new.php' === $hook_suffix && $this->is_gutenberg())) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' enqueueing "sync"');
				wp_enqueue_script('sync');
			}
			wp_enqueue_script('sync-settings');

			$option_data = array('site_key' => SyncOptions::get('site_key'));
			wp_localize_script('sync-settings', 'syncdata', $option_data);

			wp_enqueue_style('sync-admin');
		}
	}

	/**
	 * Adds the metabox with the Sync button on post and page edit screens.
	 */
	public function add_sync_metabox($post_type)
	{
//SyncDebug::log(__METHOD__.'():' . __LINE__);
		// check for minimum user role settings #122
		if (!SyncOptions::has_cap()) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' no cap');
			return;
		}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' continuing');

		$target = SyncOptions::get('host', NULL);
		$auth = SyncOptions::get_int('auth', 0);

		// make sure we have a Target and it's authenticated
		if (!empty($target) && 1 === $auth) {
			$screen = get_current_screen();
			$post_types = apply_filters('spectrom_sync_allowed_post_types', array('post', 'page'));     // only show for certain post types
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' post types=' . implode('|', $post_types));
//SyncDebug::log(__MEthOD__.'():' . __LINE__ . ' screen action=' . $screen->action);
			// make sure it's an allowed post type and it's not the add post page...unless it's Gutenberg
			if (in_array($post_type, $post_types) &&
				('add' !== $screen->action || $this->is_gutenberg())) {		// don't display metabox while adding content...unless Gutenberg
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' calling add_meta_box()');
//die(__METHOD__.'():' . __LINE__ . ' screen: ' . var_export($screen, TRUE));
				$dir = plugin_dir_url(dirname(__FILE__));
				$img = '<img id="sync-logo" src="' . $dir . 'assets/imgs/wpsitesync-logo-blue.png" width="125" height="45" alt="' .
//				$img = '<img id="sync-logo" src="' . $dir . 'assets/imgs/wpsitesync-logo.svg" width="125" height="45" alt="' .
					__('WPSiteSync logo', 'wpsitesynccontent') . '" title="' . __('WPSiteSync for Content', 'wpsitesynccontent') . '" />&#8482';
				add_meta_box(
					'spectrom_sync',				// TODO: update name
					$img, // __('WPSiteSync for Content', 'wpsitesynccontent'),
					array($this, 'render_sync_metabox'),
					$post_type,
					'side',
					'high',
					array(
						'__block_editor_compatible_meta_box' => TRUE,
//						'__back_compat_meta_box' => TRUE,
					));
			}
//else SyncDebug::log(__METHOD__.'():' . __LINE__ . ' disallowed post type');
		}
	}

	/**
	 * Checks to see if Gutenberg is present (using function and /or WP version) and the page
	 * is using Gutenberg
	 * @return boolean TRUE if Gutenberg is present and used on current page
	 */
	private function is_gutenberg()
	{
		if (function_exists('is_gutenberg_page') && is_gutenberg_page())
			return TRUE;
		if (version_compare($GLOBALS['wp_version'], '5.0', '>=') && $this->is_gutenberg_page())
			return TRUE;
		return FALSE;
	}

	/**
	 * Checks whether we're currently loading a Gutenberg page
	 * @return boolean Whether Gutenberg is being loaded.
	 */
	private function is_gutenberg_page()
	{
		// taken from Gutenberg Plugin v4.8
		if (!is_admin())
			return FALSE;

		/*
		 * There have been reports of specialized loading scenarios where `get_current_screen`
		 * does not exist. In these cases, it is safe to say we are not loading Gutenberg.
		 */
		if (!function_exists('get_current_screen'))
			return FALSE;

		if ('post' !== get_current_screen()->base)
			return FALSE;

		if (isset($_GET['classic-editor']))
			return FALSE;

		global $post;
		if (!$this->gutenberg_can_edit_post($post))
			return FALSE;

		return TRUE;
	}

	/**
	 * Return whether the post can be edited in Gutenberg and by the current user.
	 * @param int|WP_Post $post Post ID or WP_Post object.
	 * @return bool Whether the post can be edited with Gutenberg.
	 */
	private function gutenberg_can_edit_post($post)
	{
		// taken from Gutenberg Plugin v4.8
		$post = get_post($post);
		$can_edit = TRUE;

		if (!$post)
			$can_edit = FALSE;

		if ($can_edit && 'trash' === $post->post_status)
			$can_edit = FALSE;

		if ($can_edit && !$this->gutenberg_can_edit_post_type($post->post_type))
			$can_edit = FALSE;

		if ($can_edit && !current_user_can('edit_post', $post->ID))
			$can_edit = FALSE;

		// Disable the editor if on the blog page and there is no content.
		// TODO: this is probably not a necessary check for WPSS
		if ($can_edit && abs(get_option('page_for_posts')) === $post->ID && empty($post->post_content))
			$can_edit = FALSE;

		/**
		 * Filter to allow plugins to enable/disable Gutenberg for particular post.
		 *
		 * @param bool $can_edit Whether the post can be edited or not.
		 * @param WP_Post $post The post being checked.
		 */
		return apply_filters('gutenberg_can_edit_post', $can_edit, $post);
	}

	/**
	 * Return whether the post type can be edited in Gutenberg.
	 *
	 * Gutenberg depends on the REST API, and if the post type is not shown in the
	 * REST API, then the post cannot be edited in Gutenberg.
	 *
	 * @param string $post_type The post type.
	 * @return bool Whether the post type can be edited with Gutenberg.
	 */
	private function gutenberg_can_edit_post_type($post_type)
	{
		$can_edit = TRUE;
		if (!post_type_exists($post_type))
			$can_edit = FALSE;

		if (!post_type_supports($post_type, 'editor'))
			$can_edit = FALSE;

		$post_type_object = get_post_type_object($post_type);
		if ($post_type_object && !$post_type_object->show_in_rest)
			$can_edit = FALSE;

		/**
		 * Filter to allow plugins to enable/disable Gutenberg for particular post types.
		 *
		 * @param bool   $can_edit  Whether the post type can be edited or not.
		 * @param string $post_type The post type being checked.
		 */
		return apply_filters('gutenberg_can_edit_post_type', $can_edit, $post_type);
	}

	/**
	 * Display the Sync button on edit pages.
	 */
	public function render_sync_metabox()
	{
		$error = FALSE;
		if (!SyncOptions::is_auth() /*is_wp_error($e)*/) {
			$notice = __('WPSiteSync for Content has invalid or missing settings. Please go the the <a href="%s">settings page</a> to update the configuration.', 'wpsitesynccontent');
			echo '<p>', sprintf($notice, admin_url('options-general.php?page=sync')), '</p>';
			$error = TRUE;
		}

		if ($error)
			return;

		echo '<div class="sync-panel sync-panel-left">';
		echo '<span>', __('Push content to Target: ', 'wpsitesynccontent'), '<br/>',
			sprintf('<span title="%2$s"><b>%1$s</b></span>',
				SyncOptions::get('host'),
				esc_attr(__('The "Target" is the WordPress install that the Content will be pushed to.', 'wpsitesynccontent'))),
			'</span>';
		echo '</div>'; // .sync-panel-left

		echo '<div class="sync-panel sync-panel-right">';
		echo '<button id="sync-button-details" class="button" onclick="wpsitesynccontent.show_details(); return false" title="',
			__('Show Content details from Target', 'wpsitesynccontent'), '"><span class="dashicons dashicons-arrow-down"></span></button>';
		echo '</div>'; // .sync-panel-right

		echo '<div id="sync-contents">';

		// display the content details
		$content_details = $this->get_content_details();

		// add the 'remove association' button #236
		// we do this here instead of within the get_content_details() so it's not also displayed within the Pull Search dialog box
##		global $post;
##		$details = $this->get_details_meta($post->ID);
##		if (FALSE !== $details) {
##			$content_details .= '<div id="content-association">
##				<button id="remove-association" type="button" class="button button-primary" onclick="wpsitesynccontent.show_assoc();" title="' . __('Remove Association', 'wpsitesynccontent') . '">' .
##				'<span class="sync-button-icon dashicons dashicons-admin-links xdashicons-tide"></span></button>' .
##				'</div>';
##			add_action('admin_footer', array($this, 'add_remove_assoc_dialog'));
##		}

		// TODO: set details content
		echo '<div id="sync-details" style="display:none">';
		echo $content_details;
		echo '</div>';	// contains content detail information

		echo '<div id="sync-message-container" style="display:none">';
		echo '<span id="sync-content-anim" style="display:none"> <img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '" /></span>';
		echo '<span id="sync-message"></span>';
		echo '<span id="sync-message-dismiss" style="display:none">';
			echo '<span class="dashicons dashicons-dismiss" onclick="wpsitesynccontent.clear_message(); return false"></span>';
//			echo '<button type="button" class="notice-dismiss" onclick="wpsitesynccontent.clear_message(); return false">';
//			echo '<span class="screen-reader-text">Dismiss this notice.</span></button>';
		echo '</span>';
		echo '</div>';

		global $post;
		do_action('spectrom_sync_metabox_before_button', $error);

		// display the Sync button
		echo '<button id="sync-content" type="button" class="button button-primary sync-button" onclick="wpsitesynccontent.push(', $post->ID, ')" ';
		if ($error)
			echo ' disabled';
		echo ' title="', __('Push this Content to the Target site', 'wpsitesynccontent'), '" ';
		echo '>';
		echo '<span class="sync-button-icon dashicons dashicons-migrate"></span>',
			__('Push to Target', 'wpsitesynccontent');
		echo '</button>';

		if (!class_exists('WPSiteSync_Pull', FALSE)) {
			// display the button that goes in the Metabox
			echo '<button id="sync-pull-content" type="button" class="button sync-button" onclick="wpsitesynccontent.pull_feature(); return false;" ';
			echo ' title="', __('Pull this Content from the Target site', 'wpsitesynccontent'), '" ';
			echo '>';
			echo '<span><span class="sync-button-icon sync-button-icon-rotate dashicons dashicons-migrate"></span>', __('Pull from Target', 'wpsitesynccontent'), '</span>';
			echo '</button>';
		}

		do_action('spectrom_sync_metabox_after_button', $error);

		wp_nonce_field('sync', '_sync_nonce');

		echo '<div id="sync-after-operations">';
		do_action('spectrom_sync_metabox_operations', $error);
		echo '</div>';

		// container to hold messages used in WPSiteSync UI
		echo '<div style="display:none">';
		echo '<div id="sync-working-msg"><img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '" />', '</div>';
		echo '<div id="sync-success-msg">', __('Content successfully sent to Target system.', 'wpsitesynccontent'), '</div>';
		if (!class_exists('WPSiteSync_Pull', FALSE))
			echo '<div id="sync-pull-msg"><div style="color: #0085ba;">', __('Please activate the Pull extension.', 'wpsitesynccontent'), '</div></div>';
		echo '<div id="sync-runtime-err-msg">', __('A PHP runtime error occurred while processing your request. Examine Target log files for more information.', 'wpsitesynccontent'), '</div>';
		echo '<div id="sync-error-msg">', __('Error: error encountered during request.', 'wpsitesynccontent'), '</div>';
		echo '<span id="sync-msg-working">', __('Pushing Content to Target...', 'wpsitesynccontent'), '</span>';
		echo '<span id="sync-msg-update-changes"><b>', __('Please UPDATE/Save your changes in order to Sync.', 'wpsitesynccontent'), '</b></span>';
		do_action('spectrom_sync_ui_messages');
		echo '</div>';

		echo '</div>'; // #sync-contents
	}

	/**
	 * Initializes the Dashboard Widget
	 */
	public function dashboard_init()
	{
		$db_widget = new SyncAdminDashboard();
		wp_add_dashboard_widget('sync-dashboard', 'WPSiteSync', array($db_widget, 'render_dashboard_widget'));

		// move to the top
		global $wp_meta_boxes;
		$widget_list = $wp_meta_boxes['dashboard']['normal']['core'];
		$widget = array('sync-dashboard' => $widget_list['sync-dashboard']);
		unset($widget_list['sync-dashboard']);

		$new_widget_list = array_merge($widget, $widget_list);
		$wp_meta_boxes['dashboard']['normal']['core'] = $new_widget_list;
	}

	/**
	 * Filter for adding a 'settings' link in the list of plugins
	 * @param array $actions The list of available actions
	 * @return array The modified actions list
	 */
	public function plugin_action_links($actions)
	{
		// add check for minimum user role settings #122
		if (SyncOptions::has_cap())
			$actions[] = sprintf('<a href="%1$s">%2$s</a>', admin_url('options-general.php?page=sync'), __('Settings', 'wpsitesynccontent'));
		return $actions;
	}

	/**
	 * Retrieves the post details information of the Target post from local postmeta data
	 * @param itn $post_id The post ID to retrieve the detail information about
	 * @return object|boolean The details object if successful; otherwise FALSE
	 */
	public function get_details_meta($post_id)
	{
		$meta_key = self::META_DETAILS . sanitize_key(parse_url(SyncOptions::get('target'), PHP_URL_HOST));
		$meta_data = get_post_meta($post_id, $meta_key, TRUE);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' meta key="' . $meta_key . '" data=' . var_export($meta_data, TRUE));
		if (empty($meta_data))
			$meta_data = FALSE;
		return $meta_data;
	}

	/**
	 * Obtain details about the Content from the Target site
	 * @return string HTML contents to display within the Details section within the UI
	 */
	public function get_content_details()
	{
		global $post;
		$meta_key = self::META_DETAILS . sanitize_key(parse_url(SyncOptions::get('target'), PHP_URL_HOST));

		// check to see if the API call should be made
		$run_api = FALSE;
		$meta_data = get_post_meta($post->ID, $meta_key, TRUE);
		if (!empty($meta_data)) {
			$content_data = json_decode($meta_data, TRUE);
			if (!isset($content_data['content_timeout']) || current_time('timestamp') > $content_data['content_timeout'])
				$run_api = TRUE;
		} else {
			$run_api = TRUE;
		}

		if ($run_api) {
SyncDebug::log(__METHOD__.'() post id=' . $post->ID);
			$sync_model = new SyncModel();
			$target_post_id = 0;

			if (NULL === ($sync_data = $sync_model->get_sync_target_post($post->ID, SyncOptions::get('target_site_key')))) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data has not been previously sync\'d');
				$content_data = array('message' => __('This Content has not yet been Sync\'d. No details to show.', 'wpsitesynccontent'));
			} else {
				$target_post_id = $sync_data->target_content_id;

				// use API to obtain details
				$api = new SyncApiRequest();

				// get the post id on the Target for Source post_id
SyncDebug::log(__METHOD__.'() sync data: ' . var_export($sync_data, TRUE));

				// ask the Target for the post's content
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - retrieving Target post ID ' . $target_post_id);
				// change 'pullinfo' API call to 'getinfo'
				$response = $api->api('getinfo', array('post_id' => $target_post_id, 'post_name' => $post->post_name));
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - returned object: ' . var_export($response, TRUE));

				// examine API response to see if Pull is running on Target
				$pull_active = TRUE;
				if (isset($response->response)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - result data: ' . var_export($response->response, TRUE));
					if (NULL === $response->response) {
						$pull_active = FALSE;
					} else if (SyncApiRequest::ERROR_UNRECOGNIZED_REQUEST === $response->response->error_code) {
						$pull_active = FALSE;
					} else if (0 !== $response->response->error_code) {
						$msg = $api->error_code_to_string($response->response->error_code);
						echo '<p>', sprintf(__('Error #%1$d: %2$s', 'wpsitesynccontent'), $response->response->error_code, $msg), '</p>';
						$pull_active = FALSE;
					}
				}


//			$target_post = (isset($response->response->data)) ? $response->response->data->post_data : NULL;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - target post: ' . var_export($target_post, TRUE));

				if (isset($response->response) && isset($response->response->data)) {
					$response_data = $response->response->data;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - response data: ' . var_export($response_data, TRUE));
					// check for errors in 'getinfo' API call #181
					if (0 !== $response->response->error_code) {
						$content_data = array(
							'message' => sprintf(__('Error obtaining Target information: %1$s', 'wpsitesynccontent'), $response->response->error_message)
						);
					// check for missing Target information as a fallback #181
					} else if (!isset($response_data->target_post_id)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' response missing post id. cannot show details');
						$content_data = array(
							'message' => __('Error in Target content. API call failed.', 'wpsitesynccontent')
						);
					} else {
						// take the data returned from the API and prepare it for the View
						$content_data = array(
							'target' => SyncOptions::get('target'),
							'source_post_id' => $post->ID,
							'target_post_id' => $response_data->target_post_id, // $target_post_id,
							'post_title' => $response_data->post_title, // $target_post->post_title,
							'post_author' => $response_data->post_author, // $response->response->data->username,
							'feat_img' => isset($response_data->feat_img) ? $response_data->feat_img : '',
							'modified' => $response_data->modified, // $target_post->post_modified_gmt,
							'content' => $response_data->content . '...', // substr(strip_tags($target_post->post_content), 0, 200) . '...',
							'content_timeout' => current_time('timestamp') + self::CONTENT_TIMEOUT,
						);
						$meta_data = json_encode($content_data);
						update_post_meta($post->ID, $meta_key, $meta_data);
					}
				} else {
					$content_data = array(
						'message' => __('Error obtaining Content Details', 'wpsitesynccontent')
					);
				}
			}
		}

		$content = '';
		if (isset($content_data['message']))
			$content = $content_data['message'];
		else
			$content = SyncView::load_view('content_details', $content_data, TRUE);

		return $content;
	}

	/**
	 * Outputs the HTML for the Remove Association Dialog
	 */
	public function add_remove_assoc_dialog()
	{
		$title = __('WPSiteSync&#8482;: Remove Content Association', 'wpsitesynccontent');

		echo '<div id="sync-remove-assoc-dialog" style="display:none" title="', esc_html($title), '">';
			echo '<div id="spectrom_sync_remove_assoc">';
			echo '<p>', __('This will remove the association of the current Contnet with it\'s matching post ID on the Target site. This means that the next time you Push this Content it will perform a new search on the Target site for matching Content rather than updating the post ID that was previously connected with this Content. For more information, you can read our <a href="https://wpsitesync.com/knowledgebase/removing-associations">Knowledge Base Article</a>.', 'wpsitesynccontent'), '</p>';
			echo '<p>', __('To remove the association and unlink the current Content with it\'s Content on the Target site, click the "Remove Association" button below. Otherwise, press Escape or click on the Cancel button.', 'wpsitesynccontent'), '</p>';

			echo '<button id="sync-remove-assoc-api" type="button" onclick="wpsitesynccontent.remove_assoc(); return false;" class="button button-primary">',
				'<span class="sync-button-icon dashicons dashicons-admin-links"></span>', __('Remove Association', 'wpsitesynccontent'),
				'</button>';
			echo '&nbsp;';
			echo '<button id="sync-remove-assoc-cancel" type="button" onclick="jQuery(\'#sync-remove-assoc-dialog\').dialog(\'close\');" class="button">',
				__('Cancel', 'wpsitesynccontent'),
				'</button>';
			echo '</div>';
		echo '</div>'; // close dialog HTML
	}

	/**
	 * Callback for delete post action. Removes all sync records associated with Content.
	 * @param int $post_id The post ID being deleted
	 */
	public function before_delete_post($post_id)
	{
		$model = new SyncModel();
		$model->remove_all_sync_data($post_id);
	}
}

// EOF
