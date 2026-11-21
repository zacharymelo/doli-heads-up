<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/tags.php
 * \ingroup headsup
 * \brief   Core's event tag picker (Form::selectCategories) as HTML for the popup (JSON reply)
 */
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
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
 * @var Translate $langs
 * @var User $user
 */
'@phan-var-force DoliDB $db';
'@phan-var-force Translate $langs';
'@phan-var-force User $user';

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';

/**
 * Reply with JSON and stop.
 *
 * @param int                 $status HTTP status
 * @param array<string,mixed> $data   Body
 * @return never
 */
function headsup_tags_reply($status, $data)
{
	http_response_code($status);
	header('Content-Type: application/json; charset=utf-8');
	print json_encode($data);
	exit;
}

if (!isModEnabled('headsup') || !isModEnabled('category')) {
	headsup_tags_reply(403, array('ok' => 0, 'error' => 'Module disabled'));
}
if (!$user->hasRight('headsup', 'send') || !$user->hasRight('categorie', 'lire')) {
	headsup_tags_reply(403, array('ok' => 0, 'error' => 'Not allowed'));
}

$langs->loadLangs(array('categories'));

/*
 * View
 */

$form = new Form($db);
headsup_tags_reply(200, array('ok' => 1, 'html' => $form->selectCategories(Categorie::TYPE_ACTIONCOMM, 'headsup_categories')));
