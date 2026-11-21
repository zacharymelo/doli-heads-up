<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    class/actions_headsup.class.php
 * \ingroup headsup
 * \brief   Hooks: flag icon in the top bar, and page context for the popup
 */

/**
 * Hooks for the 'main' context, which every logged-in page has.
 */
class ActionsHeadsup
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var array<int|string,mixed> */
	public $results = array();

	/** @var string */
	public $resprints = '';

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Flag icon (pick mode) and open count, in the top bar.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param object              $object      Unused
	 * @param string              $action      Unused
	 * @param HookManager         $hookmanager Hook manager
	 * @return int               0
	 */
	public function printTopRightMenu($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if (!isModEnabled('headsup') || empty($user->id)) {
			return 0;
		}
		$langs->load('headsup@headsup');

		dol_include_once('/headsup/class/headsup.class.php');
		$hu = new Headsup($this->db);
		$count = $hu->countWaitingFor($user);

		// One icon, built like core's own top bar icons (print, help) so it lines up with them,
		// with the open count in a bubble. Clicking it opens a small menu: pick mode, or the queue.
		$queueurl = dol_buildpath('/headsup/queue.php', 1);
		$out = '<div class="inline-block headsup-top">';
		$out .= '<div class="inline-block login_block_elem" style="padding: 0px; padding-right: 2px;">';
		$out .= '<a href="'.$queueurl.'" class="headsup-badge'.($count > 0 ? '' : ' headsup-badge-zero').'" title="'.dol_escape_htmltag($langs->trans('HeadsupMenu')).'" aria-haspopup="true" aria-expanded="false">';
		$out .= '<span class="fa fa-flag atoplogin valignmiddle"></span>';
		$out .= '<span class="headsup-count">'.((int) $count).'</span></a>';
		$out .= '</div>';
		$out .= '<div class="headsup-menu" hidden>';
		if ($user->hasRight('headsup', 'send')) {
			// A to-do about something outside Dolibarr (a call, an email still to come): nothing to click on
			$out .= '<a href="'.dolPrintHTMLForAttributeUrl($queueurl.'?newtodo=1').'" class="headsup-new headsup-menu-item"><span class="fa fa-plus-circle pictofixedwidth"></span>'.dol_escape_htmltag($langs->trans('HeadsupNewTodo')).'</a>';
			$out .= '<a href="#" class="headsup-pick headsup-menu-item"><span class="fa fa-crosshairs pictofixedwidth"></span>'.dol_escape_htmltag($langs->trans('HeadsupPickMenu')).'<span class="headsup-menu-hint">Alt+click</span></a>';
		}
		$out .= '<a href="'.$queueurl.'" class="headsup-menu-item"><span class="fa fa-inbox pictofixedwidth"></span>'.dol_escape_htmltag($langs->trans('HeadsupMyQueue')).' <span class="headsup-menu-count">('.((int) $count).')</span></a>';
		$out .= '</div>';
		$out .= '</div>';

		$this->resprints = $out;
		return 0;
	}

	/**
	 * Whether $object is a Heads-up to-do the user may move between states.
	 *
	 * @param object $object Page object
	 * @return bool
	 */
	private function isHeadsupTodo($object)
	{
		global $user;

		if (!isModEnabled('headsup') || empty($user->id) || !($object instanceof ActionComm) || empty($object->id)) {
			return false;
		}
		dol_include_once('/headsup/class/headsup.class.php');
		return in_array($object->code, Headsup::codes(), true) && Headsup::canSetStatus($user, $object);
	}

	/**
	 * Event card: the one-click Acknowledge / Mark done / Back to to-do links.
	 * Runs before core's own actions (and its access check), so it checks the
	 * token and who may change it itself. On the Modify form's save, it also
	 * prepares a change of linked record (see prepareLink()).
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject        $object      The event, on the event card
	 * @param string              $action      headsup_status, or update
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                             0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if ($action === 'update' && $object instanceof ActionComm && GETPOSTINT('headsup_relink') && !GETPOST('cancel', 'alpha')) {
			return $this->prepareLink($object);
		}
		if ($action !== 'headsup_status' || !($object instanceof ActionComm) || !$this->isHeadsupTodo($object)) {
			return 0;
		}
		$cardurl = DOL_URL_ROOT.'/comm/action/card.php?id='.((int) $object->id);
		if (GETPOST('token', 'alpha') !== currentToken()) {
			setEventMessages($langs->trans('ErrorBadValueForParameter', 'token', 'token'), null, 'errors');
			header('Location: '.$cardurl);
			exit;
		}
		$langs->load('headsup@headsup');
		$hu = new Headsup($this->db);
		$status = GETPOST('status', 'aZ09');
		$res = $hu->setStatus($user, $object, $status);
		if ($res < 0) {
			setEventMessages($hu->error, null, 'errors');
		} elseif ($res > 0) {
			$labels = Headsup::statusLabels();
			setEventMessages($langs->trans($labels[$status]), null, 'mesgs');
		}
		header('Location: '.$cardurl);
		exit;
	}

	/**
	 * Modify form saved with a record (a first one, or another) in Heads-up's "Linked object" field: check
	 * the user may see that record, then let core's update carry it. The record's third
	 * party and project (none, when it has none) and, for a contact, the contact go into
	 * the form's own fields, unless the user changed those fields in the same form; the trigger on ACTION_MODIFY stores the linked
	 * record itself, since ActionComm::update() can set one but never clear one.
	 * A line in the description says who changed it and when.
	 *
	 * @param ActionComm $ac The event, as stored (core fetched it before the hooks)
	 * @return int           0 (core's update goes on)
	 */
	private function prepareLink($ac)
	{
		global $user, $langs;

		if (!isModEnabled('headsup') || empty($ac->id)) {
			return 0;
		}
		dol_include_once('/headsup/class/headsup.class.php');
		if (!in_array($ac->code, Headsup::codes(), true)) {
			return 0;
		}
		$langs->load('headsup@headsup');
		$hu = new Headsup($this->db);

		$element = GETPOST('headsup_link_element', 'aZ09arobase');
		$id = GETPOSTINT('headsup_link_id');
		$record = null;
		if ($element !== '' && $id > 0) {
			$record = $hu->fetchRecord($element, $id);
			if (!$record || !$hu->canSeeRecord($user, $record)) {
				// The rest of the form still saves; the link stays as it was
				setEventMessages($langs->trans('HeadsupLinkNotAllowed'), null, 'errors');
				return 0;
			}
		}

		$old = $hu->linkedRecord($ac);
		// A record that no longer exists is still a link, which removing must clear
		$oldelement = $old ? Headsup::elementOf($old) : (!empty($ac->elementtype) ? (string) $ac->elementtype : '');
		$oldid = $old ? (int) $old->id : (!empty($ac->elementtype) ? (int) $ac->elementid : 0);
		$oldlabel = $old ? Headsup::linkLabel($old) : (!empty($ac->elementtype) ? $ac->elementtype.' #'.((int) $ac->elementid) : '');

		// Third parties, projects and contacts are the event's own fields, not a linked object
		$isfield = $record && in_array($record->element, array('societe', 'project', 'contact'), true);
		$newelement = ($record && !$isfield) ? Headsup::elementOf($record) : '';
		$newid = ($record && !$isfield) ? (int) $record->id : 0;
		if ($newelement === $oldelement && $newid === $oldid && !$isfield) {
			return 0;
		}
		if ($newelement !== $oldelement || $newid !== $oldid) {
			Headsup::$pendingLinks[(int) $ac->id] = array('element' => $newelement, 'id' => $newid);
		}

		if ($record) {
			$links = new ActionComm($this->db);
			$hu->linkToObject($links, $record);
			// As a new flag on that record would have them: the old ones came from the old record
			if (max(0, GETPOSTINT('socid')) === max(0, (int) $ac->socid)) {
				$_POST['socid'] = (string) max(0, (int) $links->socid);
			}
			if (isModEnabled('project') && max(0, GETPOSTINT('projectid')) === max(0, (int) $ac->fk_project)) {
				$_POST['projectid'] = (string) max(0, (int) $links->fk_project);
			}
			if (!empty($links->contact_id)) {
				$posted = array_map('intval', (array) GETPOST('socpeopleassigned', 'array'));
				$stored = array_map('intval', array_keys((array) $ac->socpeopleassigned));
				sort($posted);
				sort($stored);
				if ($posted === $stored) {
					$_POST['socpeopleassigned'] = array((string) $links->contact_id);
					$_POST['contactid'] = (string) $links->contact_id;
				}
			}
		}

		// Say who changed it and when, like the state changes
		$who = $user->getFullName($langs);
		$when = dol_print_date(dol_now(), 'dayhour', 'tzuserrel');
		if ($record) {
			$line = $langs->transnoentitiesnoconv('HeadsupLinkedBy', Headsup::linkLabel($record), $who, $when);
		} else {
			$line = $langs->transnoentitiesnoconv('HeadsupUnlinkedBy', $oldlabel, $who, $when);
		}
		if (GETPOSTISSET('note')) {
			$note = (string) GETPOST('note', 'restricthtml');
			if (trim($note) === '' || dol_textishtml($note)) {
				$note .= (trim($note) !== '' ? '<br>' : '').dol_escape_htmltag($line);
			} else {
				$note .= "\n".$line;
			}
			$_POST['note'] = $note;
		}
		return 0;
	}

	/**
	 * Event card, Modify form: on Heads-up to-dos, a "Linked object" field to link the to-do
	 * to a record after it is sent (it may have none yet) or change it: search by ref or name,
	 * or paste a record's link, or remove the link.
	 * headsup.js puts it in place of core's read-only row.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param ?object             $object      The event
	 * @param string              $action      edit
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                             0 (core's extra fields still shown)
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->resprints = '';
		if ($action !== 'edit' || !isModEnabled('headsup') || !($object instanceof ActionComm) || empty($object->id)) {
			return 0;
		}
		dol_include_once('/headsup/class/headsup.class.php');
		if (!in_array($object->code, Headsup::codes(), true)) {
			return 0;
		}
		$langs->loadLangs(array('headsup@headsup', 'agenda'));
		$hu = new Headsup($this->db);
		$current = null;
		$rec = $hu->linkedRecord($object);
		if ($rec) {
			$current = array('element' => Headsup::elementOf($rec), 'id' => (int) $rec->id, 'label' => Headsup::linkLabel($rec));
		} elseif (!empty($object->elementtype) && !empty($object->elementid)) {
			// A record that no longer exists, or of a module that is off
			$current = array('element' => (string) $object->elementtype, 'id' => (int) $object->elementid, 'label' => $object->elementtype.' #'.((int) $object->elementid));
		}
		$i18n = array(
			'search' => $langs->transnoentitiesnoconv('HeadsupLinkSearch'),
			'nothing' => $langs->transnoentitiesnoconv('HeadsupLinkNothing'),
			'remove' => $langs->transnoentitiesnoconv('HeadsupLinkRemove'),
			'noMatch' => $langs->transnoentitiesnoconv('HeadsupLinkNoMatch'),
			'undo' => $langs->transnoentitiesnoconv('HeadsupLinkUndo'),
		);
		$json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

		$out = '<tr class="headsup-relink-row"><td>'.dol_escape_htmltag($langs->trans('LinkedObject')).'</td><td colspan="3">';
		$out .= '<div class="headsup-relink" data-url="'.dol_escape_htmltag(dol_buildpath('/headsup/ajax/records.php', 1)).'"';
		$out .= ' data-current="'.dol_escape_htmltag(json_encode($current, $json)).'" data-i18n="'.dol_escape_htmltag(json_encode($i18n, $json)).'">';
		$out .= '<input type="hidden" name="headsup_relink" value="0">';
		$out .= '<input type="hidden" name="headsup_link_element" value="'.dol_escape_htmltag($current ? $current['element'] : '').'">';
		$out .= '<input type="hidden" name="headsup_link_id" value="'.($current ? (int) $current['id'] : 0).'">';
		// Without JavaScript the field shows what it links to and changes nothing
		$out .= '<div class="headsup-chosen">'.($current ? '<span class="headsup-chip">'.dol_escape_htmltag($current['label']).'</span>' : '').'</div>';
		$out .= '</div>';
		$out .= '<div class="opacitymedium small headsup-relink-help">'.dol_escape_htmltag($langs->trans('HeadsupLinkHelp')).'</div>';
		$out .= '</td></tr>';
		$this->resprints = $out;
		return 0;
	}

	/**
	 * Event card: the progress slider and/or a "Status: To do" dropdown (setup's status
	 * control) next to Modify, on Heads-up to-dos only; the dropdown lists the other states.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject        $object      The event, on the event card
	 * @param string              $action      Current action
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                             0 (core buttons still shown)
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		if ($action === 'edit' || !($object instanceof ActionComm) || !$this->isHeadsupTodo($object)) {
			return 0;
		}
		$langs->load('headsup@headsup');
		$labels = Headsup::statusLabels();
		$current = Headsup::statusOf($object->percentage);
		$base = '/comm/action/card.php?id='.((int) $object->id).'&action=headsup_status&token='.newToken().'&status=';
		$items = array();
		foreach ($labels as $status => $labelkey) {
			if ($status !== $current) {
				$items[] = array('url' => $base.$status, 'label' => $labelkey, 'lang' => 'headsup@headsup', 'enabled' => true, 'perm' => 1);
			}
		}
		$title = $langs->trans('HeadsupStatusButton', $langs->transnoentitiesnoconv($labels[$current]));
		$control = Headsup::statusControl();
		if ($control !== 'picker') {
			print Headsup::progressSliderHtml((int) $object->id, (int) $object->percentage, $langs, true);
		}
		if ($control !== 'slider') {
			// No tooltip: it only repeats the button text and covers the first menu item
			print dolGetButtonAction('', $title, 'default', $items, 'headsup-status', 1);
		}
		return 0;
	}

	/**
	 * Event card tabs: a Conversation tab, with the number of comments, on Heads-up to-dos only.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters: mode (add, remove), type (not before v21), head
	 * @param ?object             $object      The record whose tabs are built
	 * @param string              $action      Unused
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                             0 (the tab in $this->results is added after the others)
	 */
	public function completeTabsHead($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		// Core does not reset results between hooks, and calls this again in 'remove' mode
		$this->results = array();
		if ((isset($parameters['mode']) && $parameters['mode'] !== 'add') || (isset($parameters['type']) && $parameters['type'] !== 'action')) {
			return 0;
		}
		if (!isModEnabled('headsup') || !($object instanceof ActionComm) || empty($object->id)) {
			return 0;
		}
		dol_include_once('/headsup/class/headsup.class.php');
		if (!in_array($object->code, Headsup::codes(), true)) {
			return 0;
		}
		$langs->load('headsup@headsup');
		$hu = new Headsup($this->db);
		$nb = $hu->countComments((int) $object->id);
		$label = $langs->trans('HeadsupConversation');
		if ($nb > 0) {
			$label .= '<span class="badge marginleftonlyshort">'.$nb.'</span>';
		}
		$this->results = array(array(dol_buildpath('/headsup/comments.php', 1).'?id='.((int) $object->id), $label, 'headsup'));
		return 0;
	}

	/**
	 * Settings and the record on this page, for headsup.js.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param ?object             $object      Unused (null here; the page's record is the global $object)
	 * @param string              $action      Unused
	 * @param HookManager         $hookmanager Hook manager
	 * @return int               0
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

		if (!isModEnabled('headsup') || empty($user->id) || (isset($parameters['zone']) && $parameters['zone'] !== 'private')) {
			return 0;
		}

		dol_include_once('/headsup/class/headsup.class.php');
		$hu = new Headsup($this->db);

		$record = null;
		$pageobject = isset($GLOBALS['object']) ? $GLOBALS['object'] : null;
		// Only when this page's own address is that record's card or tab. List pages
		// reuse $object for each row, which would otherwise look like "the page's record".
		$pageurl = Headsup::parseRecordUrl(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
		$ispage = false;
		if ($pageurl && is_object($pageobject) && !empty($pageobject->id) && !empty($pageobject->element)) {
			$urlelement = preg_replace('/@.*$/', '', $pageurl['element']);
			$ispage = ($urlelement === $pageobject->element)
				&& (($pageurl['id'] > 0 && (int) $pageurl['id'] === (int) $pageobject->id)
					|| ($pageurl['id'] == 0 && $pageurl['ref'] !== '' && isset($pageobject->ref) && $pageurl['ref'] === (string) $pageobject->ref));
		}
		if ($ispage && is_numeric($pageobject->id)) {
			$name = '';
			foreach (array('ref', 'name', 'title', 'label') as $prop) {
				if (!empty($pageobject->$prop) && is_scalar($pageobject->$prop)) {
					$name = (string) $pageobject->$prop;
					break;
				}
			}
			$record = array(
				'element' => (string) $pageobject->element,
				'id' => (int) $pageobject->id,
				'label' => $name,
			);
		}

		// State names for the progress slider's label
		$langs->load('headsup@headsup');
		$statuslabels = array();
		foreach (Headsup::statusLabels() as $statuskey => $labelkey) {
			$statuslabels[$statuskey] = $langs->transnoentitiesnoconv($labelkey);
		}

		$config = array(
			'flagUrl' => dol_buildpath('/headsup/ajax/flag.php', 1),
			'queueUrl' => dol_buildpath('/headsup/ajax/queue.php', 1),
			'queuePageUrl' => dol_buildpath('/headsup/queue.php', 1),
			'commentsPageUrl' => dol_buildpath('/headsup/comments.php', 1),
			'usersUrl' => dol_buildpath('/headsup/ajax/users.php', 1),
			'resolveUrl' => dol_buildpath('/headsup/ajax/resolve.php', 1),
			'projectsUrl' => dol_buildpath('/headsup/ajax/projects.php', 1),
			'projectField' => Headsup::projectField() ? 1 : 0,
			'screenshotEnabled' => getDolGlobalInt('HEADSUP_SCREENSHOT') > 0 ? 1 : 0,
			'tagsUrl' => (isModEnabled('category') && $user->hasRight('categorie', 'lire')) ? dol_buildpath('/headsup/ajax/tags.php', 1) : '',
			'popupLayout' => Headsup::popupLayout(),
			'token' => newToken(),
			'me' => (int) $user->id,
			'canSend' => $user->hasRight('headsup', 'send') ? 1 : 0,
			'problemUser' => $hu->problemUser(),
			'refresh' => max(15, getDolGlobalInt('HEADSUP_REFRESH_SECONDS', 60)),
			'record' => $record,
			'statusLabels' => $statuslabels,
		);

		$this->resprints = '<script nonce="'.getNonce().'">window.HEADSUP = '.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>'."\n";

		// Dolibarr links module stylesheets with no version in the address, so after
		// an update browsers (Safari above all) keep using the old copy. Link it again
		// with the file's date, so each deploy is fetched fresh. Same rules, so the
		// second copy changes nothing once the cache has caught up.
		$cssfile = dol_buildpath('/headsup/css/headsup.css', 0);
		if ($cssfile && is_file($cssfile)) {
			$this->resprints .= '<link rel="stylesheet" type="text/css" href="'.dol_escape_htmltag(dol_buildpath('/headsup/css/headsup.css', 1).'?v='.filemtime($cssfile)).'">'."\n";
		}
		return 0;
	}
}
