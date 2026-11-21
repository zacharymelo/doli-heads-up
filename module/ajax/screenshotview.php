<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/screenshotview.php
 * \ingroup headsup
 * \brief   Serve a screenshot attached to a to-do (authenticated)
 */
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', 1);
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
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
 * @var User   $user
 */
'@phan-var-force DoliDB $db';
'@phan-var-force User $user';

require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
dol_include_once('/headsup/class/headsup.class.php');

if (!isModEnabled('headsup') || empty($user->id)) {
	http_response_code(403);
	exit;
}

$id = GETPOSTINT('id');
if ($id <= 0) {
	http_response_code(400);
	exit;
}

$ac = new ActionComm($db);
if ($ac->fetch($id) <= 0 || !in_array($ac->code, Headsup::codes(), true)) {
	http_response_code(404);
	exit;
}
if (!Headsup::canComment($user, $ac)) {
	http_response_code(403);
	exit;
}

$hu = new Headsup($db);
$path = $hu->screenshotPath($id);
if ($path === '' || !is_readable($path)) {
	http_response_code(404);
	exit;
}

$extraw = dol_strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mimes = array('jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp');
$ct = isset($mimes[$extraw]) ? $mimes[$extraw] : 'image/jpeg';

header('Content-Type: '.$ct);
header('Content-Length: '.((int) filesize($path)));
header('Cache-Control: private, max-age=3600');
readfile($path);
exit;
