<?php

class SyncSerialize
{
	private $_data = NULL;						// string data the parser is working on
	private $_pos = 0;							// index postion within $_data where parser is working
	private $_item_stack = array();				// array containing the expected number of items within an array/object
	private $_count_stack = array();			// array containing the actual number of items within an array/object

	const VALID_MARKERS = 'abdisNO';			// all valid serialization types

	/**
	 * Fixes string length specifiers in serialized data
	 * @param string $data A string containing the serialized data to be fixed
	 * @return string The serialized data with the string specifiers adjusted to reflect actual lengths of the strings
	 * @deprecated Use the SyncSerialize->parse_data() method instead
	 */
	// TODO: remove all references to this in favor of the parse_data() method
	public function fix_serialized_data($data)
	{
		$data = preg_replace_callback('!s:(\d+):([\\\\]?"[\\\\]?"|[\\\\]?"((.*?)[^\\\\])[\\\\]?");!',
			array($this, 'fixup'),
			$data);

		return $data;
	}

	/**
	 * Callback function for the preg_replace_callback() in fix_serialized_data().
	 * @param array $matches An array of the match information
	 * @return type String representing the node with the string length fixed
	 */
	public function fixup($matches)
	{
		if (count($matches) < 4)
			return $matches[0];
		return 's:' . strlen($matches[3]) . ':' . $matches[2] . ';';
	}

	/**
	 * Fixes quoted data found within serialized data object
	 * @param string $data String containing a serialized object
	 * @param callback $callback A callback function that will receive each entry withing the serialized data
	 * @return string The data with string values fixed
	 */
	public function parse_data($data, $callback = NULL)
	{
		$res = '';											// initialize the result string
		$this->_data = $data;
		$this->_pos = 0;
		$nesting = 0;										// how deep are arrays/objects nested

		while ($this->_pos < strlen($this->_data)) {
//$this->_debug();
			$ch = substr($this->_data, $this->_pos++, 1);
			$entry = NULL;
			switch ($ch) {
			case 'a':													// array value
				// a:22:{...}
				++$nesting;
				$this->_skip_colon();
				$items = $this->_get_digits();
				$this->_skip_colon();
				$entry = new SyncSerializeEntry('a', NULL, $items);
				break;
			case 'b':													// boolean value
				// b:0;
				$this->_skip_colon();
				$bool = substr($this->_data, $this->_pos++, 1);			// the boolean value
				$this->_skip_semi();
				$entry = new SyncSerializeEntry('b', abs($bool));
				break;
			case 'd':													// decimal/numeric value
				// d:1.23;
				$this->_skip_colon();
				$num = $this->_get_number();
				$this->_skip_semi();
				$entry = new SyncSerializeEntry('d', floatval($num));
				break;
			case 'i':													// integer value
				// i:0;
				$this->_skip_colon();
				$num = $this->_get_integer();
				$this->_skip_semi();
				$entry = new SyncSerializeEntry('i', intval($num));
				break;
			case 's':													// string value
				$this->_skip_colon();
				$save = $this->_pos;
				$len = $this->_get_digits();
				$this->_skip_colon();
				$this->_skip_quote();
				$str = $this->_get_string(abs($len));
				$this->_skip_quote();
				$this->_skip_semi();
				$entry = new SyncSerializeEntry('s', $str);
				break;
			case 'N':													// null value
				// N;
				$this->_skip_semi();
				$entry = new SyncSerializeEntry('N');
				break;
			case 'O':													// object value
				// O:8:"stdClass":5:{...}
				$this->_skip_colon();
				$name_len = $this->_get_digits();			// length of class name
				$this->_skip_colon();
				$this->_skip_quote();
				$name = $this->_get_string(abs($name_len));
				$this->_skip_quote();
				$this->_skip_colon();
				$items = abs($this->_get_digits());
				$this->_push_len($items);					// number of items in class
				$this->_skip_colon();
				$entry = new SyncSerializeEntry('O', $name, $items);
				break;
			case '{':
				++$nesting;
				$res .= '{';
				break;
			case '}':
				if (0 !== $nesting) {
					--$nesting;
				} else {
//echo 'pos=', $this->_pos, ' len=', strlen($this->_data), PHP_EOL;
					if ($this->_pos < strlen($this->_data) - 3)			// #176 allow for nexted serialization
						$this->_error('Unexpected close brace found at offset ' . $this->_pos . ': ' . substr($this->_data, $this->_pos, 20));
				}
				$res .= '}';
				break;
			default:
				if ($this->_pos >= strlen($this->_data) - 2)			// #176 allow for nested serialization
					$res .= $ch;
				else
					$this->_error('Unrecognized type character "' . $ch . '" at offset ' . $this->_pos . ' found');
			}

			// if the item is a string, give the callback a chance to modify it
			if (NULL !== $entry) {
				if ('s' === $entry->type && NULL !== $callback) {
					// if this entry is a string, send it to the callback
					call_user_func($callback, $entry); #177
//					$callback($entry);
				}

				// append (modified) entry to the result
				$res .= $entry->__toString();
			}
		}
		return $res;
	}

	/**
	 * Return the string found at the current posiion. If the found string is of a different length
	 * that what is expected, the length descriptor and position indicators are updated and processing
	 * can continue as though the string was of the correct length.
	 * @param int $expected_len The expected length of the string
	 * @return string The string found at the location.
	 */
	private function _get_string($expected_len)
	{
		$str = '';
		$offset = 0;
		$chars = abs($expected_len);
		for (;;) {
			$ch = substr($this->_data, $this->_pos + $offset, 1);
			if ('"' === $ch) {
				$next = substr($this->_data, $this->_pos + $offset + 1, 1);
				if ($this->_pos + $offset + 2 < strlen($this->_data))
					$next2 = substr($this->_data, $this->_pos + $offset + 2, 1);
				else
					$next2 = '';

				if ($offset === $chars && ';' === $next) {
					// found end of string marker
					if ('}' === $next2 ||
						('' !== $next2 && FALSE !== strpos(self::VALID_MARKERS, $next2)) ||
						'}' === $next2) {
						// found string of expected length
						break;
					}
				} else if ($offset === $chars && ':' === $next && ctype_digit($next2)) {
					// found end of object marker
					break;
				}
				// ";O:8:
				// TODO: look for other markers or expected patterns at close brace, etc.
				if (';' === $next) {
					if (FALSE !== strpos(self::VALID_MARKERS . '}', $next2)) {
						// found ending quote before it was expected
						// data type follows, so call it the end of the string
						break;
					}
				}
			}
			$str .= $ch;
			++$offset;
		}
/*
		$data = substr($this->_data, $this->_pos, $offset - 1);
		$len = min(strlen($str), strlen($data));
		for ($i = 0; $i < $len; $i++) {
			if (substr($str, $i, 1) !== substr($data, $i, 1)) {
echo '1:', $str, PHP_EOL;
echo '2:', $data, PHP_EOL;
echo '**error at offset ', $i, ':', substr($str, $i, 1), '/', substr($data, $i, 1), PHP_EOL;
				break;
			}
		}
*/
		$this->_pos += $offset;
		return $str;
	}

	/**
	 * Return a (floating point) number found at the current position.
	 * @return string A string containing the floating point number
	 */
	private function _get_number()
	{
		$ret = '';
		$decimal = FALSE;
		// look for a sign indicator
		if ('-' === substr($this->_data, $this->_pos, 1)) {
			$ret = '-';
			++$this->_pos;
		}
		for (;;) {
			$ch = substr($this->_data, $this->_pos, 1);
			if ('.' === $ch && !$decimal) {
				$ret .= $ch;
				$decimal = TRUE;
			} else if (ctype_digit($ch)) {
				$ret .= $ch;
				++$this->_pos;
			} else {
				// move forward until a semicolon is found
				while (';' !== ($ch = substr($this->_data, $this->_pos++, 1)))
					;
				--$this->_pos;
				break;
			}
		}
		return $ret;
	}

	/**
	 * Return an integer value found at the current position
	 * @return string The integer found at the current position as a string.
	 */
	private function _get_integer()
	{
		$ret = '';
		// look for a sign indicator
		if ('-' === substr($this->_data, $this->_pos, 1)) {
			$ret = '-';
			++$this->_pos;
		}
		$ret .= $this->_get_digits();
		return $ret;
	}

	/**
	 * Return the digits found at the current location
	 * @return string The digits found at the current position as a string.
	 */
	private function _get_digits()
	{
		$digits = '';
		while (ctype_digit(substr($this->_data, $this->_pos, 1))) {
			$digits .= substr($this->_data, $this->_pos, 1);
			++$this->_pos;
		}
		return $digits;
	}

	/**
	 * Output some debugging information
	 */
	private function _debug()
	{
//echo 'debug: character at offset ', $this->_pos, ': [', substr($this->_data, $this->_pos, 1), ']', PHP_EOL;
	}

	/**
	 * Pushes a length descriptor to the end of the item stack
	 * @param int $len A length descriptor for an object or array
	 */
	private function _push_len($len)
	{
		$this->_item_stack[] = abs($len);
		$this->_count_stack[] = 0;
	}

	/**
	 * Increments the counter of items in the stack
	 * @param int $items Number of items to increment by
	 */
	private function _increment($items = 1)
	{
		$this->_count_stack[count($this->_count_stack) - 1] += $items;
	}

	/**
	 * Checks that the expected items in collection matches the actual items specific for collection
	 */
	private function _check()
	{
		$items = $this->_item_stack[count($this->_item_stack) - 1];
		$count = $this->_count_stack[count($this->_count_stack) - 1];
		if (abs($items) !== abs($count))
			$this->_error('Expected items does not match actual items found');
	}

	/**
	 * Removes the length item from the end of the stack
	 */
	private function _pop_len()
	{
		array_pop($this->_item_stack);
		array_pop($this->_count_stack);
	}
	
	/**
	 * Skips a colon character found at the current location. If the character is not a colon, an exception is thrown.
	 */
	private function _skip_colon()
	{
		$this->_skip_char(':');
	}

	/**
	 * Skips a semicolon character found at the current location. If the character is not a semicolon, an exception is thrown.
	 */
	private function _skip_semi()
	{
		$this->_skip_char(';');
	}

	/**
	 * Skips am open brace character found at the current location. If the character is not an open space, an exception is thrown.
	 */
	private function _skip_obrace()
	{
		$this->_skip_char('{');
	}

	/**
	 * Skips a close brace character found at the current location. If the character is not a close brace, an exception is thrown.
	 */
	private function _skip_cbrace()
	{
		$this->_skip_char('}');
	}

	/**
	 * Skips a quote character found at the current location. If the character is not a quote, an exception is thrown.
	 */
	private function _skip_quote()
	{
		$this->_skip_char('"');
	}

	/**
	 * Skips the specified character found at the current location. If the character is not the one specified, an exception is thrown.
	 */
	private function _skip_char($char)
	{
		if (substr($this->_data, $this->_pos, 1) === $char) {
			++$this->_pos;
			return;
		}
		$this->_error('Character at offset ' . $this->_pos . ' is not "' . $char . '" [' . substr($this->_data, $this->_pos, 20) . ']');
	}

	/**
	 * Displays an error message
	 * @param type $msg
	 * @throws Exception
	 */
	private function _error($msg)
	{
		echo $msg, PHP_EOL;
		throw new Exception($msg);
	}
}

/**
 * Class used to track the serialized item. It can be created with parsed data and recreated
 * to added back into serialized data stream.
 */
class SyncSerializeEntry
{
	public $type = '';				// 1 character entry type code
	public $length = 0;				// integer length
	public $content = NULL;			// string containing content or NULL
	public $count = 0;				// number of items contained in current item

	public function __construct($type, $content = NULL, $length = NULL)
	{
		switch ($type) {
		case 'a':								// array
			if (NULL === $length)
				throw new Exception('Array type requires length');
			$this->count = $length;
			break;
		case 'b':								// boolean
			if (1 !== $content && 0 !== $content)
				throw new Exception('Boolean type requires content of 1 or 0');
			$this->content = $content;
			break;
		case 'd':								// decimal
			if (NULL === $content)
				throw new Exception('Decimal type requires content');
			$this->content = floatval($content);
			break;
		case 'i':								// integer
			if (NULL === $content)
				throw new Exception('Integer type requires content');
			$this->content = intval($content);
			break;
		case 's':								// string
			if (!is_string($content))
				throw new Exception('String type requires string content');
			$this->length = strlen($content);
			$this->content = $content;
			break;
		case 'N':								// null
			if (NULL !== $content)
				throw new Exception('Null type requires no content');
			break;
		case 'O':								// object
			if (!is_string($content) || NULL === $length)
				throw new Exception('Object type requires name and length values');
			$this->length = strlen($content);
			$this->content = $content;
			$this->count = $length;
			break;
		default:
			throw new Exception('Unrecognized serialization type marker: "' . $this->type . '"');
		}
		$this->type = $type;
//echo 'entry: ', $this->__toString(), PHP_EOL;
	}

	public function __toString()
	{
		$ret = '';

		switch ($this->type) {
		case 'a':								// array
			$ret = 'a:' . $this->count . ':';
			break;
		case 'b':								// boolean
			$ret = 'b:' . $this->content . ';';
			break;
		case 'd':								// decimal
			$ret = 'd:' . $this->content . ';';
			break;
		case 'i':								// integer
			$ret = 'i:' . $this->content . ';';
			break;
		case 's':								// string
			$ret = 's:' . strlen($this->content) . ':"' . $this->content . '";';
			break;
		case 'N':								// null
			$ret = 'N;';
			break;
		case 'O':								// object
			$ret = 'O:' . strlen($this->content) . ':"' . $this->content . '":' . $this->count . ':';
			break;
		default:
			throw new Exception('Unrecognized type "' . $this->type . '"');
		}
		return $ret;
	}
}

// EOF
