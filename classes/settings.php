<?php

/*
 * Controls settings pages and retrieval.
 * @package Sync
 * @author Dave Jesch
 */
 
class SyncSettings extends SyncInput
{
	private static $_instance = NULL;

//	private $_options = array();
	private $_tab = '';

	const SETTINGS_PAGE = 'sync';		// TODO: update name

	private function __construct()
	{
		add_action('admin_menu', array($this, 'add_configuration_page'));
		add_action('current_screen'/*'admin_init'*/, array($this, 'settings_api_init'));
		add_action('load-settings_page_sync', array($this, 'contextual_help'));

//		$this->_options = SyncOptions::get_all();
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

	/*
	 * Returns an option from the `spectrom_sync_settings` options array
	 * @param string $option The key for the option under OPTION_KEY
	 * @param string $default (optional) The default value to be returned
	 * @return mixed The value if it exists, else $default
	 * @deprecated
	 */
/*	public function get_option($option, $default = NULL)
	{
		// TODO: remove this method
		return isset($this->_options[$option]) ? $this->_options[$option] : $default;
	} */

	/**
	 * Adds the Sync settings menu to the Setting section.
	 */
	public function add_configuration_page()
	{
		if (!SyncOptions::has_cap())
			return;

//SyncDebug::log(__METHOD__.'() tab=' . $this->_tab);
		$slug = add_submenu_page(
			'options-general.php',
			__('WPSiteSync for Content Settings', 'wpsitesynccontent'),
			__('WPSiteSync&#8482;', 'wpsitesynccontent'),		// displayed in menu
			'manage_options',							// capability
			self::SETTINGS_PAGE,						// menu slug
			array($this, 'settings_page')				// callback
		);
		return $slug;
	}

	/**
	 * Callback to display contents of settings page
	 */
	public function settings_page()
	{
//SyncDebug::log(__METHOD__.'() tab=' . $this->_tab);
		add_filter('admin_footer_text', array($this, 'footer_content'));
//		add_action('spectrom_page', array(&$this, 'show_settings_page'));
//		do_action('spectrom_page');
		$this->show_settings_page();
	}

	/**
	 * Echo the Sync settings page and enqueues needed scripts/styles.
	 */
	public function show_settings_page()
	{
//SyncDebug::log(__METHOD__.'() tab=' . $this->_tab);
		wp_enqueue_script('sync-settings');

		do_action('spectrom_sync_before_render_settings');
		$extensions = SyncExtensionModel::get_extensions(TRUE);

		$tabs = array(
			'general' => array(
				'name' => __('General', 'wpsitesynccontent'),
				'title' => __('General Settings for WPSiteSync for Content', 'wpsitesynccontent'),
				'icon' => 'list-view',
			),
			'extensions' => array(
				'name' => __('Extensions', 'wpsitesynccontent'),
				'title' => __('Available WPSiteSync Extensions', 'wpsitesynccontent'),
				'icon' => 'exerpt-view',
			),
		);
		if (count($extensions) > 0) {
			$tabs['license'] = array(
				'name' => __('Licenses', 'wpsitesynccontent'),
				'title' => __('License Key for WPSiteSync add-ons', 'wpsitesynccontent'),
				'icon' => 'admin-network',
			);
		}
		$tabs = apply_filters('spectrom_sync_settings_tabs', $tabs);

		echo '<div class="wrap spectrom-sync-settings">';
		echo '<p class="cta-message">', SyncAdminDashboard::get_random_message(FALSE), '</p>';

		echo '<h1 class="nav-tab-wrapper">';
		echo '<img src="', esc_url(plugin_dir_url(dirname(__FILE__)) . 'assets/imgs/wpsitesync-logo-blue.png') . '" class="sync-settings-logo" width="97" height="35" />';
		foreach ($tabs as $tab_name => $tab_info) {
			echo '<a class="nav-tab ';
			if ($tab_name === $this->_tab)
				echo 'nav-tab-active';
			echo '" title="', esc_attr($tab_info['title']), '" ';
			echo ' href="', esc_url(add_query_arg('tab', $tab_name)), '">';
			if (isset($tab_info['icon']))
				echo '<span class="dashicons dashicons-', $tab_info['icon'], '"></span>&nbsp;';
			echo esc_html($tab_info['name']);
			echo '</a>';
		}
//		echo '<a class="nav-tab nav-tab-active" title="', __('General', 'wpsitesynccontent'), '" href="',
//			esc_url(add_query_arg('tab', 'general')), '">',
//			__('General', 'wpsitesynccontent'), '</a>';
		echo '</h1>';
		echo '</div>';

		echo '<div id="tab_container" class="spectrom-sync-settings spectrom-sync-settings-tab-', $this->_tab, '">';
		echo '<form id="form-spectrom-sync" action="options.php" method="POST">';

		switch ($this->_tab) {
		case 'general':
			settings_fields('sync_options_group');
			break;

		case 'extensions':
			$ext = new SyncExtensionSettings();
			$ext->show_settings();
			break;

		case 'license':
			settings_fields(SyncLicenseSettings::SETTING_FIELDS);
			break;

		default:
			$done = apply_filters('spectrom_sync_settings_page', FALSE, $this->_tab);
			if (!$done) {
				echo '<h2>', __('Error: no settings available', 'wpsitesynccontent'), '</h2>';
			}
		}
		echo '<input type="hidden" name="sync-settings-tab" value="', esc_attr($this->_tab), '" />';
		do_settings_sections('sync');
		if ('extensions' !== $this->_tab)
			submit_button();
		echo '</form>';
		echo '<p>', sprintf(__('WPSiteSync for Content Site key: %1$s', 'wpsitesynccontent'),
			SyncOptions::get('site_key')), '<b>', '</b></p>';
		echo '</div><!-- #tab_container -->';
	}

	/**
	 * Registers the setting sections and fields to be used by Sync.
	 */
	public function settings_api_init()
	{
		// don't bother initializing if not on the WPSiteSync settings page
//		$screen = get_current_screen();
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' screen=' . var_export($screen, TRUE));
//		if (NULL !== $screen && 'settings_page_sync' !== $screen->id)
//			return;

		// check that current user is capable of performing operation
		if (!current_user_can('manage_options') || !SyncOptions::has_cap())
			return;

		$this->_tab = $this->get('tab', 'general');
//SyncDebug::log(__METHOD__.'() tab=' . $this->_tab);
		if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['sync-settings-tab']))
			$this->_tab = $_POST['sync-settings-tab'];
//SyncDebug::log(__METHOD__.'() revised tab=' . $this->_tab);

		switch ($this->_tab) {
		case 'general':
			$this->_init_general_settings();
			break;
		case 'extensions':
//			$ext = SyncExtensionSettings::get_instance();
//			$ext->init_settings();
			break;
		case 'license':
			$lic = new SyncLicenseSettings();
			$lic->init_settings();
			break;
		default:
			do_action('spectrom_sync_init_settings', $this->_tab);
			break;
		}
//SyncDebug::log(__METHOD__.'() returning');
	}

	/**
	 * Initializes the settings fields for the General tab
	 */
	private function _init_general_settings()
	{
//SyncDebug::log(__METHOD__.'() tab=' . $this->_tab);
		$data = $option_values = SyncOptions::get_all(); // $this->_options;

		$section_id = 'sync_section';

		register_setting(
			'sync_options_group',						// option group, used for settings_fields()
			SyncOptions::OPTION_NAME,					// option name, used as key in database
			array($this, 'validate_settings')			// validation callback
		);

		add_settings_section(
			$section_id,									// id
			__('WPSiteSync for Content - Configuration:', 'wpsitesynccontent'),	// title
			'__return_true',								// callback
			self::SETTINGS_PAGE								// option page
		);

		add_settings_field('hostmsg',
			'',
			array($this, 'render_message_field'),
			self::SETTINGS_PAGE,
			$section_id,
			array(
				'description' => __('Configure this site (Source) to connect with your Target site. If this is your Target site, you do not need to enter any configuration settings for the Target site.', 'wpsitesynccontent'),
			));

		add_settings_field(
			'host',											// field id
			__('Host Name of Target:', 'wpsitesynccontent'),// title
			array($this, 'render_input_field'),				// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section id
			array(											// args
				'name' => 'host',
				'value' => $data['host'],
				'placeholder' => empty($data['host']) ? 'https://' : '',
				'size' => '50',
				'description' => __('https://example.com - This is the URL that your Content will be Pushed to. If WordPress is installed in a subdirectory, include the subdirectory.', 'wpsitesynccontent'),
			)
		);

		add_settings_field(
			'username',										// field id
			__('Username on Target:', 'wpsitesynccontent'),	// title
			array($this, 'render_input_field'),				// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section id
			array(											// args
				'name' => 'username',
				'size' => '50',
				'value' => $data['username'],
				'description' => __('Username on Target for authentication. Must be able to create Content with this username.', 'wpsitesynccontent'),
			)
		);

		if (empty($data['host']) && empty($data['username']))
			$auth = 2;		// no icon
		else if ($data['auth'] && !empty($data['username']) && !empty($data['host']))
			$auth = 1;		// green checkmark
		else
			$auth = 0;		// red x

		add_settings_field(
			'password',										// field id
			__('Password on Target:', 'wpsitesynccontent'),	// title
			array($this, 'render_password_field'),			// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section
			array(											// args
				'name' => 'password',
				'value' => '', // Always empty
				'size' => '50',
				'auth' => $auth, // ($data['auth'] && !empty($data['username']) && !empty($data['host']) ? 1 : 0),
				'description' => __('Password for the Username on the Target. ', 'wpsitesynccontent') .
					($data['auth'] ? __('Username and Password are valid.', 'wpsitesynccontent') :
						__('Username and Password not entered or not valid.', 'wpsitesynccontent')),
			)
		);

		$section_id = 'sync_behaviors';
		add_settings_section(
			$section_id,									// id
			__('WPSiteSync for Content - Behaviors:', 'wpsitesynccontent'),		// title
			'__return_true',								// callback
			self::SETTINGS_PAGE								// option page
		);

		$args = array(											// args
			'name' => 'strict',
			'value' => $data['strict'],
			'options' => array(
				'1' => __('On - WordPress and WPSiteSync for Content versions must match on Source and Target in order to perform operations.', 'wpsitesynccontent'),
				'0' => __('Off - WordPress and WPSiteSync for Content versions do not need to match.', 'wpsitesynccontent'),
			),
		);
		$args = apply_filters('spectrom_sync_setting-strict', $args);

		add_settings_field(
			'strict',										// field id
			__('Strict Mode:', 'wpsitesynccontent'),		// title
			array($this, 'render_radio_field'),				// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section id
			$args
		);

		$args = array(
			'name' => 'report',
			'value' => $data['report'],
			'description' => __('When checked, WPSiteSync will forward usage information to ServerPress.com', 'wpsitesynccontent'),
		);
		add_settings_field(
			'report',										// field id
			__('Usage Reporting:', 'wpsitesynccontent'),	// title
			array($this, 'render_checkbox_field'),			// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section id
			$args
		);

		$match_mode = isset($data['match_mode']) ? $data['match_mode'] : 'slug';
		switch ($match_mode) {
		case 'slug':		$desc = __('Slug - Search for matching Content on Target by Post Slug only.', 'wpsitesynccontent');
			break;
		case 'slug-title':	$desc = __('Slug, then Title - Search for matching Content on Target by Slug, then Post Title.', 'wpsitesynccontent');
			break;
		case 'id':			$desc = __('ID - Search for matching Content on Target by Post ID.', 'wpsitesynccontent');
			break;
		case 'title':		$desc = __('Post Title - Search for matching Content on Target by Post Title only.', 'wpsitesynccontent');
		default:
			break;
		case 'title-slug':	$desc = __('Title, then Slug - Search for matching Content on Target by Post Title, then by Slug.', 'wpsitesynccontent');
			break;
		}

		add_settings_field(
			'match_mode',										// field id
			__('Content Match Mode:', 'wpsitesynccontent'),		// title
			array($this, 'render_select_field'),				// callback
			self::SETTINGS_PAGE,								// page
			$section_id,										// section id
			array(												// args
				'name' => 'match_mode',
				'value' => $match_mode,
				'options' => array(
					'title' => __('Post Title', 'wpsitesynccontent'),
					'title-slug' => __('Post Title, then Post Slug', 'wpsitesynccontent'),
					'slug' => __('Post Slug', 'wpsitesynccontent'),
					'slug-title' => __('Post Slug, then Post Title', 'wpsitesynccontent'),
//					'id' => __('Post ID', 'wpsitesynccontent'),
				),
				'description' => $desc,
			)
		);

		// add setting for minimum user role #122
		$min_role = isset($data['min_role']) ? $data['min_role'] : 'author';
		switch ($min_role) {
		case 'author':			$default_role = '|author|editor|administrator|';
		default:
			break;
		case 'editor':			$default_role = '|editor|administrator|';
			break;
		case 'admin':			$default_role = '|administrator|';
			break;
		}
		// if not present, default roles based on v1.4 settings values #169
		$roles = isset($data['roles']) ? $data['roles'] : $default_role;

		add_settings_field(
			'roles',										// field id
			__('Roles Allowed to use WPSiteSync:', 'wpsitesynccontent'),		// title
			array($this, 'render_roles_field'),				// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section id
			array(											// args
				'name' => 'roles',
				'value' => $roles,
/*				'options' => array(
					'author' => __('Authors, Editors and Admins', 'wpsitesynccontent'),
					'editor' => __('Editor and Admins', 'wpsitesynccontent'),
					'administrator' => __('Admins Only', 'wpsitesynccontent'),
				), */
				'description' => __('Select the Roles you wish to have access to the WPSiteSync User Interface. Only these Roles will be allowed to perform Syncing operations.', 'wpsitesynccontent'),
			)
		);
/*
		add_settings_field(
			'salt',											// field id
			__('Authentication Salt:', 'wpsitesynccontent'),// title
			array($this, 'render_input_field'),				// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section id
			array(
				'name' => 'salt',
				'value' => $data['salt'],
				'size' => '50',
				'description' => __('If blank, will use default value. If filled in, same value needs to be configured on Target System.', 'wpsitesynccontent'),
			)
		); */

/*
		add_settings_field(
			'min_role',										// field id
			__('Minimum Role allowed to Sync content:', 'wpsitesynccontent'),	// title
			array($this, 'render_select_field'),			// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section id
			array(
				'name' => 'min_role',
				'value' => $data['min_role'],
				'options' => array('admin' => __('Administrator', 'wpsitesynccontent'),
					'editor' => __('Editor', 'wpsitesynccontent'),
					'author' => __('Author', 'wpsitesynccontent')
					)
			)
		); */

		add_settings_field(
			'remove',										// field id
			__('Optionally remove Settings and Tables on plugin uninstall:', 'wpsitesynccontent'),	// title
			array($this, 'render_radio_field'),				// callback
			self::SETTINGS_PAGE,							// page
			$section_id,									// section id
			array(
				'name' => 'remove',
				'value' => $data['remove'],
				'options' => array(
					'1' => __('Yes, remove all settings and data on uninstall.', 'wpsitesynccontent'),
					'0' => __('No, leave settings and data on uninstall.', 'wpsitesynccontent'),
				),
//				'description' => __('Optionally removes traces of WPSiteSync for Content on plugin deactivation.', 'wpsitesynccontent'),
			)
		);

		do_action('spectrom_sync_register_settings', $data);
	}

	/**
	 * Renders an input field control
	 * @param array $args Array of arguments, contains name and value.
	 */
	public function render_input_field($args)
	{
		$attrib = '';
		if (isset($args['size']))
			$attrib = ' size="' . esc_attr($args['size']) . '" ';
		if (!empty($args['class']))
			$attrib .= ' class="' . esc_attr($args['class']) . '" ';
		if (!empty($args['placeholder']))
			$attrib .= ' placeholder="' . esc_attr($args['placeholder']) . '" ';

		printf('<input type="text" id="spectrom-form-%s" name="spectrom_sync_settings[%s]" value="%s" %s />',
			$args['name'], $args['name'], esc_attr($args['value']), $attrib);

		if (!empty($args['description']))
			echo '<p><em>', esc_html($args['description']), '</em></p>';
	}

	/**
	 * Renders a <select> field and it's <option> elements
	 * @param array $args Array of arguments, contains name, value and options data
	 */
	public function render_select_field($args)
	{
		printf('<select id="spectrom-form-%s" name="spectrom_sync_settings[%s]" value="%s">',
			$args['name'], $args['name'], esc_attr($args['value']));
		foreach ($args['options'] as $key => $value) {
			echo '<option value="', esc_attr($key), '" ', selected($key, $args['value']), '>', esc_html($value), '</option>';
		}
		echo '</select>';

		if (!empty($args['description']))
			echo '<p><em>', esc_html($args['description']), '</em></p>';
	}

	/**
	 * Renders a list of checkboxes for Role selection
	 * @param array $args Array of arguments, containser name and options data
	 */
	public function render_roles_field($args)
	{
		// display list of available Roles #166
		$roles = array_reverse(get_editable_roles());
		$allowed_roles = $args['value'];
		foreach ($roles as $role => $caps) {
			// check for both 'edit_posts' OR 'edit_pages' #245
			if ((isset($caps['capabilities']['edit_posts']) && $caps['capabilities']['edit_posts']) ||
				(isset($caps['capabilities']['edit_pages']) && $caps['capabilities']['edit_pages'])) {
				$checked = (FALSE === strpos($allowed_roles, SyncOptions::ROLE_DELIMITER . $role . SyncOptions::ROLE_DELIMITER)) ? '' : ' checked="checked" ';
				$disabled = '';
				if ('administrator' === $role) {
					$disabled =  ' disabled="disabled" ';
					$checked = ' checked="checked" ';
				}
				printf('<input type="checkbox" name="spectrom_sync_settings[%s][%s]" %s %s /> %s<br/>',
					$args['name'], $role, $checked, $disabled, $caps['name']);
			}
		}
		if (!empty($args['description']))
			echo '<p>', esc_html($args['description']), '</p>';
//		echo '<pre>', var_export($roles, TRUE), '</pre>';
	}

	/**
	 * Renders a message as part of the settings/form
	 * @param array $args Arguments used to construct the message
	 */
	public function render_message_field($args)
	{
		$class = '';
		if (!empty($args['class']))
			$class = ' class="' . $args['class'] . '" ';
		if (!empty($args['description']))
			echo "<p {$class}>", esc_html($args['description']), '</p>';
	}

	/**
	 * Renders a checkbox input field for settings display
	 * @param array $args An array of option data used to output the checkbox
	 */
	public function render_checkbox_field($args)
	{
		$name = $args['name'];

		echo '<input type="hidden" name="spectrom_sync_settings[', $name, ']" value="0" />';
		echo '<input type="checkbox" name="spectrom_sync_settings[', $name, ']" ', checked($args['value'], '1'), ' value="1" />';
		if (isset($args['description']))
			echo '&nbsp;<em>', $args['description'], '</em>';
	}

	/**
	 * Renders the radio buttons used for the control
	 * @param array $args Arguments used to render the radio buttons
	 */
	public function render_radio_field($args)
	{
		$options = $args['options'];
		$name = $args['name'];

		$br = '';
		foreach ($options as $value => $label) {
			echo $br;
			printf('<input type="radio" name="spectrom_sync_settings[%s]" value="%s" %s /> %s',
				$name, $value, checked($value, $args['value'], FALSE), $label);
			$br = '<br>';
		}
		if (isset($args['description']))
			echo '<br/><em>', $args['description'], '</em>';
	}

	/**
	 * Renders the <button> field
	 * @param array $args Arguments used to render the button
	 */
	public function render_button_field($args)
	{
		echo '<button type="button" id="spectrom-button-', $args['name'], '" class="button-primary spectrom-ui-button">', $args['title'], '</button>';
		if (!empty($args['message']))
			echo '<p>', $args['message'], '</p>';
	}

	/**
	 * Echoes the password field with the connection success indicator.
	 * @param array $args Array of arguments, contains name and value.
	 */
	public function render_password_field($args)
	{
		$attrib = '';
		if (isset($args['size']))
			$attrib = ' size="' . esc_attr($args['size']) . '" ';

		$icon = array('dashicons-no', 'dashicons-yes', '');

		printf('<input type="password" id="spectrom-form-%s" name="spectrom_sync_settings[%s]" value="%s" %s />',
			$args['name'], $args['name'], esc_attr($args['value']), $attrib);
		// TODO: use dashicon: <span class="dashicons dashicons-yes"></span> // <span class="dashicons dashicons-dismiss"></span>
//		echo '<i id="connect-success-indicator" class="fa ', ($args['auth'] ? 'fa-check' : 'fa-close'), '"';
		echo '<i id="connect-success-indicator" class="dashicons ', $icon[$args['auth']] /*($args['auth'] ? 'dashicons-yes' : 'dashicons-no')*/, ' auth', $args['auth'], '"';
		echo ' title="';
		if ($args['auth'])
			echo esc_attr(__('Settings authenticated on Target server', 'wpsitesynccontent'));
		else
			echo esc_attr(__('Settings do not authenticate on Target server', 'wpsitesynccontent'));
		echo '"></i>';

		if (!empty($args['description']))
			echo '<p><em>', esc_html($args['description']), '</em></p>';
	}

	/**
	 * Validates the values and forms the spectrom_sync_settings array
	 * @param array $values The submitted form values
	 * @return array validated form contents
	 */
	public function validate_settings($values)
	{
//SyncDebug::log(__METHOD__.'() tab=' . $this->_tab);
//SyncDebug::log(__METHOD__.'() values=' . var_export($values, TRUE));
		$settings = SyncOptions::get_all(); // $this->_options;
//SyncDebug::log(__METHOD__.'() settings: ' . var_export($settings, TRUE));

		if (!current_user_can('manage_options') || !SyncOptions::has_cap())
			return $settings;

		// start with a copy of the current settings so that 'site_key' and other hidden values are preserved on update
		$out = array_merge($settings, array());

		$missing_error = FALSE;
		$re_auth = FALSE;

		// if no setting found for roles; default to admins only #169
		if (!isset($values['roles']))
			$values['roles'] = array('administrator' => 'on');

		// check for removing host value #132
		if (empty($values['host']) && !empty($settings['host'])) {
			$sources_model = new SyncSourcesModel();
			$sources_model->remove_token($settings['host']);		// remove existing entry from the spectrom_sync_source table

			// clear any values in the array to be processed, as well as settings data
			$out['host'] = $out['username'] = $out['password'] = '';
			$out['auth'] = 0;										// signal that we're no longer authenticated
			$settings['host'] = $settings['username'] = $settings['password'] = '';
			$values['username'] = $values['password'] = '';
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' clearing host, username, password values=' . var_export($values, TRUE));
		}

		foreach ($values as $key => $value) {
//SyncDebug::log(" key={$key}  value=[" . var_export($value, TRUE) . ']');
			if (empty($values[$key]) && 'password' === $key) {
				// ignore this so that passwords are not required on every settings update
			} else {
				if ('password' !== $key && !is_array($value))
					$value = trim($value);			// strip any whitespaces on non-password fields #73
				if ('host' === $key) {
					// check to see if 'host' is changing and force use of password
					if ($value !== $settings['host'] && empty($values['password'])) {
						add_settings_error('sync_host_password', 'missing-password', __('When changing Target site, a password is required.', 'wpsitesynccontent'));
						$out[$key] = $settings[$key];
					} else {
						if (empty($value)) {
							// do nothing
						} else if (FALSE === $this->_is_valid_url($value)) {
							add_settings_error('sync_options_group', 'invalid-url', __('Invalid URL.', 'wpsitesynccontent'));
							$out[$key] = $settings[$key];
						} else {
							$out[$key] = $this->_normalize_url($value);
							if ($out[$key] !== $settings[$key])
								$re_auth = TRUE;
						}
					}
				} else if ('username' === $key) {
					// TODO: refactor so that 'host' and 'username' password checking is combined
					// check to see if 'username' is changing and force use of password
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' change username: user="' . $value . '" pass="' . $values['password'] . '"');
					if ('' === $value && '' === $values['password'] /* empty($value) && empty($values['password']) */ ) {
						// do nothing
					} else if ($value !== $settings['username'] && empty($values['password'])) {
						add_settings_error('sync_username_password', 'missing-password', __('When changing Username, a password is required.', 'wpsitesynccontent'));
						$out[$key] = $settings[$key];
					} else {
						if (!empty($value)) {
							if ($value !== $settings[$key])
								$re_auth = TRUE;
							$out[$key] = $value;
						} else
							$out[$key] = $settings['username'];
					}
				} else if ('report' === $key) {
					$out[$key] = '1' === $value ? '1' : '0';
				} else if ('roles' === $key) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' POST=' . var_export($_POST, TRUE));
					$roles = array();
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' value=' . var_export($value, TRUE));
					foreach ($value as $role => $on) {
						$roles[] = $role;
					}
					if (!in_array('administrator', $roles))
						$roles[] = 'administrator';				// always force administrator access
					$out[$key] = SyncOptions::ROLE_DELIMITER . implode(SyncOptions::ROLE_DELIMITER, $roles) . SyncOptions::ROLE_DELIMITER;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' roles: ' . $out[$key]);
				} else if (0 === strlen(trim($value))) {
					if (!$missing_error) {
						add_settings_error('sync_options_group', 'missing-field', __('All fields are required.', 'wpsitesynccontent'));
						$missing_error = TRUE;
					}
					if (!empty($settings[$key]))
						// input not provided so use value stored in settings
						$out[$key] = $settings[$key];
					else
						$out[$key] = $value;
				} else {
					$out[$key] = $value;
//					if ('username' === $key && $out[$key] !== $settings[$key])
//						$re_auth = TRUE;
				}
			}
		}
//SyncDebug::log(__METHOD__.'():' . __LINE__ . '  output array: ' . var_export($out, TRUE));

		// authenticate if there was a password provided or the host/username are different
		if ($re_auth && empty($out['password'])) {
			add_settings_error('sync_options_group', 'no-password', __('Changed Host Name of Target or Username but did not provide password.', 'wpsitesynccontent'));
			$re_auth = FALSE;
		}
		if (!empty($out['password']) || $re_auth) {
			$out['auth'] = 0;

//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' authenticating with data ' . var_export($out, TRUE));
			$api = new SyncApiRequest();
			$res = $api->api('auth', $out);
			if (!is_wp_error($res)) {
//SyncDebug::log(__METHOD__.'():' . __LINE__ . '  response from auth request: ' . var_export($res, TRUE));
				if (isset($res->response->success) && $res->response->success) {
					$out['auth'] = 1;
					$out['target_site_key'] = $res->response->data->site_key;
//SyncDebug::log(__METHOD__.'() got token: ' . $res->response->data->token);
				} else {
					// authentication failure response from Target- report this to user
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' authentication response from Target');
					$msg = SyncApiRequest::error_code_to_string($res->error_code);
					$msg .= ' <a href="https://wpsitesync.com/knowledgebase/wpsitesync-error-messages/#error' . $res->error_code . '" target="_blank" style="text-decoration:none"><span class="dashicons dashicons-info"></span></a>';
					add_settings_error('sync_options_group', 'auth-error',
						sprintf(__('Error authenticating user on Target: %s', 'wpsitesynccontent'),
							$msg));
				}
			}
			// remove ['password'] element from $out since we now have a token
			unset($out['password']);
		}

		if (empty($out['site_key'])) {
			$model = new SyncModel();
			$out['site_key'] = $model->generate_site_key();
		}

		$ret = apply_filters('spectrom_sync_validate_settings', $out, $values);
//SyncDebug::log(__METHOD__.'() validated settings: ' . var_export($ret, TRUE));
		return $ret;
	}

	/**
	 * Performs validation on URL. Note: filter_var() checks for schema but does not validate it. Also allows trailing '.' characters
	 * @param string $url The URL to validate
	 * @return boolean TRUE on successful validation; FALSE on failure
	 */
	private function _is_valid_url($url)
	{
		$res = filter_var($url, FILTER_VALIDATE_URL, FILTER_FLAG_SCHEME_REQUIRED | FILTER_FLAG_HOST_REQUIRED);
		if (FALSE === $res)
			return FALSE;

		if (0 !== stripos($url, 'http://') && 0 !== stripos($url, 'https://'))
			return FALSE;
		else if ('.' === substr($url, -1))
			return FALSE;

		return TRUE;
	}

	/**
	 * Normalizes the Target url, removes usernames, passwords, queries and fragments and forces trailing slash only when path is present.
	 * @param string $url The URL to be normalized
	 * @return string The normalized URL
	 */
	private function _normalize_url($url)
	{
		$parts = parse_url($url);
//SyncDebug::log(__METHOD__.'() parts=' . var_export($parts, TRUE));
		$ret = $parts['scheme'] . '://' . $parts['host'];
		if (!empty($parts['port']))
			$ret .= ':' . $parts['port'];
		$path = isset($parts['path']) ? trim($parts['path'], '/') : '';
		if (!empty($path))
			$ret .= '/' . $path . '/';
		return $ret;
	}

	/**
	 * Callback for adding contextual help to Sync Settings page
	 */
	public function contextual_help()
	{
		$screen = get_current_screen();
		if ('settings_page_sync' !== $screen->id)
			return;

		$screen->set_help_sidebar(
			'<p><strong>' . __('For more information:', 'wpsitesynccontent') . '</strong></p>' .
			'<p>' . sprintf(__('Visit the <a href="%s" target="_blank">documentation</a> on the WPSiteSync for Content website.', 'wpsitesynccontent'),
						esc_url('https://wpsitesync.com/knowledgebase/use-wpsitesync-content/')) . '</p>' .
			'<p>' . sprintf(
						__('<a href="%1$s" target="_blank">Post an issue</a> on <a href="%2$s" target="_blank">GitHub</a>.', 'wpsitesynccontent'),
						esc_url('https://github.com/ServerPress/wpsitesync/issues'),
						esc_url('https://github.com/ServerPress/wpsitesync')) .
			'</p>'
		);

		$screen->add_help_tab(array(
			'id'	    => 'sync-settings-general',
			'title'	    => __('General', 'wpsitesynccontent'),
			'content'	=>
				'<p>' . __('This page allows you to configure how WPSiteSync for Content behaves.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<strong>Host Name of Target</strong>: Enter the URL of the Target website you wish to Sync with. This is the URL of your Home Page. Examples would be https://www.mydomain.com or http://domain.com.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<strong>Username on Target</strong>: Enter the username of an account on the Target website. This username must be able to create content. A role of "Administrator" or "Editor" is required.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<strong>Password on Target</strong>: Enter the password associated with the username of the Target website.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<strong>Strict Mode</strong>: Select if WordPress and WPSiteSync for Content should be the same versions on the Source and the Target.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<strong>Match Mode</strong>: How WPSiteSync should match posts on the Target. Searches for matching Content on Target site based on "Post Title" (default), "Post Slug", "Post Title, then Post Slug", or "Post Slug, then Post Title".', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<strong>Roles</strong>: The Roles that will be allowed to perform Syncing operations from the Source site. Only Roles with the "edit_posts" capability will be shown. The "Administrator" Role is always allowed to perform operations.', 'wpsitesynccontent') . '</p>'
//				'<p>' . __('<strong>Authentication Salt:</strong>: Enter a salt to use when Content is sent to current site or leave blank.', 'wpsitesynccontent') . '</p>' .
		));
		$screen->add_help_tab(array(
			'id'		=> 'sync-settings-terms',
			'title'		=> __('Terms/Definitions', 'wpsitesynccontent'),
			'content'	=>
				'<p>' . __('<b>Source</b> - The website that Content is being Syncd from. This is the non-authority or development/staging site.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<b>Target</b> - The website that you will be Pushing/Syncing Content to. This is the authoritative or live site.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<b>Push</b> - Moving Content from the Source to the Target website.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<b>Pull</b> - Moving Content from the Target to the Source website.', 'wpsitesynccontent') . '</p>' .
				'<p>' . __('<b>Content</b> - The data that is being Syncd between websites. This can be Posts, Pages or Custom Post Types, Products, User Information, Comments, and more, depending on the Sync Add-ons that you have installed.', 'wpsitesynccontent') . '</p>'
		));

		do_action('spectrom_sync_contextual_help', $screen);
	}

	/**
	 * Callback for modifying the footer text on the Sync settings page
	 * @param string $footer_text Original footer text
	 * @return string Modified text, with links to Sync pages
	 */
	public function footer_content($footer_text)
	{
		$rate_text = sprintf(__('Thank you for using <a href="%1$s" target="_blank">WPSiteSync for Content</a>! Please <a href="%2$s" target="_blank">rate us</a> on <a href="%2$s" target="_blank">WordPress.org</a>', 'wpsitesynccontent'),
			esc_url('https://wpsitesync.com'),
			esc_url('https://wordpress.org/support/view/plugin-reviews/wpsitesynccontent?filter=5#postform')
		);

		return str_replace('</span>', '', $footer_text) . ' | ' . $rate_text . '</span>';
	}
}

// EOF
