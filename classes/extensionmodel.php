<?php

/**
 * Models information tracked for add-ons for WPSiteSync
 *	Add-ons will provide the following information via the FILTER_ACTIVE_EXTENSIONS filter:
 *		'add-on-slug' => array(					// slug is the add-on name used to store information under and obtain Licensing information
 *			'name' => self::PLUGIN_NAME,		// name is the full name of the add-on
 *			'version' => self::PLUGIN_VERSION,	// version is the version number of the add-on
 * 			'file' => __FILE__,					// file is the file name of the add-on
 *		)
 */
class SyncExtensionModel
{
	const FILTER_ACTIVE_EXTENSIONS = 'spectrom_sync_active_extensions';

	private static $_extensions = NULL;

	/**
	 * Obtain list of all known extensions for WPSiteSync
	 * @return array List of all active extensions
	 */
	public static function get_extensions($set = FALSE)
	{
		if (NULL === self::$_extensions || $set)
			self::$_extensions = apply_filters(self::FILTER_ACTIVE_EXTENSIONS, array(), $set);
		return self::$_extensions;
	}
}

// EOF