<?php

/**
 * Declares all of the Request Headers used in the SYNC Apis
 */
interface SyncApiHeaders
{
	const HEADER_SYNC_VERSION = 'x-sync-version';			// SYNC version number; used in requests and responses
	const HEADER_WP_VERSION = 'x-wp-version';				// WP version number; used in requests and responses
	const HEADER_SOURCE = 'x-sync-source';					// Source site's URL; used in requests
	const HEADER_SITE_KEY = 'x-sync-site-key';				// Source site's site_key; used in requests
	const HEADER_MATCH_MODE = 'x-sync-match-mode';			// How to match Content on Target
}

// EOF