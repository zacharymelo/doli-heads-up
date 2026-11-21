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
if ($action === 'setpopuplayout') {
	$raw = GETPOST('HEADSUP_POPUP_LAYOUT', 'none');
	$layout = json_decode($raw ?: '[]', true);
	$validkeys = array('title', 'description', 'screenshot', 'tags', 'project');
	$clean = array();
	$seen = array();
	foreach ((array) $layout as $item) {
		if (!is_array($item) || !isset($item['key'], $item['fold'])) {
			continue;
		}
		if (!in_array($item['key'], $validkeys, true) || !in_array($item['fold'], array('above', 'below'), true)) {
			continue;
		}
		if (in_array($item['key'], $seen, true)) {
			continue;
		}
		$clean[] = array('key' => $item['key'], 'fold' => $item['fold']);
		$seen[] = $item['key'];
	}
	dolibarr_set_const($db, 'HEADSUP_POPUP_LAYOUT', json_encode($clean), 'chaine', 0, '', $conf->entity);
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

dol_include_once('/headsup/class/headsup.class.php');
$layout = Headsup::popupLayout();
$allfields = array(
	'title'       => $langs->trans('HeadsupFieldTitle'),
	'description' => $langs->trans('HeadsupFieldDescription'),
	'screenshot'  => $langs->trans('HeadsupFieldScreenshot'),
	'tags'        => $langs->trans('HeadsupFieldTags'),
	'project'     => $langs->trans('HeadsupFieldProject'),
);
$aboveItems = array_values(array_filter($layout, function ($i) {
	return $i['fold'] === 'above';
}));
$belowItems = array_values(array_filter($layout, function ($i) {
	return $i['fold'] === 'below';
}));

print '<br>';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.dol_escape_htmltag($langs->trans('HeadsupPopupLayout')).'</td></tr>';
print '<tr class="oddeven"><td colspan="2">';
print '<p class="opacitymedium">'.dol_escape_htmltag($langs->trans('HeadsupPopupLayoutDesc')).'</p>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" id="headsup-layout-form" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="setpopuplayout">';
print '<input type="hidden" name="HEADSUP_POPUP_LAYOUT" id="headsup-layout-data">';
print '<ul id="headsup-layout-list" class="headsup-layout-list">';
foreach ($aboveItems as $item) {
	$key = $item['key'];
	if (!isset($allfields[$key])) {
		continue;
	}
	print '<li class="headsup-layout-item" data-key="'.dol_escape_htmltag($key).'" draggable="true">';
	print '<span class="headsup-layout-handle" aria-hidden="true">&#8942;</span>';
	print '<span class="headsup-layout-name">'.dol_escape_htmltag($allfields[$key]).'</span>';
	print '<span class="headsup-layout-badge headsup-layout-above">'.dol_escape_htmltag($langs->trans('HeadsupAboveFold')).'</span>';
	print '</li>';
}
print '<li class="headsup-layout-sep" data-key="_fold" draggable="true">';
print '<span class="headsup-layout-sep-label">'.dol_escape_htmltag($langs->trans('HeadsupFoldSep')).'</span>';
print '</li>';
foreach ($belowItems as $item) {
	$key = $item['key'];
	if (!isset($allfields[$key])) {
		continue;
	}
	print '<li class="headsup-layout-item" data-key="'.dol_escape_htmltag($key).'" draggable="true">';
	print '<span class="headsup-layout-handle" aria-hidden="true">&#8942;</span>';
	print '<span class="headsup-layout-name">'.dol_escape_htmltag($allfields[$key]).'</span>';
	print '<span class="headsup-layout-badge headsup-layout-below">'.dol_escape_htmltag($langs->trans('HeadsupBelowFold')).'</span>';
	print '</li>';
}
print '</ul>';
print '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Save')).'">';
print '</form>';
print '</td></tr>';
print '</table>';

print '<script nonce="'.getNonce().'">
(function () {
	var list = document.getElementById(\'headsup-layout-list\');
	var input = document.getElementById(\'headsup-layout-data\');
	if (!list || !input) { return; }
	var dragging = null;
	var placeholder = null;

	function updateBadges() {
		var pastFold = false;
		for (var i = 0; i < list.children.length; i++) {
			var item = list.children[i];
			if (item === placeholder) { continue; }
			if (item.dataset.key === \'_fold\') { pastFold = true; continue; }
			var badge = item.querySelector(\'.headsup-layout-badge\');
			if (badge) {
				badge.className = \'headsup-layout-badge \' + (pastFold ? \'headsup-layout-below\' : \'headsup-layout-above\');
				badge.textContent = pastFold ? \''.addslashes($langs->trans('HeadsupBelowFold')).'\' : \''.addslashes($langs->trans('HeadsupAboveFold')).'\';
			}
		}
	}

	function updateInput() {
		var result = [];
		var pastFold = false;
		for (var i = 0; i < list.children.length; i++) {
			var item = list.children[i];
			if (item === placeholder) { continue; }
			if (item.dataset.key === \'_fold\') { pastFold = true; continue; }
			if (item.dataset.key) {
				result.push({ key: item.dataset.key, fold: pastFold ? \'below\' : \'above\' });
			}
		}
		input.value = JSON.stringify(result);
	}

	list.addEventListener(\'dragstart\', function (e) {
		var target = e.target.closest(\'[draggable="true"]\');
		if (!target || target.parentNode !== list) { return; }
		dragging = target;
		dragging.classList.add(\'headsup-layout-dragging\');
		e.dataTransfer.effectAllowed = \'move\';
		e.dataTransfer.setData(\'text/plain\', \'\');
		placeholder = document.createElement(\'li\');
		placeholder.className = \'headsup-layout-placeholder\';
	});

	list.addEventListener(\'dragover\', function (e) {
		e.preventDefault();
		e.dataTransfer.dropEffect = \'move\';
		if (!dragging || !placeholder) { return; }
		var target = e.target.closest(\'li\');
		if (!target || target === dragging || target === placeholder || target.parentNode !== list) { return; }
		var rect = target.getBoundingClientRect();
		var midY = rect.top + rect.height / 2;
		if (e.clientY < midY) {
			if (target.previousSibling !== placeholder) { list.insertBefore(placeholder, target); }
		} else {
			if (target.nextSibling !== placeholder) { list.insertBefore(placeholder, target.nextSibling); }
		}
	});

	list.addEventListener(\'drop\', function (e) {
		e.preventDefault();
		if (dragging && placeholder && placeholder.parentNode === list) {
			list.insertBefore(dragging, placeholder);
			placeholder.parentNode.removeChild(placeholder);
			placeholder = null;
		}
	});

	list.addEventListener(\'dragend\', function () {
		if (dragging) {
			dragging.classList.remove(\'headsup-layout-dragging\');
			dragging = null;
		}
		if (placeholder && placeholder.parentNode) {
			placeholder.parentNode.removeChild(placeholder);
			placeholder = null;
		}
		updateBadges();
		updateInput();
	});

	updateInput();
})();
</script>';

llxFooter();
$db->close();
