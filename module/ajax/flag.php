<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/flag.php
 * \ingroup headsup
 * \brief   Save a flag or problem report from the popup (POST, JSON reply)
 */
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', 1);
}

$res = 0;
if (file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}
/**
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */
'@phan-var-force DoliDB $db';
'@phan-var-force HookManager $hookmanager';
'@phan-var-force Translate $langs';
'@phan-var-force User $user';

dol_include_once('/headsup/class/headsup.class.php');

/**
 * Reply with JSON and stop.
 *
 * @param int   $status HTTP status
 * @param array<string,mixed> $data Body
 * @return never
 */
function headsup_reply($status, $data)
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	print json_encode($data);
	exit;
}

if (!isModEnabled('headsup')) {
	headsup_reply(403, array('ok' => 0, 'error' => 'Module disabled'));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	headsup_reply(405, array('ok' => 0, 'error' => 'POST only'));
}
if (!$user->hasRight('headsup', 'send')) {
	headsup_reply(403, array('ok' => 0, 'error' => 'You are not allowed to send flags'));
}

$langs->load('headsup@headsup');

$kind = GETPOST('kind', 'aZ09');
$code = ($kind === 'problem') ? Headsup::CODE_PROBLEM : Headsup::CODE_FLAG;
$userids = GETPOST('users', 'array:int');
$title = trim(GETPOST('title', 'alphanohtml'));
$description = trim(GETPOST('description', 'nohtml'));
$page = Headsup::safePageUrl(GETPOST('page', 'nohtml'));
$box = dol_trunc(trim(GETPOST('box', 'alphanohtml')), 150, 'right', 'UTF-8', 1);
$clicked = dol_trunc(trim(GETPOST('clicked', 'alphanohtml')), 150, 'right', 'UTF-8', 1);
$element = GETPOST('element', 'aZ09arobase');
$elementid = GETPOSTINT('elementid');
$clickedelement = GETPOST('clickedelement', 'aZ09arobase');
$clickedid = GETPOSTINT('clickedid');
$confirmed = GETPOSTINT('confirm');
$project = GETPOSTINT('project');	// the sender's choice: a project id, -1 for none, 0 automatic
$clickx = GETPOSTINT('clickx');
$clicky = GETPOSTINT('clicky');
$vw = GETPOSTINT('vw');
$vh = GETPOSTINT('vh');
// Screenshot: only accepted when the feature is enabled; validated below
$screenshotData = '';

if ($title === '') {
	headsup_reply(400, array('ok' => 0, 'error' => 'A title is needed'));
}
if (empty($userids)) {
	headsup_reply(400, array('ok' => 0, 'error' => 'Choose at least one person'));
}

// Only active users of this entity
$hu = new Headsup($db);
$valid = array();
foreach ($hu->activeUsers() as $u) {
	$valid[$u['id']] = true;
}
$userids = array_values(array_filter(array_map('intval', $userids), function ($id) use ($valid) {
	return isset($valid[$id]);
}));
if (empty($userids)) {
	headsup_reply(400, array('ok' => 0, 'error' => 'Choose at least one active user'));
}

// A project the sender chose must be one they can see
if ($project > 0) {
	$allowed = $hu->projectsAllowed($user);
	if (!isset($allowed[$project])) {
		headsup_reply(403, array('ok' => 0, 'error' => 'You cannot use that project'));
	}
} elseif ($project < 0) {
	$project = -1;
}

// The record on the page, and the record that was clicked (a link, or a link in the clicked row).
// The clicked one is what the user meant, so it is the main link; the page's record fills the rest.
$record = null;
if ($element !== '' && $elementid > 0) {
	$record = $hu->fetchRecord($element, $elementid);
}
$clickedrecord = null;
if ($clickedelement !== '' && $clickedid > 0) {
	$clickedrecord = $hu->fetchRecord($clickedelement, $clickedid);
}

$mainrecord = $clickedrecord ? $clickedrecord : $record;
$secondaryrecord = $clickedrecord ? $record : null;

// Other modules can check the flag before it is saved (context 'headsupflag', hook
// 'headsupBeforeFlag'), for example whether the people chosen can open the record:
// - 'warnings' (string[]): shown to the sender, who must confirm before it is saved
// - 'recordonly' (bool): link the record alone, without its third party, contact or project
$options = array();
if (is_object($hookmanager)) {
	$hookmanager->initHooks(array('headsupflag'));
	$parameters = array('userids' => $userids, 'secondary' => $secondaryrecord, 'confirmed' => $confirmed, 'project' => $project);
	$hookaction = 'flag';
	$reshook = $hookmanager->executeHooks('headsupBeforeFlag', $parameters, $mainrecord, $hookaction);
	if ($reshook < 0) {
		headsup_reply(400, array('ok' => 0, 'error' => $hookmanager->error ? $hookmanager->error : 'Refused'));
	}
	$res = is_array($hookmanager->resArray) ? $hookmanager->resArray : array();
	if (!empty($res['warnings']) && !$confirmed) {
		headsup_reply(200, array('ok' => 0, 'confirm' => implode("\n", (array) $res['warnings'])));
	}
	if (!empty($res['recordonly'])) {
		$options['recordonly'] = true;
	}
}

if ($project !== 0) {
	$options['project'] = $project;
}

if (getDolGlobalInt('HEADSUP_SCREENSHOT') > 0) {
	$raw = GETPOST('screenshot', 'none');
	if (is_string($raw) && preg_match('/^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+\/]+=*$/', $raw) && strlen($raw) <= 2000000) {
		$screenshotData = $raw;
	}
}

$context = array('page' => $page, 'box' => $box, 'clicked' => $clicked);
if ($screenshotData !== '' && $clickx > 0 && $clicky > 0) {
	$context['clickx'] = $clickx;
	$context['clicky'] = $clicky;
	$context['vw'] = $vw;
	$context['vh'] = $vh;
}
if ($clickedrecord) {
	$context['record'] = Headsup::recordLabel($clickedrecord).' ('.$clickedelement.' #'.((int) $clickedrecord->id).')';
	$context['recordurl'] = Headsup::recordUrl($clickedrecord);
}
$note = Headsup::buildNote($description, $context);

$id = $hu->createTodo($user, $code, $userids, $title, $note, $mainrecord, $secondaryrecord, $options);
if ($id <= 0) {
	headsup_reply(500, array('ok' => 0, 'error' => $hu->error ? $hu->error : 'Could not save'));
}

if ($screenshotData !== '') {
	$hu->addScreenshotToTodo($user, $id, $screenshotData);
}

headsup_reply(200, array('ok' => 1, 'id' => $id, 'count' => $hu->countWaitingFor($user)));
