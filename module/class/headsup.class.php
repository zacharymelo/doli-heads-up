<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/headsup.class.php
 * \ingroup headsup
 * \brief   Creating and counting Heads-up to-dos (agenda events)
 */
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

/**
 * Helpers shared by the popup endpoint, the trigger and the queue page.
 * Every flag, problem report and assignment is an ActionComm to-do.
 */
class Headsup
{
	const CODE_FLAG = 'AC_HU_FLAG';
	const CODE_PROBLEM = 'AC_HU_PROBLEM';
	const CODE_ASSIGN = 'AC_HU_ASSIGN';

	/** Element type of a to-do's comments in core's comment table (ActionComm's element). */
	const COMMENT_ELEMENT = 'action';

	/** Longest comment kept, in characters. */
	const COMMENT_MAX = 5000;

	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/**
	 * Linked records to set when an event is saved, by event id: the Modify form's
	 * "Linked object" field, applied by the ACTION_MODIFY trigger.
	 *
	 * @var array<int,array{element:string,id:int}>
	 */
	public static $pendingLinks = array();

	/** @var array<int,array<int,array{id:int,todo:int,label:string,from:string,mention:int}>> openMentionsFor() results, by user id */
	private $openmentions = array();

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Event codes this module creates.
	 *
	 * @return string[]
	 */
	public static function codes()
	{
		return array(self::CODE_FLAG, self::CODE_PROBLEM, self::CODE_ASSIGN);
	}

	/**
	 * The states of a Heads-up to-do, stored in the event's percent. In progress
	 * is 50, the value core's own "In progress" status sets; acknowledged is 10.
	 *
	 * @return array<string,int> todo, ack, progress, done
	 */
	public static function statuses()
	{
		return array('todo' => 0, 'ack' => 10, 'progress' => 50, 'done' => 100);
	}

	/**
	 * Which status control to show on My queue and the event card (setup):
	 * both (default), slider or picker.
	 *
	 * @return string both, slider or picker
	 */
	public static function statusControl()
	{
		$control = getDolGlobalString('HEADSUP_STATUS_CONTROL', 'both');
		return in_array($control, array('slider', 'picker'), true) ? $control : 'both';
	}

	/**
	 * The popup's field order and fold assignment from HEADSUP_POPUP_LAYOUT.
	 * Returns all five configurable keys (title, description, screenshot, tags, project)
	 * in the saved order; any key missing from the saved config is appended above fold.
	 *
	 * @return array<array{key:string,fold:string}>
	 */
	public static function popupLayout()
	{
		$defaults = array(
			array('key' => 'title',       'fold' => 'above'),
			array('key' => 'description', 'fold' => 'above'),
			array('key' => 'screenshot',  'fold' => 'above'),
			array('key' => 'tags',        'fold' => 'above'),
			array('key' => 'project',     'fold' => 'above'),
		);
		$raw = getDolGlobalString('HEADSUP_POPUP_LAYOUT');
		if (!$raw) {
			return $defaults;
		}
		$saved = json_decode($raw, true);
		if (!is_array($saved)) {
			return $defaults;
		}
		$validkeys = array('title', 'description', 'screenshot', 'tags', 'project');
		$result = array();
		$seen = array();
		foreach ($saved as $item) {
			if (!is_array($item) || !isset($item['key'], $item['fold'])) {
				continue;
			}
			if (!in_array($item['key'], $validkeys, true) || !in_array($item['fold'], array('above', 'below'), true)) {
				continue;
			}
			if (in_array($item['key'], $seen, true)) {
				continue;
			}
			$result[] = array('key' => $item['key'], 'fold' => $item['fold']);
			$seen[] = $item['key'];
		}
		foreach ($defaults as $def) {
			if (!in_array($def['key'], $seen, true)) {
				$result[] = $def;
			}
		}
		return $result;
	}

	/**
	 * Whether the popup shows the Project field (setup; off by default).
	 *
	 * @return bool
	 */
	public static function projectField()
	{
		return isModEnabled('project') && getDolGlobalInt('HEADSUP_PROJECT_FIELD') > 0;
	}

	/**
	 * Ids of the projects $user may link a to-do to: the ones they can see.
	 *
	 * @param User $user User
	 * @return array<int,int|string> Project id => ref (core documents the values as int)
	 */
	public function projectsAllowed($user)
	{
		if (!isModEnabled('project') || !$user->hasRight('projet', 'lire')) {
			return array();
		}
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
		$project = new Project($this->db);
		// 2: every project (the "see all projects" right), 0: public ones and those they are a contact of
		$ids = $project->getProjectsAuthorizedForUser($user, $user->hasRight('projet', 'all', 'lire') ? 2 : 0, 0);
		return is_array($ids) ? $ids : array();
	}

	/**
	 * Open projects $user can see whose ref or title matches $search, newest first,
	 * for the popup's Project search. With $id, that one project (open or not) instead.
	 *
	 * @param User   $user   User
	 * @param string $search Words to find in the ref or title
	 * @param int    $limit  Most to return
	 * @param int    $id     One project id, to show the name of a project passed in
	 * @return array<int,array{id:int,ref:string,title:string}>
	 */
	public function openProjectsFor($user, $search = '', $limit = 10, $id = 0)
	{
		$allowed = $this->projectsAllowed($user);
		if (!$allowed) {
			return array();
		}
		$sql = "SELECT p.rowid, p.ref, p.title FROM ".MAIN_DB_PREFIX."projet as p";
		$sql .= " WHERE p.entity IN (".getEntity('project').")";
		$sql .= " AND p.rowid IN (".$this->db->sanitize(implode(',', array_map('intval', array_keys($allowed)))).")";
		if ($id > 0) {
			$sql .= " AND p.rowid = ".((int) $id);
		} else {
			$sql .= " AND p.fk_statut = 1";
			if ($search !== '') {
				$sql .= natural_search(array('p.ref', 'p.title'), $search);
			}
		}
		$sql .= " ORDER BY p.datec DESC, p.rowid DESC";
		$sql .= $this->db->plimit((int) $limit);
		$out = array();
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'title' => (string) $obj->title);
			}
		}
		return $out;
	}

	/**
	 * Language key of each state's name.
	 *
	 * @return array<string,string> todo, ack, progress, done
	 */
	public static function statusLabels()
	{
		return array('todo' => 'HeadsupToDo', 'ack' => 'HeadsupAcknowledged', 'progress' => 'HeadsupInProgress', 'done' => 'HeadsupDone');
	}

	/**
	 * State of an event from its percent.
	 *
	 * @param int $percent Event percent
	 * @return string todo, ack, progress or done
	 */
	public static function statusOf($percent)
	{
		$percent = (int) $percent;
		if ($percent >= 100 || $percent < 0) {
			return 'done';
		}
		if ($percent === 0) {
			return 'todo';
		}
		// Any other value set on the event card counts as in progress
		return $percent === 10 ? 'ack' : 'progress';
	}

	/**
	 * Whether a user may change a Heads-up to-do's state: someone it is
	 * assigned to, its sender, anyone who sees everyone's flags, or anyone
	 * allowed to edit every event.
	 *
	 * @param User       $user User
	 * @param ActionComm $ac   Event (fetched with its assignees)
	 * @return bool
	 */
	public static function canSetStatus($user, $ac)
	{
		return isset($ac->userassigned[$user->id])
			|| (int) $ac->authorid === (int) $user->id
			|| $user->hasRight('headsup', 'readall')
			|| $user->hasRight('agenda', 'allactions', 'create');
	}

	/**
	 * Set a Heads-up to-do's state, and add a line to its note saying who did
	 * it and when ("Acknowledged by Alex Martin, 11/11/2026 10:40").
	 *
	 * @param User       $user   User making the change
	 * @param ActionComm $ac     Event (fetched with its assignees)
	 * @param string     $status todo, ack, progress or done
	 * @return int               1 changed, 0 already in that state, <0 error (see $this->error)
	 */
	public function setStatus($user, $ac, $status)
	{
		global $langs;

		$statuses = self::statuses();
		if (!isset($statuses[$status]) || !in_array($ac->code, self::codes(), true)) {
			$this->error = 'Bad status';
			return -1;
		}
		if (self::statusOf($ac->percentage) === $status) {
			return 0;
		}
		$langs->load('headsup@headsup');
		$keys = array('todo' => 'HeadsupReopenedBy', 'ack' => 'HeadsupAckedBy', 'progress' => 'HeadsupInProgressBy', 'done' => 'HeadsupDoneBy');
		$line = $langs->transnoentitiesnoconv($keys[$status], $user->getFullName($langs), dol_print_date(dol_now(), 'dayhour', 'tzuserrel'));
		$note = (string) $ac->note_private;
		if ($note === '' || dol_textishtml($note)) {
			$note .= ($note !== '' ? '<br>' : '').dol_escape_htmltag($line);
		} else {
			$note .= "\n".$line;
		}

		$ac->percentage = $statuses[$status];
		$ac->note_private = $note;
		if ($status === 'done' && empty($ac->datef)) {
			$ac->datef = dol_now();
		}
		if ($ac->update($user) < 0) {
			$this->error = $ac->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Set how far along a Heads-up to-do is (the progress slider). The state
	 * follows the percent: 0 to do, 10 acknowledged, 1 to 99 in progress,
	 * 100 done. A note line is added only when the state changes.
	 *
	 * @param User       $user    User making the change
	 * @param ActionComm $ac      Event (fetched with its assignees)
	 * @param int        $percent 0 to 100
	 * @return int                1 changed, 0 already at that percent, <0 error (see $this->error)
	 */
	public function setProgress($user, $ac, $percent)
	{
		global $langs;

		$percent = (int) $percent;
		if ($percent < 0 || $percent > 100 || !in_array($ac->code, self::codes(), true)) {
			$this->error = 'Bad percent';
			return -1;
		}
		if ((int) $ac->percentage === $percent) {
			return 0;
		}
		$status = self::statusOf($percent);
		if (self::statusOf($ac->percentage) !== $status) {
			$langs->load('headsup@headsup');
			$keys = array('todo' => 'HeadsupReopenedBy', 'ack' => 'HeadsupAckedBy', 'progress' => 'HeadsupInProgressBy', 'done' => 'HeadsupDoneBy');
			$line = $langs->transnoentitiesnoconv($keys[$status], $user->getFullName($langs), dol_print_date(dol_now(), 'dayhour', 'tzuserrel'));
			$note = (string) $ac->note_private;
			if ($note === '' || dol_textishtml($note)) {
				$note .= ($note !== '' ? '<br>' : '').dol_escape_htmltag($line);
			} else {
				$note .= "\n".$line;
			}
			$ac->note_private = $note;
		}

		$ac->percentage = $percent;
		if ($status === 'done' && empty($ac->datef)) {
			$ac->datef = dol_now();
		}
		if ($ac->update($user) < 0) {
			$this->error = $ac->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Where the progress slider snaps: to do, acknowledged, three steps of
	 * in progress, done.
	 *
	 * @return int[]
	 */
	public static function progressPoints()
	{
		return array(0, 10, 25, 50, 75, 100);
	}

	/**
	 * The progress slider for a to-do: one stop per snap point and the state
	 * it lands on. headsup.js updates the state while it moves and saves it
	 * when it is let go.
	 *
	 * @param int       $id      Event id
	 * @param int       $percent Event percent
	 * @param Translate $langs   Language
	 * @param bool      $reload  Reload the page once saved (event card)
	 * @return string            HTML
	 */
	public static function progressSliderHtml($id, $percent, $langs, $reload = false)
	{
		$percent = (int) $percent;
		$value = $percent < 0 ? 100 : min(100, $percent);
		$labels = self::statusLabels();
		$state = $langs->transnoentitiesnoconv($labels[self::statusOf($percent)]);

		// Stop nearest to the stored percent (any percent may be set on the event card)
		$points = self::progressPoints();
		$stop = 0;
		foreach ($points as $i => $point) {
			if (abs($point - $value) < abs($points[$stop] - $value)) {
				$stop = $i;
			}
		}
		$ticks = 'headsup-ticks-'.((int) $id);

		$out = '<span class="headsup-progress-wrap nowrap">';
		$out .= '<input type="range" class="headsup-progress" min="0" max="'.(count($points) - 1).'" step="1" list="'.$ticks.'"';
		$out .= ' value="'.$stop.'" data-points="'.implode(',', $points).'" data-id="'.((int) $id).'" data-percent="'.$value.'"'.($reload ? ' data-reload="1"' : '');
		$out .= ' aria-label="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupProgress')).'"';
		$out .= ' title="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupProgressTitle')).'">';
		$out .= '<datalist id="'.$ticks.'">';
		foreach (array_keys($points) as $i) {
			$out .= '<option value="'.$i.'"></option>';
		}
		$out .= '</datalist>';
		$out .= ' <span class="headsup-progress-label">'.dolPrintLabel($state).' · '.$value.'%</span>';
		$out .= '</span>';
		return $out;
	}

	/**
	 * SQL list of our event codes, quoted.
	 *
	 * @return string
	 */
	public static function codesSql()
	{
		return "'".implode("','", self::codes())."'";
	}

	/**
	 * Create a to-do assigned to one or more users.
	 *
	 * @param User        $user    Who is sending it (becomes the author)
	 * @param string      $code    One of the CODE_* constants
	 * @param int[]       $userids Users it is assigned to; the first is the owner
	 * @param string      $label   Title
	 * @param string      $note    Description (plain text)
	 * @param object|null $object    Record it is about (main link), or null
	 * @param object|null $secondary Another record it relates to (fills project/third party/element if still empty)
	 * @param array<string,mixed> $options 'recordonly' => true: link the record alone, with no third party, contact or
	 *                                     project, so the to-do stays off those records' Events tabs;
	 *                                     'project' => int: the sender's choice, a project id, or -1 for none
	 *                                     (left out or 0: automatic, from the record or the headsupTodoProject hook)
	 * @return int                   Event id, or <0 on error
	 */
	public function createTodo($user, $code, $userids, $label, $note, $object = null, $secondary = null, $options = array())
	{
		$userids = array_values(array_unique(array_filter(array_map('intval', (array) $userids))));
		if (empty($userids)) {
			$this->error = 'NoUser';
			return -1;
		}

		$ac = new ActionComm($this->db);
		$ac->type_code = $code;
		$ac->code = $code;
		$ac->label = dol_trunc($label, 255, 'right', 'UTF-8', 1);
		$ac->note_private = $note;
		$ac->datep = dol_now();
		$ac->fulldayevent = 0;
		$ac->percentage = 0;
		$ac->priority = 0;
		$ac->transparency = 0;
		$ac->userownerid = $userids[0];
		$ac->userassigned = array();
		foreach ($userids as $uid) {
			$ac->userassigned[$uid] = array('id' => $uid, 'transparency' => 0);
		}

		if (is_object($object)) {
			$this->linkToObject($ac, $object);
		}
		if (is_object($secondary) && (!is_object($object) || $secondary->element !== $object->element || (int) $secondary->id !== (int) $object->id)) {
			$this->linkToObject($ac, $secondary, true);
		}
		if (!empty($options['recordonly'])) {
			$ac->socid = 0;
			$ac->fk_project = 0;
			$ac->contact_id = 0;
			$ac->socpeopleassigned = array();
		}

		// Project: the sender's choice, else what another module says, else the record's
		$project = isset($options['project']) ? (int) $options['project'] : 0;
		if ($project === 0) {
			$project = $this->projectFromHooks($code, $userids, $ac, $object, $secondary);
		}
		if ($project < 0) {
			$ac->fk_project = 0;
		} elseif ($project > 0 && $this->projectExists($project)) {
			$ac->fk_project = $project;
		}

		$id = $ac->create($user);
		if ($id <= 0) {
			$this->error = $ac->error;
			$this->errors = $ac->errors;
			return -2;
		}
		return $id;
	}

	/**
	 * Ask other modules which project a new to-do belongs to: hook 'headsupTodoProject',
	 * context 'headsuptodo'. A hook puts 'project' in its results: a project id, or -1 for none.
	 *
	 * @param string      $code      One of the CODE_* constants
	 * @param int[]       $userids   Users it is assigned to
	 * @param ActionComm  $ac        Event being built (fk_project holds the automatic project)
	 * @param object|null $object    Record it is about, or null
	 * @param object|null $secondary Another record it relates to, or null
	 * @return int                   Project id, -1 for none, 0 when no module says
	 */
	private function projectFromHooks($code, $userids, $ac, $object, $secondary)
	{
		global $hookmanager;

		if (!($hookmanager instanceof HookManager)) {
			return 0;
		}
		$hookmanager->initHooks(array('headsuptodo'));
		$parameters = array('code' => $code, 'userids' => $userids, 'secondary' => $secondary, 'project' => (int) $ac->fk_project);
		$hookaction = 'create';
		$hookobject = $object;
		$reshook = $hookmanager->executeHooks('headsupTodoProject', $parameters, $hookobject, $hookaction);
		if ($reshook < 0 || !is_array($hookmanager->resArray) || !isset($hookmanager->resArray['project'])) {
			return 0;
		}
		$project = (int) $hookmanager->resArray['project'];
		return $project < 0 ? -1 : $project;
	}

	/**
	 * Whether a project exists in this entity.
	 *
	 * @param int $id Project id
	 * @return bool
	 */
	private function projectExists($id)
	{
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."projet WHERE rowid = ".((int) $id)." AND entity IN (".getEntity('project').")";
		$resql = $this->db->query($sql);
		return $resql && $this->db->num_rows($resql) > 0;
	}

	/**
	 * Point an event at a record so it shows on that record's Events tab.
	 *
	 * @param ActionComm $ac       Event being built
	 * @param object     $object   Record (Societe, Project, Propal, Commande, ...)
	 * @param bool       $fillOnly Only fill links that are still empty
	 * @return void
	 */
	public function linkToObject($ac, $object, $fillOnly = false)
	{
		$element = isset($object->element) ? $object->element : '';
		$soc = 0;
		$project = 0;
		if ($element === 'societe') {
			$soc = (int) $object->id;
		} elseif ($element === 'project') {
			$project = (int) $object->id;
			$soc = (int) (!empty($object->socid) ? $object->socid : 0);
		} else {
			$project = (int) (!empty($object->fk_project) ? $object->fk_project : 0);
			if (!empty($object->socid)) {
				$soc = (int) $object->socid;
			} elseif (!empty($object->fk_soc)) {
				$soc = (int) $object->fk_soc;
			}
		}

		if ($soc > 0 && (!$fillOnly || empty($ac->socid))) {
			$ac->socid = $soc;
		}
		if ($project > 0 && (!$fillOnly || empty($ac->fk_project))) {
			$ac->fk_project = $project;
		}
		if ($element === 'contact' && (!$fillOnly || empty($ac->contact_id))) {
			$ac->contact_id = (int) $object->id;
			$ac->socpeopleassigned = array((int) $object->id => (int) $object->id);
		}
		if (!in_array($element, array('', 'societe', 'project', 'contact'), true) && (!$fillOnly || empty($ac->elementtype))) {
			// Objects of custom modules are stored as 'myobject@mymodule'
			$mod = (!empty($object->module) && is_string($object->module)) ? $object->module : '';
			if ($mod !== '' && is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$mod) && !is_dir(DOL_DOCUMENT_ROOT.'/'.$mod)) {
				$element .= '@'.$mod;
			}
			$ac->elementtype = $element;
			$ac->elementid = (int) $object->id;
		}
	}

	/**
	 * Load a record from its element name and id, the way Dolibarr links objects.
	 *
	 * @param string $element Element name (societe, project, propal, commande, ...)
	 * @param int    $id      Record id
	 * @return object|null
	 */
	public function fetchRecord($element, $id)
	{
		if (empty($element) || $id <= 0 || !function_exists('fetchObjectByElement')) {
			return null;
		}
		$obj = fetchObjectByElement($id, $element);
		if (is_object($obj) && !empty($obj->id)) {
			return $obj;
		}
		return null;
	}

	/**
	 * Card and tab pages of each kind of record: path pattern => element, and
	 * the URL parameters that may hold its id. 'ref' is always tried too.
	 *
	 * @return array<string,array{0:string,1:string[]}>
	 */
	private static function urlPatterns()
	{
		return array(
			'#^/projet/(card|tasks|element|contact|note|info|messaging|comment|document|ganttview|agenda)\.php$#' => array('project', array('id', 'projectid')),
			'#^/projet/tasks/(task|contact|time|note|document|comment)\.php$#' => array('project_task', array('id')),
			'#^/(societe/(card|contact|note|document|consumption|agenda|messaging|project|notify|price|website|paymentmodes)|comm/card|fourn/card)\.php$#' => array('societe', array('socid', 'id')),
			'#^/contact/(card|note|document|agenda|perso|consumption|messaging)\.php$#' => array('contact', array('id')),
			'#^/comm/propal/(card|contact|note|document|info|agenda|messaging)\.php$#' => array('propal', array('id')),
			'#^/commande/(card|contact|note|document|info|agenda|messaging)\.php$#' => array('commande', array('id')),
			'#^/compta/facture/(card|contact|note|document|info|agenda|messaging|prelevement)\.php$#' => array('facture', array('facid', 'id')),
			'#^/expedition/(card|contact|note|document|info|agenda)\.php$#' => array('shipping', array('id')),
			'#^/reception/(card|contact|note|document|info|agenda)\.php$#' => array('reception', array('id')),
			'#^/fourn/commande/(card|contact|note|document|info|dispatch|agenda)\.php$#' => array('order_supplier', array('id')),
			'#^/fourn/facture/(card|contact|note|document|info|agenda)\.php$#' => array('invoice_supplier', array('facid', 'id')),
			'#^/product/(card|document|note|price|agenda|messaging|fournisseurs|stock/product|stats/card|composition/card)\.php$#' => array('product', array('id')),
			'#^/comm/action/(card|document|info)\.php$#' => array('action', array('id')),
			'#^/fichinter/(card|contact|note|document|info)\.php$#' => array('fichinter', array('id')),
			'#^/contrat/(card|contact|note|document|info|agenda)\.php$#' => array('contrat', array('id')),
			'#^/ticket/(card|contact|agenda|messaging|document)\.php$#' => array('ticket', array('id')),
			'#^/mrp/mo_(card|production|note|document|agenda|movements)\.php$#' => array('mo', array('id')),
			'#^/bom/bom_(card|net_needs|note|document|agenda)\.php$#' => array('bom', array('id')),
		);
	}

	/**
	 * Which record a Dolibarr link points to.
	 *
	 * @param string $href Link from the page (absolute or relative)
	 * @return array{element:string,id:int,ref:string}|null
	 */
	public static function parseRecordUrl($href)
	{
		$parts = parse_url(html_entity_decode((string) $href));
		if ($parts === false || empty($parts['path'])) {
			return null;
		}
		if (!empty($parts['host'])) {
			$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '';
			if (strcasecmp($parts['host'], $host) !== 0) {
				return null;
			}
		}
		$path = $parts['path'];
		// DOL_URL_ROOT is '' or '/subfolder'; with '' this takes nothing off
		$root = (string) DOL_URL_ROOT;
		if (strpos($path, $root.'/') === 0) {
			$path = substr($path, strlen($root));
		}
		$query = array();
		if (!empty($parts['query'])) {
			parse_str($parts['query'], $query);
		}
		// Links that only change something (delete, edit forms) are not "the record"
		if (!empty($query['action']) && !in_array($query['action'], array('view', ''), true)) {
			return null;
		}
		$ref = (isset($query['ref']) && is_string($query['ref'])) ? $query['ref'] : '';

		foreach (self::urlPatterns() as $pattern => $def) {
			if (preg_match($pattern, $path)) {
				foreach ($def[1] as $param) {
					if (!empty($query[$param]) && is_numeric($query[$param])) {
						return array('element' => $def[0], 'id' => (int) $query[$param], 'ref' => '');
					}
				}
				if ($ref !== '') {
					return array('element' => $def[0], 'id' => 0, 'ref' => $ref);
				}
				return null;
			}
		}

		// Custom modules built the usual way: /custom/<module>/<object>_card.php?id=
		if (preg_match('#^/custom/([a-z0-9]+)/([a-z0-9]+)_card\.php$#', $path, $m) && !empty($query['id']) && is_numeric($query['id'])) {
			return array('element' => $m[2].'@'.$m[1], 'id' => (int) $query['id'], 'ref' => '');
		}
		return null;
	}

	/**
	 * The first link, of those given, that leads to a record that exists.
	 *
	 * @param string[] $hrefs Links nearest the click first
	 * @return object|null    The record, with ->element and ->id set
	 */
	public function resolveLinks($hrefs)
	{
		foreach (array_slice((array) $hrefs, 0, 8) as $href) {
			$p = self::parseRecordUrl($href);
			if (!$p) {
				continue;
			}
			$obj = fetchObjectByElement($p['id'], $p['element'], $p['ref']);
			if (is_object($obj) && !empty($obj->id)) {
				return $obj;
			}
		}
		return null;
	}

	/**
	 * Short label for a record: its ref, plus its name or title when it has one.
	 *
	 * @param object $obj Record
	 * @return string
	 */
	public static function recordLabel($obj)
	{
		$ref = '';
		foreach (array('ref', 'name', 'title', 'label') as $prop) {
			if (!empty($obj->$prop) && is_scalar($obj->$prop)) {
				$ref = (string) $obj->$prop;
				break;
			}
		}
		$extra = '';
		foreach (array('title', 'label', 'name') as $prop) {
			if (!empty($obj->$prop) && is_scalar($obj->$prop) && (string) $obj->$prop !== $ref) {
				$extra = (string) $obj->$prop;
				break;
			}
		}
		return trim($ref.($extra !== '' ? ' '.$extra : ''));
	}

	/**
	 * Element name a record is linked by: 'myobject@mymodule' for custom modules.
	 *
	 * @param object $obj Record
	 * @return string
	 */
	public static function elementOf($obj)
	{
		$element = isset($obj->element) ? (string) $obj->element : '';
		$mod = (!empty($obj->module) && is_string($obj->module)) ? $obj->module : '';
		if ($element !== '' && $mod !== '' && is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$mod) && !is_dir(DOL_DOCUMENT_ROOT.'/'.$mod)) {
			$element .= '@'.$mod;
		}
		return $element;
	}

	/**
	 * Kinds of record the Modify form's "Linked object" search looks through: element => module, table,
	 * fields searched, the entity element, the read permissions (any one will do), how core checks
	 * access to one record (checkUserAccessToObject's feature, table, feature2, third party field)
	 * and the name of the kind.
	 *
	 * @return array<string,array{module:string,table:string,search:string[],entity:string,rights:array<int,string[]>,features:string,tableandshare:string,feature2:string,keyfield:string,name:string}>
	 */
	public static function linkTypes()
	{
		return array(
			'project' => array('module' => 'project', 'table' => 'projet', 'search' => array('ref', 'title'), 'entity' => 'project', 'rights' => array(array('projet', 'lire')), 'features' => 'projet', 'tableandshare' => 'projet&project', 'feature2' => '', 'keyfield' => '', 'name' => 'Project'),
			'project_task' => array('module' => 'project', 'table' => 'projet_task', 'search' => array('ref', 'label'), 'entity' => 'project', 'rights' => array(array('projet', 'lire')), 'features' => 'projet', 'tableandshare' => '', 'feature2' => '', 'keyfield' => '', 'name' => 'Task'),
			'societe' => array('module' => 'societe', 'table' => 'societe', 'search' => array('nom', 'name_alias', 'code_client', 'code_fournisseur'), 'entity' => 'societe', 'rights' => array(array('societe', 'lire')), 'features' => 'societe', 'tableandshare' => '&societe', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'ThirdParty'),
			'contact' => array('module' => 'societe', 'table' => 'socpeople', 'search' => array('lastname', 'firstname', 'email'), 'entity' => 'contact', 'rights' => array(array('societe', 'contact', 'lire')), 'features' => 'contact', 'tableandshare' => 'socpeople&societe', 'feature2' => '', 'keyfield' => '', 'name' => 'Contact'),
			'propal' => array('module' => 'propal', 'table' => 'propal', 'search' => array('ref', 'ref_client'), 'entity' => 'propal', 'rights' => array(array('propal', 'lire')), 'features' => 'propal', 'tableandshare' => '', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'Proposal'),
			'commande' => array('module' => 'order', 'table' => 'commande', 'search' => array('ref', 'ref_client'), 'entity' => 'commande', 'rights' => array(array('commande', 'lire')), 'features' => 'commande', 'tableandshare' => '', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'Order'),
			'facture' => array('module' => 'invoice', 'table' => 'facture', 'search' => array('ref', 'ref_client'), 'entity' => 'invoice', 'rights' => array(array('facture', 'lire')), 'features' => 'facture', 'tableandshare' => '', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'Invoice'),
			'order_supplier' => array('module' => 'supplier_order', 'table' => 'commande_fournisseur', 'search' => array('ref', 'ref_supplier'), 'entity' => 'supplier_order', 'rights' => array(array('fournisseur', 'commande', 'lire'), array('supplier_order', 'lire')), 'features' => 'fournisseur', 'tableandshare' => 'commande_fournisseur', 'feature2' => 'commande', 'keyfield' => 'fk_soc', 'name' => 'SupplierOrder'),
			'invoice_supplier' => array('module' => 'supplier_invoice', 'table' => 'facture_fourn', 'search' => array('ref', 'ref_supplier'), 'entity' => 'supplier_invoice', 'rights' => array(array('fournisseur', 'facture', 'lire'), array('supplier_invoice', 'lire')), 'features' => 'fournisseur', 'tableandshare' => 'facture_fourn', 'feature2' => 'facture', 'keyfield' => 'fk_soc', 'name' => 'SupplierInvoice'),
			'shipping' => array('module' => 'expedition', 'table' => 'expedition', 'search' => array('ref', 'ref_customer'), 'entity' => 'expedition', 'rights' => array(array('expedition', 'lire')), 'features' => 'expedition', 'tableandshare' => '', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'Shipment'),
			'fichinter' => array('module' => 'ficheinter', 'table' => 'fichinter', 'search' => array('ref', 'ref_client'), 'entity' => 'intervention', 'rights' => array(array('ficheinter', 'lire')), 'features' => 'ficheinter', 'tableandshare' => 'fichinter', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'Intervention'),
			'contrat' => array('module' => 'contract', 'table' => 'contrat', 'search' => array('ref', 'ref_customer'), 'entity' => 'contract', 'rights' => array(array('contrat', 'lire')), 'features' => 'contrat', 'tableandshare' => '', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'Contract'),
			'ticket' => array('module' => 'ticket', 'table' => 'ticket', 'search' => array('ref', 'subject'), 'entity' => 'ticket', 'rights' => array(array('ticket', 'read')), 'features' => 'ticket', 'tableandshare' => '', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'Ticket'),
			'product' => array('module' => 'product', 'table' => 'product', 'search' => array('ref', 'label'), 'entity' => 'product', 'rights' => array(array('produit', 'lire'), array('service', 'lire')), 'features' => 'produit', 'tableandshare' => 'product&product', 'feature2' => '', 'keyfield' => '', 'name' => 'Product'),
			'mo' => array('module' => 'mrp', 'table' => 'mrp_mo', 'search' => array('ref', 'label'), 'entity' => 'mo', 'rights' => array(array('mrp', 'read')), 'features' => 'mrp', 'tableandshare' => 'mrp_mo', 'feature2' => '', 'keyfield' => 'fk_soc', 'name' => 'ManufacturingOrder'),
		);
	}

	/**
	 * Whether $user may open a record: core's read permission and per-record check.
	 * Records of other kinds (custom modules) need their module's read permission.
	 *
	 * @param User   $user User
	 * @param object $obj  Record, with ->element and ->id
	 * @return bool
	 */
	public function canSeeRecord($user, $obj)
	{
		if (empty($obj->id) || empty($obj->element)) {
			return false;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
		$element = (string) $obj->element;
		$types = self::linkTypes();
		if ($element === 'project_task') {
			// A task is visible with its project
			$allowed = $this->projectsAllowed($user);
			return !empty($obj->fk_project) && isset($allowed[(int) $obj->fk_project]);
		}
		if ($element === 'project') {
			$allowed = $this->projectsAllowed($user);
			if (!isset($allowed[(int) $obj->id])) {
				return false;
			}
		}
		if (isset($types[$element])) {
			$t = $types[$element];
			$canread = false;
			foreach ($t['rights'] as $right) {
				if (call_user_func_array(array($user, 'hasRight'), $right)) {
					$canread = true;
					break;
				}
			}
			// Entity, and third parties the user is not a sales representative of.
			// Not restrictedArea(): before Dolibarr 21 it asks for write access during a save.
			return $canread && checkUserAccessToObject($user, array($t['features']), (int) $obj->id, $t['tableandshare'], $t['feature2'], $t['keyfield'], 'rowid') > 0;
		}
		$prop = getElementProperties(self::elementOf($obj));
		$module = (string) $prop['module'];
		if ($module === '') {
			return false;
		}
		return $user->hasRight($module, 'read') || $user->hasRight($module, (string) $prop['element'], 'read');
	}

	/**
	 * Records $user can see whose ref or name matches $search, newest first, a few of
	 * each kind. A pasted link to a record gives that record alone.
	 *
	 * @param User   $user   User
	 * @param string $search Words, or a Dolibarr link
	 * @param int    $limit  Most to return
	 * @return array<int,array{element:string,id:int,label:string,type:string}>
	 */
	public function searchRecords($user, $search, $limit = 15)
	{
		global $langs;

		$search = trim((string) $search);
		if ($search === '') {
			return array();
		}
		$langs->loadLangs(array('main', 'companies', 'projects', 'propal', 'orders', 'bills', 'products', 'sendings', 'interventions', 'contracts', 'ticket', 'mrp'));
		$types = self::linkTypes();
		$out = array();

		// A pasted link: that record, if the user may see it
		if (preg_match('#^(https?://|/)|\.php\b#i', $search)) {
			$obj = $this->resolveLinks(array($search));
			if ($obj && $this->canSeeRecord($user, $obj)) {
				$out[] = $this->recordChoice($obj, isset($types[$obj->element]) ? $langs->transnoentitiesnoconv($types[$obj->element]['name']) : (string) $obj->element);
			}
			return $out;
		}

		foreach ($types as $element => $t) {
			if (count($out) >= $limit) {
				break;
			}
			$module = $t['module'];
			if ($module === 'product' ? !(isModEnabled('product') || isModEnabled('service')) : !isModEnabled($module)) {
				continue;
			}
			$sql = "SELECT t.rowid";
			$sql .= " FROM ".MAIN_DB_PREFIX.$t['table']." as t";
			$sql .= " WHERE t.entity IN (".getEntity($t['entity']).")";
			$sql .= natural_search(array_map(function ($f) {
				return 't.'.$f;
			}, $t['search']), $search);
			$sql .= " ORDER BY t.rowid DESC";
			$sql .= $this->db->plimit(5);
			$resql = $this->db->query($sql);
			if (!$resql) {
				continue;
			}
			$name = $langs->transnoentitiesnoconv($t['name']);
			while (($row = $this->db->fetch_object($resql)) && count($out) < $limit) {
				$obj = $this->fetchRecord($element, (int) $row->rowid);
				if ($obj && $this->canSeeRecord($user, $obj)) {
					$out[] = $this->recordChoice($obj, $name);
				}
			}
			$this->db->free($resql);
		}
		return $out;
	}

	/**
	 * A record as the "links to" field shows it.
	 *
	 * @param object $obj  Record
	 * @param string $type Name of its kind (Invoice, Project, ...)
	 * @return array{element:string,id:int,label:string,type:string}
	 */
	private function recordChoice($obj, $type)
	{
		return array('element' => self::elementOf($obj), 'id' => (int) $obj->id, 'label' => self::linkLabel($obj), 'type' => $type);
	}

	/**
	 * Name of a linked record: recordLabel(), or a contact's full name (a contact's ref is its id).
	 *
	 * @param object $obj Record
	 * @return string
	 */
	public static function linkLabel($obj)
	{
		global $langs;

		if (isset($obj->element) && $obj->element === 'contact' && method_exists($obj, 'getFullName')) {
			return (string) $obj->getFullName($langs);
		}
		return self::recordLabel($obj);
	}

	/**
	 * The record a to-do is linked to (its event's linked object), or null.
	 *
	 * @param ActionComm $ac Event
	 * @return object|null
	 */
	public function linkedRecord($ac)
	{
		$element = !empty($ac->elementtype) ? (string) $ac->elementtype : '';
		$id = !empty($ac->elementid) ? (int) $ac->elementid : (!empty($ac->fk_element) ? (int) $ac->fk_element : 0);
		return $this->fetchRecord($element, $id);
	}

	/**
	 * Point a saved to-do at another record, or at none, in the database.
	 * ActionComm::update() can set a linked object but never clear one.
	 *
	 * @param ActionComm $ac      Event
	 * @param string     $element Element name, '' for no record
	 * @param int        $id      Record id, 0 for no record
	 * @return int                1 done, <0 error
	 */
	public function setLinkedRecord($ac, $element, $id)
	{
		// Stored the way ActionComm::create() stores them
		$aliases = array('facture' => 'invoice', 'commande' => 'order', 'contrat' => 'contract');
		if (isset($aliases[$element])) {
			$element = $aliases[$element];
		}
		$linked = ($element !== '' && $id > 0);
		$sql = "UPDATE ".MAIN_DB_PREFIX."actioncomm SET";
		$sql .= " elementtype = ".($linked ? "'".$this->db->escape($element)."'" : "NULL");
		$sql .= ", fk_element = ".($linked ? ((int) $id) : "NULL");
		$sql .= " WHERE id = ".((int) $ac->id);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$ac->elementtype = $linked ? $element : '';
		$ac->elementid = $linked ? (int) $id : 0;
		$ac->fk_element = $ac->elementid;
		return 1;
	}

	/**
	 * Number of Heads-up to-dos waiting for a user: assigned to them and
	 * neither acknowledged nor done.
	 *
	 * @param int $userid User id
	 * @return int
	 */
	public function countOpenFor($userid)
	{
		$sql = "SELECT COUNT(DISTINCT a.id) as nb";
		$sql .= " FROM ".MAIN_DB_PREFIX."actioncomm as a";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources as r ON r.fk_actioncomm = a.id AND r.element_type = 'user'";
		$sql .= " WHERE r.fk_element = ".((int) $userid);
		$sql .= " AND a.code IN (".self::codesSql().")";
		$sql .= " AND a.percent = 0";
		$sql .= " AND a.entity IN (".getEntity('agenda').")";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->nb;
		}
		return 0;
	}

	/**
	 * Newest open Heads-up to-do assigned to a user, for the browser notification.
	 *
	 * @param int $userid User id
	 * @return array{id:int,label:string,from:string}|null
	 */
	public function latestOpenFor($userid)
	{
		$sql = "SELECT a.id, a.label, u.firstname, u.lastname, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."actioncomm as a";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources as r ON r.fk_actioncomm = a.id AND r.element_type = 'user'";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = a.fk_user_author";
		$sql .= " WHERE r.fk_element = ".((int) $userid);
		$sql .= " AND a.code IN (".self::codesSql().")";
		$sql .= " AND a.percent >= 0 AND a.percent < 100";
		$sql .= " AND a.entity IN (".getEntity('agenda').")";
		$sql .= " ORDER BY a.id DESC";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$sendername = trim($obj->firstname.' '.$obj->lastname);
			return array('id' => (int) $obj->id, 'label' => (string) $obj->label, 'from' => ($sendername !== '' ? $sendername : (string) $obj->login));
		}
		return null;
	}

	/**
	 * Whether a user may read and join a to-do's conversation: anyone who may
	 * change its state, or who may read every event.
	 *
	 * @param User       $user User
	 * @param ActionComm $ac   Event (fetched with its assignees)
	 * @return bool
	 */
	public static function canComment($user, $ac)
	{
		return self::canSetStatus($user, $ac) || $user->hasRight('agenda', 'allactions', 'read');
	}

	/**
	 * Number of comments on a to-do.
	 *
	 * @param int $id Event id
	 * @return int
	 */
	public function countComments($id)
	{
		$counts = $this->commentCounts(array((int) $id));
		return isset($counts[(int) $id]) ? $counts[(int) $id] : 0;
	}

	/**
	 * Number of comments on each of several to-dos, in one query.
	 *
	 * @param int[] $ids Event ids
	 * @return array<int,int> Event id => number of comments (only those with some)
	 */
	public function commentCounts($ids)
	{
		$out = array();
		$ids = array_filter(array_map('intval', (array) $ids));
		if (!$ids) {
			return $out;
		}
		$sql = "SELECT c.fk_element, COUNT(c.rowid) as nb";
		$sql .= " FROM ".MAIN_DB_PREFIX."comment as c";
		$sql .= " WHERE c.element_type = '".$this->db->escape(self::COMMENT_ELEMENT)."'";
		$sql .= " AND c.fk_element IN (".$this->db->sanitize(implode(',', $ids)).")";
		$sql .= " GROUP BY c.fk_element";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[(int) $obj->fk_element] = (int) $obj->nb;
			}
		}
		return $out;
	}

	/**
	 * A to-do's conversation, oldest first.
	 *
	 * @param int $id Event id
	 * @return array<int,array{id:int,userid:int,name:string,date:int,text:string}>
	 */
	public function fetchComments($id)
	{
		$out = array();
		$sql = "SELECT c.rowid, c.datec, c.description, c.fk_user_author, u.firstname, u.lastname, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."comment as c";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = c.fk_user_author";
		$sql .= " WHERE c.element_type = '".$this->db->escape(self::COMMENT_ELEMENT)."'";
		$sql .= " AND c.fk_element = ".((int) $id);
		$sql .= " ORDER BY c.rowid";
		$sql .= $this->db->plimit(1000);
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$name = trim($obj->firstname.' '.$obj->lastname);
				$out[] = array(
					'id' => (int) $obj->rowid,
					'userid' => (int) $obj->fk_user_author,
					'name' => ($name !== '' ? $name : (string) $obj->login),
					'date' => (int) $this->db->jdate($obj->datec),
					'text' => (string) $obj->description,
				);
			}
		}
		return $out;
	}

	/**
	 * Add a comment to a to-do's conversation (core's comment table, the one task comments use).
	 *
	 * @param User       $user User writing it
	 * @param ActionComm $ac   Event
	 * @param string     $text What they wrote
	 * @return int             Comment id, 0 when empty, <0 on error (see $this->error)
	 */
	public function addComment($user, $ac, $text)
	{
		global $conf;

		$text = trim((string) $text);
		if ($text === '' || !in_array($ac->code, self::codes(), true)) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/class/comment.class.php';
		$comment = new Comment($this->db);
		$comment->description = dol_substr($text, 0, self::COMMENT_MAX);
		$comment->datec = dol_now();
		$comment->fk_element = (int) $ac->id;
		$comment->element_type = self::COMMENT_ELEMENT;
		$comment->fk_user_author = (int) $user->id;
		$comment->entity = (int) $conf->entity;
		// No trigger: core's is TASK_COMMENT_CREATE, which is about project tasks
		$id = $comment->create($user, 1);
		if ($id <= 0) {
			$this->error = $comment->error;
			return -1;
		}
		return $id;
	}

	/**
	 * Delete one comment of a to-do: its writer, or an admin.
	 *
	 * @param User       $user      User deleting it
	 * @param ActionComm $ac        Event
	 * @param int        $commentid Comment id
	 * @return int                  1 deleted, 0 not this to-do's or not allowed, <0 error (see $this->error)
	 */
	public function deleteComment($user, $ac, $commentid)
	{
		require_once DOL_DOCUMENT_ROOT.'/core/class/comment.class.php';
		$comment = new Comment($this->db);
		if ($commentid <= 0 || $comment->fetch($commentid) <= 0) {
			return 0;
		}
		if ((int) $comment->fk_element !== (int) $ac->id || $comment->element_type !== self::COMMENT_ELEMENT) {
			return 0;
		}
		if ((int) $comment->fk_user_author !== (int) $user->id && empty($user->admin)) {
			return 0;
		}
		if ($comment->delete($user, 1) <= 0) {
			$this->error = $comment->error;
			return -1;
		}
		return 1;
	}

	/**
	 * Delete a to-do's whole conversation (when the event itself is deleted).
	 *
	 * @param int $id Event id
	 * @return void
	 */
	public function deleteAllComments($id)
	{
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."comment";
		$sql .= " WHERE element_type = '".$this->db->escape(self::COMMENT_ELEMENT)."' AND fk_element = ".((int) $id);
		$this->db->query($sql);
	}

	/**
	 * Newest comment someone else wrote on a to-do a user sent or is assigned
	 * to, for the browser notification.
	 *
	 * @param int $userid User id
	 * @return array{id:int,todo:int,label:string,from:string}|null
	 */
	public function latestCommentFor($userid)
	{
		$sql = "SELECT c.rowid, a.id, a.label, u.firstname, u.lastname, u.login";
		$sql .= " FROM ".MAIN_DB_PREFIX."comment as c";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm as a ON a.id = c.fk_element";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = c.fk_user_author";
		$sql .= " WHERE c.element_type = '".$this->db->escape(self::COMMENT_ELEMENT)."'";
		$sql .= " AND c.fk_user_author <> ".((int) $userid);
		$sql .= " AND a.code IN (".self::codesSql().")";
		$sql .= " AND a.entity IN (".getEntity('agenda').")";
		$sql .= " AND (a.fk_user_author = ".((int) $userid);
		$sql .= " OR EXISTS (SELECT r.rowid FROM ".MAIN_DB_PREFIX."actioncomm_resources as r";
		$sql .= " WHERE r.fk_actioncomm = a.id AND r.element_type = 'user' AND r.fk_element = ".((int) $userid)."))";
		$sql .= " ORDER BY c.rowid DESC";
		$sql .= $this->db->plimit(1);
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$sendername = trim($obj->firstname.' '.$obj->lastname);
			return array('id' => (int) $obj->rowid, 'todo' => (int) $obj->id, 'label' => (string) $obj->label, 'from' => ($sendername !== '' ? $sendername : (string) $obj->login));
		}
		return null;
	}

	/**
	 * Pattern finding "@login" in a comment: after a space or the start, and not
	 * as the beginning of a longer login ("@sam" is not found in "@sam.smith").
	 *
	 * @param string $login User login
	 * @return string       Regular expression
	 */
	private static function mentionPattern($login)
	{
		return '/(^|[\s(>;,])@'.preg_quote($login, '/').'(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])/i';
	}

	/**
	 * Whether a comment mentions a user ("@login").
	 *
	 * @param string $text  Comment
	 * @param string $login User login
	 * @return bool
	 */
	public static function textMentions($text, $login)
	{
		return $login !== '' && preg_match(self::mentionPattern($login), (string) $text) === 1;
	}

	/**
	 * The active users mentioned in some comments, in one query.
	 *
	 * @param string[] $texts Comments
	 * @return array<string,array{id:int,name:string}> Login => user
	 */
	public function mentionedUsers($texts)
	{
		$out = array();
		$logins = array();
		foreach ($texts as $text) {
			$m = array();
			if (preg_match_all('/(?:^|[\s(>;,])@([A-Za-z0-9_.\-]+)/', (string) $text, $m)) {
				foreach ($m[1] as $word) {
					// "@sam." at the end of a sentence is sam
					$logins[$word] = "'".$this->db->escape($word)."'";
					$logins[rtrim($word, '.-')] = "'".$this->db->escape(rtrim($word, '.-'))."'";
				}
			}
		}
		unset($logins['']);
		if (!$logins) {
			return $out;
		}
		$sql = "SELECT u.rowid, u.login, u.firstname, u.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " WHERE u.statut = 1";
		$sql .= " AND u.entity IN (".getEntity('user').")";
		$sql .= " AND u.login IN (".$this->db->sanitize(implode(',', $logins), 1).")";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$name = trim($obj->firstname.' '.$obj->lastname);
				$out[(string) $obj->login] = array('id' => (int) $obj->rowid, 'name' => ($name !== '' ? $name : (string) $obj->login));
			}
		}
		return $out;
	}

	/**
	 * Show each "@login" of a comment as the person's name.
	 *
	 * @param string                                  $html  Comment, already escaped for output
	 * @param array<string,array{id:int,name:string}> $users Login => user, from mentionedUsers()
	 * @return string                                        HTML
	 */
	public static function formatMentions($html, $users)
	{
		foreach ($users as $login => $mentioned) {
			$name = str_replace(array('\\', '$'), array('\\\\', '\\$'), dol_escape_htmltag($mentioned['name']));
			$html = (string) preg_replace(self::mentionPattern((string) $login), '$1<span class="headsup-mention">@'.$name.'</span>', $html);
		}
		return $html;
	}

	/**
	 * Mentions of a user still waiting for them: on a to-do that is not done,
	 * written by someone else, with no comment of theirs after it.
	 *
	 * @param User $user User
	 * @return array<int,array{id:int,todo:int,label:string,from:string,mention:int}> To-do id => its newest such mention
	 */
	public function openMentionsFor($user)
	{
		$uid = (int) $user->id;
		if (isset($this->openmentions[$uid])) {
			return $this->openmentions[$uid];
		}
		$out = array();
		$login = (string) $user->login;
		if ($login !== '') {
			$sql = "SELECT c.rowid, c.description, a.id, a.label, u.firstname, u.lastname, u.login";
			$sql .= " FROM ".MAIN_DB_PREFIX."comment as c";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm as a ON a.id = c.fk_element";
			$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = c.fk_user_author";
			$sql .= " WHERE c.element_type = '".$this->db->escape(self::COMMENT_ELEMENT)."'";
			$sql .= " AND c.fk_user_author <> ".((int) $uid);
			$sql .= " AND c.description LIKE '".$this->db->escape('%@'.$this->db->escapeforlike($login).'%')."'";
			$sql .= " AND a.code IN (".self::codesSql().")";
			$sql .= " AND a.entity IN (".getEntity('agenda').")";
			$sql .= " AND a.percent >= 0 AND a.percent < 100";
			$sql .= " AND NOT EXISTS (SELECT c2.rowid FROM ".MAIN_DB_PREFIX."comment as c2";
			$sql .= " WHERE c2.element_type = c.element_type AND c2.fk_element = c.fk_element";
			$sql .= " AND c2.fk_user_author = ".((int) $uid)." AND c2.rowid > c.rowid)";
			$sql .= " ORDER BY c.rowid DESC";
			$sql .= $this->db->plimit(200);
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					// LIKE also finds "@sam" in "@samuel": check the whole login
					if (isset($out[(int) $obj->id]) || !self::textMentions($obj->description, $login)) {
						continue;
					}
					$sendername = trim($obj->firstname.' '.$obj->lastname);
					$out[(int) $obj->id] = array('id' => (int) $obj->rowid, 'todo' => (int) $obj->id, 'label' => (string) $obj->label, 'from' => ($sendername !== '' ? $sendername : (string) $obj->login), 'mention' => 1);
				}
			}
		}
		$this->openmentions[$uid] = $out;
		return $out;
	}

	/**
	 * Whether a user was ever mentioned in a to-do's conversation, which lets them read and join it.
	 *
	 * @param User $user User
	 * @param int  $id   Event id
	 * @return bool
	 */
	public function isMentioned($user, $id)
	{
		$login = (string) $user->login;
		if ($login === '') {
			return false;
		}
		$sql = "SELECT c.description FROM ".MAIN_DB_PREFIX."comment as c";
		$sql .= " WHERE c.element_type = '".$this->db->escape(self::COMMENT_ELEMENT)."'";
		$sql .= " AND c.fk_element = ".((int) $id);
		$sql .= " AND c.description LIKE '".$this->db->escape('%@'.$this->db->escapeforlike($login).'%')."'";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				if (self::textMentions($obj->description, $login)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * The number in the top bar bubble: to-dos waiting for a user (countOpenFor),
	 * plus the other to-dos where a mention of them waits for a reply.
	 *
	 * @param User $user User
	 * @return int
	 */
	public function countWaitingFor($user)
	{
		$count = $this->countOpenFor((int) $user->id);
		$mentions = $this->openMentionsFor($user);
		if (!$mentions) {
			return $count;
		}
		// Those already counted: assigned to them and still at to do
		$counted = 0;
		$sql = "SELECT COUNT(DISTINCT a.id) as nb";
		$sql .= " FROM ".MAIN_DB_PREFIX."actioncomm as a";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources as r ON r.fk_actioncomm = a.id AND r.element_type = 'user'";
		$sql .= " WHERE r.fk_element = ".((int) $user->id);
		$sql .= " AND a.percent = 0";
		$sql .= " AND a.id IN (".$this->db->sanitize(implode(',', array_keys($mentions))).")";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			$counted = (int) $obj->nb;
		}
		return $count + count($mentions) - $counted;
	}

	/**
	 * Active users, for the "Assign to" list.
	 *
	 * @return array<int,array{id:int,name:string,login:string}>
	 */
	public function activeUsers()
	{
		$out = array();
		$sql = "SELECT u.rowid, u.login, u.firstname, u.lastname";
		$sql .= " FROM ".MAIN_DB_PREFIX."user as u";
		$sql .= " WHERE u.statut = 1";
		$sql .= " AND u.entity IN (".getEntity('user').")";
		$sql .= " ORDER BY u.firstname, u.lastname, u.login";
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$name = trim($obj->firstname.' '.$obj->lastname);
				$out[] = array('id' => (int) $obj->rowid, 'name' => ($name !== '' ? $name : $obj->login), 'login' => $obj->login);
			}
		}
		return $out;
	}

	/**
	 * Users an admin picked to show as quick choices in the popup.
	 *
	 * @return int[]
	 */
	public function shortlist()
	{
		$ids = array();
		foreach (explode(',', getDolGlobalString('HEADSUP_SHORTLIST')) as $id) {
			if ((int) $id > 0) {
				$ids[] = (int) $id;
			}
		}
		return array_values(array_unique($ids));
	}

	/**
	 * Who problem reports go to by default.
	 *
	 * @return int User id, or 0
	 */
	public function problemUser()
	{
		$id = getDolGlobalInt('HEADSUP_PROBLEM_USER');
		if ($id > 0) {
			return $id;
		}
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE admin = 1 AND statut = 1";
		$sql .= " AND entity IN (0, ".getEntity('user').") ORDER BY rowid LIMIT 1";
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * Build the note: what the user wrote, then where they were.
	 *
	 * Stored as HTML so the page and the linked record are clickable on the event card
	 * (core shows the note through dol_htmlentitiesbr + dol_string_onlythesehtmltags, which keep <a href>).
	 *
	 * @param string $description What the user wrote (plain text)
	 * @param array{page?:string,box?:string,clicked?:string,record?:string,recordurl?:string,clickx?:int,clicky?:int,vw?:int,vh?:int} $context page (path inside Dolibarr), box, clicked, record (label), recordurl, click coordinates
	 * @return string
	 */
	public static function buildNote($description, $context)
	{
		$note = trim((string) $description);
		$note = $note !== '' ? nl2br(dol_escape_htmltag($note, 0, 1), false) : '';
		$lines = array();
		if (!empty($context['page'])) {
			$page = (string) $context['page'];
			$lines[] = 'Page: '.($page[0] === '/' ? '<a href="'.dol_escape_htmltag($page).'">'.dol_escape_htmltag(dol_trunc($page, 120)).'</a>' : dol_escape_htmltag($page));
		}
		if (!empty($context['box'])) {
			$lines[] = 'Box: '.dol_escape_htmltag($context['box']);
		}
		if (!empty($context['clicked'])) {
			$lines[] = 'Clicked: '.dol_escape_htmltag($context['clicked']);
		}
		if (!empty($context['record'])) {
			$label = dol_escape_htmltag($context['record']);
			$url = isset($context['recordurl']) ? (string) $context['recordurl'] : '';
			$lines[] = 'Linked to: '.($url !== '' && $url[0] === '/' ? '<a href="'.dol_escape_htmltag($url).'">'.$label.'</a>' : $label);
		}
		if (isset($context['clickx']) && (int) $context['clickx'] > 0 && isset($context['clicky']) && (int) $context['clicky'] > 0) {
			$lines[] = 'Click: '.((int) $context['clickx']).'px, '.((int) $context['clicky']).'px'
				.((!empty($context['vw']) && !empty($context['vh'])) ? ' (viewport '.((int) $context['vw']).' × '.((int) $context['vh']).')' : '');
		}
		if ($lines) {
			$note .= ($note !== '' ? '<br><br>' : '').'-- Heads-up context --<br>'.implode('<br>', $lines);
		}
		return $note;
	}

	/**
	 * Save a screenshot (base64 data URL) to disk for a to-do.
	 *
	 * @param int    $id   Event id
	 * @param string $data Data URL (data:image/jpeg;base64,...)
	 * @return bool
	 */
	public function saveScreenshot($id, $data)
	{
		global $conf;

		$matches = array();
		if (!preg_match('/^data:image\/(jpeg|png|webp);base64,([A-Za-z0-9+\/]+=*)$/', (string) $data, $matches)) {
			return false;
		}
		$ext = $matches[1] === 'jpeg' ? 'jpg' : $matches[1];
		$decoded = base64_decode($matches[2]);
		if ($decoded === false || strlen($decoded) < 100) {
			return false;
		}
		$dir = DOL_DATA_ROOT.'/headsup/'.(int) $conf->entity.'/'.(int) $id;
		if (!dol_mkdir($dir)) {
			return false;
		}
		$file = $dir.'/screenshot.'.$ext;
		return file_put_contents($file, $decoded) !== false;
	}

	/**
	 * Path on disk to the screenshot for a to-do, or '' if none.
	 *
	 * @param int $id Event id
	 * @return string
	 */
	public function screenshotPath($id)
	{
		global $conf;

		foreach (array('jpg', 'png', 'webp') as $ext) {
			$f = DOL_DATA_ROOT.'/headsup/'.(int) $conf->entity.'/'.(int) $id.'/screenshot.'.$ext;
			if (is_file($f)) {
				return $f;
			}
		}
		return '';
	}

	/**
	 * URL to view the screenshot for a to-do (served by ajax/screenshotview.php).
	 *
	 * @param int $id Event id
	 * @return string
	 */
	public function screenshotUrl($id)
	{
		return dol_buildpath('/headsup/ajax/screenshotview.php', 1).'?id='.((int) $id);
	}

	/**
	 * Save a screenshot and append a link to it in a to-do's note.
	 *
	 * @param User   $user           User who sent the flag
	 * @param int    $id             Event id
	 * @param string $screenshotData Base64 data URL
	 * @return void
	 */
	public function addScreenshotToTodo($user, $id, $screenshotData)
	{
		global $langs;

		if (!$this->saveScreenshot($id, $screenshotData)) {
			return;
		}
		$ac = new ActionComm($this->db);
		if ($ac->fetch((int) $id) <= 0) {
			return;
		}
		$langs->load('headsup@headsup');
		$url = $this->screenshotUrl($id);
		$link = '<a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($langs->transnoentitiesnoconv('HeadsupScreenshotView')).'</a>';
		$note = (string) $ac->note_private;
		if ($note === '' || dol_textishtml($note)) {
			$note .= ($note !== '' ? '<br>' : '').dol_escape_htmltag($langs->transnoentitiesnoconv('HeadsupScreenshot')).': '.$link;
		} else {
			$note .= "\n".$langs->transnoentitiesnoconv('HeadsupScreenshot').': '.$url;
		}
		$ac->note_private = $note;
		$ac->update($user);
	}

	/**
	 * Address of a record's card, taken from its getNomUrl() so custom modules work too.
	 *
	 * @param CommonObject $obj Record
	 * @return string           Path inside Dolibarr, or ''
	 */
	public static function recordUrl($obj)
	{
		if (!is_object($obj) || !method_exists($obj, 'getNomUrl')) {
			return '';
		}
		$html = (string) $obj->getNomUrl(0);
		if (!preg_match('/href="([^"]+)"/', $html, $m)) {
			return '';
		}
		return self::safePageUrl(html_entity_decode($m[1], ENT_QUOTES));
	}

	/**
	 * Split a stored note back into the description and the page link.
	 * Reads both the HTML notes (0.2.2+) and the older plain-text ones.
	 *
	 * @param string $note Stored note
	 * @return array{text:string,page:string,box:string}
	 */
	public static function parseNote($note)
	{
		$note = (string) $note;
		$out = array('text' => $note, 'page' => '', 'box' => '');
		$pos = strpos($note, '-- Heads-up context --');
		$text = $pos === false ? $note : substr($note, 0, $pos);
		$ctx = $pos === false ? '' : substr($note, $pos);
		if (dol_textishtml($note)) {
			$text = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>(\r?\n)?/i', "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if (preg_match('/Page: <a href="([^"]+)"/', $ctx, $m)) {
				$out['page'] = html_entity_decode($m[1], ENT_QUOTES);
			}
			$ctx = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>(\r?\n)?/i', "\n", $ctx)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
		}
		$out['text'] = trim($text);
		if ($out['page'] === '' && preg_match('/^Page: (.+)$/m', $ctx, $m)) {
			$out['page'] = trim($m[1]);
		}
		if (preg_match('/^Box: (.+)$/m', $ctx, $m)) {
			$out['box'] = trim($m[1]);
		}
		return $out;
	}

	/**
	 * Keep only links back into this Dolibarr (no other hosts, no javascript:).
	 *
	 * @param string $url URL from the browser
	 * @return string     Path and query inside Dolibarr, or ''
	 */
	public static function safePageUrl($url)
	{
		$url = trim((string) $url);
		if ($url === '') {
			return '';
		}
		$parts = parse_url($url);
		if ($parts === false) {
			return '';
		}
		if (!empty($parts['host'])) {
			$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '';
			if (strcasecmp($parts['host'], $host) !== 0) {
				return '';
			}
		}
		$path = isset($parts['path']) ? $parts['path'] : '';
		if ($path === '' || $path[0] !== '/') {
			return '';
		}
		return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
	}
}
