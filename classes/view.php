<?php

class SyncView
{
	/**
	 * Loads a view from the view directory
	 * @param string $view Name of view template file to load
	 * @param array $data The data array to pass to the view
	 * @param boolean $return TRUE if the contents of the view are to be return; FALSE to output the contents of the view
	 */
	public static function load_view($view, $data = array(), $return = FALSE)
	{
		if ($return)
			ob_start();

		include(dirname(dirname(__FILE__)) . '/views/' . $view . '.php');

		if ($return) {
			$output = ob_get_clean();
			return $output;
		}
	}
}
