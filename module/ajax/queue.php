<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/queue.php
 * \ingroup headsup
 * \brief   GET: open count for the top bar. POST action=status (todo, ack, done), action=progress (percent), or action=done.
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
 * @var User $user
 */
'@phan-var-force DoliDB $db';
'@phan-var-force User $user';

dol_include_once('/headsup/class/headsup.class.php');
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';

header('Content-Type: application/json; charset=utf-8');
if (!isModEnabled('headsup')) {
	http_response_code(403);
	print json_encode(array('ok' => 0));
	exit;
}

$hu = new Headsup($db);

// POST action=done (kept for older pages), action=status&status=todo|ack|done, or action=progress&percent=0..100
$postaction = $_SERVER['REQUEST_METHOD'] === 'POST' ? GETPOST('action', 'aZ09') : '';
if ($postaction === 'done' || $postaction === 'status' || $postaction === 'progress') {
	$status = $postaction === 'done' ? 'done' : GETPOST('status', 'aZ09');
	$id = GETPOSTINT('id');
	$ac = new ActionComm($db);
	if ($id <= 0 || $ac->fetch($id) <= 0 || !in_array($ac->code, Headsup::codes(), true)) {
		http_response_code(404);
		print json_encode(array('ok' => 0, 'error' => 'Not found'));
		exit;
	}
	if (!Headsup::canSetStatus($user, $ac)) {
		http_response_code(403);
		print json_encode(array('ok' => 0, 'error' => 'Not yours'));
		exit;
	}
	if ($postaction === 'progress') {
		$res = $hu->setProgress($user, $ac, GETPOSTINT('percent'));
	} else {
		$res = $hu->setStatus($user, $ac, $status);
	}
	if ($res < 0) {
		http_response_code(400);
		print json_encode(array('ok' => 0, 'error' => $hu->error));
		exit;
	}
	print json_encode(array('ok' => 1, 'status' => Headsup::statusOf($ac->percentage), 'percent' => (int) $ac->percentage, 'count' => $hu->countWaitingFor($user)));
	exit;
}

// Newest comment to tell them about: one on their own to-dos, or one that mentions them
$latestcomment = $hu->latestCommentFor($user->id);
foreach ($hu->openMentionsFor($user) as $mention) {
	if (!$latestcomment || $mention['id'] >= $latestcomment['id']) {
		$latestcomment = $mention;
	}
	break;
}

print json_encode(array('ok' => 1, 'count' => $hu->countWaitingFor($user), 'latest' => $hu->latestOpenFor($user->id), 'latestComment' => $latestcomment));
