<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/resolve.php
 * \ingroup headsup
 * \brief   Which record the clicked link (or its row) points to, for the popup preview (JSON)
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

$hrefs = GETPOST('links', 'array:nohtml');
$hu = new Headsup($db);
$obj = $hu->resolveLinks(is_array($hrefs) ? $hrefs : array());
if (!$obj) {
	print json_encode(array('ok' => 1, 'record' => null));
	exit;
}

$langs->loadLangs(array('main', 'companies', 'projects', 'propal', 'orders', 'bills', 'products'));
print json_encode(array('ok' => 1, 'record' => array(
	'element' => (string) $obj->element.((!empty($obj->module) && is_string($obj->module) && is_dir(DOL_DOCUMENT_ROOT.'/custom/'.$obj->module) && !is_dir(DOL_DOCUMENT_ROOT.'/'.$obj->module)) ? '@'.$obj->module : ''),
	'id' => (int) $obj->id,
	'label' => Headsup::recordLabel($obj),
)));
