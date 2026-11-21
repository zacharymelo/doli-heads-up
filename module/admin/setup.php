<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup headsup
 * \brief   Heads-up setup
 */
$res = 0;
if (file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}
/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */
'@phan-var-force Conf $conf';
'@phan-var-force DoliDB $db';
'@phan-var-force Translate $langs';
'@phan-var-force User $user';

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

$langs->loadLangs(array('admin', 'headsup@headsup'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action === 'setproblemuser') {
	$id = GETPOSTINT('HEADSUP_PROBLEM_USER');
	dolibarr_set_const($db, 'HEADSUP_PROBLEM_USER', ($id > 0 ? (string) $id : ''), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action === 'setshortlist') {
	$ids = GETPOST('HEADSUP_SHORTLIST', 'array:int');
	$ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : array()))));
	dolibarr_set_const($db, 'HEADSUP_SHORTLIST', implode(',', $ids), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action === 'setstatuscontrol') {
	$control = GETPOST('HEADSUP_STATUS_CONTROL', 'aZ09');
	if (!in_array($control, array('both', 'slider', 'picker'), true)) {
		$control = 'both';
	}
	dolibarr_set_const($db, 'HEADSUP_STATUS_CONTROL', $control, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}
if ($action === 'setrefresh') {
	$s = max(15, GETPOSTINT('HEADSUP_REFRESH_SECONDS'));
	dolibarr_set_const($db, 'HEADSUP_REFRESH_SECONDS', (string) $s, 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$form = new Form($db);

llxHeader('', $langs->trans('HeadsupSetup'));

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('HeadsupSetup'), $linkback, 'title_setup');

print '<div class="opacitymedium marginbottomonly">'.$langs->trans('HeadsupHowTo').'</div>';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td><td></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('HeadsupNotifyAssignments').'</td>';
print '<td>'.ajax_constantonoff('HEADSUP_NOTIFY_ASSIGNMENTS').'</td>';
print '<td class="opacitymedium">'.$langs->trans('HeadsupNotifyAssignmentsDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('HeadsupProblemUser').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setproblemuser">';
print $form->select_dolusers(getDolGlobalInt('HEADSUP_PROBLEM_USER'), 'HEADSUP_PROBLEM_USER', 1, null, 0, '', '', '0', 0, 0, '', 0, '', 'minwidth200');
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form></td>';
print '<td class="opacitymedium">'.$langs->trans('HeadsupProblemUserDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('HeadsupUsersShowAll').'</td>';
print '<td>'.ajax_constantonoff('HEADSUP_USERS_SHOW_ALL').'</td>';
print '<td class="opacitymedium">'.$langs->trans('HeadsupUsersShowAllDesc').'</td></tr>';

$shortlist = array_filter(array_map('intval', explode(',', getDolGlobalString('HEADSUP_SHORTLIST'))));
print '<tr class="oddeven"><td>'.$langs->trans('HeadsupShortlist').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setshortlist">';
print $form->select_dolusers($shortlist, 'HEADSUP_SHORTLIST', 0, null, 0, '', '', '0', 0, 0, '', 0, '', 'minwidth300', 1, 0, true);
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form></td>';
print '<td class="opacitymedium">'.$langs->trans('HeadsupShortlistDesc').'</td></tr>';

$controls = array('both' => $langs->trans('HeadsupControlBoth'), 'slider' => $langs->trans('HeadsupControlSlider'), 'picker' => $langs->trans('HeadsupControlPicker'));
print '<tr class="oddeven"><td>'.$langs->trans('HeadsupStatusControl').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setstatuscontrol">';
print Form::selectarray('HEADSUP_STATUS_CONTROL', $controls, getDolGlobalString('HEADSUP_STATUS_CONTROL', 'both'), 0, 0, 0, '', 0, 0, 0, '', 'minwidth200', 0);
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form></td>';
print '<td class="opacitymedium">'.$langs->trans('HeadsupStatusControlDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('HeadsupProjectField').'</td>';
print '<td>'.ajax_constantonoff('HEADSUP_PROJECT_FIELD').'</td>';
print '<td class="opacitymedium">'.$langs->trans('HeadsupProjectFieldDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('HeadsupRefreshSeconds').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setrefresh">';
print '<input type="number" name="HEADSUP_REFRESH_SECONDS" min="15" value="'.((int) getDolGlobalInt('HEADSUP_REFRESH_SECONDS', 60)).'" style="width:70px;">';
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form></td>';
print '<td class="opacitymedium">'.$langs->trans('HeadsupRefreshSecondsDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('HeadsupScreenshot').'</td>';
print '<td>'.ajax_constantonoff('HEADSUP_SCREENSHOT').'</td>';
print '<td class="opacitymedium">'.$langs->trans('HeadsupScreenshotDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('DebugMode').'</td>';
print '<td>'.ajax_constantonoff('HEADSUP_DEBUG_MODE').'</td>';
print '<td class="opacitymedium">'.$langs->trans('DebugModeDesc').'</td></tr>';

print '</table>';

llxFooter();
$db->close();
