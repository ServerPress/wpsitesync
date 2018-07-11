<div id="sync-details-container">
	<p><b><?php _e('Content Details:', 'wpsitesynccontent'); ?></b></p>
	<ul style="border:1px solid gray; padding:.2rem; margin: -4px">
		<li><?php printf(__('Target Content Id: %d', 'wpsitesynccontent'), $data['target_post_id']); ?></li>
		<li><?php printf(__('Content Title: %s', 'wpsitesynccontent'), $data['post_title']); ?></li>
		<li><?php printf(__('Content Author: %s', 'wpsitesynccontent'), $data['post_author']); ?></li>
<?php
		if (!empty($data['feat_img']))
			echo '<li>', sprintf(__('Featured Img: %s', 'wpsitesynccontent'), $data['feat_img']), '</li>';
?>
		<li><?php printf(__('Last Modified: %s', 'wpsitesynccontent'),
				date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($data['modified'])) ); ?></li>
		<li><?php printf(__('Content: %s', 'wpsitesynccontent'), $data['content']); ?></li>
		<?php do_action('spectrom_sync_details_view', $data); ?>
	</ul>
	<p><?php _e('Note: Syncing this Content will overwrite data.', 'wpsitesynccontent'); ?></p>
</div>
