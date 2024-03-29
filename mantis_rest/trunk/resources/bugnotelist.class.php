<?php
class BugnoteList extends ResourceList
{
	/**
	 *      A list of bug notes.
	 */
	public static function get_bug_id_from_url($url)
	{
		$matches = array();
		if (preg_match('!/(\d+)/notes/?!', $url, $matches)) {
			return $matches[1];
		}
	}

	function __construct() {
		/**
		 *      Constructs the list.
		 */
		$this->mantis_data = array();
		$this->rsrc_data = array();
	}

	protected function _get_query_condition($key, $value)
	{
		/**
		 *	Returns a SQL condition for filtering.
		 *
		 *	@param $key - The resource attribute on which we're filtering
		 *	@param $value - The value it must have
		 */
		if ($key == 'reporter') {
			return "n.reporter_id = " . (int)User::get_mantis_id_from_url($value);
		} elseif ($key == 'private') {
			return "n.view_state = " . ((int)$value ? VS_PRIVATE : VS_PUBLIC);
		}
		return NULL;
	}

	protected function _get_query_order($key, $value)
	{
		/**
		 * 	Returns an ORDER BY argument, given an argument from the query string.
		 *
		 * 	The return value of this function goes right after an 'ORDER BY', so it
		 * 	might be 'b.reporter ASC' or 'u.date_created DESC'.
		 *
		 * 	@param $key - The resource attribute on which the request says to sort.  For
		 * 		example, if the QS parameter is 'sort-reporter', $key here will be
		 * 		'reporter'.
		 * 	@param $value - The sense of the sort; 1 for ascending, -1 for descending.
		 */
		if ($key == 'reporter') {
			$key .= '_id';
		} elseif ($key == 'private') {
			$key = 'view_state';
		} elseif ($key == 'date_submitted' || $key == 'last_modified') {
			$key = mysql_escape_string($key);
		} else {
			throw new HTTPException(500, "Can't sort bugnotes by attribute '$key'");
		}
		$sql = "n.$key";

		if ($value == 1) {
			$sql .= ' ASC';
		} elseif ($value == -1) {
			$sql .= ' DESC';
		}

		return $sql;
	}

	public function get($request)
	{
		/*
		 *      Returns a Response with a representation of the note list.
		 *
		 *      @param $request - The Request we're responding to
		 */
		$this->bug_id = BugnoteList::get_bug_id_from_url($request->url);
		# Access checking and note gathering is based on Mantis's
		# email_build_visible_bug_data().
		$project_id = bug_get_field($this->bug_id, 'project_id');
		$user_id = auth_get_current_user_id();
		$access_level = user_get_access_level($user_id, $project_id);
		if (!access_has_bug_level(VIEWER, $this->bug_id)) {
			throw new HTTPException(403, "Access denied");
		}

		$visible_notes = bugnote_get_all_visible_bugnotes($this->bug_id, $access_level,
			'ASC', 0);
		$visible_note_ids = array();
		foreach ($visible_notes as $n) {
			$visible_note_ids[] = (int)$n->id;
		}

		# Apply conditions and sorts
		$sql_to_add = $this->_build_sql_from_querystring($request->query);
		$note_ids = array();
		if ($sql_to_add) {
			$mantis_bugnote_table = config_get('mantis_bugnote_table');
			$query = "SELECT n.id FROM $mantis_bugnote_table n $sql_to_add;";
			$result = db_query($query);
			foreach ($result as $r) {
				if (in_array((int)$r[0], $visible_note_ids)) {
					$note_ids[] = (int)$r[0];
				}
			}
		} else {
			$note_ids = $visible_note_ids;
		}

		$this->rsrc_data = array();
		$this->rsrc_data['results'] = array();
		foreach ($note_ids as $n) {
			$config = get_config();
			$this->rsrc_data['results'][] = Bugnote::get_url_from_mantis_id($n);
		}

		$resp = new Response();
		$resp->status = 200;
		$resp->body = $this->_repr($request);
		return $resp;
	}

	public function post($request)
	{
		/**
		 * 	Creates a new bugnote.
		 *
		 * 	Sets the location header and returns the main URL of the created resource,
		 * 	as RFC2616 says we SHOULD.
		 *
		 * 	@param $request - The Request we're responding to
		 */
		$this->bug_id = BugnoteList::get_bug_id_from_url($request->url);
		if (!access_has_bug_level(config_get('add_bugnote_threshold'), $this->bug_id)) {
			throw new HTTPException(403, "Access denied to add bugnote");
		}
		if (bug_is_readonly($this->bug_id)) {
			throw new HTTPException(500, "Cannot add a bugnote to a read-only bug");
		}

		$new_note = new Bugnote;
		$new_note->populate_from_repr($request->body);
		$bugnote_added = bugnote_add($this->bug_id, $new_note->mantis_data['note'],
			'0:00', $new_note->mantis_data['view_state'] == VS_PRIVATE);
		if ($bugnote_added) {
			$bugnote_added_url = Bugnote::get_url_from_mantis_id($bugnote_added);
			$this->rsrc_data = $bugnote_added_url;

			$resp = new Response();
			$resp->headers[] = "location: $bugnote_added_url";
			$resp->status = 201;
			$resp->body = json_encode($bugnote_added_url);
			return $resp;
		} else {
			throw new HTTPException(500, "Couldn't create bugnote");
		}
	}
}
?>
