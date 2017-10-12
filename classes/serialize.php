<?php

class SyncSerialize
{
	public function fix_serialized_data($data)
	{
		$data = preg_replace_callback('!s:(\d+):([\\\\]?"[\\\\]?"|[\\\\]?"((.*?)[^\\\\])[\\\\]?");!',
			array($this, 'fixup'),
			$data);

		return $data;
	}
	public function fixup($matches)
	{
		if (count($matches) < 4)
			return $matches[0];
		return 's:' . strlen($matches[3]) . ':' . $matches[2] . ';';
//		return 's:' . $this->unescape_mysql($str) . ':"' . $this->unescape_quotes($str) . '";';
	}
}

// EOF