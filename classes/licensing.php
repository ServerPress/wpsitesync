<?php

/**
 * Perform Licensing operations for WPSiteSync plugins
 * http://docs.easydigitaldownloads.com/category/373-software-licensing
 */
class SyncLicensing
{
	const OPTION_NAME = 'spectrom_sync_licensing';

	const LICENSE_API_URL = 'https://wpsitesync.com';
	const LICENSE_TTL = 10800;						# 28800 = 8hrs | 10800 = 3hrs

	const STATE_UNKNOWN = '0';
	const STATE_ACTIVE = '1';
	const STATE_INACTIVE = '2';
	const STATE_EXPIRED = '3';
	const STATE_ERROR = '9';

	private static $_licenses = NULL;
	private static $_status = array();
	private static $_dirty = FALSE;
	private static $_instance = NULL;

	public function __construct()
	{
		if (NULL === self::$_instance)
			self::$_instance = $this;
		return self::$_instance;
	}

	/**
	 * Returns the URL to use for Licensing API calls
	 * @return string The License API url
	 */
	private function _get_api_url()
	{
		$url = self::LICENSE_API_URL;
		if (file_exists(dirname(dirname(__FILE__)) . '/license.tmp'))
			$url = str_replace('//', '//staging.', $url);
		return $url;
	}

	/**
	 * Loads the License Keys from the options table
	 * @return array An array of License Keys entered for all add-ons
	 */
	public function get_license_keys()
	{
		$this->_load_licenses();
		return self::$_licenses;
	}

	/**
	 * Returns the License Key stored for the named add-on
	 * @param string $name The name of the add-on to get the License Key for
	 * @return boolean|string The License Key or FALSE if License Key not present
	 */
	public function get_license_key($name)
	{
		$this->_load_licenses();
		if (empty(self::$_licenses[$name]))
			return FALSE;
		return self::$_licenses[$name];
	}

	/**
	 * Used to check validity of the License Key for the named add-on
	 * @param string $slug The name of the add-on to check
	 * @param string $key The expected plugin Key associated with the License
	 * @param string $name The name of the plugin performing License Check
	 * @return boolean TRUE if the License is active and valid; otherwise FALSE
	 */
	public function check_license($slug, $key, $name)
	{
//SyncDebug::log(__METHOD__."('{$slug}', '{$key}', '{$name}')");
		$this->_load_licenses();

		// check cached value
		if (isset(self::$_status[$slug])) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' cached[' . $slug . ']=' . (self::$_status[$slug] ? 'TRUE' : 'FALSE'));
			return self::$_status[$slug];
		}

		// check for presence of licensing information
		if (!isset(self::$_licenses[$slug]) || empty(self::$_licenses[$slug]) ||
			!isset(self::$_licenses[$slug . '_st']) || empty(self::$_licenses[$slug . '_st'])) {
			// incomplete information, return failure
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' FALSE');
			return FALSE;
		}

//		$extensions = SyncExtensionModel::get_extensions();
//		if (!isset($extensions[$slug]))
//			return FALSE;

		$call = FALSE;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' [' . $slug . '] check call status=TRUE');
		if (isset(self::$_licenses[$slug . '_tr']) && self::$_licenses[$slug . '_tr'] < time()) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' [' . $slug . '] not expired');
			$call = FALSE;
		}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' [' . $slug . '] call=' . ($call ? 'TRUE' : 'FALSE') . ' t=' . time());

		if (!isset(self::$_licenses[$slug . '_vl']) || self::$_licenses[$slug . '_vl'] !== $key) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' [' . $slug . '] no validation: ' . $key);
			$call = TRUE;
		}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' [' . $slug . '] call=' . ($call ? 'TRUE' : 'FALSE') . ' v=' . self::$_licenses[$slug . '_vl']);

		if ($call) {
			// TODO: move this down - after transient and API calls
			// check license status
			if ('valid' === self::$_licenses[$slug . '_st']) {
				self::$_status[$slug] = TRUE;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' TRUE');
				return TRUE;
			}

			// make the API call
			$api_params = array(
				'edd_action' => 'check_license',
				'license' => self::$_licenses[$slug],
				'item_name' => urlencode($name)
			);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sending ' . var_export($api_params, TRUE) . ' to ' . $this->_get_api_url());
			$response = wp_remote_get($remote_url = add_query_arg($api_params, $this->_get_api_url()), array('timeout' => 15, 'sslverify' => FALSE));
			if (is_wp_error($response)) {
				self::$_status[$slug] = FALSE;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' FALSE');
				return FALSE;
			}

			// check response
			$response_body = wp_remote_retrieve_body($response);
			if (!empty($response_body)) {
				$license_data = json_decode($response_body);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' license data=' . var_export($license_data, TRUE));
				if ('valid' === $license_data->license) {
					// this license is still valid
					self::$_licenses[$slug . '_st'] = self::STATE_ACTIVE;
					self::$_licenses[$slug . '_tr'] = time() + self::LICENSE_TTL;
					self::$_licenses[$slug . '_vl'] = md5($slug . $name);
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' [' . $slug . '] vl=' . self::$_licenses[$slug . '_vl']);
				} else {
					// this license is no longer valid
					self::$_licenses[$slug . '_st'] = self::STATE_UNKNOWN;
					self::$_licenses[$slug . '_vl'] = '';
				}
				self::$_dirty = TRUE;
				$this->save_licenses();
			} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' slug=' . $slug . ' url=' . $remote_url . ' with params: ' . var_export($api_params, TRUE) . ' returned: ' . $response_body);
			}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' setting dirty flag');
		}

		self::$_status[$slug] = self::STATE_ACTIVE === self::$_licenses[$slug . '_st'];
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning st=' . (self::$_status[$slug] ? 'TRUE' : 'FALSE') . ' vl=' . ($key === self::$_licenses[$slug . '_vl'] ? 'TRUE' : 'FALSE'));
//		return self::STATE_ACTIVE === self::$_licenses[$slug . '_st'] && $key === self::$_licenses[$slug . '_vl'];
		return self::$_status[$slug];
	}

	/**
	 * Determine status of named License Key
	 * @param string $name The add-on name
	 * @return string Text description of License status. One of 'inactive', 'active', 'expired', 'unknown' or 'unset'
	 */
	public function get_status($name)
	{
		$this->_load_licenses();
		if (!empty(self::$_licenses[$name]) && isset(self::$_licenses[$name . '_st'])) {
			switch (self::$_licenses[$name . '_st']) {
			case self::STATE_UNKNOWN:
				return __('unknown', 'wpsitesynccontent');
			case self::STATE_ACTIVE:
				return __('active', 'wpsitesynccontent');
			case self::STATE_INACTIVE:
				return __('inactive', 'wpsitesynccontent');
			case self::STATE_EXPIRED:
				return __('expired', 'wpsitesynccontent');
			}
		}
		return __('unset', 'wpsitesynccontent');
	}

	/**
	 * Activates the License Key for the named add-on
	 * @param string $name The name of the add-on to activate the License Key for
	 * @return boolean TRUE if the License was successfully activated; otherwise FALSE.
	 */
	public function activate($name)
	{
//SyncDebug::log(__METHOD__."('{$name}')");
		$this->_load_licenses();
		if (empty(self::$_licenses[$name])) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' license empty');
			return FALSE;
		}

		$extensions = SyncExtensionModel::get_extensions(TRUE);
		if (empty($extensions[$name])) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' extension empty');
			return FALSE;
		}

		$license = self::$_licenses[$name];
		// data to send in our API request
		$api_params = array( 
			'edd_action'=> 'activate_license', 
			'license' 	=> $license, 
			'item_name' => urlencode($extensions[$name]['name']),	// the name of our product in EDD,
			'url'       => home_url()
		);

		// Call the licensing API
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' sending ' . var_export($api_params, TRUE) . ' to ' . $this->_get_api_url());
		$response = wp_remote_post($this->_get_api_url(), array(
			'timeout'   => 15,
			'sslverify' => FALSE,
			'body'      => $api_params
		));
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' results=' . var_export($response, TRUE));

		// check for errors
		if (is_wp_error($response)) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' FALSE');
			return FALSE;
		}

		// decode the license data
		$license_data = json_decode(wp_remote_retrieve_body($response));
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' data=' . var_export($license_data, TRUE));

/*
ERROR:
   'success' => false,
   'error' => 'missing',
   'license_limit' => false,
   'site_count' => 0,
   'expires' => false,
   'activations_left' => 'unlimited',
   'license' => 'invalid',
   'item_name' => 'WPSiteSync+CPT',
   'payment_id' => false,
   'customer_name' => NULL,
   'customer_email' => NULL,
SUCCESS:
   'success' => true,
   'license_limit' => '1',
   'site_count' => 1,
   'expires' => '2017-06-29 23:59:59',
   'activations_left' => 0,
   'license' => 'valid',
   'item_name' => 'WPSiteSync+for+Custom+Post+Types',
   'payment_id' => '236',
   'customer_name' => 'David Jesch',
   'customer_email' => 'd.jesch@serverpress.com',
 */
		$status = 'unset';
		$message = 'unknown';
		$code = self::STATE_ERROR;
		$update = FALSE;

		if ($license_data->success) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' success');
			switch ($license_data->license) {
			case 'valid':
				$code = self::$_licenses[$name . '_st'] = self::STATE_ACTIVE;
				$update = TRUE;
				$status = 'active';
				$message = __('Activated', 'wpsitesynccontent');
				break;
			case 'inactive':
				$code = self::$_licenses[$name . '_st'] = self::STATE_INACTIVE;
				$update = TRUE;
				$status = 'inactive';
				$message = __('Inactive License', 'wpsitesynccontent');
				break;
			default:
				$code = self::$_licenses[$name . '_st'] = self::STATE_ERROR;
				$update = TRUE;
				$status = 'unknown';
				$message = __('Unknown API result: ', 'wpsitesynccontent') . $license_data->license;
			}
		} else {
			switch ($license_data->error) {
			case 'missing':
				$code = self::STATE_ERROR;
				$status = 'unset';
				$message = __('Invalid License Key', 'wpsitesynccontent');
				break;
			}
		}
		if ($update) {
			self::$_dirty = TRUE;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' setting dirty');
			$this->save_licenses();
		}

		$ret = array(
			'code' => $code,
			'status' => $status,
			'message' => $message,
		);
		return $ret;
	}

	/**
	 * Retrieves the add-on's version from the EDD API 
	 * @param string $slug The slug for the add-on to retrieve version information for
	 * @param string $name The name of the add-on for version retrieval
	 * @return string|boolean The version number as a string (allowing for numbers x.x.x) or FALSE if the add-on is not recognized
	 */
	public function get_version($slug, $name)
	{
//SyncDebug::log(__METHOD__."('{$slug}', '{$name}')");
		$this->_load_licenses();
		if (empty(self::$_licenses[$name])) {
//SyncDebug::log(' - license empty ' . __LINE__);
			return FALSE;
		}

		$extensions = SyncExtensionModel::get_extensions(TRUE);
		if (empty($extensions[$name])) {
//SyncDebug::log(' - extension empty ' . __LINE__);
			return FALSE;
		}

		$api_params = array(
			'edd_action' => 'get_version',
			'url' => site_url(),
			'license' => self::$_licenses[$name],
			'name' => urlencode($name),
			'slug' => $slug,
		);
//SyncDebug::log(__METHOD__.'() sending ' . var_export($api_params, TRUE) . ' to ' . $this->_get_api_url());
		$response = wp_remote_get($this->_get_api_url(), array(
			'timeout' => 15,
			'sslverify' => FALSE
		));
//SyncDebug::log(__METHOD__.'() results=' . var_export($response, TRUE));
/**
			'new_version'   => $version,
			'name'          => $download->post_title,
			'slug'          => $slug,
			'url'           => esc_url( add_query_arg( 'changelog', '1', get_permalink( $item_id ) ) ),
			'last_updated'  => $download->post_modified,
			'homepage'      => get_permalink( $item_id ),
			'package'       => $this->get_encoded_download_package_url( $item_id, $license, $url ),
			'download_link' => $this->get_encoded_download_package_url( $item_id, $license, $url ),
			'sections'      => serialize(
				array(
					'description' => wpautop( strip_tags( $description, '<p><li><ul><ol><strong><a><em><span><br>' ) ),
					'changelog'   => wpautop( strip_tags( stripslashes( $changelog ), '<p><li><ul><ol><strong><a><em><span><br>' ) ),
				)
			),
 */
		// check for errors
		if (is_wp_error($response))
			return FALSE;

		// decode the response
		$license_data = json_decode(wp_remote_retrieve_body($response));
//SyncDebug::log(__METHOD__.'() data=' . var_export($license_data, TRUE));
	}

	/**
	 * Deactivates the License Key for the named add-on
	 * @param string $name The name of the add-on to activate the License Key for
	 * @return boolean TRUE if the License was successfully deactivated; otherwise FALSE.
	 */
	public function deactivate($name)
	{
//SyncDebug::log(__METHOD__."('{$name}')");
		$this->_load_licenses();
		if (empty(self::$_licenses[$name]))
			return FALSE;

		$extensions = SyncExtensionModel::get_extensions();
		if (empty($extensions[$name]))
			return FALSE;

		$license = self::$_licenses[$name];
		// data to send in our API request
		$api_params = array( 
			'edd_action'=> 'deactivate_license', 
			'license' 	=> $license, 
			'item_name' => urlencode($extensions[$name]['name']),	// the name of our product in EDD,
		);

		// Call the licensing API
//SyncDebug::log(__METHOD__.'() sending ' . var_export($api_params, TRUE) . ' to ' . $this->_get_api_url());
		$response = wp_remote_post($this->_get_api_url(), array(
			'timeout'   => 15,
			'sslverify' => FALSE,
			'body'      => $api_params
		));
//SyncDebug::log(__METHOD__.'() results=' . var_export($response, TRUE));

		// check for errors
		if (is_wp_error($response))
			return FALSE;

		// decode the license data
		$license_data = json_decode(wp_remote_retrieve_body($response));
//SyncDebug::log(__METHOD__.'() data=' . var_export($license_data, TRUE));

/*
ERROR:
	license: failed
	item: Sample Plugin
SUCCESS:
	license: deactivated
	item: Sample Plugin
 */
		$status = 'unset';
		$message = 'unknown';
		$code = self::STATE_ERROR;
		$update = FALSE;

		if (isset($license_data->license)) {
			switch ($license_data->license) {
			case 'deactivated':
				$code = self::$_licenses[$name . '_st'] = self::STATE_INACTIVE;
				$update = TRUE;
				$status = 'inactive';
				$message = __('Deactivated', 'wpsitesynccontent');
				break;
			case 'failed':
				$code = self::$_licenses[$name . '_st'] = self::STATE_ERROR;
				$status = 'error';
				$message = __('Error deactivating License.', 'wpsitesynccontent');
				break;
			default:
				$code = self::$_licenses[$name . '_st'] = self::STATE_ERROR;
				$status = 'error';
				$message = __('Unknown API result: ', 'wpsitesynccontent') . $license_data->license;
			}
		} else {
			$code = self::$_licenses[$name . '_st'] = self::STATE_ERROR;
			$status = 'error';
			$message = __('Unexpected API response', 'wpsitesynccontent');
		}
		if ($update) {
			self::$_dirty = TRUE;
			$this->save_licenses();
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' setting dirty');
		}

		$ret = array(
			'code' => $code,
			'status' => $status,
			'message' => $message,
		);
		return $ret;
	}

	/**
	 * Loads the License Key data and caches it in static property
	 */
	private function _load_licenses()
	{
		if (NULL === self::$_licenses) {
			self::$_licenses = get_option(self::OPTION_NAME, array());
//SyncDebug::log(__METHOD__.'() licenses: ' . var_export(self::$_licenses, TRUE));
//$ex = new Exception();
//SyncDebug::log(__METHOD__.'() trace=' . $ex->getTraceAsString());
			$modified = FALSE;
			$extensions = SyncExtensionModel::get_extensions();
			foreach ($extensions as $key => $extension) {
				if (!isset(self::$_licenses[$key])) {
					self::$_licenses[$key] = '';
					$modified = TRUE;
				}
			}

			// check if anything added and update if necessary
			if ($modified) {
				update_option(self::OPTION_NAME, self::$_licenses);
			}
			self::$_dirty = FALSE;
		}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' returning');
	}

	/**
	 * Persists the current license information.
	 */
	public function save_licenses()
	{
//SyncDebug::log(__METHOD__.'()');
		if (self::$_dirty) {
//SyncDebug::log(' - saving');
			update_option(self::OPTION_NAME, self::$_licenses);
			self::$_dirty = FALSE;
		}
	}

	/**
	 * Used to retrieve information on the Licensing API and known add-ons
	 */
	public function get_update_data()
	{
//SyncDebug::log(__METHOD__.'():' . __LINE__);
		$ret = array(
			'store_url' => $this->_get_api_url(),
			'extensions' => array(),
		);

		$extensions = apply_filters('spectrom_sync_active_extensions', array(), TRUE);
		$this->_load_licenses();
//SyncDebug::log(__METHOD__.'() licenses=' . var_export(self::$_licenses, TRUE));
//SyncDebug::log(__METHOD__.'() extensions=' . var_export($extensions, TRUE));

		foreach ($extensions as $ext_slug => $extension) {
			if (isset(self::$_licenses[$ext_slug]))
				$lic = self::$_licenses[$ext_slug];
			else
				$lic = '';
			$extension['license'] = $lic;
			$ret['extensions'][] = $extension;
		}
//SyncDebug::log(__METHOD__.'() returning data: ' . var_export($ret, TRUE));
		return $ret;
	}
}
