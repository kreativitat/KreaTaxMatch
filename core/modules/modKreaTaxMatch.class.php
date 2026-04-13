<?php
/* Copyright (C) 2004-2018 Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019 Nicolas ZABOURI <info@inovea-conseil.com>
 * Copyright (C) 2019-2024 Frédéric France <frederic.france@free.fr>
 * Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module KreaTaxMatch
 */
class modKreaTaxMatch extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// ID 156550 - 156599: Kreativität Works
		$this->numero = 156563;
		$this->rights_class = 'kreataxmatch';
		$this->family = 'Kreativität Works';
		$this->module_position = '41';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModuleKreaTaxMatchDesc';
		$this->descriptionlong = 'ModuleKreaTaxMatchDescLong';
		$this->editor_name = 'Kreativität Works';
		$this->editor_url = 'https://kreativitat.com';
		$this->version = '1.6.2';
		$this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
		$this->picto = 'kreataxmatch@kreataxmatch';

		$this->module_parts = array(
			'triggers' => 0,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
			'websitetemplates' => 0,
			'captcha' => 0,
		);

		$this->dirs = array('/kreataxmatch/temp');
		$this->config_page_url = array('setup.php@kreataxmatch');
		$this->hidden = getDolGlobalInt('MODULE_KREATAXMATCH_DISABLED');
		$this->depends = array('modFournisseur');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('kreataxmatch@kreataxmatch');
		$this->phpmin = array(7, 1);
		$this->need_dolibarr_version = array(19, -3);
		$this->need_javascript_ajax = 1;
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		$this->const = array(
			array('KREATAXMATCH_AT_NIF', 'chaine', '', 'Portuguese Tax Authority NIF used for e-Fatura login', 1, 'current', 0),
			array('KREATAXMATCH_AT_PASSWORD', 'chaine', '', 'Encrypted Portuguese Tax Authority password used for e-Fatura login', 0, 'current', 0),
			array('KREATAXMATCH_CRON_LOOKBACK_DAYS', 'integer', '2', 'Number of days rechecked by daily cron synchronization', 1, 'current', 0),
			array('KREATAXMATCH_ENABLE_ACCOUNTING_COLUMN', 'yesno', '1', 'Enable the accounting checkbox column in the reconciliation workspace', 1, 'current', 0),
		);

		if (!isModEnabled('kreataxmatch')) {
			$conf->kreataxmatch = new stdClass();
			$conf->kreataxmatch->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$arraydate = dol_getdate(dol_now());
		$datestart = dol_mktime(3, 0, 0, $arraydate['mon'], $arraydate['mday'], $arraydate['year']);
		$this->cronjobs = array(
			0 => array(
				'label' => 'KreaTaxMatchDailySyncJob',
				'jobtype' => 'method',
				'class' => 'custom/kreataxmatch/class/KreaTaxMatchCron.class.php',
				'objectname' => 'KreaTaxMatchCron',
				'method' => 'downloadDailyLists',
				'parameters' => '0',
				'comment' => 'Download AT e-Fatura purchaser rows daily and store them without duplication.',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'priority' => 60,
				'status' => 1,
				'test' => 'isModEnabled("kreataxmatch") && isModEnabled("cron")',
				'datestart' => $datestart,
			),
		);

		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero + 1;
		$this->rights[$r][1] = 'Read KreaTaxMatch reconciliation workspace';
		$this->rights[$r][4] = 'match';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->rights[$r][0] = $this->numero + 2;
		$this->rights[$r][1] = 'Run KreaTaxMatch matching and create supplier drafts';
		$this->rights[$r][4] = 'match';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=billing,fk_leftmenu=suppliers_bills',
			'type' => 'left',
			'titre' => 'KreaTaxMatchMenu',
			'mainmenu' => 'billing',
			'leftmenu' => 'kreataxmatch',
			'url' => '/custom/kreataxmatch/match.php?mainmenu=billing&leftmenu=kreataxmatch&action=view',
			'langs' => 'kreataxmatch@kreataxmatch',
			'position' => 1995,
			'enabled' => 'isModEnabled("kreataxmatch") && isModEnabled("fournisseur")',
			'perms' => '$user->hasRight("kreataxmatch", "match", "read")',
			'target' => '',
			'user' => 2,
		);
	}

	/**
	 * Function called when module is enabled.
	 *
	 * @param string $options Options when enabling module
	 * @return int<-1,1>
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/kreataxmatch/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);
		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 *
	 * @param string $options Options when disabling module
	 * @return int<-1,1>
	 */
	public function remove($options = '')
	{
		$sql = array();

		return $this->_remove($sql, $options);
	}
}
