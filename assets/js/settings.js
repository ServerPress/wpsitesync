/*
 * @copyright Copyright (C) 2015-2019 WPSiteSync.com. - All Rights Reserved.
 * @author WPSiteSync.com <hello@WPSiteSync.com>
 * @url https://wpsitesync.com/
 * The PHP code portions are distributed under the GPL license. If not otherwise stated, all images,
 * manuals, cascading style sheets, and included JavaScript *are NOT GPL*, and are released under the
 * SpectrOMtech Proprietary Use License v1.0
 * More info at https://wpsitesync.com
 */

/**
 * Javascript handlers for WPSiteSync's settings page
 * @returns {SyncSettings} instance
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

//	jQuery('.sync-license-input', '.spectrom-sync-settings').on('keyup', function() {
//		jQuery('button.sync-license', '.spectrom-sync-settings').attr('disabled', 'disabled');
//	});
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
	var lic_key = jQuery('#spectrom-form-' + name).val();

	var data = {
		action: 'spectrom_sync',
		operation: op,
		extension: name,
		key: lic_key
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

/**
 * Button callback for activating license key
 * @param {object} el Button element generating the click
 * @param {string} name Name of add-on being activated
 */
SyncSettings.prototype.activate = function(el, name)
{
	jQuery(el).blur();
	this.activate_api('activate', name);
};

/**
 * Button callback for deactivating license key
 * @param {object} el Button element generating the click
 * @param {string} name Name of add-on being deactivated
 */
SyncSettings.prototype.deactivate = function(el, name)
{
	jQuery(el).blur();
	this.activate_api('deactivate', name);
};

/**
 * Button callback for onblur event for license key field
 * @param {object} el The input field containing the license key
 */
SyncSettings.prototype.license_change = function(el)
{
//console.log('.license_change()');
	var id = jQuery(el).attr('id');
	var ext_name = id.replace('spectrom-form-sync_', '');
//console.log('id=' + id + ' name=' + ext_name);

	var lic_key = jQuery('#spectrom-form-sync_' + ext_name).val();
//console.log('key=' + lic_key)
	if (lic_key.length !== 32)
		return;

	// enable activate/deactivate buttons
	jQuery('#sync-license-act-sync_' + ext_name).removeAttr('disabled');
	jQuery('#sync-license-deact-sync_' + ext_name).removeAttr('disabled');
};

/**
 * Verifies the target settings
 * @param {object} e The event generating the submit
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
