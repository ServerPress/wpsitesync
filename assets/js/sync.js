/*
 * @copyright Copyright (C) 2014-2016 SpectrOMtech.com. - All Rights Reserved.
 * @author SpectrOMtech.com <SpectrOMtech.com>
 * @url https://wpsitesync.com/license
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images,
 * manuals, cascading style sheets, and included JavaScript *are NOT GPL*, and are released under the
 * SpectrOMtech Proprietary Use License v1.0
 * More info at https://wpsitesync.com
 */

/**
 * Javascript handlers for SYNC running on the post editor page
 * @since 1.0
 * @author SpectrOMtech
 */
function WPSiteSyncContent()
{
	this.inited = false;
	this.$content = null;
	this.disable = false;
	this.post_id = null;
	this.original_value = '';
	this.nonce = jQuery('#_sync_nonce').val();
	this.push_xhr = null;
	this.push_callback = null;					// callback to perform push; returns true to continue processing; false to stop processing
	this.pull_callback = null;					// callback to perform pull; returns true to continue processing; false to stop processing
}


/**
 * Initializes SYNC operations on the page
 */
WPSiteSyncContent.prototype.init = function()
{
	if (0 === jQuery('#spectrom_sync').length)
		return;

	this.inited = true;

	var _self = this;

	this.$content = jQuery('#content');
	this.original_value = this.$content.val();
	this.$content.on('keypress change', function() { _self.on_content_change(); });
};

/**
 * Callback function to show or hide the contents of the details panel
 */
WPSiteSyncContent.prototype.show_details = function()
{
	if (!this.inited)
		return;

	if ('none' === jQuery('#sync-details').css('display'))
		jQuery('#sync-details').show(200, 'linear');
//			{
//			duration: 200,
//			easing: 'linear' } );
	else
		jQuery('#sync-details').hide(200);
	jQuery('#sync-button-details').blur();
};

/**
 * Sets the message area within the metabox
 * @param {string} msg The HTML contents of the message to be shown.
 * @param {boolean|null} anim If set to true, display the animation image; otherwise animation will not be shown.
 * @param {boolean|null) dismiss If set to true, will include a dismiss button for the message
 * @param {string|null} CSS class to add to the message container
 */
WPSiteSyncContent.prototype.set_message = function(msg, anim, dismiss, css_class)
{
	if (!this.inited)
		return;

	jQuery('#sync-message').attr('class', '').html(msg);
	if ('string' === typeof(css_class))
		jQuery('#sync-message').addClass(css_class);

	if ('boolean' === typeof(anim) && anim)
		jQuery('#sync-content-anim').show();
	else
		jQuery('#sync-content-anim').hide();

	if ('boolean' === typeof(dismiss) && dismiss)
		jQuery('#sync-message-dismiss').show();
	else
		jQuery('#sync-message-dismiss').hide();

	jQuery('#sync-message-container').show();

	this.force_refresh();
};

/**
 * Adds some message content to the current success/failure message in the Sync metabox
 * @param {string} msg The message to append
 */
WPSiteSyncContent.prototype.add_message = function(msg)
{
//console.log('add_message() ' + msg);
	jQuery('#sync-message').append('<br/>' + msg);
};

/**
 * Hides the message area within the metabox
 * @returns {undefined}
 */
WPSiteSyncContent.prototype.clear_message = function()
{
	jQuery('#sync-message-container').hide();
	jQuery('#sync-message').empty();
	jQuery('#sync-content-anim').hide();
	jQuery('#sync-message-dismiss').hide();
};

/**
 * Disables Sync Button every time the content changes.
 */
WPSiteSyncContent.prototype.on_content_change = function()
{
	if (this.$content.val() !== this.original_value) {
		this.disable = true;
		jQuery('#sync-content').attr('disabled', true);
		this.set_message(jQuery('#sync-msg-update-changes').html(), false, false, 'sync-error');
//		jQuery('#disabled-notice-sync').show();
	} else {
		this.disable = false;
		jQuery('#sync-content').removeAttr('disabled');
//		jQuery('#disabled-notice-sync').hide();
		this.clear_message();
	}
};

/**
 * Causes the browser to refresh the page contents
 */
WPSiteSyncContent.prototype.force_refresh = function()
{
//	jQuery(window).trigger('resize');
//	jQuery('#sync-message').parent().hide().show(0);
};

/**
 * Perfrom WPSiteSync API call
 * @param {string} op The name of the API to call
 * @param {int} post_id The post ID for the API call or null if not applicable
 * @param {string} msg The message to be set
 * @param {string} msg_success The success message to be set
 * @param {object} values Optional values to add to data
 * @returns {undefined}
 */
WPSiteSyncContent.prototype.api = function(op, post_id, msg, msg_success, values)
{
//console.log('wpsitesync.api() performing "' + op + '" api request... ' + msg);
	// Do nothing when in a disabled state
	if (this.disable || !this.inited)
		return;

	// add callback checks based on 'op' parameter values ... see .push() example
	switch (op) {
	case 'push':
		// check for a callback function - used to alter the behavior of the Push operation
		if (null !== this.push_callback) {
			var res = this.push_callback(post_id);
			if (!res)							// if the callback returns a false
				return;							// do not continue processing
		}
		break;
	case 'pull':
		// check for a callback function - used to alter the behavior of the Pull operation
		if (null !== this.pull_callback) {
			var res = this.pull_callback(post_id);
			if (!res)							// if the callback returns a false
				return;							// do not continue processing
		}
		break;
	}

	// set the message while API is running
	this.set_message(msg, true);

	this.post_id = post_id;
	var data = {
		action: 'spectrom_sync',
		operation: op,
		post_id: post_id,
		_sync_nonce: this.nonce
	};

	if ('undefined' !== typeof(values)) {
        _.extend(data, values);
	}

	this.push_xhr = {
		type: 'post',
		async: true, // false,
		data: data,
		url: ajaxurl,
		success: function(response) {
//console.log('push() success response:');
//console.log(response);
			wpsitesynccontent.clear_message();
			if (response.success) {
//				jQuery('#sync-message').text(jQuery('#sync-success-msg').text());
				wpsitesynccontent.set_message(msg_success, false, true);
				if ('undefined' !== typeof(response.notice_codes) && response.notice_codes.length > 0) {
					for (var idx = 0; idx < response.notice_codes.length; idx++) {
						wpsitesynccontent.add_message(response.notices[idx]);
					}
				}
			} else {
				if ('undefined' !== typeof(response.data.message))
//					jQuery('#sync-message').text(response.data.message);
					wpsitesynccontent.set_message(response.data.message, false, true);
			}
		},
		error: function(response) {
//console.log('push() failure response:');
//console.log(response);
			var msg = '';
			if ('undefined' !== typeof(response.error_message))
				wpsitesynccontent.set_message('<span class="error">' + response.error_message + '</span>', false, true);
			else
				wpsitesynccontent.set_message('<span class="error">' + jQuery('#sync-runtime-err-msg').html() + '</span>', false, true)
//			jQuery('#sync-content-anim').hide();
		}
	};

	// Allow other plugins to alter the ajax request
	jQuery(document).trigger('sync_api_call', [op, this.push_xhr]);
//console.log('push() calling jQuery.ajax');
	jQuery.ajax(this.push_xhr);
//console.log('push() returned from ajax call');
};

/**
 * Sync Content button handler
 * @param {int} post_id The post id to perform Push operations on
 */
WPSiteSyncContent.prototype.push = function(post_id)
{
//console.log('push()');
	// Do nothing when in a disabled state
	if (this.disable || !this.inited)
		return;

	// check for a callback function - used to alter the behavior of the Push operation
	if (null !== this.push_callback) {
		var res = this.push_callback(post_id);
		if (!res)							// if the callback returns a false
			return;							// do not continue processing
	}

	// set message to "working..."
	this.set_message(jQuery('#sync-msg-working').text(), true);

	this.post_id = post_id;
	var data = { action: 'spectrom_sync', operation: 'push', post_id: post_id, _sync_nonce: jQuery('#_sync_nonce').val() };

//console.log('push() calling AJAX');
	var push_xhr = {
		type: 'post',
		async: true, // false,
		data: data,
		url: ajaxurl,
		success: function(response) {
//console.log('push() success response:');
//console.log(response);
			wpsitesynccontent.clear_message();
			if (response.success) {
//console.log('push() response.success');
//				jQuery('#sync-message').text(jQuery('#sync-success-msg').text());
				wpsitesynccontent.set_message(jQuery('#sync-success-msg').text(), false, true);
				if ('undefined' !== typeof(response.notice_codes) && response.notice_codes.length > 0) {
					for (var idx = 0; idx < response.notice_codes.length; idx++) {
						wpsitesynccontent.add_message(response.notices[idx]);
					}
				}
			} else {
//console.log('push() !response.success');
				if ('undefined' !== typeof(response.data.message))
//					jQuery('#sync-message').text(response.data.message);
					wpsitesynccontent.set_message(response.data.message, false, true, 'sync-error');
			}
		},
		error: function(response) {
//console.log('push() failure response:');
//console.log(response);
			var msg = '';
			if ('undefined' !== typeof(response.error_message))
				wpsitesynccontent.set_message('<span class="error">' + response.error_message + '</span>', false, true);
			else
				wpsitesynccontent.set_message('<span class="error">' + jQuery('#sync-runtime-err-msg').html() + '</span>', false, true)
//			jQuery('#sync-content-anim').hide();
		}
	};

	// Allow other plugins to alter the ajax request
	jQuery(document).trigger('sync_push', [push_xhr]);
//console.log('push() calling jQuery.ajax');
	jQuery.ajax(push_xhr);
//console.log('push() returned from ajax call');
};

/**
 * Set a callback function to be used to alter behavior of .push() method
 * @param {function} fn The function to store and use as a callback in .push()
 */
WPSiteSyncContent.prototype.set_push_callback = function(fn)
{
	this.push_callback = fn;
};

/**
 * Set a callback function to be used to alter behavior of .pull() method
 * @param {function} fn The function to store and use as a callback in .pull()
 */
WPSiteSyncContent.prototype.set_pull_callback = function(fn)
{
	this.pull_callback = fn;
};

/**
 * Display message about WPSiteSync Pull feature
 */
WPSiteSyncContent.prototype.pull_feature = function()
{
	this.set_message(jQuery('#sync-pull-msg').html());
	jQuery('#sync-pull-content').blur();
};

var wpsitesynccontent = new WPSiteSyncContent();

// initialize the WPSiteSync operation on page load
jQuery(document).ready(function() {
	wpsitesynccontent.init();
});

// EOF
