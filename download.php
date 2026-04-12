<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    kreataxmatch/download.php
 * \ingroup kreataxmatch
 * \brief   Manual AT download/synchronization workspace.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'] . '/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . '/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)) . '/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))) . '/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once './lib/kreataxmatch.lib.php';
require_once './class/KreaTaxMatchAtClient.class.php';
require_once './class/KreaTaxMatchService.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'other', 'kreataxmatch@kreataxmatch'));

if (!isModEnabled('kreataxmatch')) {
	accessforbidden();
}
if (!$user->hasRight('kreataxmatch', 'match', 'read')) {
	accessforbidden();
}

$action    = GETPOST('action', 'aZ09');
$mainmenu  = GETPOST('mainmenu', 'aZ09');
$leftmenu  = GETPOST('leftmenu', 'aZ09');
$canWrite  = $user->hasRight('kreataxmatch', 'match', 'write');

// Pagination
$requestPage = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
$page = max(0, (int) $requestPage);
$limit = (int) GETPOST('limit', 'int');
if ($limit <= 0) {
	$limit = (int) $conf->liste_limit;
}
if ($limit <= 0) {
	$limit = 25;
}

// Sort
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper((string) GETPOST('sortorder', 'aZ09comma'));
if (!$sortfield) {
	$sortfield = 'datec';
}
if (!in_array($sortorder, array('ASC', 'DESC'), true)) {
	$sortorder = 'DESC';
}

// Download date range (for the run-download form)
$defaultDays  = max(1, (int) getDolGlobalInt('KREATAXMATCH_CRON_LOOKBACK_DAYS', 2));
$defaultStart = dol_print_date(dol_time_plus_duree(dol_now(), -($defaultDays - 1), 'd'), '%Y-%m-%d');
$defaultEnd   = dol_print_date(dol_now(), '%Y-%m-%d');

$dateStart = GETPOST('date_start', 'alphanohtml');
$dateEnd   = GETPOST('date_end',   'alphanohtml');
if ($dateStart === '') {
	$dateStart = $defaultStart;
}
if ($dateEnd === '') {
	$dateEnd = $defaultEnd;
}

// ---------------------------------------------------------------------------
// Actions
// ---------------------------------------------------------------------------
$service = new KreaTaxMatchService($db);

if ($action === 'run_download') {
	if (!$canWrite) {
		accessforbidden();
	}
	if (!kreataxmatchIsTokenValid()) {
		accessforbidden('Invalid CSRF token');
	}

	$atNif             = (string) getDolGlobalString('KREATAXMATCH_AT_NIF');
	$encryptedPassword = (string) getDolGlobalString('KREATAXMATCH_AT_PASSWORD');
	$atPassword        = ($encryptedPassword !== '') ? dolDecrypt($encryptedPassword) : '';

	if (!preg_match('/^[0-9]{9}$/', $atNif)) {
		setEventMessages($langs->trans('KreaTaxMatchErrorMissingConfig'), null, 'errors');
	} elseif ($atPassword === '') {
		setEventMessages($langs->trans('KreaTaxMatchErrorMissingPassword'), null, 'errors');
	} else {
		try {
			$atClient  = new KreaTaxMatchAtClient();
			$documents = $atClient->fetchPurchaseDocuments($atNif, $atPassword, $dateStart, $dateEnd);
			$result    = $service->syncAtDocuments($documents, $dateStart, $dateEnd, 'manual', (int) $user->id);
			setEventMessages(
				$langs->trans('KreaTaxMatchSyncDone', (int) $result['received'], (int) $result['inserted'], (int) $result['updated']),
				null,
				'mesgs'
			);
		} catch (Exception $e) {
			dol_syslog('KreaTaxMatch download tab error: ' . $e->getMessage(), LOG_ERR);
			setEventMessages($e->getMessage(), null, 'errors');
		}
	}
}

// ---------------------------------------------------------------------------
// Load data
// ---------------------------------------------------------------------------
$syncBatches      = array();
$totalSyncBatches = 0;

try {
	$totalSyncBatches = $service->countSyncBatches();
	if ($totalSyncBatches > 0) {
		$maxPage = (int) floor(($totalSyncBatches - 1) / $limit);
		if ($page > $maxPage) {
			$page = $maxPage;
		}
	}
	$offset      = $page * $limit;
	$syncBatches = $service->fetchSyncBatches($limit, $offset, array('sortfield' => $sortfield, 'sortorder' => $sortorder));
} catch (Exception $e) {
	dol_syslog('KreaTaxMatch load sync batches error: ' . $e->getMessage(), LOG_ERR);
	setEventMessages($e->getMessage(), null, 'errors');
}

// $param carries all filter state (no sortfield/sortorder — framework adds those)
$param  = '&mainmenu=' . urlencode($mainmenu !== '' ? $mainmenu : 'billing');
$param .= '&leftmenu=' . urlencode($leftmenu !== '' ? $leftmenu : 'kreataxmatch');
$param .= '&action=view';
$param .= '&date_start=' . urlencode($dateStart);
$param .= '&date_end='   . urlencode($dateEnd);
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$param .= '&limit=' . ((int) $limit);
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
$title = $langs->trans('KreaTaxMatchDownloadTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-kreataxmatch page-download bodyforlist');

print '<style>tr.liste_titre th, tr.liste_titre td, tr.liste_titre_filter td { background-color: #F1F1F1 !important; }</style>' . "\n";

print load_fiche_titre($title, '', 'generic');

$head = kreataxmatchPrepareHead();
print dol_get_fiche_head($head, 'download', $title, 0, 'generic');

// Download trigger form
print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" class="marginbottom">';
print '<input type="hidden" name="token"    value="' . newToken() . '">';
print '<input type="hidden" name="mainmenu" value="' . dol_escape_htmltag($mainmenu !== '' ? $mainmenu : 'billing') . '">';
print '<input type="hidden" name="leftmenu" value="' . dol_escape_htmltag($leftmenu !== '' ? $leftmenu : 'kreataxmatch') . '">';
print '<input type="hidden" name="action"   value="run_download">';
print '<input type="hidden" name="page"     value="0">';
print '<input type="hidden" name="limit"    value="' . ((int) $limit) . '">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><th colspan="4">' . $langs->trans('KreaTaxMatchDownloadFilters') . '</th></tr>';
print '<tr class="oddeven">';
print '<td class="fieldrequired width20">' . $langs->trans('DateStart') . '</td>';
print '<td class="width30"><input type="date" class="flat" name="date_start" value="' . dol_escape_htmltag($dateStart) . '"></td>';
print '<td class="fieldrequired width20">' . $langs->trans('DateEnd') . '</td>';
print '<td class="width30"><input type="date" class="flat" name="date_end" value="' . dol_escape_htmltag($dateEnd) . '"></td>';
print '</tr>';
print '</table>';

if ($canWrite) {
	print '<div class="center marginbottomonly marginleftonly">';
	print '<input class="button button-search" type="submit" value="' . dol_escape_htmltag($langs->trans('KreaTaxMatchRunDownload')) . '">';
	print '</div>';
} else {
	print '<div class="opacitymedium">' . $langs->trans('NotEnoughPermissions') . '</div>';
}
print '</form>';

// Pager form
$numRowsInPage = min($limit, max(0, $totalSyncBatches - ($page * $limit)));
print '<form method="GET" id="downloadPagerForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="mainmenu"  value="' . dol_escape_htmltag($mainmenu !== '' ? $mainmenu : 'billing') . '">';
print '<input type="hidden" name="leftmenu"  value="' . dol_escape_htmltag($leftmenu !== '' ? $leftmenu : 'kreataxmatch') . '">';
print '<input type="hidden" name="action"    value="view">';
print '<input type="hidden" name="date_start" value="' . dol_escape_htmltag($dateStart) . '">';
print '<input type="hidden" name="date_end"   value="' . dol_escape_htmltag($dateEnd) . '">';
print '<input type="hidden" name="limit"     value="' . ((int) $limit) . '">';
print_barre_liste($langs->trans('KreaTaxMatchSyncHistory'), $page, $_SERVER['PHP_SELF'],
	$param, $sortfield, $sortorder, '', $numRowsInPage, $totalSyncBatches, 'title_setup',
	0, '', '', $limit, 0, 0, 1);
print '</form>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Date'),                $_SERVER['PHP_SELF'], 'datec',         '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Source'),              $_SERVER['PHP_SELF'], 'source',        '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('DateStart'),           $_SERVER['PHP_SELF'], 'date_start',    '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('DateEnd'),             $_SERVER['PHP_SELF'], 'date_end',      '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaTaxMatchRowsReceived'),  $_SERVER['PHP_SELF'], 'rows_received',  '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaTaxMatchRowsInserted'),  $_SERVER['PHP_SELF'], 'rows_inserted',  '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaTaxMatchRowsUpdated'),   $_SERVER['PHP_SELF'], 'rows_updated',   '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Status'),              $_SERVER['PHP_SELF'], 'status',        '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('Error'),               '',                   '',              '', '',     '');
print '</tr>';

// Filter row (no-op — sync history has no text filters beyond date range in the trigger form)
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre" colspan="9">&nbsp;</td>';
print '</tr>';

if (empty($syncBatches)) {
	print '<tr class="oddeven"><td colspan="9" class="opacitymedium">' . $langs->trans('KreaTaxMatchNoSyncHistory') . '</td></tr>';
} else {
	foreach ($syncBatches as $batch) {
		$status      = isset($batch['status']) ? (string) $batch['status'] : 'done';
		$statusLabel = $langs->trans('KreaTaxMatchBatchStatus' . ucfirst($status));
		if ($statusLabel === 'KreaTaxMatchBatchStatus' . ucfirst($status)) {
			$statusLabel = $status;
		}
		$statusColor = '#2f9e44';
		if ($status === 'error') {
			$statusColor = '#e03131';
		} elseif ($status === 'running') {
			$statusColor = '#f08c00';
		}

		print '<tr class="oddeven">';
		print '<td>' . dol_escape_htmltag((string) $batch['datec']) . '</td>';
		print '<td>' . dol_escape_htmltag((string) $batch['source']) . '</td>';
		print '<td>' . dol_escape_htmltag((string) $batch['date_start']) . '</td>';
		print '<td>' . dol_escape_htmltag((string) $batch['date_end']) . '</td>';
		print '<td class="right">' . ((int) $batch['rows_received']) . '</td>';
		print '<td class="right">' . ((int) $batch['rows_inserted']) . '</td>';
		print '<td class="right">' . ((int) $batch['rows_updated']) . '</td>';
		print '<td><span class="badge" style="background:' . $statusColor . ';color:#fff;">' . dol_escape_htmltag($statusLabel) . '</span></td>';
		print '<td>' . (!empty($batch['error_message']) ? dol_escape_htmltag((string) $batch['error_message']) : '-') . '</td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
