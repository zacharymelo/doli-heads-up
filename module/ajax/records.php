<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/records.php
 * \ingroup headsup
 * \brief   Search the records the user can see, for the "Linked object" field of a to-do's Modify form (JSON)
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

header('Content-Type: application/json; charset=utf-8');
// Whoever may modify events (the field is on the event's Modify form)
if (!isModEnabled('headsup') || !($user->hasRight('agenda', 'myactions', 'create') || $user->hasRight('agenda', 'allactions', 'create'))) {
	http_response_code(403);
	print json_encode(array('ok' => 0));
	exit;
}

$search = dol_trunc(trim(GETPOST('q', 'alphanohtml')), 200, 'right', 'UTF-8', 1);

$hu = new Headsup($db);
print json_encode(array(
	'ok' => 1,
	'records' => $hu->searchRecords($user, $search, 15),
));
