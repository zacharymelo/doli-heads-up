<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/users.php
 * \ingroup headsup
 * \brief   Active users for the "Assign to" list (JSON)
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
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}

dol_include_once('/headsup/class/headsup.class.php');

header('Content-Type: application/json; charset=utf-8');
if (!isModEnabled('headsup') || !$user->hasRight('headsup', 'send')) {
	http_response_code(403);
	print json_encode(array('ok' => 0));
	exit;
}

$hu = new Headsup($db);
print json_encode(array(
	'ok' => 1,
	'users' => $hu->activeUsers(),
	'showAll' => getDolGlobalInt('HEADSUP_USERS_SHOW_ALL') ? 1 : 0,
	'shortlist' => $hu->shortlist(),
));
