<?php

class SyncLicenseSettings extends SyncInput
{
	const OPTION_GROUP = 'sync_options_group'; // 'sync_license_options_group';
	const SETTING_FIELDS = 'sync_options_group'; // 'sync_licenses';

	private $_rendered = FALSE;

	public function init_settings()
	{
SyncDebug::log(__METHOD__.'()');
		$section_id = self::SETTING_FIELDS;
$section_id = 'sync_section';

		$lic = new SyncLicensing();
		$licenses = $lic->get_license_keys(); // $this->_load_licenses();

		$settings = SyncSettings::get_instance();

		register_setting(
			self::OPTION_GROUP,									// option group, used for settings_fields()
			SyncLicensing::OPTION_NAME,							// option name, used as key in database
			array(&$this, 'validate_settings')					// validation callback
		);

		add_settings_section(
			$section_id,										// id
			__('WPSiteSync Add-on Licenses:', 'wpsitesynccontent'),	// title
			'__return_true',									// callback
			SyncSettings::SETTINGS_PAGE							// option page
		);

		$extensions = SyncExtensionModel::get_extensions(TRUE);
		if (0 === count($extensions)) {
			add_settings_section(
				$section_id,									// id
				__('No extensions are currently activated.', 'wpsitesynccontent'),		// title
				'__return_true',								// callback
				SyncSettings::SETTINGS_PAGE						// option page
			);
			return;
		}

		foreach ($extensions as $key => $extension) {
			$field_id = $key;

			$status = $lic->get_status($key);

			add_settings_field(
				$field_id,									// field id
				sprintf(__('%s License Key:', 'wpsitesynccontent'), $extension['name']),// title
				array($this, 'render_license_field'),			// callback
				SyncSettings::SETTINGS_PAGE,					// page
				$section_id,									// section id
				array(											// args
					'name' => $field_id,
					'value' => isset($licenses[$key]) ? $licenses[$key] : '',
					'size' => '50',
					'status' => $status,
					'description' => sprintf(__('License key for the %1$s v%2$s add-on', 'wpsitesynccontent'), $extension['name'], $extension['version'])
				)
			);
		}

		add_settings_field(
			'license_msg',									// field id
			'',												// title
			array(SyncSettings::get_instance(), 'render_message_field'),	// callback
			SyncSettings::SETTINGS_PAGE,					// page
			$section_id,									// section id
			array(
				'name' => 'license_msg',
				'description' => sprintf(__('License keys are required to activate add-on\'s functionality and to be notified of updates.', 'wpsitesynccontent')),
			)
		);
SyncDebug::log(__METHOD__.'() - returning');
	}

	/**
	 * Renders the input field and the Activate / Deactivate buttons for a License Key
	 * @param array $args The arguments array
	 */
	public function render_license_field($args)
	{
		$attrib = '';
		$class = 'sync-license-input ';
		if (!empty($args['class']))
			$class .= $args['class'];

		if (isset($args['size']))
			$attrib = ' size="' . esc_attr($args['size']) . '" ';
		$attrib .= ' class="' . esc_attr($class) . '" ';
		if (!empty($args['placeholder']))
			$attrib .= ' placeholder="' . esc_attr($args['placeholder']) . '" ';


		printf('<input type="text" id="spectrom-form-%s" name="spectrom_sync_settings[%s]" value="%s" %s />',
			$args['name'], $args['name'], esc_attr($args['value']), $attrib);

		echo '<span id="sync-license-status-', $args['name'], '" class="sync-license-status">',
			__('Status: ', 'wpsitesynccontent'), '<span>', $args['status'], '</span></span>';

		if (!empty($args['value']) && 32 === strlen($args['value'])) {
			echo '<button id="sync-license-act-', $args['name'], '" type="button" class="button sync-license sync-license-activate" data="', $args['name'], '" ';
			echo ' onclick="sync_settings.activate(this, \'', $args['name'] , '\'); return false;" >';
			_e('Activate', 'wpsitesynccontent');
			echo '</button>';

			echo '<button id="sync-license-deact-', $args['name'], '" type="button" class="button sync-license sync-license-deactivate" data="', $args['name'], '" ';
			echo ' onclick="sync_settings.deactivate(this, \'', $args['name'] , '\'); return false;" >';
			_e('Dectivate', 'wpsitesynccontent');
			echo '</button>';

			echo '<div id="sync-license-msg-', $args['name'], '" style="display:none" class="sync-license-msg"></div>';
		}

		if (!empty($args['description']))
			echo '<p><em>', esc_html($args['description']), '</em></p>';

		if (!$this->_rendered) {
			$this->_rendered = TRUE;
			echo '<div style="display:none">';
			echo '<div id="sync-activating-msg"><img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '" />&nbsp;', __('Activating License Key...', 'wpsitesynccontent'), '</div>';
			echo '<div id="sync-deactivating-msg"><img src="', WPSiteSyncContent::get_asset('imgs/ajax-loader.gif'), '" />&nbsp;', __('Deactivating License Key...', 'wpsitesynccontent'), '</div>';
			echo '</div>';
		}
	}

	/**
	 * Performs validations and sanitizing on input data from the Licensing tab on the Settings page
	 * @param array $values Input values passed in from the Settings API
	 * @return array A list of valid, santized data to store for the Licensing settings
	 */
	public function validate_settings($values)
	{
		// TODO: $values is always NULL. why?
SyncDebug::log(__METHOD__.'() values=' . var_export($values, TRUE));
SyncDebug::log(' - post=' . var_export($_POST, TRUE));
		$input = $this->post('spectrom_sync_settings', array());
SyncDebug::log(' - input=' . var_export($input, TRUE));
SyncDebug::log(' method=' . $_SERVER['REQUEST_METHOD']);

		$lic = new SyncLicensing();
		$out = $lic->get_license_keys();

		// sanitize values
		foreach ($input as $name => $value) {
//			if (array_key_exists($name, $out))
				$out[$name] = sanitize_key($value);
		}

		return $out;
	}
}

// EOF