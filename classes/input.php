<?php

class SyncInput
{
	/*
	 * Return the named element for a given get variable
	 * @param string $name The name of the form element and array element within $_GET[]
	 * @param mixed $default The default value to return if no value found
	 * @return mixed The named get parameter if found, otherwise the $default value provided.
	 */
	public function get($name, $default = '')
	{
		if (isset($_GET[$name]))
			return sanitize_text_field($_GET[$name]);
		return $default;
	}


	/*
	 * Return the named element for a given get variable as an integer
	 * @param string $name The name of the form element and array element within $_GET[]
	 * @param mixed $default The default value to return if no value found
	 * @return int The integer value of the named get parameter if found, otherwise the $default value provided.
	 */
	public function get_int($name, $default = 0)
	{
		$get = $this->get($name, $default);
		return intval($get);
	}

	/*
	 * Check if a GET array element exists
	 * @return Boolean TRUE if GET variable exists otherwise FALSE
	 */
	public function get_exists($name)
	{
		if (isset($_GET[$name]))
			return TRUE;
		return FALSE;
	}


	/*
	 * Return the named element for a given $_POST variable
	 * @param string $name The name of the form element and array element within $_POST[]
	 * @param mixed $default The default value to return if no value found
	 * @return mixed The named form element if found, otherwise the $default value provided.
	 */
	public function post($name, $default = '')
	{
		if (isset($_POST[$name])) {
			if (is_array($_POST[$name])) {
				$data = array_map('stripslashes', $_POST[$name]);
				$data = array_map('strip_tags', $data);
				return $data;
			}
			return strip_tags(stripslashes($_POST[$name]));
		}
		return $default;
	}


	/*
	 * Return the named element for a given POST variable as an integer
	 * @param string $name The name of the form element and array element within $_GET[]
	 * @param mixed $default The default value to return if no value found
	 * @return int The integer value of the named POST element if found, otherwise the $default value provided.
	 */
	public function post_int($name, $default = 0)
	{
		$post = $this->post($name, $default);
		return intval($post);
	}


	/*
	 * Return raw POST data for a given form field
	 * @param string $name The name of the form element and array element within $_POST[]
	 * @param mixed $default The default value to return if no value found
	 * @return mixed The named form element if found, otherwise the $default value provided.
	 */
	public function post_raw($name, $default = '')
	{
		if (isset($_POST[$name]))
			return $_POST[$name];
		return $default;
	}

	/*
	 * Check if a POST array element exists
	 * @return Boolean TRUE if POST variable exists otherwise FALSE
	 */
	public function post_exists($name)
	{
		if (isset($_POST[$name]))
			return TRUE;
		return FALSE;
	}
}

// EOF