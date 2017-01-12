<?php

class SyncAttachModel
{
	public $_sizes = NULL;

	/**
	 * Searches for attachment resources by GUID
	 * @param string $guid The GUIS, or URL used to identify the resource
	 * @param boolean $extended_search Whether or not to perform an extended search
	 * @return NULL|array The found resource or NULL if no resources found
	 */
	public function search_by_guid($guid, $extended_search = FALSE)
	{
		global $wpdb;

		$sql = "SELECT *
				FROM `{$wpdb->posts}`
				WHERE `post_type`='attachment' AND `guid`=%s";
		$query = $wpdb->prepare($sql, $guid);
		$res = $wpdb->get_results($query, OBJECT);
SyncDebug::log(__METHOD__.'() sql=' . $query);
SyncDebug::log(' - res=' . var_export($res, TRUE));

		// check if not found and an extended search has been requested
		if (0 === count($res) && $extended_search) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' performing extended search for ' . $guid);
			$this->get_image_sizes();
SyncDebug::log(' - image sizes: ' . var_export($this->_sizes, TRUE));
			foreach ($this->_sizes as $img_size) {
				$dims = '-' . $img_size . '.';
				// check if there's a known image size suffix in the file name
				if (FALSE !== strpos($guid, $dims)) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found image match for size ' . $img_size);
					$img_url = str_replace($dims, '.', $guid);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' searching for ' . $img_url);
					$res = $this->search_by_guid($img_url, FALSE);
					if (0 !== count($res)) {
						$res[0]->orig_guid = $guid;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found a matching image: ' . $img_url);
						return $res;			// set this for the loop below
					}
				}
			}

			// no image found with the known image sizes
			$pos = strrpos($guid, '-');
			if (FALSE !== $pos) {
				$ext = strpos($guid, '.', $pos);
				if (FALSE !== $ext) {
					$img_url = substr($guid, 0, $pos) . substr($guid, $ext);
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' img=' . $img_url);
					$res = $this->search_by_guid($img_url, FALSE);
					if (0 !== count($res))
						$res[0]->orig_guid = $guid;
				}
			}
		}
		return $res;
	}

	/**
	 * Builds a list of all the images sizes known to WP
	 * @return array The list of registered image sizes in the form {width}x{height}
	 */
	public function get_image_sizes()
	{
		if (NULL === $this->_sizes) {
			$this->_sizes = array();
			global $_wp_additional_image_sizes;

			// start with the built-in image sizes
			$sizes = get_intermediate_image_sizes();
			foreach ($sizes as $size) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found size: ' . var_export($size, TRUE));
				if (in_array($size, array('thumbnail', 'medium', 'medium_large', 'large'))) {
					$wid = get_option("{$size}_size_w");
					$hgt = get_option("{$size}_size_h");
					$this->_sizes[] = $wid . 'x' . $hgt;
				} else if (isset($_wp_additional_image_sizes[$size])) {
					continue;			// skip if in the additional size list
//					$sizes[ $_size ] = array(
//						'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
//						'height' => $_wp_additional_image_sizes[ $_size ]['height'],
//						'crop'   => $_wp_additional_image_sizes[ $_size ]['crop'],
//					);
				} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' unrecognized image size: ' . var_export($size, TRUE));
				}
			}

			// now go through the list of additional image sizes
			foreach ($_wp_additional_image_sizes as $img_size) {
				$size = $img_size['width'] . 'x' . $img_size['height'];
				$this->_sizes[] = $size;
			}
		}

		return $this->_sizes;
	}

	/**
	 * Returns a list of all known image types and their sizes
	 * @return array An associated array in the form [{name}] = ['width'=>150, 'height'=>150, 'crop'=>FALSE]
	 */
	public function get_image_size_data()
	{
		$ret = array();
		global $_wp_additional_image_sizes;

		// start with the built-in image sizes
		$sizes = get_intermediate_image_sizes();
		foreach ($sizes as $size) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' found size: ' . var_export($size, TRUE));
			if (in_array($size, array('thumbnail', 'medium', 'medium_large', 'large'))) {
				$wid = get_option("{$size}_size_w");
				$hgt = get_option("{$size}_size_h");
				$crp = (bool) get_option("{$size}_crop");
			} else if (isset($_wp_additional_image_sizes[$size])) {
				continue;			// skip if in the additional size list
			} else {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' unrecognized image size: ' . var_export($size, TRUE));
				continue;
			}
			$ret[$size] = array(
				'width' => $wid,
				'height' => $hgt,
				'crop' => $crp,
			);
		}

		// now go through the list of additional image sizes
		foreach ($_wp_additional_image_sizes as $size => $img_data) {
			if (!isset($ret[$size]))
				$ret[$size] = $img_data;
		}

		return $ret;
	}
}

// EOF