<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    comments.php
 * \ingroup headsup
 * \brief   Conversation tab of a Heads-up to-do's event card: its comments, and a box to add one.
 */
$res = 0;
if (file_exists("../main.inc.php")) {
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/agenda.lib.php';
require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
dol_include_once('/headsup/class/headsup.class.php');

$langs->loadLangs(array('headsup@headsup', 'commercial', 'agenda'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$commentid = GETPOSTINT('commentid');

if (!isModEnabled('headsup')) {
	accessforbidden();
}

// Security check: the event's own rules, then who may see this to-do
$socid = $user->socid > 0 ? $user->socid : 0;
restrictedArea($user, 'agenda', $id, 'actioncomm&societe', 'myactions|allactions', 'fk_soc', 'id');

$object = new ActionComm($db);
if ($id <= 0 || $object->fetch($id) <= 0 || !in_array($object->code, Headsup::codes(), true)) {
	accessforbidden();
}
if ($socid > 0 || !Headsup::canComment($user, $object)) {
	accessforbidden();
}

$hu = new Headsup($db);
$pageurl = $_SERVER['PHP_SELF'].'?id='.((int) $object->id);


/*
 * Actions
 */

if ($action === 'addcomment') {
	$result = $hu->addComment($user, $object, GETPOST('comment', 'nohtml'));
	if ($result < 0) {
		setEventMessages($hu->error, null, 'errors');
	} elseif ($result > 0) {
		header('Location: '.$pageurl.'#headsup-comment-'.((int) $result));
		exit;
	}
	$action = '';
}

if ($action === 'confirm_deletecomment' && GETPOST('confirm', 'alpha') === 'yes') {
	$result = $hu->deleteComment($user, $object, $commentid);
	if ($result < 0) {
		setEventMessages($hu->error, null, 'errors');
	} elseif ($result > 0) {
		setEventMessages($langs->trans('HeadsupCommentDeleted'), null, 'mesgs');
		header('Location: '.$pageurl);
		exit;
	}
	$action = '';
}


/*
 * Views
 */

$form = new Form($db);

llxHeader('', $langs->trans('HeadsupConversation'), '', '', 0, 0, '', '', '', 'mod-headsup page-comments');

$head = actions_prepare_head($object);
print dol_get_fiche_head($head, 'headsup', $langs->trans('Action'), -1, 'action');

$linkback = '<a href="'.dolPrintHTMLForAttributeUrl(dol_buildpath('/headsup/queue.php', 1)).'">';
$linkback .= img_picto('', 'fa-inbox', 'class="pictofixedwidth"').$langs->trans('HeadsupMyQueue');
$linkback .= '</a>';

// No previous/next arrows: the neighbouring events are mostly not to-dos
dol_banner_tab($object, 'id', $linkback, 0, 'id', 'ref', '');

print '<div class="underbanner clearboth"></div>';

if ($action === 'deletecomment') {
	print $form->formconfirm($pageurl.'&commentid='.((int) $commentid), $langs->trans('HeadsupDeleteComment'), $langs->trans('HeadsupDeleteCommentConfirm'), 'confirm_deletecomment', '', 0, 1);
}

$comments = $hu->fetchComments((int) $object->id);

print '<div class="headsup-thread">';

// What the to-do says, so the conversation reads on its own
$parsed = Headsup::parseNote($object->note_private);
$sender = new User($db);
$sendername = ($object->authorid > 0 && $sender->fetch($object->authorid) > 0) ? $sender->getFullName($langs) : '';
print '<div class="headsup-comment headsup-comment-first">';
print '<div class="headsup-comment-head"><strong>'.dolPrintLabel($sendername).'</strong>';
print ' <span class="opacitymedium">'.dol_print_date($object->datec ? $object->datec : $object->datep, 'dayhour', 'tzuserrel').'</span></div>';
print '<div class="headsup-comment-body"><strong>'.dolPrintLabel($object->label).'</strong>';
if ($parsed['text'] !== '') {
	print '<br>'.dolPrintHTML($parsed['text']);
}
print '</div></div>';

foreach ($comments as $comment) {
	$mine = ($comment['userid'] === (int) $user->id);
	print '<div class="headsup-comment'.($mine ? ' headsup-comment-mine' : '').'" id="headsup-comment-'.$comment['id'].'">';
	print '<div class="headsup-comment-head"><strong>'.dolPrintLabel($comment['name']).'</strong>';
	print ' <span class="opacitymedium">'.dol_print_date($comment['date'], 'dayhour', 'tzuserrel').'</span>';
	if ($mine || !empty($user->admin)) {
		$deleteurl = $pageurl.'&action=deletecomment&token='.newToken().'&commentid='.$comment['id'];
		print ' <a class="headsup-comment-delete" href="'.dolPrintHTMLForAttributeUrl($deleteurl).'" title="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupDeleteComment')).'">'.img_picto('', 'delete').'</a>';
	}
	print '</div>';
	print '<div class="headsup-comment-body">'.dolPrintHTML($comment['text']).'</div>';
	print '</div>';
}

if (!$comments) {
	print '<div class="headsup-thread-empty opacitymedium">'.$langs->trans('HeadsupNoComments').'</div>';
}

print '<form method="POST" action="'.dolPrintHTMLForAttributeUrl($pageurl).'" class="headsup-comment-form">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="addcomment">';
print '<textarea name="comment" class="flat" rows="3" maxlength="'.Headsup::COMMENT_MAX.'" required';
print ' placeholder="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupWriteComment')).'"';
print ' aria-label="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupWriteComment')).'"></textarea>';
print '<div><input type="submit" class="button" value="'.dolPrintHTMLForAttribute($langs->transnoentitiesnoconv('HeadsupSendComment')).'"></div>';
print '</form>';

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
