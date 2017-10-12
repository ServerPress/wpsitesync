<?php

/**
 * Utility methods for authentication
 * @package Sync
 */

class SyncAuth extends SyncInput
{
	const HASHING_PASSWORD = TRUE;

	// TODO: make this configurable between Source and Target sites so it's harder to break
	private $salt = 'Cx}@d7M#Q:C;k0GHigDFh&w^ jwIsm@Vc$:oEL+q:(%.iKp?Q*5Axfc[d_f(2#>ZZ^??4g-B|Wd>Q4NyM^;G+R`}S`fnFG?~+cM9<?V9s}UzVzW-t:x]?5)f|~EJ-NLb';

	/**
	 * Verifies the login information and creates a nonce to be sent back to the 'Source' that made the request.
	 * @param SyncApiResponse $resp The response object to fill in.
	 */
	public function check_credentials(SyncApiResponse $resp)
	{
//SyncDebug::log(__METHOD__.'()');
		$info = array();
		$username = $this->post('username', NULL);
		$password = $this->post('password', NULL);
		$token = $this->post('token', NULL);
//SyncDebug::log(__METHOD__.'() user=' . $username . ' pass=' . $password . ' token=' . $token);
//SyncDebug::log(__METHOD__.'() post= ' . var_export($_POST, TRUE));
		$source_model = new SyncSourcesModel();
		$api_controller = SyncApiController::get_instance();

		if (NULL !== $token && NULL !== $username) {
			// perform authentication via the token
			$source = $api_controller->source;
			$site_key = $api_controller->source_site_key;

//SyncDebug::log(__METHOD__.'() authenticating via token');
//SyncDebug::log(' - source: ' . $source . ' site_key: ' . $site_key . ' user: ' . $username . ' token: ' . $token);
			$user_signon = $source_model->check_auth($source, $site_key, $username, $token);
//SyncDebug::log(__METHOD__.'() source->check_auth() returned ' . var_export($user_signon, TRUE));
		} else {
			$info['user_login'] = $username;
//SyncDebug::log(' - target: ' . get_bloginfo('wpurl'));
			if (self::HASHING_PASSWORD) {
				$info['user_password'] = $this->decode_password($password, get_bloginfo('wpurl'));
			} else {
				$info['user_password'] = $password;
			}
			$info['remember'] = FALSE;

			// this is to get around the block in PeepSo that checks for the referrer
			$_SERVER['HTTP_REFERER'] = get_bloginfo('wpurl');

//SyncDebug::log(__METHOD__.'() checking credentials: ' . var_export($info, TRUE));
			// if no credentials provided, don't bother authenticating
			if (empty($info['user_login']) || empty($info['user_password'])) {
//SyncDebug::log(__METHOD__.'() missing credentials');
				$resp->success(FALSE);
				$resp->error_code(SyncApiRequest::ERROR_BAD_CREDENTIALS);
				return;
			}

			$user_signon = wp_signon($info, FALSE);
		}

//SyncDebug::log(__METHOD__.'() checking login status ' . var_export($user_signon, TRUE));
		if (is_wp_error($user_signon)) {
			$resp->success(FALSE);
//SyncDebug::log(__METHOD__.'() failed login ' . var_export($user_signon, TRUE));
			// return error message
			$resp->error_code(SyncApiRequest::ERROR_BAD_CREDENTIALS, $user_signon->get_error_message());
		} else {
			// we have a valid user - check additional requirements

			// check capabilities
			if (!$user_signon->has_cap('edit_posts')) {
SyncDebug::log(__METHOD__.'() does not have capability: edit_posts');
				$resp->error_code(SyncApiRequest::ERROR_NO_PERMISSION);
				return;
			}

			// check to see if a token exists
			if (NULL === $token) {
				// we've just authenticated for the first time, create a token to return to Source site
				$data = array(
					'domain' => $api_controller->source,
					'site_key' => $api_controller->source_site_key,
					'auth_name' => $username,
				);
				$token = $source_model->add_source($data);
				if (FALSE === $token) {
					$resp->error_code(SyncApiRequest::ERROR_CANNOT_WRITE_TOKEN);
					return;
				}
				$resp->set('token', $token);				// return the token to the caller
			}
			// set cookies and nonce here
			$auth_cookie = wp_generate_auth_cookie($user_signon->ID, time() + 3600);
			$access_nonce = $this->generate_access_nonce($this->post('site_key'));
			$resp->set('access_nonce', $access_nonce);
			$resp->set('auth_cookie', $auth_cookie);
			$resp->set('user_id', $user_signon->ID);

			// include the site_key so the Source site can track what site the post is associated with
			$resp->set('site_key', SyncOptions::get('site_key'));

			// TODO: add API request type (auth, push, etc) to SyncApiResponse so callbacks know context
			$resp = apply_filters('spectrom_sync_auth_result', $resp);
//SyncDebug::log('Generated nonce - `' . $this->post('site_key') . ' = ' . $access_nonce . '`');
//SyncDebug::log('Generated auth cookie - `' . $auth_cookie . '`');
			$resp->success(TRUE);

			// save the user object in the controller for later permissions checks
			$api_controller->set_user($user_signon);
		}
	}

	/**
	 * Generate an access nonce for Sync operations
	 * @param string $site_key The site key for the current site
	 * @return string The generated nonce value
	 */
	public function generate_access_nonce($site_key)
	{
		return wp_create_nonce($site_key);
	}

	/*
	 * The following encode/decode methods are not meant to be super-secure.
	 * Just a means to avoid sending password completely in the clear.
	 */

	/**
	 * Encodes a password using Target domain to encrypt it
	 * @param string $password Clear text password that is to be encoded
	 * @param string $target Target domain name used to help obfuscate the returned string
	 * @return string The encoded password
	 */
	public function encode_password($password, $target)
	{
//SyncDebug::log(__METHOD__.'()');
		$key = $this->get_key($target);
//SyncDebug::log(' - key: ' . $key);

		$left = $right = '';
		if (function_exists('mcrypt_get_iv_size')) {
			$iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
			$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
			$encrypted = mcrypt_encrypt(MCRYPT_BLOWFISH, $key, utf8_encode($password), MCRYPT_MODE_ECB, $iv);
			$left = base64_encode($encrypted);
		}

		$right = $this->enc_str($password, $key);

		$encoded = $left . ':' . $right;
		return $encoded;
	}

	/**
	 * Decodes a password using Target domain to decode it
	 * @param string $password A previously encoded password to be decoded
	 * @param string $target Target domain name used to help obfucate the encoded string
	 * @return string The password decoded and in clear text
	 */
	public function decode_password($password, $target)
	{
//SyncDebug::log(__METHOD__.'()');
		$key = $this->get_key($target);
//SyncDebug::log('  key: ' . $key);

		$left = $password;
		if (!empty($_POST['encode']))
			$right = $_POST['encode'];

		$cleartext = NULL;
		if (function_exists('mcrypt_get_iv_size')) {
			$decoded = base64_decode($left);
//SyncDebug::log('  decoded: ' . $decoded);

			$iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
			$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
			$cleartext = mcrypt_decrypt(MCRYPT_BLOWFISH, $key, $decoded, MCRYPT_MODE_ECB, $iv);
//SyncDebug::log('  cleartext: ' . var_export($cleartext, TRUE));
			$cleartext = trim($cleartext, "\0");
//SyncDebug::log(__METHOD__.'() decoded left "' . $left . '" into "' . $cleartext . '"');
		}
		if (empty($cleartext) && !empty($right)) {
			$cleartext = $this->dec_str($right, $key);
//SyncDebug::log(__METHOD__.'() decoded right "' . $right . '" into "' . $cleartext . '"');
		}

//SyncDebug::log('  cleartext: ' . var_export($cleartext, TRUE));
		return $cleartext;
	}

	/**
	 * Encrypts a string
	 * @param type $string
	 * @param type $key
	 * @return type
	 */
	private function enc_str($string, $key)
	{
		$result = '';
		for ($i = 0; $i < strlen($string); ++$i) {
			$char = substr($string, $i, 1);
			$keychar = substr($key, ($i % strlen($key)) - 1, 1);
			$char = chr(ord($char) + ord($keychar));
			$result .= $char;
		}

		return base64_encode($result);
	}

	/**
	 * Decrypts a string
	 * @param type $string
	 * @param type $key
	 * @return type
	 */
	function dec_str($string, $key)
	{
		$result = '';
		$string = base64_decode($string);

		for ($i = 0; $i < strlen($string); ++$i) {
			$char = substr($string, $i, 1);
			$keychar = substr($key, ($i % strlen($key)) - 1, 1);
			$char = chr(ord($char) - ord($keychar));
			$result .= $char;
		}

		return $result;
	}

	/**
	 * Generates a 16 to 25 character "key" from a Target domain
	 * @param string $target The target domain name
	 * @return string A random looking string that is based on the target domain name
	 */
	private function get_key($target)
	{
		$target = trim(parse_url($target, PHP_URL_HOST), '/');
		// the encoded string needs to be fairly long so there's enough room to find our random looking string
		// so we add a long salt value to it
		$salted = $target . $this->salt;
		$encode = base64_encode($salted);

		$str = '';
		$len = strlen($encode);
		$count = 0;
		$start = min(20, strlen($target));

		for ($i = $start; $i < $len && '' === $str; ++$i) {
			if (ctype_digit($d = substr($encode, $i, 1))) {
				// when we find a digit - skip that number of characters
				$i += intval($d);
				// after the third time, take a portion of the string as our key
				if (3 === ++$count)
					$str = substr($encode, $i, 16 + intval($d));
			}
		}

		// if for some reason we didn't find a string, just grab something
		if ('' === $str)
			$str = substr($encode, 10, 20);

		return $str;
	}
}

// EOF