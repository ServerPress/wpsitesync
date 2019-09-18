<?php

class SyncGutenbergEntry
{
	// list of Gutenberg property types used for $prop_type value
	const PROPTYPE_IMAGE = 1;					// :i (default)
	const PROPTYPE_POST = 2;					// :p - post
	const PROPTYPE_USER = 3;					// :u - user
	const PROPTYPE_LINK = 4;					// :l - link
	const PROPTYPE_TAX = 5;						// :t - taxonomy
	const PROPTYPE_TAXSTR = 6;					// :T - taxonomy ID as a string
	const PROPTYPE_GF = 6;						// :gf gravity form
	const PROPTYPE_CF = 7;						// :cf contact form 7

	public $prop_type = self::PROPTYPE_IMAGE;			// int		Type of property- one of the PROPTYPE_ constant values
	public $prop_name = NULL;							// string	Name of the property within JSON object
	public $prop_list = NULL;							// array	list of names to access property within JSON object
	public $prop_array = FALSE;							// bool		TRUE for property denotes an array of data

	private static $_sync_model = NULL;						// model used for Target content lookups
	private static $_source_site_key = NULL;					// source site's Site Key; used for Content lookups

	/**
	 * Parses Gutenberg property codes, setting the type, name and list from the property name
	 * @param string $prop The property name to be parsed
	 * @return stdClass instance with the property string parsed into the class's properties
	 */
	public function __construct($prop)
	{
		// property is in the form: '[name.name:type'
		//		a '[' at the begining indicates that the property is an array of items
		//		':type' is the type of property
		//			:i or nothing - indicates a reference to an image id
		//			:u - indicates a reference to a user id
		//			:p - indicates a reference to a post id
		//			:l - indicates a reference to a link. the link can include a post id: /wp-admin/post.php?post={post_id}\u0026action=edit
		//			:t - indicates a reference to a taxonomy id

		// check for the suffix and set the _prop_type from that
		if (FALSE !== ($pos = strpos($prop, ':'))) {
			switch (substr($prop, $pos)) {
			case ':i':			$this->prop_type = self::PROPTYPE_IMAGE;		break;
			case ':l':			$this->prop_type = self::PROPTYPE_LINK;			break;
			case ':p':			$this->prop_type = self::PROPTYPE_POST;			break;
			case ':t':			$this->prop_type = self::PROPTYPE_TAX;			break;
			case ':T':			$this->prop_type = self::PROPTYPE_TAXSTR;		break;
			case ':u':			$this->prop_type = self::PROPTYPE_USER;			break;
			case ':cf':			$this->prop_type = self::PROPTYPE_CF;			break;
			case ':gf':			$this->prop_type = self::PROPTYPE_GF;			break;
			}
			$prop = substr($prop, 0, $pos);			// remove the suffix
		}

		// check for array references
		if ('[' === substr($prop, 0, 1)) {
			$this->prop_array = TRUE;
			$prop = substr($prop, 1);
		}

		if (FALSE !== strpos($prop, '.')) {
			// this section handles Ultimate Addons for Gutenberg's nested properties
			// right now, it only handles one level of property nesting
			$this->prop_list = explode('.', $prop);
if (count($this->prop_list) > 3)
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' ERROR: more than three properties: ' . implode('->', $this->_prop_list));
		} else {
			$this->prop_name = $prop;
		}
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' type=' . $this->prop_type . ' arr=' . ($this->prop_array ? 'T' : 'F') .
			' name=' . (NULL === $this->prop_name ? '(NULL)' : $this->prop_name) .
			' list=' . (NULL === $this->prop_list ? '(NULL)' : implode('->', $this->prop_list)));
	}

	/**
	 * Obtains a property's value
	 * @param stdClass $obj JSON object reference
	 * @param int $ndx Index into array, if current property references an array
	 * @return multi the value from the object referenced by the current property
	 */
	public function get_val($obj, $ndx = 0)
	{
		$val = 0;
		$idx = 0;						// this is the index within the _prop_list array to use for property references
		$prop_name = '';
		if ($this->prop_array) {
			$idx = 1;
			$prop_name = $this->prop_list[0] . '[' . $ndx . ']->';
		}
		$idx2 = $idx + 1;
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' idx=' . $idx . ' idx2=' . $idx2 . ' ' . (NULL !== $this->prop_list ? implode('|', $this->prop_list) : ''));

		if (NULL === $this->prop_name) {									// nested reference
			$prop_name .= $this->prop_list[$idx];
			if ($idx2 < count($this->prop_list))
				$prop_name .= '->' . $this->prop_list[$idx2];
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' getting property: ' . $prop_name);
			if ($idx2 < count($this->prop_list)) {
				if (isset($obj->{$this->prop_list[$idx]}->{$this->prop_list[$idx2]}))
					$val = $obj->{$this->prop_list[$idx]}->{$this->prop_list[$idx2]};
			} else {
				if (isset($obj->{$this->prop_list[$idx]}))
					$val = $obj->{$this->prop_list[$idx]};
			}
		} else {															// single reference
			$prop_name .= $this->prop_name;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' getting property: ' . $prop_name);
			// property denotes a single reference
			if (isset($obj->{$this->prop_name}))
				$val = $obj->{$this->prop_name};
		}
		return $val;
	}

	/**
	 * Sets a property's value
	 * @param stdClass $obj JSON object reference
	 * @param multi $val The value to set for the current property
	 * @param int $ndx Index into array, if current property references an array
	 */
	public function set_val($obj, $val, $ndx = 0)
	{
		if (self::PROPTYPE_TAXSTR === $this->prop_type)
			$val = strval($val);

		$idx = 0;
		$prop_name = '';
		if ($this->prop_array) {
			$idx = 1;
			$prop_name = $this->prop_list[0] . '[' . $ndx . ']->';
		}
		$idx2 = $idx + 1;

		if (NULL === $this->prop_name) {									// nested reference
			$prop_name .= $this->prop_list[$idx];
			if ($idx2 < count($this->prop_list))
				$prop_name .= '->' . $this->prop_list[$idx2];
//SyncDebug::log(__METHOD__.'():' . __LINE__ . ' setting property: ' . $prop_name);
			if ($idx2 < count($this->prop_list)) {
				if (isset($obj->{$this->prop_list[$idx]}->{$this->prop_list[$idx2]}))
					$obj->{$this->prop_list[$idx]}->{$this->prop_list[$idx2]} = $val;
				else
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' Property "' . $prop_name . '" does not exist in object');
			} else {
				if (isset($obj->{$this->prop_list[$idx]}))
					$obj->{$this->prop_list[$idx]} = $val;
				else
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' Property "' . $prop_name . '" does not exist in object');
			}
		} else {															// single reference
			$prop_name .= $this->prop_name;
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' setting property: ' . $prop_name);
			if (isset($obj->{$this->prop_name}))
				$obj->{$this->prop_name} = $val;
			else
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' Property "' . $prop_name . '" does not exist in object');
		}
	}

	/**
	 * Gets the Target Content's ID from the Source site's Content ID
	 * @param int $source_ref_id The post ID of the Content on the Source site
	 * @return int|FALSE The Target site's ID value if found, otherwise FALSE to indicate not found
	 */
	public function get_target_ref($source_ref_id)
	{
		if (NULL === self::$_sync_model)
			self::$_sync_model = new SyncModel();
		if (NULL === self::$_source_site_key)
			self::$_source_site_key = SyncApiController::get_instance()->source_site_key;

		$type = 'post';				// used to indicate to SyncModel->get_sync_data() what type of content
		switch ($this->prop_type) {
		case self::PROPTYPE_IMAGE:		$type = 'post';			break;		// images and posts both stored in posts table
		case self::PROPTYPE_POST:		$type = 'post';			break;
		case self::PROPTYPE_USER:		$type = 'user';			break;
		case self::PROPTYPE_LINK:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' link');
			break;
		case self::PROPTYPE_TAX:
		case self::PROPTYPE_TAXSTR:		$type = 'term';			break;
		default:
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' unrecognized type "' . $this->prop_type . '"');
			break;
		}

		$source_ref_id = abs($source_ref_id);
		if (0 !== $source_ref_id) {
			$sync_data = self::$_sync_model->get_sync_data($source_ref_id, self::$_source_site_key, $type);
			if (NULL !== $sync_data) {
SyncDebug::log(__METHOD__.'():' . __LINE__ . ' source ref=' . $source_ref_id . ' target=' . $sync_data->target_content_id);
				return abs($sync_data->target_content_id);
			}
		}
		return FALSE;
	}
}

// EOF
