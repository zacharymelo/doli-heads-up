<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/triggers/interface_99_modHeadsup_HeadsupTrigger.class.php
 * \ingroup headsup
 * \brief   A to-do for whoever gets assigned to a record
 */
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * When a user is added to a record (project leader, sales rep following a
 * proposal or order, task executive, a third party's sales representative...),
 * that user gets an "Assignment" to-do. Assigning yourself creates nothing.
 */
class InterfaceHeadsupTrigger extends DolibarrTriggers
{
	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'crm';
		$this->description = 'Heads-up: a to-do for whoever gets assigned to a record';
		$this->version = '0.1.0';
		$this->picto = 'fa-flag';
	}

	/**
	 * @return string
	 */
	public function getName()
	{
		return 'HeadsupTrigger';
	}

	/**
	 * @return string
	 */
	public function getDesc()
	{
		return 'A to-do for whoever gets assigned to a record';
	}

	/**
	 * @param string    $action Event code
	 * @param object    $object Record the event is about
	 * @param User      $user   User who did it
	 * @param Translate $langs  Language
	 * @param Conf      $conf   Configuration
	 * @return int              0 (never blocks the action)
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('headsup') || !getDolGlobalInt('HEADSUP_NOTIFY_ASSIGNMENTS', 1)) {
			return 0;
		}

		if ($action === 'COMPANY_LINK_SALE_REPRESENTATIVE') {
			$assignee = isset($object->context['commercial_modified']) ? (int) $object->context['commercial_modified'] : 0;
			if ($assignee > 0) {
				$langs->load('headsup@headsup');
				$this->notify($user, $assignee, $langs->transnoentitiesnoconv('HeadsupSalesRep'), $object, $langs);
			}
			return 0;
		}

		if (substr($action, -12) === '_ADD_CONTACT' && !empty($object->id) && !empty($object->element)) {
			$added = $this->lastAddedInternal($object);
			if ($added) {
				$langs->load('headsup@headsup');
				$this->notify($user, $added['userid'], $added['role'], $object, $langs);
			}
		}

		return 0;
	}

	/**
	 * The contact just added to this record, if it was a user (internal contact).
	 * add_contact() does not say which contact it added, so take the newest row.
	 *
	 * @param object $object Record
	 * @return array{userid:int,role:string}|null
	 */
	private function lastAddedInternal($object)
	{
		global $langs;

		$sql = "SELECT ec.fk_socpeople, ec.datecreate, tc.source, tc.code, tc.libelle, tc.element";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_contact as ec";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."c_type_contact as tc ON tc.rowid = ec.fk_c_type_contact";
		$sql .= " WHERE ec.element_id = ".((int) $object->id);
		$sql .= " AND tc.element = '".$this->db->escape($object->element)."'";
		$sql .= " ORDER BY ec.rowid DESC";
		$sql .= " LIMIT 1";
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			return null;
		}
		if ($obj->source !== 'internal') {
			return null;
		}
		// Only a row added just now
		$created = $this->db->jdate($obj->datecreate);
		if ($created && abs(dol_now() - $created) > 300) {
			return null;
		}

		$key = 'TypeContact_'.$obj->element.'_internal_'.$obj->code;
		$role = $langs->transnoentitiesnoconv($key);
		if ($role === $key) {
			$role = $obj->libelle;
		}
		return array('userid' => (int) $obj->fk_socpeople, 'role' => $role);
	}

	/**
	 * Create the "Assignment" to-do.
	 *
	 * @param User      $user     Who made the assignment
	 * @param int       $assignee Who was assigned
	 * @param string    $role     Role on the record
	 * @param object    $object   Record
	 * @param Translate $langs    Language
	 * @return void
	 */
	private function notify($user, $assignee, $role, $object, $langs)
	{
		if ($assignee <= 0 || $assignee === (int) $user->id) {
			return;
		}

		// Only active users
		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."user WHERE rowid = ".((int) $assignee)." AND statut = 1";
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->num_rows($resql) == 0) {
			return;
		}

		dol_include_once('/headsup/class/headsup.class.php');
		$hu = new Headsup($this->db);

		$name = '';
		foreach (array('ref', 'name', 'title', 'label') as $prop) {
			if (!empty($object->$prop) && is_scalar($object->$prop)) {
				$name = (string) $object->$prop;
				break;
			}
		}
		if (!empty($object->title) && !empty($object->ref) && $object->element === 'project') {
			$name = $object->ref.' '.$object->title;
		} elseif (!empty($object->ref) && !empty($object->label) && $object->element === 'project_task') {
			$name = $object->ref.' '.$object->label;
		}

		$label = $langs->transnoentitiesnoconv('HeadsupAssignedLabel', $role, $name);
		$note = $langs->transnoentitiesnoconv('HeadsupAssignedNote', $user->getFullName($langs), $role, $name);

		$res = $hu->createTodo($user, Headsup::CODE_ASSIGN, array($assignee), $label, $note, $object);
		if ($res <= 0) {
			dol_syslog('Headsup: could not create assignment to-do: '.$hu->error, LOG_WARNING);
		}
	}
}
