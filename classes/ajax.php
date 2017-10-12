<?php

/**
 * Handles AJAX requests on the Source system
 */
class SyncAjax extends SyncInput
{
	public function __construct()
	{
		// add user data on a high priority - in case other add-ons want to change/modify it
//		add_filter('spectrom_sync_api_request', array(&$this, 'add_user_info'), 1, 3);
	}

	/**
	 * Modifies the push data, adding the user information
	 * @param array $data The data being assembled for the current operation
	 * @param string $action The API request type, 'auth', 'push', etc.
	 * @param array $remote_args The arguments being sent to wp_remote_post()
	 * @return array The modified $sync_data
	 */
	// TODO: this should be moved into SyncApiRequest instead of in the AJAX class
	public function add_user_info($data, $action, $remote_args)
	{
		// also include the user name of the user on the Source site that is Pushing the Content
		$current_user = wp_get_current_user();
//SyncDebug::log(__METHOD__.'() current user=' . var_export($current_user, TRUE));
		if (NULL !== $current_user && 0 !== $current_user->ID) {
			$data['username'] = $current_user->user_login;
			$data['user_id'] = $current_user->ID;
		}
		return $data;
	}

	/*
	 * Called from WPSiteSyncContent::check_ajax_query() where it processes the incoming admin AJAX requests
	 */
	public function dispatch()
	{
		$operation = $this->post('operation');
SyncDebug::log(__METHOD__."('{$operation}')");
		$response = new SyncApiResponse(TRUE);

		// set headers
//		header('Content-Type: text/html; charset=ISO-utf-8');
		header('Content-Type: application/json; charset=utf-8');
		header('Content-Encoding: ajax');
		header('Cache-Control: private, max-age=0');
		header('Expires: -1');

		// perform authentication checking: must be logged in, an 'Author' role or higher
		if (!is_user_logged_in()) {
			$response->error_code(SyncApiRequest::ERROR_SESSION_EXPIRED, $operation);
			$response->send();
		}
		if (!current_user_can('publish_posts')) {
			$response->error_code(SyncApiRequest::ERROR_NO_PERMISSION, $operation);
			$response->send();
		}
		// TODO: check nonce

		switch ($operation) {
		case 'activate':
		case 'deactivate':
			$name = $this->post('extension');
			$lic = new SyncLicensing();
			if ('activate' === $operation)
				$res = $lic->activate($name);
			else
				$res = $lic->deactivate($name);
			$status = $lic->get_status($name);
			$response->success(TRUE);
			$response->set('status', $status);
			if (isset($res['message']))
				$response->set('message', __('License Key status: ', 'wpsitesynccontent') . $res['message']);
			if (isset($res['status']))
				$response->set('status', $res['status']);
			else
				$response->set('status', 'unknown');
			break;
		case 'push':
			$this->_push($response);
			break;
		case 'upload_media':
			$this->upload_media($response);
			break;
		case 'verify_connection':
			$this->verify_connection($response);
			break;
		default:
			// allow add-ons a chance to handle their own AJAX request operation types
			if (FALSE === apply_filters('spectrom_sync_ajax_operation', FALSE, $operation, $response)) {
				// No method found, fallback to error message.
//				$response->success(FALSE);
				$response->error_code(SyncApiRequest::ERROR_EXTENSION_MISSING, $operation);
				// TODO: error_code() data parameter
				$response->error(sprintf(__('Method `%s` not found.', 'wpsitesynccontent'), $operation));
			}
		}

		// send the response to the browser
//SyncDebug::log(__METHOD__.'() operation "' . $operation . '" returning ' . var_export($response, TRUE));
		$response->send();
	}

	/**
	 * Ajax Callback
	 * 
	 * Sends an auth request to the target host.		 
	 */
	public function verify_connection(SyncApiResponse $response)
	{
		$input = new SyncInput();
		$settings = array_merge(
			SyncOptions::get_all(), // get_option(SyncOptions::OPTION_NAME),
			$input->post(SyncOptions::OPTION_NAME)
		);

		$api = new SyncApiRequest($settings);
		$e = $api->auth();
		if (is_wp_error($e)) {
			$response->success(FALSE);
			$response->error($e->get_error_message());
		} else
			$response->success(TRUE);

		$response->send();
		exit();
	}

	/**
	 * Ajax callback
	 *
	 * Pushes the data to the target site.
	 */
	private function _push(SyncApiResponse &$response)
	{
SyncDebug::log(__METHOD__.'()');

		// TODO: move nonce check into dispatch() so it's centralized
		if (!(wp_verify_nonce($this->post('_sync_nonce', ''), 'sync'))) {
SyncDebug::log('- failed nonce check');
			$response->success(FALSE);
			$response->error_code(SyncApiRequest::ERROR_BAD_NONCE);
			$response->send();
			exit();
		}

		$post_id = $this->post_int('post_id', 0);
		$api = new SyncApiRequest();
		$api_response = $api->api('push', array('post_id' => $post_id));
$response->copy($api_response);
		if ($api_response->is_success()) {
			$response->success(TRUE);
			$response->set('message', __('Content successfully sent to Target system.', 'wpsitesynccontent'));
		} else {
//			$response->copy($api_response);
		}
		return;
	}
}

// EOF
