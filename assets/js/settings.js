/*
 * @copyright Copyright (C) 2014-2016 SpectrOMtech.com. - All Rights Reserved.
 * @author SpectrOMtech.com <SpectrOMtech.com>
 * @url https://wpsitesync.com/license
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images,
 * manuals, cascading style sheets, and included JavaScript *are NOT GPL*, and are released under the
 * SpectrOMtech Proprietary Use License v1.0
 * More info at https://wpsitesync.com
 */

function SyncSettings()
{
	this.$form = null;
	this.fields = new Array('host', 'username', 'password');
}

sync_settings = new SyncSettings();

/**
 * Registers events and callbacks
 */
SyncSettings.prototype.init = function()
{
	var _self = this;

	this.$form = jQuery('#form-spectrom-sync');
//	this.$form.on('submit', function(e) { return _self.on_submit(e); });

	// hide form fields if there is currently no Target
//	if ('' === jQuery('#spectrom-form-host').val()) {
//		for (var i = 0; i < this.fields.length; i++) {
//			jQuery('#spectrom-form-' + this.fields[i]).closest('tr').hide();
//		}
//	}

	// button handler for the "Create Target" button
//	jQuery('#spectrom-button-showtarget').on('click', function(el) {
//		for (var i = 0; i < sync_settings.fields.length; i++) {
//			jQuery('#spectrom-form-' + sync_settings.fields[i]).closest('tr').show();
//		}
//	});

	jQuery('.sync-license-input', '.spectrom-sync-settings').on('keyup', function() {
		jQuery('button.sync-license', '.spectrom-sync-settings').attr('disabled', 'disabled');
	});
};

/**
 * Perform activation or deactivation AJAX call
 * @param {string} op Operation name, one of 'activate' or 'deactivate'
 * @param {string} name Name of product to perform operation on
 */
SyncSettings.prototype.activate_api = function(op, name)
{
	if ('activate' === op)
		jQuery('#sync-license-msg-' + name).html(jQuery('#sync-activating-msg').html());
	else
		jQuery('#sync-license-msg-' + name).html(jQuery('#sync-deactivating-msg').html());
	jQuery('#sync-license-msg-' + name).show();

	var data = {
		action: 'spectrom_sync',
		operation: op,
		extension: name
	};

	jQuery.ajax({
		type: 'post',
		async: true,
		data: data,
		url: ajaxurl,
		success: function(response) {
			jQuery('#sync-license-msg-' + name).text('');
			if (response.success) {
				jQuery('#sync-license-status-' + name + ' span').text(response.data.status);
				jQuery('#sync-license-msg-' + name).html(response.data.message);
			} else {
				jQuery('#sync-license-msg-' + name).html(response.data.message);
			}
		}
	});
};

SyncSettings.prototype.activate = function(el, name)
{
	jQuery(el).blur();
	this.activate_api('activate', name);
};

SyncSettings.prototype.deactivate = function(el, name)
{
	jQuery(el).blur();
	this.activate_api('deactivate', name);
};

/**
 * Verifies the target settings
 */
SyncSettings.prototype.on_submit = function(e)
{
	var data = jQuery("[name^='spectrom_sync_settings']").serialize();
	data += '&action=spectrom_sync&operation=verify_connection';

	var $indicator = jQuery('#connect-success-indicator');

	var proceed = false;

/*	jQuery.ajax({
		type: 'post',
		async: false,
		data: data,
		url: ajaxurl,
		success: function(response) {
			$indicator.removeClass('fa-check fa-close');
			if (response.success) {
				$indicator
					.removeAttr('title')
					.addClass('fa-check');

				proceed = true;
			} else {
				$indicator
					.addClass('fa-close')
					.attr('title', response.errors[0]);

				proceed = false;
			}
		}
	}); */

	return proceed;
};

// initialize the settings operations on page load
jQuery(document).ready(function() {
	sync_settings.init();
});

// EOF
