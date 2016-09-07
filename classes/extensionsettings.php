<?php

class SyncExtensionSettings
{
	const FEED_URL = 'https://wpsitesync.com/downloads/feed/';
	const TRANSIENT_KEY = 'wpsitesync_extension_list';
	const TRANSIENT_TTL = 86400;					// 24 hours

	private static $_instance = NULL;

	private function __construct()
	{
	}

	public static function get_instance()
	{
		if (NULL === self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}

	public function init_settings()
	{
		// no settings fields to display so nothing to initialize
	}

	public function show_settings()
	{
		echo '<h3>Available Extensions:</h3>';

		$extens = $this->_get_extension_data();
		if (FALSE === $extens) {
			echo '<p><b>Error:</b> Temporarily unable to read data from https://wpsitesync.com</p>';
		} else {
			$count = 0;
			foreach ($extens as $ext_info) {
				// image is 491x309
				echo '<div class="sync-extension">';
				echo '<h3>', esc_html($ext_info['title']), ' ', $ext_info['price'], '</h3>';
				echo '</div>';
				++$count;
			}
			echo '<p>', sprintf(__('%1$d extensions found.', 'wpsitesynccontent')), '</p>';
		}
	}

	private function _get_extension_data()
	{
		$data = get_transient(self::TRANSIENT_KEY);
		if (FALSE === $data) {
			$url = self::FEED_URL;
$url = 'http://wpsitesync.dev/downloadfeed/';
			$response = wp_remote_get($url);
//echo 'reading data from ', $url, '<br/>';
			if (is_wp_error($response)) {
				return FALSE;
die('error');
			} else {
				$data = wp_remote_retrieve_body($response);
			}
//			set_transient(self::TRANSIENT_KEY, $data, self::TRANSIENT_TTL);
		}

//echo '<pre>', $data, '</pre>';
//echo 'read ', strlen($data), ' bytes of data<br/>';

		$ret = array();
		$info = array();

		$xmldoc = simplexml_load_string($data);
//echo 'found ', count($xmldoc->channel->item), ' items<br/>';
		foreach ($xmldoc->channel->item as $item) {
//echo 'title=', $item->title, '<br/>';
			$info = array(
				'title' => $item->title,
				'link' => $item->link,
				'price' => $item->price,
				'saleprice' => isset($item->saleprice) ? $item->saleprice : '',
				'image' => isset($item->image) ? $item->image : '',
			);
			$ret[] = $info;
		}
//echo '<pre>', var_export($xmldoc, TRUE), '</pre>';
		return $ret;
####
		$parser = xml_parser_create();
		xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
		xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
		xml_parse_into_struct($parser, $data, $values, $tags);
		xml_parser_free($parser);

		foreach ($tags as $key => $val) {
			echo $key, ' = ', esc_html($val), '<br/>';
			if ('item' === $key) {
				echo var_export($val, TRUE), '<br/>';
				foreach ($val as $idx) {
					echo 'value=', var_export($values[$idx], TRUE), '<br/>';
				}
			}
		}

		return $ret;
####
		

		// parse the XML data
		$xml = new DOMDocument();
		$xml->loadHTML($data);
		$items = $xml->getElementsByTagName('item');

		// loop through each <item> tag and return them
		$nodes = $xml->documentElement;
		$count = 0;
		foreach ($nodes->childNodes as $item) {
			echo $item->nodeName, ' = ', esc_html($item->nodeValue), '<br/>';
			++$count;
		}
		echo '<p>found ', $count, ' nodes<br/>';

/*		for ($i = $items->length - 1; $i >= 0; $i--) {
			$node = $items->item($i);
			$title = $node->get('title');
echo $title, '<br/>';
			if (FALSE !== stripos($title, 'wpsitesync')) {
				$ret[] = array(
					'title' => $title,
				);
			}
		} */

		return $ret;
	}
}

// EOF