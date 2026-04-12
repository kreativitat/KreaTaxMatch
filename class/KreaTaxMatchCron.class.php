<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once __DIR__ . '/KreaTaxMatchAtClient.class.php';
require_once __DIR__ . '/KreaTaxMatchService.class.php';

/**
 * Cron handler for KreaTaxMatch.
 */
class KreaTaxMatchCron
{
	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * @var string
	 */
	public $error = '';

	/**
	 * @var array<int,string>
	 */
	public $errors = array();

	/**
	 * @var string
	 */
	public $output = '';

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Daily AT synchronization job.
	 *
	 * @param string|int $daysBack
	 * @return int
	 */
	public function downloadDailyLists($daysBack = 2)
	{
		$daysBack = (int) $daysBack;
		if ($daysBack <= 0) {
			$daysBack = (int) getDolGlobalInt('KREATAXMATCH_CRON_LOOKBACK_DAYS', 2);
		}
		if ($daysBack <= 0) {
			$daysBack = 2;
		}

		$dateEnd = dol_print_date(dol_now(), '%Y-%m-%d');
		$daysToSubtract = $daysBack - 1;
		$dateStart = dol_print_date(dol_time_plus_duree(dol_now(), -$daysToSubtract, 'd'), '%Y-%m-%d');

		$atNif = (string) getDolGlobalString('KREATAXMATCH_AT_NIF');
		$encryptedPassword = (string) getDolGlobalString('KREATAXMATCH_AT_PASSWORD');
		$atPassword = ($encryptedPassword !== '') ? dolDecrypt($encryptedPassword) : '';

		if (!preg_match('/^[0-9]{9}$/', $atNif)) {
			$this->error = 'KreaTaxMatch cron aborted: missing or invalid AT NIF configuration.';
			$this->errors[] = $this->error;
			dol_syslog($this->error, LOG_ERR);

			return -1;
		}
		if ($atPassword === '') {
			$this->error = 'KreaTaxMatch cron aborted: missing AT password configuration.';
			$this->errors[] = $this->error;
			dol_syslog($this->error, LOG_ERR);

			return -1;
		}

		try {
			$client = new KreaTaxMatchAtClient();
			$service = new KreaTaxMatchService($this->db);

			$documents = $client->fetchPurchaseDocuments($atNif, $atPassword, $dateStart, $dateEnd);
			$result = $service->syncAtDocuments($documents, $dateStart, $dateEnd, 'cron', 0);

			$this->output = 'KreaTaxMatch cron sync OK. Received=' . ((int) $result['received']) . ', inserted=' . ((int) $result['inserted']) . ', updated=' . ((int) $result['updated']) . '.';
			dol_syslog($this->output, LOG_INFO);

			return 0;
		} catch (Exception $e) {
			$this->error = 'KreaTaxMatch cron error: ' . $e->getMessage();
			$this->errors[] = $this->error;
			$this->output = $this->error;
			dol_syslog($this->error, LOG_ERR);

			return -1;
		}
	}
}
