<?php
/* Copyright (C) 2026 DPG Supply
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/modules/modHeadsup.class.php
 * \ingroup headsup
 * \brief   Description and activation file for the Heads-up module
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Heads-up: Alt+click anything to flag it for someone, report a problem with
 * a page, and get a to-do when a record is assigned to you.
 *
 * Everything is stored as agenda events (to-dos). The module adds no tables.
 */
class modHeadsup extends DolibarrModules
{
	/** Event type ids added to llx_c_actioncomm. */
	const TYPE_IDS = array(
		'AC_HU_FLAG' => 520901,
		'AC_HU_PROBLEM' => 520902,
		'AC_HU_ASSIGN' => 520903,
	);

	/**
	 * Constructor.
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->numero = 520900;
		$this->family = 'crm';
		$this->module_position = '90';

		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Alt+click anything to flag it for someone or report a problem; get a to-do when a record is assigned to you";
		$this->version = '0.3.15';
		$this->editor_name = 'Zach Melo';
		$this->editor_url = 'https://zachmelo.com';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-flag';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'hooks' => array('data' => array('main'), 'entity' => '0'),
			'js' => array('/headsup/js/headsup.js'),
			'css' => array('/headsup/css/headsup.css'),
		);

		$this->dirs = array();
		$this->config_page_url = array("setup.php@headsup");

		$this->hidden = false;
		$this->depends = array('modAgenda');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("headsup@headsup");
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(20, 0);

		$this->const = array(
			array('HEADSUP_NOTIFY_ASSIGNMENTS', 'chaine', '1', 'Create a to-do when a record is assigned to someone else', 0, 'current', 0),
			array('HEADSUP_PROBLEM_USER', 'chaine', '', 'User who receives problem reports by default (empty: first admin)', 0, 'current', 0),
			array('HEADSUP_USERS_SHOW_ALL', 'chaine', '0', 'Show every user as a button in the popup (off: search and short list only)', 0, 'current', 0),
			array('HEADSUP_SHORTLIST', 'chaine', '', 'Users shown as quick choices in the popup (comma-separated ids)', 0, 'current', 0),
			array('HEADSUP_REFRESH_SECONDS', 'chaine', '60', 'How often the top bar count refreshes', 0, 'current', 0),
		);

		// Permissions
		$this->rights = array();
		$this->rights_class = 'headsup';
		$r = 0;

		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'Send flags and problem reports';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 1;
		$this->rights[$r][4] = 'send';
		$this->rights[$r][5] = '';
		$r++;

		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = "See everyone's flags and problem reports";
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'readall';
		$this->rights[$r][5] = '';
		$r++;

		// Menus
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => 0,
			'type' => 'top',
			'titre' => 'HeadsupMenu',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'headsup',
			'leftmenu' => '',
			'url' => '/headsup/queue.php',
			'langs' => 'headsup@headsup',
			'position' => 1010,
			'enabled' => 'isModEnabled("headsup")',
			'perms' => '1',
			'target' => '',
			'user' => 0,
		);

		// Opens My queue with the New to-do popup open (easy to reach, or bookmark, on a phone)
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=headsup',
			'type' => 'left',
			'titre' => 'HeadsupNewTodo',
			'mainmenu' => 'headsup',
			'leftmenu' => 'headsup_new',
			'url' => '/headsup/queue.php?newtodo=1',
			'langs' => 'headsup@headsup',
			'position' => 1010,
			'enabled' => 'isModEnabled("headsup")',
			'perms' => '$user->hasRight("headsup", "send")',
			'target' => '',
			'user' => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=headsup',
			'type' => 'left',
			'titre' => 'HeadsupMyQueue',
			'mainmenu' => 'headsup',
			'leftmenu' => 'headsup_queue',
			'url' => '/headsup/queue.php',
			'langs' => 'headsup@headsup',
			'position' => 1011,
			'enabled' => 'isModEnabled("headsup")',
			'perms' => '1',
			'target' => '',
			'user' => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=headsup',
			'type' => 'left',
			'titre' => 'HeadsupSent',
			'mainmenu' => 'headsup',
			'leftmenu' => 'headsup_sent',
			'url' => '/headsup/queue.php?view=sent',
			'langs' => 'headsup@headsup',
			'position' => 1012,
			'enabled' => 'isModEnabled("headsup")',
			'perms' => '1',
			'target' => '',
			'user' => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=headsup',
			'type' => 'left',
			'titre' => 'HeadsupCompleted',
			'mainmenu' => 'headsup',
			'leftmenu' => 'headsup_done',
			'url' => '/headsup/queue.php?view=done',
			'langs' => 'headsup@headsup',
			'position' => 1013,
			'enabled' => 'isModEnabled("headsup")',
			'perms' => '1',
			'target' => '',
			'user' => 0,
		);

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=headsup',
			'type' => 'left',
			'titre' => 'HeadsupAllOpen',
			'mainmenu' => 'headsup',
			'leftmenu' => 'headsup_all',
			'url' => '/headsup/queue.php?view=all',
			'langs' => 'headsup@headsup',
			'position' => 1014,
			'enabled' => 'isModEnabled("headsup")',
			'perms' => '$user->hasRight("headsup", "readall")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Enable the module: constants, permissions, menus, and the three event types.
	 *
	 * @param string $options Options when enabling module
	 * @return int            1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$this->delete_menus();

		$labels = array(
			'AC_HU_FLAG' => 'Flag',
			'AC_HU_PROBLEM' => 'Problem report',
			'AC_HU_ASSIGN' => 'Assignment',
		);
		$pos = 900;
		foreach (self::TYPE_IDS as $code => $id) {
			$sql = "SELECT id FROM ".MAIN_DB_PREFIX."c_actioncomm WHERE id = ".((int) $id)." OR code = '".$this->db->escape($code)."'";
			$resql = $this->db->query($sql);
			if ($resql && $this->db->num_rows($resql) == 0) {
				$sql = "INSERT INTO ".MAIN_DB_PREFIX."c_actioncomm (id, code, type, libelle, module, active, todo, position)";
				$sql .= " VALUES (".((int) $id).", '".$this->db->escape($code)."', 'headsup', '".$this->db->escape($labels[$code])."', 'headsup', 1, 1, ".((int) $pos).")";
				$this->db->query($sql);
			} elseif ($resql) {
				$this->db->query("UPDATE ".MAIN_DB_PREFIX."c_actioncomm SET active = 1 WHERE code = '".$this->db->escape($code)."'");
			}
			$pos++;
		}

		return $this->_init(array(), $options);
	}

	/**
	 * Disable the module. Event types stay so existing events keep their type.
	 *
	 * @param string $options Options when disabling module
	 * @return int            1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
