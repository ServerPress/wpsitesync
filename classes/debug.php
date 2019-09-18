<?php

class SyncDebug
{
	const DEBUG = TRUE;

	public static $_debug = FALSE;

	public static $_debug_output = FALSE;
	private static $_id = NULL;

	/**
	 * Array dump - removes "array(" and ")," lines from the output
	 * @param array $arr The data array to dump
	 * @return string The var_export() results with "array(" and ")," lines removed
	 */
	public static function arr_dump($arr)
	{
		$out = var_export($arr, TRUE);
		$data = explode("\n", $out);
		$ret = array();
		foreach ($data as $line) {
			if ('array (' !== trim($line) && '),' !== trim($line))
				$ret[] = $line;
		}
		return implode("\n", $ret) . PHP_EOL;
	}

	/**
	 * Sanitizes array content, removing any tokens, passwords, and reducing large content before converting to string
	 * @param array $arr Array to be dumped
	 * @return string Array contents dumped to a string
	 */
	public static function arr_sanitize($arr)
	{
		if (isset($arr['username']))
			$arr['username'] = 'target-user';
		if (isset($arr['password']))
			$arr['password'] = 'target-password';
		if (isset($arr['token']))
			$arr['token'] = 'xxx';
		if (isset($arr['customer_email']))
			$arr['customer_email'] = 'mail@domain.com';
		if (isset($arr['contents']) && strlen($arr['contents']) > 1024)
			$arr['contents'] = strlen($arr['contents']) . ' bytes...truncated...';

		if (isset($arr[0]) && isset($arr[0]->post_password)) {
			$idx = 0;
			foreach ($arr as $obj) {
				if (!empty($obj->post_password))
					$arr[$idx]->post_password = 'xxx';
			}
		}

		$ret = var_export($arr, TRUE);
		return $ret;
	}

	/**
	 * Perform logging
	 * @param string $msg The message to log
	 * @param boolean TRUE if a backtrace is to be logged after the message is logged
	 */
	public static function log($msg = NULL, $backtrace = FALSE)
	{
//		if (!self::$_debug && !defined('WP_DEBUG') || !WP_DEBUG)
//			return;

		if (self::$_debug_output)
			echo $msg, PHP_EOL;

		if (NULL === self::$_id)
			self::$_id = rand(10, 99);

		$file = dirname(dirname(__FILE__)) . '/~log.txt';
		$fh = @fopen($file, 'a+');
		if (FALSE !== $fh) {
			if (NULL === $msg)
				fwrite($fh, current_time('Y-m-d H:i:s'));
			else
				fwrite($fh, current_time('Y-m-d H:i:s') . '#' . self::$_id . ' - ' . $msg . "\r\n");

			if ($backtrace) {
				$callers = debug_backtrace(defined('DEBUG_BACKTRACE_IGNORE_ARGS') ? DEBUG_BACKTRACE_IGNORE_ARGS : FALSE);
				array_shift($callers);
				$path = dirname(dirname(dirname(plugin_dir_path(__FILE__)))) . DIRECTORY_SEPARATOR;

				$n = 1;
				foreach ($callers as $caller) {
					$func = $caller['function'] . '()';
					if (isset($caller['class']) && !empty($caller['class'])) {
						$type = '->';
						if (isset($caller['type']) && !empty($caller['type']))
							$type = $caller['type'];
						$func = $caller['class'] . $type . $func;
					}
					$file = isset($caller['file']) ? $caller['file'] : '';
					$file = str_replace('\\', '/', str_replace($path, '', $file));
					if (isset($caller['line']) && !empty($caller['line']))
						$file .= ':' . $caller['line'];
					$frame = $func . ' - ' . $file;
					$out = '    #' . ($n++) . ': ' . $frame . PHP_EOL;
					fwrite($fh, $out);
					if (self::$_debug_output)
						echo $out;
				}
			}

			fclose($fh);
		}
	}
}

// EOF