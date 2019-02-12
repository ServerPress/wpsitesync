<?php

class SyncExtensionSettings
{
	const FEED_URL = 'https://wpsitesync.com/downloads/feed/';
	const CONTENT_URL = 'https://serverpress.com/wp-content/uploads/';
	const TRANSIENT_KEY = 'wpsitesync_extension_list';
	const TRANSIENT_TTL = 86400;					// 24 hours
	const EXTENSIONS_DATA = 'syncextensions';

	private static $_instance = NULL;

	public function __construct()
	{
	}

	public function init_settings()
	{
		// no settings fields to display so nothing to initialize
	}

	/**
	 * Outputs the content for the Exention Settings Page- a list of available extensions
	 */
	public function show_settings()
	{
		echo '<h3>', __('Available Extensions:', 'wpsitesynccontent'), '</h3>';

		$extens = $this->get_extension_data();
//echo __LINE__, ':', var_export($extens, TRUE), PHP_EOL;
		if (NULL === $extens) {
			echo '<p>', __('<b>Error:</b> Temporarily unable to read data from https://serverpress.com', 'wpsitesynccontent'), '</p>';
		} else {
			$count = 0;
			echo '<div class="sync-extension-list">';
			foreach ($extens->extensions as $ext_info) {
				echo '<div class="sync-extension">';
				echo '<a href="', esc_url($ext_info->url), '" target="_wpsitesync">';
				echo	'<img class="sync-extension-image" src="', $ext_info->image, '" width="300" height="150" />';
				echo '</a>';
				echo '<a href="', esc_url($ext_info->url), '" target="_wpsitesync">';
				echo	'<h3>', esc_html($ext_info->name), ' ', $ext_info->price, '</h3>';
				echo	'<p>v', $ext_info->version, ' ', esc_html($ext_info->description), '</p>';
				echo '</a>';
				echo '</div>';
				++$count;
			}
			echo '</div>';
			echo '<div style="clear:both"></div>';
			echo '<p>', sprintf(_n('%1$d extension found.', '%1$d extensions found.', $count, 'wpsitesynccontent'), $count), '</p>';
		}
	}

	/**
	 * Retrieves JSON encoded data that describes current WPSiteSync extension offerings
	 * @return string JSON data representing extensions or FALSE if not available
	 */
	public function get_extension_data()
	{
//$data = json_decode(file_get_contents(dirname(__FILE__) . '/syncextensions.json'));
//return $data;

		$data = get_transient(self::TRANSIENT_KEY);
		if (FALSE === $data) {
			// https://serverpress.com/wp-content/uploads/syncextensions.json
			$url = self::CONTENT_URL . self::EXTENSIONS_DATA . '.json';
			$response = wp_remote_get($url, array('sslverify' => FALSE));

			if (is_wp_error($response)) {
				$data = SyncView::load_view(self::EXTENSIONS_DATA, array(), TRUE);
				if (empty($data))
					return FALSE;
			} else {
				$data = wp_remote_retrieve_body($response);
			}
			$data = json_decode($data);
			set_transient(self::TRANSIENT_KEY, $data, self::TRANSIENT_TTL);
		}

		return $data;
	}
}

// EOF