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

	/** @var array<string,mixed> */
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
		$count = $hu->countOpenFor($user->id);

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
	 * token and who may change it itself.
	 *
	 * @param array<string,mixed> $parameters  Hook parameters
	 * @param CommonObject        $object      The event, on the event card
	 * @param string              $action      headsup_status
	 * @param HookManager         $hookmanager Hook manager
	 * @return int                             0
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;

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
			'usersUrl' => dol_buildpath('/headsup/ajax/users.php', 1),
			'resolveUrl' => dol_buildpath('/headsup/ajax/resolve.php', 1),
			'projectsUrl' => dol_buildpath('/headsup/ajax/projects.php', 1),
			'projectField' => Headsup::projectField() ? 1 : 0,
			'token' => newToken(),
			'me' => (int) $user->id,
			'canSend' => $user->hasRight('headsup', 'send') ? 1 : 0,
			'problemUser' => $hu->problemUser(),
			'refresh' => max(15, getDolGlobalInt('HEADSUP_REFRESH_SECONDS', 60)),
			'record' => $record,
			'statusLabels' => $statuslabels,
		);
		// Service worker for notification taps (see js/headsup-sw.js); its date in
		// the address makes browsers pick up a new version
		$swfile = dol_buildpath('/headsup/js/headsup-sw.js', 0);
		if ($swfile && is_file($swfile)) {
			$config['swUrl'] = dol_buildpath('/headsup/js/headsup-sw.js', 1).'?v='.filemtime($swfile);
		}

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
