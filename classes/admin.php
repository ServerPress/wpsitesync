<?php

/*
 * Controls post edit page and activation.
 * @package Sync
 * @author Dave Jesch
 */
class SyncAdmin
{
	private static $_instance = NULL;

	const CONTENT_TIMEOUT = 180;			// (3 * 60) = 3 minutes

	private function __construct()
	{
		// Hook here, admin_notices won't work on plugin activation since there's a redirect.
		add_action('admin_notices', array(&$this, 'configure_notice'));
		add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
		add_action('add_meta_boxes', array(&$this, 'add_sync_metabox'));
		add_filter('plugin_action_links_wpsitesynccontent/wpsitesynccontent.php', array(&$this, 'plugin_action_links'));

		add_action('before_delete_post', array($this, 'before_delete_post'));

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
		if (1 != get_option('spectrom_sync_activated')) {
			// Make sure this runs only once.
			add_option('spectrom_sync_activated', 1);
			$notice = __('You just installed WPSiteSync for Content and it needs to be configured. Please go to the <a href="%s">WPSiteSync for Content Settings page</a>.', 'wpsitesynccontent');
			echo '<div class="update-nag fade">';
			printf($notice, admin_url('options-general.php?page=sync'));
			echo '</div>';
		}
	}

	/**
	 * Registers js and css to be used.
	 */
	public function admin_enqueue_scripts($hook_suffix)
	{
		wp_register_script('sync', WPSiteSyncContent::get_asset('js/sync.js'), array('jquery'), WPSiteSyncContent::PLUGIN_VERSION, TRUE);
		wp_register_script('sync-settings', WPSiteSyncContent::get_asset('js/settings.js'), array('jquery'), WPSiteSyncContent::PLUGIN_VERSION, TRUE);

		wp_register_style('sync-admin', WPSiteSyncContent::get_asset('css/sync-admin.css'), array(), WPSiteSyncContent::PLUGIN_VERSION, 'all');

		$screen = get_current_screen();
		// load resources only on Sync settings page or page/post editor
		if ('post' === $screen->id || 'page' === $screen->id ||
			'settings_page_sync' === $screen->id ||
			in_array($screen->id, apply_filters('spectrom_sync_allowed_post_types', array()))) {
			if ('post.php' === $hook_suffix && 'add' !== $screen->action)
				wp_enqueue_script('sync');

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
		$target = SyncOptions::get('host', NULL);
		$auth = SyncOptions::get('auth', 0);

		// make sure we have a Target and it's authenticated
		if (!empty($target) && 1 === $auth) {
			$screen = get_current_screen();
			$post_types = apply_filters('spectrom_sync_allowed_post_types', array('post', 'page'));     // only show for certain post types
			if (in_array($post_type, $post_types) &&
				'add' !== $screen->action) {		// don't display metabox while adding content
				$dir = plugin_dir_url(dirname(__FILE__));
				$img = '<img id="sync-logo" src="' . $dir . 'assets/imgs/wpsitesync-logo-blue.png" width="125" height="45" alt="' .
//				$img = '<img id="sync-logo" src="' . $dir . 'assets/imgs/wpsitesync-logo.svg" width="125" height="45" alt="' .
					__('WPSiteSync logo', 'wpsitesynccontent') . '" title="' . __('WPSiteSync for Content', 'wpsitesynccontent') . '" />&#8482';
				add_meta_box(
					'spectrom_sync',				// TODO: update name
					$img, // __('WPSiteSync for Content', 'wpsitesynccontent'),
					array(&$this, 'render_sync_metabox'),
					$post_type,
					'side',
					'high');
			}
		}
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

		echo '<div style="display:none">';
		echo '<div id="sync-working-msg"><img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '" />', '</div>';
		echo '<div id="sync-success-msg">', __('Content successfully sent to Target system.', 'wpsitesynccontent'), '</div>';
		if (!class_exists('WPSiteSync_Pull', FALSE))
				echo '<div id="sync-pull-msg"><div style="color: #0085ba;">', __('Please activate the Pull extension.', 'wpsitesynccontent'), '</div></div>';
		echo '<div id="sync-runtime-err-msg">', __('A PHP runtime error occured while processing your request. Examine Target log files for more information.', 'wpsitesynccontent'), '</div>';
		echo '</div>';

		echo '</div>'; // #sync-contents

		echo '<div style="display:none">';
		echo '<span id="sync-msg-working">', __('Pushing Content to Target...', 'wpsitesynccontent'), '</span>';
		echo '<span id="sync-msg-update-changes"><b>', __('Please UPDATE your changes in order to Sync.', 'wpsitesynccontent'), '</b></span>';
		do_action('spectrom_sync_ui_messages');
		echo '</div>';
	}

	/**
	 * Filter for adding a 'settings' link in the list of plugins
	 * @param array $actions The list of available actions
	 * @return array The modified actions list
	 */
	public function plugin_action_links($actions)
	{
		$actions[] = sprintf('<a href="%1$s">%2$s</a>', admin_url('options-general.php?page=sync' ), __('Settings', 'wpsitesynccontent'));
		return $actions;
	}

	/**
	 * Obtain details about the Content from the Target site
	 * @return string HTML contents to display within the Details section within the UI
	 */
	public function get_content_details()
	{
		global $post;
		$meta_key = '_spectrom_sync_details_' . sanitize_key(SyncOptions::get('target'));

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
SyncDebug::log(__METHOD__.'() data has not been previously syncd');
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
				if (isset($response->result['body'])) {
					$response_body = json_decode($response->result['body']);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - result data: ' . var_export($response_body, TRUE));
					if (NULL === $response_body) {
						$pull_active = FALSE;
					} else if (SyncApiRequest::ERROR_UNRECOGNIZED_REQUEST === $response_body->error_code) {
						$pull_active = FALSE;
					} else if (0 !== $response_body->error_code) {
						$msg = $api->error_code_to_string($response_body->error_code);
						echo '<p>', sprintf(__('Error #%1$d: %2$s', 'wpsitesynccontent'), $response_body->error_code, $msg), '</p>';
						$pull_active = FALSE;
					}
				}


//			$target_post = (isset($response_body->data)) ? $response_body->data->post_data : NULL;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - target post: ' . var_export($target_post, TRUE));

				if (isset($response_body) && isset($response_body->data)) {
					$response_data = $response_body->data;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' - target data: ' . var_export($response_data, TRUE));
					// take the data returned from the API and
					$content_data = array(
						'target' => SyncOptions::get('target'),
						'source_post_id' => $post->ID,
						'target_post_id' => $response_data->target_post_id, // $target_post_id,
						'post_title' => $response_data->post_title, // $target_post->post_title,
						'post_author' => $response_data->post_author, // $response_body->data->username,
						'modified' => $response_data->modified, // $target_post->post_modified_gmt,
						'content' => substr($response_data->content, 0, 200) . '...', // substr(strip_tags($target_post->post_content), 0, 200) . '...',
						'content_timeout' => current_time('timestamp') + self::CONTENT_TIMEOUT,
					);
					$meta_data = json_encode($content_data);
					update_post_meta($post->ID, $meta_key, $meta_data);
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
