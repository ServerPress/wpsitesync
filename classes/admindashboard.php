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
		echo	sprintf(__('You have Pushed %1$d pieces of Content and Received %2$d pieces of Content.', 'wpsitesynccontent'), $sent, $recv), '<br/>';
		echo '</p>';

		$msg = self::get_random_message();
		if (!empty($msg)) {
			echo '<p>', $msg, '</p>';
		}

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

	/**
	 * Generates a random CTA message for display within the Dashboard Metabox
	 * @param boolean $blanks TRUE for allowing occasional blank messages; FALSE for no blank messages
	 * @return string A CTA message.
	 */
	public static function get_random_message($blanks = TRUE)
	{
		$max = 5;
		if ($blanks)
			$max += 2;
		$msg = '';

		switch (rand(1, $max)) {
		case 1:
			$msg = sprintf(__('Thank you for using <a href="%1$s" target="_blank">WPSiteSync for Content</a>. Please consider <a href="%2$s" target="_blank">rating us</a> on <a href="%2$s" target="_blank">WordPress.org</a>!', 'wpsitesynccontent'),
				esc_url('https://wpsitesync.com'),
				esc_url('https://wordpress.org/support/view/plugin-reviews/wpsitesynccontent?filter=5#postform')
			);
			break;

		case 2:
			$msg = sprintf(__('Have a question? Need some help? Do you want to make a suggestion or feature request? Connect with us on our <a href="%1$s" target="_blank">Contact Page</a>, or email us at <a href="mailto:%2$s">%2$s</a>. We always appreciate your feedback.', 'wpsitesynccontent'),
				esc_url('https://serverpress.com/contact/'),
				esc_html('support@serverpress.com')
			);
			break;

		case 3:
			$msg = sprintf(__('Does using WPSiteSync help your workflow? Would you like to write us a review? Please contact us at <a href="mailto:%1$s">%1$s</a>. We would like to add your comments to our web site.', 'wpsitesynccontent'),
				esc_html('support@serverpress.com')
			);
			break;

		case 4:
			$msg = sprintf(__('Come by and visit us at <a href="%1$s" target="_blank">%1$s</a> for all the latest news, information and updates on WPSiteSync.', 'wpsitesynccontent'),
				esc_url('https://wpsitesync.com/news/')
			);
			break;

		case 5:
			$msg = sprintf(__('Want to get the latest updates on WPSiteSync and ServerPress? <a href="%1$s" target="_blank">Sign up for our Newsletter here</a>.', 'wpsitesynccontent'),
				esc_url('https://wpsitesync.com/#newsoptin')
			);
			break;
		}

		return $msg;
	}
}

// EOF