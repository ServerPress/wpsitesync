<?php

class SyncAdminDashboard
{
	/**
	 * Outputs the contents of the Dashboard Widget
	 */
	public function render_dashboard_widget()
	{
		$img = WPSiteSyncContent::get_asset('imgs/wpsitesync-logo-blue.png');
		echo '<span style="vertical-align:top"><img id="sync-logo" src="', $img, '" width="125" height="45" alt="',
			__('WPSiteSync logo', 'wpsitesynccontent'), '" title="', __('WPSiteSync for Content', 'wpsitesynccontent'), '" />&#8482</span><br />';
		$installed = SyncOptions::get('installed');
		$time_diff = human_time_diff(strtotime($installed));
		echo '<p>', sprintf(__('WPSiteSync v%1$s', 'wpsitesync'), WPSiteSyncContent::PLUGIN_VERSION), '</p>';
		echo '<p>';
		echo	sprintf(__('You have been using WPSiteSync for %1$s.', 'wpsitesynccontent'),
					$time_diff), '<br/>';
		$log_model = new SyncLogModel();
		$sent = $log_model->get_count('send');
		$recv = $log_model->get_count('recv');
		echo	sprintf(__('You haved Pushed %1$d pieces of Content and Received %2$d pieces of Content.', 'wpsitesynccontent'), $sent, $recv), '<br/>';
		echo '</p>';
		echo '<p>';
		echo	sprintf(__('Thank you for using <a href="%1$s" target="_blank">WPSiteSync for Content</a>. Please consider <a href="%2$s" target="_blank">rating us</a> on <a href="%2$s" target="_blank">WordPress.org</a>!', 'wpsitesynccontent'),
			esc_url('https://wpsitesync.com'),
			esc_url('https://wordpress.org/support/view/plugin-reviews/wpsitesynccontent?filter=5#postform')
		);
		echo '</p>';

		$ext = new SyncExtensionSettings();
		$extensions = $ext->get_extension_data();
		if (FALSE !== $extensions) {
			$num = count($extensions->extensions);
			$idx = rand(0, $num - 1);
			$ext_info = $extensions->extensions[$idx];

			echo '<div class="sync-extension">';
			echo '<a href="', esc_url($ext_info->url), '" target="_wpsitesync">';
			echo	'<img class="sync-extension-image" src="', $ext_info->image, '" width="300" height="150" />';
			echo '</a>';
			echo '<a href="', esc_url($ext_info->url), '" target="_wpsitesync">';
			echo	'<h3><b>', esc_html($ext_info->name), ' ', $ext_info->price, '</b></h3>';
			echo	'<p>v', $ext_info->version, ' ', esc_html($ext_info->description), '</p>';
			echo '</a>';
			echo '</div>';
		}
	}
}

// EOF