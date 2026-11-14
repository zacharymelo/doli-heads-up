<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    queue.php
 * \ingroup headsup
 * \brief   My queue: flags, problem reports and assignments for me, and
 *          everything currently assigned to me.
 */
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
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

require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
require_once DOL_DOCUMENT_ROOT.'/projet/class/task.class.php';
require_once DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php';
require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
dol_include_once('/headsup/class/headsup.class.php');

$langs->loadLangs(array('headsup@headsup', 'projects', 'companies', 'propal', 'orders', 'agenda'));

if (!isModEnabled('headsup')) {
	accessforbidden();
}

$view = GETPOST('view', 'aZ09');
if (!in_array($view, array('me', 'sent', 'done', 'all'), true)) {
	$view = 'me';
}
if ($view === 'all' && !$user->hasRight('headsup', 'readall')) {
	accessforbidden();
}
$showdone = GETPOSTINT('showdone');

// Completed: every finished to-do, a page at a time
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : (int) $conf->liste_limit;
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;
$nbtotalofrecords = 0;

$now = dol_now();
$hu = new Headsup($db);

/**
 * "today", "1 day", "12 days"
 *
 * @param int $ts Timestamp
 * @return string
 */
function headsup_age($ts)
{
	if (empty($ts)) {
		return '';
	}
	$days = (int) floor((dol_now() - $ts) / 86400);
	if ($days <= 0) {
		return 'today';
	}
	return $days.' '.($days == 1 ? 'day' : 'days');
}

/** @var array<string,object|false> $recordcache */
$recordcache = array();

/**
 * Link to the record an event is about.
 *
 * @param DoliDB $db  Database
 * @param object $row Event row
 * @return string
 */
function headsup_record_link($db, $row)
{
	global $recordcache;

	$candidates = array();
	if (!empty($row->elementtype) && !empty($row->fk_element)) {
		$candidates[] = array($row->elementtype, (int) $row->fk_element);
	}
	if (!empty($row->fk_project)) {
		$candidates[] = array('project', (int) $row->fk_project);
	}
	if (!empty($row->fk_soc)) {
		$candidates[] = array('societe', (int) $row->fk_soc);
	}
	// Every record the to-do is linked to: the clicked one first, then its project and third party
	$out = array();
	foreach ($candidates as $c) {
		$key = $c[0].':'.$c[1];
		if (!array_key_exists($key, $recordcache)) {
			$obj = fetchObjectByElement($c[1], $c[0]);
			$recordcache[$key] = (is_object($obj) && !empty($obj->id)) ? $obj : false;
		}
		if ($recordcache[$key] && method_exists($recordcache[$key], 'getNomUrl')) {
			$out[] = $recordcache[$key]->getNomUrl(1);
		}
	}
	return implode('<br>', $out);
}

// ---------------------------------------------------------------------------
// Heads-up to-dos
// ---------------------------------------------------------------------------

$sqlfrom = " FROM ".MAIN_DB_PREFIX."actioncomm as a";
$sqlfrom .= " LEFT JOIN ".MAIN_DB_PREFIX."user as ua ON ua.rowid = a.fk_user_author";
$sqlfrom .= " LEFT JOIN ".MAIN_DB_PREFIX."user as um ON um.rowid = a.fk_user_mod";
if ($view === 'me' || $view === 'done') {
	$sqlfrom .= " INNER JOIN ".MAIN_DB_PREFIX."actioncomm_resources as r ON r.fk_actioncomm = a.id AND r.element_type = 'user' AND r.fk_element = ".((int) $user->id);
}
$sqlfrom .= " WHERE a.code IN (".Headsup::codesSql().")";
$sqlfrom .= " AND a.entity IN (".getEntity('agenda').")";
if ($view === 'sent') {
	$sqlfrom .= " AND a.fk_user_author = ".((int) $user->id);
}
if ($view === 'done') {
	$sqlfrom .= " AND (a.percent >= 100 OR a.percent < 0)";
} elseif ($showdone) {
	$sqlfrom .= " AND (a.percent < 100 OR a.tms >= '".$db->idate($now - 30 * 86400)."')";
} else {
	$sqlfrom .= " AND a.percent >= 0 AND a.percent < 100";
}

if ($view === 'done') {
	$resql = $db->query("SELECT COUNT(DISTINCT a.id) as nb".$sqlfrom);
	if ($resql && ($obj = $db->fetch_object($resql))) {
		$nbtotalofrecords = (int) $obj->nb;
	}
	if ($offset > $nbtotalofrecords) {
		$page = 0;
		$offset = 0;
	}
}

$sql = "SELECT DISTINCT a.id, a.code, a.label, a.note, a.datep, a.tms, a.percent, a.fk_user_author,";
$sql .= " a.fk_soc, a.fk_project, a.elementtype, a.fk_element,";
$sql .= " ua.firstname as a_firstname, ua.lastname as a_lastname, ua.login as a_login,";
$sql .= " um.firstname as m_firstname, um.lastname as m_lastname, um.login as m_login";
$sql .= $sqlfrom;
if ($view === 'done') {
	// Most recently finished first (the event's last change)
	$sql .= " ORDER BY a.tms DESC, a.id DESC";
	$sql .= $db->plimit($limit + 1, $offset);
} else {
	$sql .= " ORDER BY a.percent >= 100, a.datep DESC";
	$sql .= " LIMIT 500";
}

$events = array();
$num = 0;
$resql = $db->query($sql);
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$num++;
		// Completed: the extra row only tells the page bar there is a next page
		if ($view === 'done' && $num > $limit) {
			break;
		}
		$events[(int) $obj->id] = $obj;
	}
} else {
	dol_print_error($db);
}

// Who each one is assigned to (for "sent" and "all")
$assignees = array();
if ($events && $view !== 'me') {
	$sql = "SELECT r.fk_actioncomm, u.firstname, u.lastname, u.login";
	$sql .= " FROM ".MAIN_DB_PREFIX."actioncomm_resources as r";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."user as u ON u.rowid = r.fk_element";
	$sql .= " WHERE r.element_type = 'user' AND r.fk_actioncomm IN (".implode(',', array_keys($events)).")";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$n = trim($obj->firstname.' '.$obj->lastname);
			$assignees[(int) $obj->fk_actioncomm][] = ($n !== '' ? $n : $obj->login);
		}
	}
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------

$titles = array('me' => 'HeadsupForMe', 'sent' => 'HeadsupSentTitle', 'done' => 'HeadsupCompletedTitle', 'all' => 'HeadsupAllTitle');
llxHeader('', $langs->trans('HeadsupMyQueue'), '', '', 0, 0, '', '', '', 'mod-headsup page-queue');

// Tabs: open for me, sent by me, my completed ones, everyone's
$head = array();
$head[] = array($_SERVER['PHP_SELF'].'?view=me', $langs->trans('HeadsupMyQueue'), 'me');
$head[] = array($_SERVER['PHP_SELF'].'?view=sent', $langs->trans('HeadsupSent'), 'sent');
$head[] = array($_SERVER['PHP_SELF'].'?view=done', $langs->trans('HeadsupCompleted'), 'done');
if ($user->hasRight('headsup', 'readall')) {
	$head[] = array($_SERVER['PHP_SELF'].'?view=all', $langs->trans('HeadsupAllOpen'), 'all');
}
print dol_get_fiche_head($head, $view, '', 1);

// New to-do: about something outside Dolibarr, so nothing to click on (headsup.js opens the popup)
$newtodobutton = '';
if ($user->hasRight('headsup', 'send')) {
	$newtodobutton = dolGetButtonAction('', $langs->trans('HeadsupNewTodo'), 'default', $_SERVER['PHP_SELF'].'?view='.$view.'&newtodo=1', 'headsup-new');
}

if ($view === 'done') {
	// The page size picker submits the form around it
	$param = '&view=done'.($limit != (int) $conf->liste_limit ? '&limit='.((int) $limit) : '');
	print '<form method="GET" action="'.dolPrintHTMLForAttribute($_SERVER['PHP_SELF']).'">';
	print '<input type="hidden" name="view" value="done">';
	print_barre_liste($langs->trans($titles[$view]), $page, $_SERVER['PHP_SELF'], $param, '', '', '', $num, $nbtotalofrecords, 'fa-check', 0, $newtodobutton, '', $limit, 0, 0, 1);
	print '</form>';
} else {
	$toggleurl = $_SERVER['PHP_SELF'].'?view='.$view.($showdone ? '' : '&showdone=1');
	$morehtml = $newtodobutton.'<a class="butAction" href="'.dol_escape_htmltag($toggleurl).'">'.$langs->trans($showdone ? 'HeadsupHideDone' : 'HeadsupShowDone').'</a>';
	print load_fiche_titre($langs->trans($titles[$view]).' <span class="opacitymedium">('.count(array_filter($events, function ($e) {
		return $e->percent < 100;
	})).')</span>', $morehtml, 'fa-flag');
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent'.($view === 'done' ? ' headsup-completed' : '').'" id="headsup-events">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('HeadsupType').'</th>';
print '<th>'.$langs->trans('HeadsupWhat').'</th>';
print '<th>'.$langs->trans('HeadsupRecord').'</th>';
print '<th>'.$langs->trans(($view === 'me' || $view === 'done') ? 'HeadsupFrom' : 'HeadsupTo').'</th>';
print '<th class="right">'.$langs->trans($view === 'done' ? 'HeadsupFinished' : 'HeadsupAge').'</th>';
print '<th class="right"></th>';
print '</tr>';

if (!$events) {
	print '<tr class="oddeven"><td colspan="6"><span class="opacitymedium">'.$langs->trans($view === 'done' ? 'HeadsupNothingDone' : 'HeadsupNothingOpen').'</span></td></tr>';
}

$typelabels = array(
	Headsup::CODE_FLAG => array('fa-flag', 'ActionAC_HU_FLAG'),
	Headsup::CODE_PROBLEM => array('fa-bug', 'ActionAC_HU_PROBLEM'),
	Headsup::CODE_ASSIGN => array('fa-user-plus', 'ActionAC_HU_ASSIGN'),
);
$cardurl = DOL_URL_ROOT.'/comm/action/card.php?id=';
$statuscontrol = Headsup::statusControl();
$statusoptions = array();
foreach (Headsup::statusLabels() as $statuskey => $labelkey) {
	$statusoptions[$statuskey] = $langs->trans($labelkey);
}

foreach ($events as $ev) {
	$done = ($ev->percent >= 100);
	$parsed = Headsup::parseNote($ev->note);
	$datep = $db->jdate($ev->datep);
	$tl = isset($typelabels[$ev->code]) ? $typelabels[$ev->code] : array('fa-flag', $ev->code);

	print '<tr class="oddeven'.($done ? ' headsup-done-row' : '').'" data-id="'.((int) $ev->id).'">';
	print '<td class="nowrap headsup-col-type">'.img_picto('', $tl[0], 'class="pictofixedwidth"').$langs->trans($tl[1]).'</td>';

	print '<td class="tdoverflowmax500 headsup-col-what">';
	print '<a href="'.$cardurl.((int) $ev->id).'"><strong>'.dol_escape_htmltag($ev->label).'</strong></a>';
	if ($parsed['text'] !== '') {
		print '<div class="headsup-note">'.dol_htmlentitiesbr(dol_trunc($parsed['text'], 400)).'</div>';
	}
	if ($parsed['page'] !== '' && $parsed['page'][0] === '/') {
		print '<div class="headsup-note"><a href="'.dol_escape_htmltag($parsed['page']).'">'.img_picto('', 'fa-external-link-alt', 'class="pictofixedwidth"').$langs->trans('HeadsupOpenPage').'</a>';
		if ($parsed['box'] !== '') {
			print ' · '.dol_escape_htmltag($parsed['box']);
		}
		print '</div>';
	}
	print '</td>';

	print '<td class="tdoverflowmax200 headsup-col-record">'.headsup_record_link($db, $ev).'</td>';

	if ($view === 'me' || $view === 'done') {
		$from = trim($ev->a_firstname.' '.$ev->a_lastname);
		print '<td class="tdoverflowmax150 headsup-col-who" data-label="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupFrom')).'">'.dol_escape_htmltag($from !== '' ? $from : (string) $ev->a_login).'</td>';
	} else {
		print '<td class="tdoverflowmax200 headsup-col-who" data-label="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupTo')).'">'.dol_escape_htmltag(implode(', ', isset($assignees[$ev->id]) ? $assignees[$ev->id] : array())).'</td>';
	}

	if ($view === 'done') {
		// When it was finished (its last change) and by whom
		$finished = $db->jdate($ev->tms);
		$by = trim($ev->m_firstname.' '.$ev->m_lastname);
		if ($by === '') {
			$by = (string) $ev->m_login;
		}
		print '<td class="right headsup-col-age" data-label="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupFinished')).'" title="'.dol_escape_htmltag(dol_print_date($finished, 'dayhour', 'tzuserrel')).'">';
		print '<span class="nowrap">'.dol_print_date($finished, 'day', 'tzuserrel').'</span>';
		if ($by !== '') {
			print ' <span class="opacitymedium nowrap">'.$langs->trans('HeadsupFinishedBy', dol_escape_htmltag($by)).'</span>';
		}
		print '</td>';
	} else {
		print '<td class="right nowrap headsup-col-age" title="'.dol_escape_htmltag(dol_print_date($datep, 'dayhour')).'">'.headsup_age($datep).'</td>';
	}

	// Slider, then the status picker under it (or one of them, per setup)
	print '<td class="right headsup-col-status"><div class="headsup-status-stack">';
	$current = Headsup::statusOf($ev->percent);
	$moreparam = 'data-id="'.((int) $ev->id).'" data-status="'.dol_escape_htmltag($current).'" aria-label="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupStatusOf')).'"';
	if ($statuscontrol !== 'picker') {
		print Headsup::progressSliderHtml((int) $ev->id, (int) $ev->percent, $langs);
	}
	if ($statuscontrol !== 'slider') {
		print Form::selectarray('headsupstatus'.((int) $ev->id), $statusoptions, $current, 0, 0, 0, $moreparam, 0, 0, 0, '', 'flat headsup-status-select', 0);
	}
	print '</div>';
	print '</td>';
	print '</tr>';
}
print '</table>';
print '</div>';

// ---------------------------------------------------------------------------
// Everything currently assigned to me (read from the records themselves)
// ---------------------------------------------------------------------------

if ($view === 'me') {
	print '<br>';
	print load_fiche_titre($langs->trans('HeadsupAssignedToMe'), '', 'fa-user-check');

	$uid = (int) $user->id;
	$contactjoin = function ($alias, $element) use ($uid) {
		return " INNER JOIN ".MAIN_DB_PREFIX."element_contact as ec ON ec.element_id = ".$alias.".rowid AND ec.fk_socpeople = ".$uid
			." INNER JOIN ".MAIN_DB_PREFIX."c_type_contact as tc ON tc.rowid = ec.fk_c_type_contact AND tc.source = 'internal' AND tc.element = '".$element."'";
	};
	$rolelabel = function ($element, $code, $libelle) use ($langs) {
		$key = 'TypeContact_'.$element.'_internal_'.$code;
		$t = $langs->trans($key);
		return ($t === $key) ? $libelle : $t;
	};

	print '<div class="fichecenter"><div class="fichehalfleft">';

	// Leads and projects
	$sql = "SELECT p.rowid, p.ref, p.title, p.fk_soc, p.usage_opportunity, p.fk_opp_status, ls.code as opp_code, ls.label as opp_label, tc.code as role_code, tc.libelle as role_label,";
	$sql .= " (SELECT MAX(a.datep) FROM ".MAIN_DB_PREFIX."actioncomm as a WHERE a.fk_project = p.rowid AND a.code NOT LIKE '%AUTO' AND a.code NOT IN (".Headsup::codesSql().")) as lastact";
	$sql .= " FROM ".MAIN_DB_PREFIX."projet as p";
	$sql .= $contactjoin('p', 'project');
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."c_lead_status as ls ON ls.rowid = p.fk_opp_status";
	$sql .= " WHERE p.fk_statut = 1 AND p.entity IN (".getEntity('project').")";
	$sql .= " ORDER BY p.usage_opportunity DESC, p.datec DESC LIMIT 200";
	$resql = $db->query($sql);
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent headsup-mine"><tr class="liste_titre"><th colspan="2">'.$langs->trans('HeadsupMyLeads').'</th><th>'.$langs->trans('Status').'</th><th class="right">'.$langs->trans('LastAction').'</th></tr>';
	$n = 0;
	if ($resql) {
		$p = new Project($db);
		while ($obj = $db->fetch_object($resql)) {
			$n++;
			$p->id = $obj->rowid;
			$p->ref = $obj->ref;
			$p->title = $obj->title;
			$status = '';
			if ($obj->usage_opportunity && $obj->opp_code) {
				$k = 'OppStatus'.$obj->opp_code;
				$status = $langs->trans($k) !== $k ? $langs->trans($k) : $obj->opp_label;
			}
			$last = $db->jdate($obj->lastact);
			print '<tr class="oddeven"><td class="nowrap headsup-mine-ref">'.$p->getNomUrl(1).'</td>';
			print '<td class="tdoverflowmax200 headsup-mine-title">'.dol_escape_htmltag($obj->title).' <span class="opacitymedium">· '.dol_escape_htmltag($rolelabel('project', $obj->role_code, $obj->role_label)).'</span></td>';
			print '<td class="nowrap headsup-mine-side">'.dol_escape_htmltag($status).'</td>';
			print '<td class="right nowrap headsup-mine-side">'.($last ? headsup_age($last) : '<span class="opacitymedium">'.$langs->trans('None').'</span>').'</td></tr>';
		}
	}
	if (!$n) {
		print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('HeadsupNone').'</span></td></tr>';
	}
	print '</table></div><br>';

	// Tasks
	$sql = "SELECT t.rowid, t.ref, t.label, t.progress, t.dateo, t.datee, t.fk_projet, tc.code as role_code, tc.libelle as role_label";
	$sql .= " FROM ".MAIN_DB_PREFIX."projet_task as t";
	$sql .= $contactjoin('t', 'project_task');
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."projet as p ON p.rowid = t.fk_projet AND p.fk_statut = 1";
	$sql .= " WHERE (t.progress IS NULL OR t.progress < 100) AND t.entity IN (".getEntity('project').")";
	$sql .= " ORDER BY t.datee IS NULL, t.datee, t.rowid DESC LIMIT 200";
	$resql = $db->query($sql);
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent headsup-mine"><tr class="liste_titre"><th colspan="2">'.$langs->trans('HeadsupMyTasks').'</th><th class="right">'.$langs->trans('DateEnd').'</th></tr>';
	$n = 0;
	if ($resql) {
		$t = new Task($db);
		while ($obj = $db->fetch_object($resql)) {
			$n++;
			$t->id = $obj->rowid;
			$t->ref = $obj->ref;
			$t->label = $obj->label;
			$t->fk_project = $obj->fk_projet;
			$end = $db->jdate($obj->datee);
			print '<tr class="oddeven"><td class="nowrap headsup-mine-ref">'.$t->getNomUrl(1, 'withproject').'</td>';
			print '<td class="tdoverflowmax200 headsup-mine-title">'.dol_escape_htmltag($obj->label).' <span class="opacitymedium">· '.((int) $obj->progress).'%</span></td>';
			print '<td class="right nowrap headsup-mine-side'.($end && $end < $now ? ' error' : '').'">'.dol_print_date($end, 'day').'</td></tr>';
		}
	}
	if (!$n) {
		print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('HeadsupNone').'</span></td></tr>';
	}
	print '</table></div>';

	print '</div><div class="fichehalfright">';

	// Proposals
	if (isModEnabled('propal')) {
		$sql = "SELECT p.rowid, p.ref, p.total_ht, p.datep, p.fk_soc, s.nom as socname";
		$sql .= " FROM ".MAIN_DB_PREFIX."propal as p";
		$sql .= $contactjoin('p', 'propal');
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = p.fk_soc";
		$sql .= " WHERE p.fk_statut = 1 AND p.entity IN (".getEntity('propal').")";
		$sql .= " ORDER BY p.datep DESC LIMIT 200";
		$resql = $db->query($sql);
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent headsup-mine"><tr class="liste_titre"><th colspan="2">'.$langs->trans('HeadsupMyProposals').'</th><th class="right">'.$langs->trans('AmountHT').'</th></tr>';
		$n = 0;
		if ($resql) {
			$pr = new Propal($db);
			while ($obj = $db->fetch_object($resql)) {
				$n++;
				$pr->id = $obj->rowid;
				$pr->ref = $obj->ref;
				print '<tr class="oddeven"><td class="nowrap headsup-mine-ref">'.$pr->getNomUrl(1).'</td>';
				print '<td class="tdoverflowmax200 headsup-mine-title">'.dol_escape_htmltag($obj->socname).'</td>';
				print '<td class="right nowrap headsup-mine-side">'.price($obj->total_ht).'</td></tr>';
			}
		}
		if (!$n) {
			print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('HeadsupNone').'</span></td></tr>';
		}
		print '</table></div><br>';
	}

	// Orders
	if (isModEnabled('order')) {
		$sql = "SELECT c.rowid, c.ref, c.total_ht, c.date_commande, c.fk_soc, s.nom as socname";
		$sql .= " FROM ".MAIN_DB_PREFIX."commande as c";
		$sql .= $contactjoin('c', 'commande');
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = c.fk_soc";
		$sql .= " WHERE c.fk_statut IN (1, 2) AND c.entity IN (".getEntity('commande').")";
		$sql .= " ORDER BY c.date_commande DESC LIMIT 200";
		$resql = $db->query($sql);
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent headsup-mine"><tr class="liste_titre"><th colspan="2">'.$langs->trans('HeadsupMyOrders').'</th><th class="right">'.$langs->trans('AmountHT').'</th></tr>';
		$n = 0;
		if ($resql) {
			$co = new Commande($db);
			while ($obj = $db->fetch_object($resql)) {
				$n++;
				$co->id = $obj->rowid;
				$co->ref = $obj->ref;
				print '<tr class="oddeven"><td class="nowrap headsup-mine-ref">'.$co->getNomUrl(1).'</td>';
				print '<td class="tdoverflowmax200 headsup-mine-title">'.dol_escape_htmltag($obj->socname).'</td>';
				print '<td class="right nowrap headsup-mine-side">'.price($obj->total_ht).'</td></tr>';
			}
		}
		if (!$n) {
			print '<tr class="oddeven"><td colspan="3"><span class="opacitymedium">'.$langs->trans('HeadsupNone').'</span></td></tr>';
		}
		print '</table></div><br>';
	}

	// Third parties I'm the sales rep for
	$sql = "SELECT s.rowid, s.nom, s.client, s.fournisseur, s.town";
	$sql .= " FROM ".MAIN_DB_PREFIX."societe_commerciaux as sc";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = sc.fk_soc";
	$sql .= " WHERE sc.fk_user = ".$uid." AND s.status = 1 AND s.entity IN (".getEntity('societe').")";
	$sql .= " ORDER BY s.nom LIMIT 200";
	$resql = $db->query($sql);
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent headsup-mine"><tr class="liste_titre"><th colspan="2">'.$langs->trans('HeadsupMyThirdparties').'</th></tr>';
	$n = 0;
	if ($resql) {
		$so = new Societe($db);
		while ($obj = $db->fetch_object($resql)) {
			$n++;
			$so->id = $obj->rowid;
			$so->name = $obj->nom;
			$so->client = $obj->client;
			$so->fournisseur = $obj->fournisseur;
			print '<tr class="oddeven"><td class="tdoverflowmax250 headsup-mine-ref">'.$so->getNomUrl(1).'</td>';
			print '<td class="tdoverflowmax150 headsup-mine-side">'.dol_escape_htmltag($obj->town).'</td></tr>';
		}
	}
	if (!$n) {
		print '<tr class="oddeven"><td colspan="2"><span class="opacitymedium">'.$langs->trans('HeadsupNone').'</span></td></tr>';
	}
	print '</table></div>';

	print '</div></div>';
	print '<div class="clearboth"></div>';
}

llxFooter();
$db->close();
