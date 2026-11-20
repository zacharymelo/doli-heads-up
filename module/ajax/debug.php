<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/debug.php
 * \ingroup headsup
 * \brief   Diagnostics. Admins only, and only with Debug mode on in setup.
 *
 * Modes (?mode=): overview (default), events, settings, all
 */
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
 * @var Conf $conf
 * @var DoliDB $db
 * @var User $user
 */
'@phan-var-force Conf $conf';
'@phan-var-force DoliDB $db';
'@phan-var-force User $user';

if (!$user->admin) {
	http_response_code(403);
	print 'Admin only';
	exit;
}
if (!getDolGlobalInt('HEADSUP_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode is off. Turn it on in Heads-up setup.';
	exit;
}

dol_include_once('/headsup/class/headsup.class.php');
header('Content-Type: text/plain; charset=utf-8');

$mode = GETPOST('mode', 'aZ09') ?: 'overview';
$all = ($mode === 'all');

print "=== HEADSUP DEBUG ===\n";
print "Time: ".dol_print_date(dol_now(), 'dayhourlog')."\n";
print "Dolibarr: ".DOL_VERSION."\n";
print "Usage: ?mode=overview|events|settings|all\n\n";

if ($mode === 'overview' || $all) {
	print "--- MODULE ---\n";
	print "isModEnabled('headsup'): ".(isModEnabled('headsup') ? 'YES' : 'NO')."\n";
	foreach (array('hooks', 'triggers', 'js', 'css') as $part) {
		$found = array();
		if (!empty($conf->modules_parts[$part])) {
			foreach ((array) $conf->modules_parts[$part] as $k => $v) {
				if (stripos($k, 'headsup') !== false || stripos(print_r($v, true), 'headsup') !== false) {
					$found[] = $k.' => '.(is_array($v) ? implode(', ', $v) : $v);
				}
			}
		}
		print "  $part: ".($found ? implode(' | ', $found) : '(not registered)')."\n";
	}

	print "\n--- EVENT TYPES (llx_c_actioncomm) ---\n";
	$resql = $db->query("SELECT id, code, type, libelle, module, active FROM ".MAIN_DB_PREFIX."c_actioncomm WHERE code IN (".Headsup::codesSql().")");
	while ($resql && ($o = $db->fetch_object($resql))) {
		print "  [$o->id] $o->code type=$o->type module=$o->module active=$o->active ($o->libelle)\n";
	}

	print "\n--- TRIGGER ---\n";
	$f = dol_buildpath('/headsup/core/triggers/interface_99_modHeadsup_HeadsupTrigger.class.php');
	print "  file: ".(file_exists($f) ? 'EXISTS' : 'MISSING')."\n";

	$hu = new Headsup($db);
	print "\n--- YOU ---\n";
	print "  open to-dos for you: ".$hu->countOpenFor($user->id)."\n";
	print "  problem reports go to user id: ".$hu->problemUser()."\n\n";
}

if ($mode === 'events' || $all) {
	print "--- LAST 30 HEADS-UP EVENTS ---\n";
	$sql = "SELECT a.id, a.code, a.label, a.percent, a.datep, a.fk_user_author, a.fk_soc, a.fk_project, a.elementtype, a.fk_element,";
	$sql .= " (SELECT GROUP_CONCAT(r.fk_element) FROM ".MAIN_DB_PREFIX."actioncomm_resources as r WHERE r.fk_actioncomm = a.id AND r.element_type = 'user') as users";
	$sql .= " FROM ".MAIN_DB_PREFIX."actioncomm as a WHERE a.code IN (".Headsup::codesSql().") ORDER BY a.id DESC LIMIT 30";
	$resql = $db->query($sql);
	while ($resql && ($o = $db->fetch_object($resql))) {
		print "  [$o->id] $o->code {$o->percent}% author=$o->fk_user_author to=$o->users soc=$o->fk_soc project=$o->fk_project element=$o->elementtype:$o->fk_element  $o->label\n";
	}
	print "\n";
}

if ($mode === 'settings' || $all) {
	print "--- SETTINGS ---\n";
	$resql = $db->query("SELECT name, value FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'HEADSUP%' OR name LIKE 'MAIN_MODULE_HEADSUP%' ORDER BY name");
	while ($resql && ($o = $db->fetch_object($resql))) {
		print "  $o->name = ".dol_trunc($o->value, 120)."\n";
	}
	print "\n";
}

print "=== END ===\n";
