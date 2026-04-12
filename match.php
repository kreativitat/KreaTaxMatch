<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    kreataxmatch/match.php
 * \ingroup kreataxmatch
 * \brief   AT-centric reconciliation workspace.
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
require_once './class/KreaTaxMatchMatcher.class.php';
require_once './class/KreaTaxMatchService.class.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('main', 'other', 'suppliers', 'companies', 'kreataxmatch@kreataxmatch'));

if (!isModEnabled('kreataxmatch')) {
	accessforbidden();
}
if (!$user->hasRight('kreataxmatch', 'match', 'read')) {
	accessforbidden();
}

// ---------------------------------------------------------------------------
// Request parameters
// ---------------------------------------------------------------------------
$action   = GETPOST('action',   'aZ09');
$mainmenu = GETPOST('mainmenu', 'aZ09');
$leftmenu = GETPOST('leftmenu', 'aZ09');

// Date range — fundamental navigation parameter, not a text-search filter
$dateStart = GETPOST('date_start', 'alphanohtml');
$dateEnd   = GETPOST('date_end',   'alphanohtml');
if ($dateStart === '') {
	$dateStart = '';
}
if ($dateEnd === '') {
	$dateEnd = '';
}

// Sorting — Dolibarr list-framework convention (same as /compta/facture/list.php)
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper((string) GETPOST('sortorder', 'aZ09comma'));
if (!$sortfield) {
	$sortfield = 'at_date';
}
if (!in_array($sortorder, array('ASC', 'DESC'), true)) {
	$sortorder = 'DESC';
}

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

// Search filters — Dolibarr search_ naming convention
$searchStatus   = trim((string) GETPOST('search_status',   'aZ09'));
$searchSupplier = trim((string) GETPOST('search_supplier', 'restricthtml'));
$searchVat      = trim((string) GETPOST('search_vat',      'alphanohtml'));
$searchRef      = trim((string) GETPOST('search_ref',      'restricthtml'));
$searchAmount   = trim((string) GETPOST('search_amount',   'restricthtml'));
$searchDolRef   = trim((string) GETPOST('search_dol_ref',  'restricthtml'));
$searchNote     = trim((string) GETPOST('search_note',     'restricthtml'));

if (!in_array($searchStatus, array('all', 'matched', 'manual', 'missing', 'discrepancy'), true)) {
	$searchStatus = 'all';
}

// Remove-filter / new-search resets to page 0
if (GETPOST('button_removefilter_x', 'alpha') !== '' || GETPOST('button_removefilter', 'alpha') !== '' || GETPOST('removefilter', 'alpha') !== '') {
	$searchStatus   = 'all';
	$searchSupplier = '';
	$searchVat      = '';
	$searchRef      = '';
	$searchAmount   = '';
	$searchDolRef   = '';
	$searchNote     = '';
	$page           = 0;
}
if (GETPOST('button_search_x', 'alpha') !== '' || GETPOST('button_search', 'alpha') !== '') {
	$page = 0;
}

// ---------------------------------------------------------------------------
// $param — appended to every pager / sort link.
// sortfield/sortorder are passed as separate arguments to print_barre_liste
// and print_liste_field_titre; the framework builds them into each link URL.
// ---------------------------------------------------------------------------
$param  = '&mainmenu=' . urlencode($mainmenu !== '' ? $mainmenu : 'billing');
$param .= '&leftmenu=' . urlencode($leftmenu !== '' ? $leftmenu : 'kreataxmatch');
$param .= '&action=view';
$param .= '&date_start=' . urlencode($dateStart);
$param .= '&date_end='   . urlencode($dateEnd);
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$param .= '&limit=' . ((int) $limit);
}
if ($searchStatus !== 'all') {
	$param .= '&search_status='   . urlencode($searchStatus);
}
if ($searchSupplier !== '') {
	$param .= '&search_supplier=' . urlencode($searchSupplier);
}
if ($searchVat !== '') {
	$param .= '&search_vat='      . urlencode($searchVat);
}
if ($searchRef !== '') {
	$param .= '&search_ref='      . urlencode($searchRef);
}
if ($searchAmount !== '') {
	$param .= '&search_amount='   . urlencode($searchAmount);
}
if ($searchDolRef !== '') {
	$param .= '&search_dol_ref='  . urlencode($searchDolRef);
}
if ($searchNote !== '') {
	$param .= '&search_note='     . urlencode($searchNote);
}

$service  = new KreaTaxMatchService($db);
$matcher  = new KreaTaxMatchMatcher();
$canWrite = $user->hasRight('kreataxmatch', 'match', 'write');

// ---------------------------------------------------------------------------
// Helper: build redirect URL preserving full list state (filter + sort + page)
// ---------------------------------------------------------------------------
$buildRedirectUrl = static function ($pageNum) use ($mainmenu, $leftmenu, $dateStart, $dateEnd, $limit, $conf, $sortfield, $sortorder, $searchStatus, $searchSupplier, $searchVat, $searchRef, $searchAmount, $searchDolRef, $searchNote) {
	$url  = dol_buildpath('/custom/kreataxmatch/match.php', 1);
	$url .= '?mainmenu='  . urlencode($mainmenu !== '' ? $mainmenu : 'billing');
	$url .= '&leftmenu='  . urlencode($leftmenu !== '' ? $leftmenu : 'kreataxmatch');
	$url .= '&action=view';
	$url .= '&date_start=' . urlencode($dateStart);
	$url .= '&date_end='   . urlencode($dateEnd);
	$url .= '&page='       . ((int) $pageNum);
	if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
		$url .= '&limit=' . ((int) $limit);
	}
	$url .= '&sortfield=' . urlencode($sortfield);
	$url .= '&sortorder=' . urlencode($sortorder);
	if ($searchStatus !== 'all') {
		$url .= '&search_status='   . urlencode($searchStatus);
	}
	if ($searchSupplier !== '') {
		$url .= '&search_supplier=' . urlencode($searchSupplier);
	}
	if ($searchVat !== '') {
		$url .= '&search_vat='      . urlencode($searchVat);
	}
	if ($searchRef !== '') {
		$url .= '&search_ref='      . urlencode($searchRef);
	}
	if ($searchAmount !== '') {
		$url .= '&search_amount='   . urlencode($searchAmount);
	}
	if ($searchDolRef !== '') {
		$url .= '&search_dol_ref='  . urlencode($searchDolRef);
	}
	if ($searchNote !== '') {
		$url .= '&search_note='     . urlencode($searchNote);
	}
	return $url;
};

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if ($action === 'save_manual_match') {
	if (!$canWrite) {
		accessforbidden();
	}
	if (!kreataxmatchIsTokenValid()) {
		accessforbidden('Invalid CSRF token');
	}

	$documentRowId = (int) GETPOST('document_rowid', 'int');
	$invoiceRowId  = (int) GETPOST('invoice_rowid',  'int');
	$manualNote    = trim((string) GETPOST('manual_note', 'restricthtml'));

	try {
		$service->saveManualMatch($documentRowId, $invoiceRowId, (int) $user->id, $manualNote);
		setEventMessages($langs->trans('KreaTaxMatchManualMatchSaved'), null, 'mesgs');
	} catch (Exception $e) {
		dol_syslog('KreaTaxMatch save manual match error: ' . $e->getMessage(), LOG_ERR);
		setEventMessages($e->getMessage(), null, 'errors');
	}

	header('Location: ' . $buildRedirectUrl($page));
	exit;
}

if ($action === 'save_manual_note') {
	if (!$canWrite) {
		accessforbidden();
	}
	if (!kreataxmatchIsTokenValid()) {
		accessforbidden('Invalid CSRF token');
	}

	$documentRowId = (int) GETPOST('document_rowid', 'int');
	$manualNote    = trim((string) GETPOST('manual_note', 'restricthtml'));

	try {
		$service->saveDocumentNote($documentRowId, $manualNote, (int) $user->id);
		setEventMessages($langs->trans('KreaTaxMatchManualNoteSaved'), null, 'mesgs');
	} catch (Exception $e) {
		dol_syslog('KreaTaxMatch save document note error: ' . $e->getMessage(), LOG_ERR);
		setEventMessages($e->getMessage(), null, 'errors');
	}

	header('Location: ' . $buildRedirectUrl($page));
	exit;
}

if ($action === 'delete_manual_match') {
	if (!$canWrite) {
		accessforbidden();
	}
	if (!kreataxmatchIsTokenValid()) {
		accessforbidden('Invalid CSRF token');
	}

	$documentRowId = (int) GETPOST('document_rowid', 'int');

	try {
		$service->deleteManualMatch($documentRowId);
		setEventMessages($langs->trans('KreaTaxMatchManualMatchDeleted'), null, 'mesgs');
	} catch (Exception $e) {
		dol_syslog('KreaTaxMatch delete manual match error: ' . $e->getMessage(), LOG_ERR);
		setEventMessages($e->getMessage(), null, 'errors');
	}

	header('Location: ' . $buildRedirectUrl($page));
	exit;
}

if ($action === '') {
	$action = 'view';
}

// ---------------------------------------------------------------------------
// Fetch + match data
// ---------------------------------------------------------------------------
$matchResult           = null;
$documentsCount        = 0;
$invoiceCount          = 0;
$invoiceOptionByInvoiceId  = array();
$invoiceOptionsByPrimaryKey = array();
$invoiceOptionsByVat        = array();

if ($action === 'view') {
	try {
		$dolibarrInvoices  = $service->fetchDolibarrSupplierInvoices($dateStart, $dateEnd);
		$storedAtDocuments = $service->fetchStoredAtDocuments($dateStart, $dateEnd);
		$invoiceCount      = count($dolibarrInvoices);
		$documentsCount    = count($storedAtDocuments);

		foreach ($dolibarrInvoices as $invoiceRow) {
			if (!is_array($invoiceRow) || empty($invoiceRow['rowid'])) {
				continue;
			}

			$invoiceRowId = (int) $invoiceRow['rowid'];
			$resolvedRef = $matcher->resolveDolibarrInvoiceReference($invoiceRow);
			$displaySupplierRef = $matcher->normalizeReference(isset($invoiceRow['ref_supplier']) ? $invoiceRow['ref_supplier'] : '');
			$labelParts = array();
			if ($displaySupplierRef !== '') {
				$labelParts[] = $displaySupplierRef;
			}
			$labelParts[] = !empty($invoiceRow['invoice_date']) ? (string) $invoiceRow['invoice_date'] : '-';
			$optionLabel = implode(' | ', $labelParts);
			$optionLabel .= ' | ' . (isset($invoiceRow['total_ttc']) ? price((float) $invoiceRow['total_ttc']) : '-');
			if (!empty($invoiceRow['supplier_name'])) {
				$optionLabel .= ' | ' . (string) $invoiceRow['supplier_name'];
			}
			$invoiceOptionByInvoiceId[$invoiceRowId] = $optionLabel;

			$vat = $matcher->normalizeVat(isset($invoiceRow['supplier_vat']) ? $invoiceRow['supplier_vat'] : '');
			$ref = $resolvedRef;
			if ($vat !== '' && $ref !== '') {
				$primaryKey = $vat . '|' . $ref;
				if (!isset($invoiceOptionsByPrimaryKey[$primaryKey])) {
					$invoiceOptionsByPrimaryKey[$primaryKey] = array();
				}
				$invoiceOptionsByPrimaryKey[$primaryKey][$invoiceRowId] = $optionLabel;
			}
			if ($vat !== '') {
				if (!isset($invoiceOptionsByVat[$vat])) {
					$invoiceOptionsByVat[$vat] = array();
				}
				$invoiceOptionsByVat[$vat][$invoiceRowId] = $optionLabel;
			}
		}

		$documentRowIds = array();
		foreach ($storedAtDocuments as $rawDocument) {
			if (!is_array($rawDocument)) {
				continue;
			}

			$normalizedAt  = $matcher->normalizeAtDocument($rawDocument);
			if (!empty($normalizedAt['stored_rowid'])) {
				$documentRowIds[] = (int) $normalizedAt['stored_rowid'];
			}
		}
		$manualMatchesByDocument = $service->getManualMatchesIndexed($documentRowIds);
		$matchResult = $matcher->match($storedAtDocuments, $dolibarrInvoices);
		$matchResult = $service->applyManualMatchesToResult($matchResult, $manualMatchesByDocument);
	} catch (Exception $e) {
		dol_syslog('KreaTaxMatch match page error: ' . $e->getMessage(), LOG_ERR);
		setEventMessages($e->getMessage(), null, 'errors');
	}
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
$title = $langs->trans('KreaTaxMatchDashboardTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-kreataxmatch page-dashboard bodyforlist');

print '<style>
.mod-kreataxmatch.page-dashboard tr.liste_titre th,
.mod-kreataxmatch.page-dashboard tr.liste_titre td,
.mod-kreataxmatch.page-dashboard tr.liste_titre_filter td {
	background-color: #F1F1F1 !important;
}
.mod-kreataxmatch.page-dashboard .krea-inline-icon-btn {
	border: 0;
	background: transparent;
	box-shadow: none;
	padding: 2px 4px;
	margin: 0;
	min-width: 0;
	color: #555;
	cursor: pointer;
}
.mod-kreataxmatch.page-dashboard .krea-inline-icon-btn:hover,
.mod-kreataxmatch.page-dashboard .krea-inline-icon-btn:focus {
	color: #1f3c88;
	outline: none;
}
.mod-kreataxmatch.page-dashboard .krea-inline-icon-btn.krea-danger:hover,
.mod-kreataxmatch.page-dashboard .krea-inline-icon-btn.krea-danger:focus {
	color: #c92a2a;
}
.mod-kreataxmatch.page-dashboard .krea-note-cell {
	min-width: 180px;
}
.mod-kreataxmatch.page-dashboard .krea-note-view {
	display: flex;
	align-items: center;
	gap: 6px;
}
.mod-kreataxmatch.page-dashboard .krea-note-text {
	flex: 1 1 auto;
	min-width: 0;
}
.mod-kreataxmatch.page-dashboard .krea-note-edit-form {
	display: none;
	align-items: center;
	gap: 4px;
	margin: 0;
}
.mod-kreataxmatch.page-dashboard .krea-note-edit-form .flat {
	flex: 1 1 auto;
	min-width: 120px;
}
.mod-kreataxmatch.page-dashboard .krea-action-cell {
	white-space: nowrap;
	width: 560px;
	min-width: 560px;
	max-width: 560px;
	overflow: hidden;
}
.mod-kreataxmatch.page-dashboard .krea-action-row {
	display: flex;
	flex-direction: row;
	align-items: center;
	gap: 4px;
	width: 100%;
	max-width: 100%;
	min-width: 0;
}
.mod-kreataxmatch.page-dashboard .krea-action-form,
.mod-kreataxmatch.page-dashboard .krea-action-delete-form {
	margin: 0;
}
.mod-kreataxmatch.page-dashboard .krea-action-form {
	display: flex;
	flex-direction: row;
	align-items: center;
	gap: 4px;
	flex: 1 1 0;
	min-width: 0;
	max-width: 100%;
}
.mod-kreataxmatch.page-dashboard .krea-action-delete-form {
	flex: 0 0 auto;
	display: flex;
	align-items: center;
}
.mod-kreataxmatch.page-dashboard .krea-action-form .krea-manual-match-select,
.mod-kreataxmatch.page-dashboard .krea-action-form .select2-container.krea-manual-match-select2 {
	flex: 1 1 0;
	min-width: 0;
	max-width: none;
}
.mod-kreataxmatch.page-dashboard .krea-manual-match-select {
	width: 100%;
	min-width: 0;
	max-width: none;
}
.mod-kreataxmatch.page-dashboard .select2-container.krea-manual-match-select2 {
	width: auto !important;
	min-width: 0;
	max-width: none;
}
.mod-kreataxmatch.page-dashboard .krea-note-filter-tools {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 4px;
	width: 100%;
}
.mod-kreataxmatch.page-dashboard .krea-note-filter-cell {
	padding-right: 8px;
}
.mod-kreataxmatch.page-dashboard .krea-note-filter-wrap {
	display: flex;
	align-items: center;
	gap: 4px;
	width: 100%;
}
.mod-kreataxmatch.page-dashboard .krea-note-filter-wrap .flat {
	flex: 1 1 auto;
	min-width: 150px;
}
.mod-kreataxmatch.page-dashboard td.krea-note-cell .opacitymedium {
	display: inline-block;
	min-height: 1.2em;
}
</style>' . "\n";

print load_fiche_titre($title, '', 'generic');

$head = kreataxmatchPrepareHead();
print dol_get_fiche_head($head, 'dashboard', $title, 0, 'generic');

if ($matchResult !== null && is_array($matchResult)) {
	$stats = isset($matchResult['stats']) && is_array($matchResult['stats']) ? $matchResult['stats'] : array();
	$rows  = isset($matchResult['rows'])  && is_array($matchResult['rows'])  ? $matchResult['rows']  : array();
	$statusLabels = array(
		'matched' => $langs->trans('KreaTaxMatchStatusMatched'),
		'manual' => $langs->trans('KreaTaxMatchStatusManual'),
		'missing' => $langs->trans('KreaTaxMatchStatusMissingInDolibarr'),
		'discrepancy' => $langs->trans('KreaTaxMatchStatusDiscrepancy'),
	);

	// -----------------------------------------------------------------------
	// In-memory filter
	// -----------------------------------------------------------------------
	$searchAmountNumeric = null;
	$searchAmountComparable = '';
	if ($searchAmount !== '') {
		$parsedAmount = function_exists('price2num') ? price2num($searchAmount, 'MT') : str_replace(',', '.', $searchAmount);
		if ($parsedAmount !== '' && is_numeric((string) $parsedAmount)) {
			$searchAmountNumeric = (float) $parsedAmount;
		} else {
			$searchAmountComparable = strtoupper($searchAmount);
		}
	}

	$filteredRows = array();
	foreach ($rows as $row) {
		$status = isset($row['status'])            ? (string) $row['status']            : 'discrepancy';
		$at     = (isset($row['at'])     && is_array($row['at']))     ? $row['at']     : array();
		$dol    = (isset($row['dolibarr']) && is_array($row['dolibarr'])) ? $row['dolibarr'] : array();
		$note   = isset($row['manual_match_note']) ? (string) $row['manual_match_note'] : '';
		$reason = isset($row['reason'])             ? (string) $row['reason']            : '';
		if ($reason !== '') {
			$t = $langs->trans($reason);
			if ($t !== $reason) {
				$reason = $t;
			}
		}

		if ($searchStatus !== 'all' && $status !== $searchStatus) {
			continue;
		}
		if ($searchSupplier !== '') {
			$hay = strtoupper(trim(
				(string) (isset($dol['supplier_name']) ? $dol['supplier_name'] : '') . ' ' .
				(string) (isset($at['supplier_name'])  ? $at['supplier_name']  : '')
			));
			if (strpos($hay, strtoupper($searchSupplier)) === false) {
				continue;
			}
		}
		if ($searchVat !== '') {
			$hay = strtoupper(trim(
				(string) (isset($dol['supplier_vat']) ? $dol['supplier_vat'] : '') . ' ' .
				(string) (isset($at['supplier_vat'])  ? $at['supplier_vat']  : '')
			));
			if (strpos($hay, strtoupper($searchVat)) === false) {
				continue;
			}
		}
		if ($searchRef !== '') {
			$dolSearchRef = $matcher->resolveDolibarrInvoiceReference($dol);
			$hay = strtoupper(trim(
				(string) $dolSearchRef . ' ' .
				(string) (isset($at['supplier_ref'])  ? $at['supplier_ref']  : '')
			));
			if (strpos($hay, strtoupper($searchRef)) === false) {
				continue;
			}
		}
		if ($searchAmount !== '') {
			$atAmount = isset($at['total_ttc']) ? $at['total_ttc'] : null;
			if ($atAmount === null || $atAmount === '') {
				continue;
			}

			if ($searchAmountNumeric !== null) {
				if (abs(((float) $atAmount) - $searchAmountNumeric) > 0.01) {
					continue;
				}
			} else {
				$hay = strtoupper(trim(sprintf('%.2F', (float) $atAmount) . ' ' . price((float) $atAmount)));
				if ($searchAmountComparable === '' || strpos($hay, $searchAmountComparable) === false) {
					continue;
				}
			}
		}
		if ($searchDolRef !== '') {
			$hay = strtoupper(trim(
				(string) (isset($dol['ref']) ? $dol['ref'] : '') . ' ' .
				(string) $matcher->resolveDolibarrInvoiceReference($dol)
			));
			if (strpos($hay, strtoupper($searchDolRef)) === false) {
				continue;
			}
		}
		if ($searchNote !== '') {
			$hay = strtoupper(trim($note));
			if (strpos($hay, strtoupper($searchNote)) === false) {
				continue;
			}
		}

		$filteredRows[] = $row;
	}
	$rows = $filteredRows;

	// -----------------------------------------------------------------------
	// In-memory sort — mirrors the ORDER BY clause in a standard DB list page
	// -----------------------------------------------------------------------
	$sortKeyMap = array(
		'status'         => array('root' => 'status',            'sub' => null),
		'at_document_id' => array('root' => 'at',                'sub' => 'at_document_id'),
		'supplier_name'  => array('root' => 'at',                'sub' => 'supplier_name'),
		'supplier_vat'   => array('root' => 'at',                'sub' => 'supplier_vat'),
		'at_ref'         => array('root' => 'at',                'sub' => 'supplier_ref'),
		'at_date'        => array('root' => 'at',                'sub' => 'invoice_date'),
		'at_amount'      => array('root' => 'at',                'sub' => 'total_ttc'),
		'dol_ref'        => array('root' => 'dolibarr',          'sub' => 'ref'),
		'note'           => array('root' => 'manual_match_note', 'sub' => null),
	);
	$activeSortEntry = isset($sortKeyMap[$sortfield]) ? $sortKeyMap[$sortfield] : $sortKeyMap['at_date'];
	$isDesc          = ($sortorder === 'DESC');

	usort($rows, static function ($a, $b) use ($activeSortEntry, $isDesc) {
		$get = static function ($row, $entry) {
			$root = $entry['root'];
			$sub  = $entry['sub'];
			if ($sub === null) {
				return isset($row[$root]) ? (string) $row[$root] : '';
			}
			$parent = (isset($row[$root]) && is_array($row[$root])) ? $row[$root] : array();
			return isset($parent[$sub]) ? (string) $parent[$sub] : '';
		};
		$cmp = strnatcasecmp($get($a, $activeSortEntry), $get($b, $activeSortEntry));
		return $isDesc ? -$cmp : $cmp;
	});

	// -----------------------------------------------------------------------
	// Post-filter stats
	// -----------------------------------------------------------------------
	$stats = array('total' => 0, 'matched' => 0, 'manual' => 0, 'missing' => 0, 'discrepancy' => 0);
	foreach ($rows as $fr) {
		$fs = isset($fr['status']) ? (string) $fr['status'] : 'discrepancy';
		if (!isset($stats[$fs])) {
			$fs = 'discrepancy';
		}
		$stats['total']++;
		$stats[$fs]++;
	}

	// -----------------------------------------------------------------------
	// Pagination
	// -----------------------------------------------------------------------
	$totalRows = count($rows);
	if ($totalRows > 0) {
		$maxPage = (int) floor(($totalRows - 1) / $limit);
		if ($page > $maxPage) {
			$page = $maxPage;
		}
	}
	$offset      = $page * $limit;
	$rowsVisible = array_slice($rows, $offset, $limit);
	// Dolibarr pager shows next arrow when $num > $limit
	$numRows = min($totalRows - $offset, $limit + 1);
	if ($numRows < 0) {
		$numRows = 0;
	}

	// -----------------------------------------------------------------------
	// Summary badges
	// -----------------------------------------------------------------------
	print '<div class="marginbottomonly">';
	print '<span class="badge marginrightonly" style="background:#2f9e44;color:#fff;">'
		. dol_escape_htmltag($statusLabels['matched'])     . ': ' . (int) $stats['matched']     . '</span>';
	print '<span class="badge marginrightonly" style="background:#1971c2;color:#fff;">'
		. dol_escape_htmltag($statusLabels['manual'])      . ': ' . (int) $stats['manual']      . '</span>';
	print '<span class="badge marginrightonly" style="background:#e03131;color:#fff;">'
		. dol_escape_htmltag($statusLabels['missing'])     . ': ' . (int) $stats['missing']     . '</span>';
	print '<span class="badge marginrightonly" style="background:#f08c00;color:#fff;">'
		. dol_escape_htmltag($statusLabels['discrepancy']) . ': ' . (int) $stats['discrepancy'] . '</span>';
	print '<span class="opacitymedium marginrightonly">'
		. $langs->trans('KreaTaxMatchSupplierInvoicesLoaded', (int) $invoiceCount) . '</span>';
	print '<span class="opacitymedium">'
		. $langs->trans('KreaTaxMatchDocumentsStored', (int) $documentsCount) . '</span>';
	print '</div>';

	if ($documentsCount === 0) {
		print '<div class="warning">' . $langs->trans('KreaTaxMatchNoAtDataDownloaded') . '</div>';
	}

	// -----------------------------------------------------------------------
	// Filter form (GET, self-closing).
	// The table lives OUTSIDE this form; filter-row inputs reference it via
	// the HTML5 form="" attribute.  GET keeps state in the URL (bookmarkable,
	// refreshable) and does not require a CSRF token.
	// -----------------------------------------------------------------------
	print '<form method="GET" id="searchFormList" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	print '<input type="hidden" name="mainmenu"  value="' . dol_escape_htmltag($mainmenu !== '' ? $mainmenu : 'billing') . '">';
	print '<input type="hidden" name="leftmenu"  value="' . dol_escape_htmltag($leftmenu !== '' ? $leftmenu : 'kreataxmatch') . '">';
	print '<input type="hidden" name="action"    value="view">';
	print '<input type="hidden" name="page"      value="0">';
	print '<input type="hidden" name="limit"     value="' . ((int) $limit) . '">';
	print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
	print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
	print '</form>';

	// -----------------------------------------------------------------------
	// Pager form (wraps print_barre_liste so every page link carries the
	// current filter state via $param and sort state via separate args).
	// -----------------------------------------------------------------------
	print '<form method="GET" id="pagerFormList" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
	print '<input type="hidden" name="mainmenu"   value="' . dol_escape_htmltag($mainmenu !== '' ? $mainmenu : 'billing') . '">';
	print '<input type="hidden" name="leftmenu"   value="' . dol_escape_htmltag($leftmenu !== '' ? $leftmenu : 'kreataxmatch') . '">';
	print '<input type="hidden" name="action"     value="view">';
	print '<input type="hidden" name="date_start" value="' . dol_escape_htmltag($dateStart) . '">';
	print '<input type="hidden" name="date_end"   value="' . dol_escape_htmltag($dateEnd) . '">';
	if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
		print '<input type="hidden" name="limit" value="' . ((int) $limit) . '">';
	}
	if ($searchStatus !== 'all') {
		print '<input type="hidden" name="search_status"   value="' . dol_escape_htmltag($searchStatus) . '">';
	}
	if ($searchSupplier !== '') {
		print '<input type="hidden" name="search_supplier" value="' . dol_escape_htmltag($searchSupplier) . '">';
	}
	if ($searchVat !== '') {
		print '<input type="hidden" name="search_vat"      value="' . dol_escape_htmltag($searchVat) . '">';
	}
	if ($searchRef !== '') {
		print '<input type="hidden" name="search_ref"      value="' . dol_escape_htmltag($searchRef) . '">';
	}
	if ($searchAmount !== '') {
		print '<input type="hidden" name="search_amount"   value="' . dol_escape_htmltag($searchAmount) . '">';
	}
	if ($searchDolRef !== '') {
		print '<input type="hidden" name="search_dol_ref"  value="' . dol_escape_htmltag($searchDolRef) . '">';
	}
	if ($searchNote !== '') {
		print '<input type="hidden" name="search_note"     value="' . dol_escape_htmltag($searchNote) . '">';
	}
	print_barre_liste(
		$langs->trans('KreaTaxMatchInvoiceTab'),
		$page,
		$_SERVER['PHP_SELF'],
		$param,
		$sortfield,
		$sortorder,
		'',
		$numRows,
		$totalRows,
		'title_setup',
		0, '', '', $limit, 0, 0, 1
	);
	print '</form>';

	// -----------------------------------------------------------------------
	// List table.
	// The table is NOT wrapped by any form so the per-row POST forms (save /
	// delete manual match) inside <td> cells are valid standalone forms with
	// no nested-form violation.
	// -----------------------------------------------------------------------
	print '<div class="div-table-responsive">';
	print '<table class="tagtable nobottomiftotal liste">';

	// -- Filter row ----------------------------------------------------------
	// Each input carries form="searchFormList" to associate it with the GET
	// form declared above even though the input sits outside that form tag.
	print '<tr class="liste_titre_filter">';
	// Col 1 – Status
	print '<td class="liste_titre">';
	print '<select name="search_status" class="flat maxwidth150" form="searchFormList">';
	print '<option value="all"'         . ($searchStatus === 'all'         ? ' selected' : '') . '>' . dol_escape_htmltag($langs->trans('KreaTaxMatchFilterStatusAll'))         . '</option>';
	print '<option value="matched"'     . ($searchStatus === 'matched'     ? ' selected' : '') . '>' . dol_escape_htmltag($statusLabels['matched'])     . '</option>';
	print '<option value="manual"'      . ($searchStatus === 'manual'      ? ' selected' : '') . '>' . dol_escape_htmltag($statusLabels['manual'])      . '</option>';
	print '<option value="missing"'     . ($searchStatus === 'missing'     ? ' selected' : '') . '>' . dol_escape_htmltag($statusLabels['missing'])     . '</option>';
	print '<option value="discrepancy"' . ($searchStatus === 'discrepancy' ? ' selected' : '') . '>' . dol_escape_htmltag($statusLabels['discrepancy']) . '</option>';
	print '</select>';
	print '</td>';
		// Col 2 – AT document id: no filter
		print '<td class="liste_titre">&nbsp;</td>';
	// Col 3 – ThirdParty
	print '<td class="liste_titre">';
	print '<input class="flat minwidth150" type="text" name="search_supplier" value="' . dol_escape_htmltag($searchSupplier) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaTaxMatchFilterSupplierPlaceholder')) . '" form="searchFormList">';
	print '</td>';
	// Col 4 – VATIntra
	print '<td class="liste_titre">';
	print '<input class="flat width100" type="text" name="search_vat" value="' . dol_escape_htmltag($searchVat) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaTaxMatchFilterVatPlaceholder')) . '" form="searchFormList">';
	print '</td>';
		// Col 5 – RefSupplier (AT)
		print '<td class="liste_titre">';
		print '<input class="flat minwidth100" type="text" name="search_ref" value="' . dol_escape_htmltag($searchRef) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaTaxMatchFilterRefPlaceholder')) . '" form="searchFormList">';
		print '</td>';
		// Col 6 – Date (AT): stacked date-range picker
		print '<td class="liste_titre">';
	print '<div style="display:flex;gap:4px;flex-direction:column;min-width:130px">';
	print '<input class="flat" type="date" name="date_start" value="' . dol_escape_htmltag($dateStart) . '" title="' . dol_escape_htmltag($langs->trans('From')) . '" form="searchFormList">';
	print '<input class="flat" type="date" name="date_end"   value="' . dol_escape_htmltag($dateEnd)   . '" title="' . dol_escape_htmltag($langs->trans('To'))   . '" form="searchFormList">';
	print '</div>';
	print '</td>';
		// Col 7 – AmountTTC (AT)
		print '<td class="liste_titre">';
		print '<input class="flat width75 right" type="text" name="search_amount" value="' . dol_escape_htmltag($searchAmount) . '" placeholder="' . dol_escape_htmltag($langs->trans('AmountTTC')) . '" form="searchFormList">';
		print '</td>';
		// Col 8 – Supplier invoice
		print '<td class="liste_titre">';
		print '<input class="flat minwidth100" type="text" name="search_dol_ref" value="' . dol_escape_htmltag($searchDolRef) . '" placeholder="' . dol_escape_htmltag($langs->trans('SupplierInvoice')) . '" form="searchFormList">';
		print '</td>';
		// Col 9 – Action: no direct filter
		print '<td class="liste_titre">&nbsp;</td>';
		// Col 10 – Note + search/reset buttons aligned to the extreme right
		print '<td class="liste_titre krea-note-filter-cell">';
		print '<div class="krea-note-filter-wrap">';
		print '<input class="flat" type="text" name="search_note" value="' . dol_escape_htmltag($searchNote) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaTaxMatchFilterNotePlaceholder')) . '" form="searchFormList">';
		print '<div class="krea-note-filter-tools">';
		print '<button type="submit" class="liste_titre button_search reposition"       name="button_search_x"       value="x" form="searchFormList"><span class="fas fa-search"></span></button>';
		print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x" form="searchFormList"><span class="fas fa-times"></span></button>';
		print '</div>';
		print '</div>';
		print '</td>';
	print '</tr>';

	// -- Column-header row (sortable) ----------------------------------------
	// Passing $sortfield / $sortorder turns each header into a clickable sort
	// link.  $param carries all active filter state so it survives the click.
	print '<tr class="liste_titre">';
		print_liste_field_titre($langs->trans('Status'),                    $_SERVER['PHP_SELF'], 'status',         '', $param, '',              $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('KreaTaxMatchAtDocumentId'),  $_SERVER['PHP_SELF'], 'at_document_id', '', $param, 'class="right"', $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('ThirdParty'),                $_SERVER['PHP_SELF'], 'supplier_name',  '', $param, '',              $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('VATIntra'),                  $_SERVER['PHP_SELF'], 'supplier_vat',   '', $param, '',              $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('RefSupplier'),               $_SERVER['PHP_SELF'], 'at_ref',         '', $param, '',              $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('Date') . ' (AT)',            $_SERVER['PHP_SELF'], 'at_date',        '', $param, '',              $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('AmountTTC') . ' (AT)',       $_SERVER['PHP_SELF'], 'at_amount',      '', $param, 'class="right"', $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('SupplierInvoice'),           $_SERVER['PHP_SELF'], 'dol_ref',        '', $param, '',              $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('Action'),                    '',                   '',               '', '',     'class="center"');
		print_liste_field_titre($langs->trans('Note'),                      $_SERVER['PHP_SELF'], 'note',           '', $param, '',              $sortfield, $sortorder);
		print '</tr>';

	// -- Data rows -----------------------------------------------------------
	foreach ($rowsVisible as $row) {
		$status      = isset($row['status'])            ? (string) $row['status']            : 'discrepancy';
		$reason      = isset($row['reason'])             ? (string) $row['reason']             : '';
		$at          = (isset($row['at'])      && is_array($row['at']))      ? $row['at']      : null;
		$dol         = (isset($row['dolibarr']) && is_array($row['dolibarr'])) ? $row['dolibarr'] : array();
		$manualMatch = !empty($row['manual_match']);
		$manualNote  = isset($row['manual_match_note']) ? (string) $row['manual_match_note'] : '';

		$statusLabel = isset($statusLabels[$status]) ? $statusLabels[$status] : $status;
		$statusColor = '#f08c00';
		if ($status === 'matched') {
			$statusColor = '#2f9e44';
		} elseif ($status === 'manual') {
			$statusColor = '#1971c2';
		} elseif ($status === 'missing') {
			$statusColor = '#e03131';
		}

			$documentRowId = ($at !== null && !empty($at['stored_rowid'])) ? (int) $at['stored_rowid'] : 0;
			$atDocumentId = ($at !== null && !empty($at['at_document_id'])) ? (int) $at['at_document_id'] : 0;
			$invoiceRowId = isset($dol['rowid']) ? (int) $dol['rowid'] : 0;
			$atVat        = $matcher->normalizeVat($at !== null && isset($at['supplier_vat']) ? $at['supplier_vat'] : '');
			$atRef        = $matcher->normalizeReference($at !== null && isset($at['supplier_ref']) ? $at['supplier_ref'] : '');
			$primaryKey   = ($atVat !== '' && $atRef !== '') ? ($atVat . '|' . $atRef) : '';

			$manualOptions = array();
			if ($primaryKey !== '' && isset($invoiceOptionsByPrimaryKey[$primaryKey])) {
				$manualOptions = $invoiceOptionsByPrimaryKey[$primaryKey];
			} elseif ($atVat !== '' && isset($invoiceOptionsByVat[$atVat])) {
				$manualOptions = $invoiceOptionsByVat[$atVat];
			}
			if ($invoiceRowId > 0 && isset($invoiceOptionByInvoiceId[$invoiceRowId])) {
				$manualOptions[$invoiceRowId] = $invoiceOptionByInvoiceId[$invoiceRowId];
			}

			$supplierNameDisplay = '';
			if ($at !== null && !empty($at['supplier_name'])) {
				$supplierNameDisplay = (string) $at['supplier_name'];
			} elseif (!empty($dol['supplier_name'])) {
				$supplierNameDisplay = (string) $dol['supplier_name'];
			}
			$supplierVatDisplay = '';
			if ($at !== null && !empty($at['supplier_vat'])) {
				$supplierVatDisplay = (string) $at['supplier_vat'];
			} elseif (!empty($dol['supplier_vat'])) {
				$supplierVatDisplay = (string) $dol['supplier_vat'];
			}
				$atRefDisplay = ($at !== null && !empty($at['supplier_ref'])) ? (string) $at['supplier_ref'] : '';
				$atDateDisplay = ($at !== null && !empty($at['invoice_date'])) ? (string) $at['invoice_date'] : '';
				$atAmountDisplay = ($at !== null && isset($at['total_ttc'])) ? $at['total_ttc'] : null;
				$selectedInvoiceRowId = $invoiceRowId;
				$selectedInvoiceRowIdForAction = ((in_array($status, array('matched', 'manual', 'discrepancy'), true) || $manualMatch) && $invoiceRowId > 0) ? $invoiceRowId : 0;

			print '<tr class="oddeven">';

			// Col 1 – Status badge
			print '<td>';
			print '<span class="badge" style="background:' . $statusColor . ';color:#fff;white-space:normal;display:inline-block;text-align:center;line-height:1.3;">' . dol_escape_htmltag($statusLabel) . '</span>';
			print '</td>';

			// Col 2 – AT document id
			print '<td class="right">' . ($atDocumentId > 0 ? ((int) $atDocumentId) : '-') . '</td>';

			// Col 3 – Supplier name (AT first)
			print '<td>' . ($supplierNameDisplay !== '' ? dol_escape_htmltag($supplierNameDisplay) : '-') . '</td>';
			// Col 4 – VAT (AT first)
			print '<td>' . ($supplierVatDisplay !== '' ? dol_escape_htmltag($supplierVatDisplay) : '-') . '</td>';
			// Col 5 – Ref supplier (AT)
			print '<td>' . ($atRefDisplay !== '' ? dol_escape_htmltag($atRefDisplay) : '-') . '</td>';
			// Col 6 – Date (AT)
			print '<td>' . ($atDateDisplay !== '' ? dol_escape_htmltag($atDateDisplay) : '-') . '</td>';
			// Col 7 – Amount TTC (AT)
			print '<td class="right">' . ($atAmountDisplay !== null && $atAmountDisplay !== '' ? price((float) $atAmountDisplay) : '-') . '</td>';

			// Col 8 – Invoice ref (link to supplier invoice card)
			if ($invoiceRowId > 0) {
				print '<td><a class="krea-supplier-invoice-link" target="_blank" rel="noopener noreferrer" href="' . DOL_URL_ROOT . '/fourn/facture/card.php?facid=' . $invoiceRowId . '">'
					. dol_escape_htmltag((string) (isset($dol['ref']) ? $dol['ref'] : ('#' . $invoiceRowId)))
					. '</a></td>';
			} else {
				print '<td>-</td>';
			}

				$canEditInlineNote = $canWrite && $documentRowId > 0;
			$noteEditorId = 'krea-note-editor-' . $documentRowId;
			$noteViewerId = 'krea-note-view-' . $documentRowId;

			// Col 9 – Action: per-row POST forms.
			print '<td class="krea-action-cell">';
			print '<div class="krea-action-row">';

			if ($canWrite && $documentRowId > 0 && !empty($manualOptions)) {
				print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" class="krea-action-form">';
				print '<input type="hidden" name="token"           value="' . newToken() . '">';
				print '<input type="hidden" name="action"          value="save_manual_match">';
				print '<input type="hidden" name="mainmenu"        value="' . dol_escape_htmltag($mainmenu !== '' ? $mainmenu : 'billing') . '">';
				print '<input type="hidden" name="leftmenu"        value="' . dol_escape_htmltag($leftmenu !== '' ? $leftmenu : 'kreataxmatch') . '">';
				print '<input type="hidden" name="date_start"      value="' . dol_escape_htmltag($dateStart) . '">';
				print '<input type="hidden" name="date_end"        value="' . dol_escape_htmltag($dateEnd) . '">';
				print '<input type="hidden" name="page"            value="' . ((int) $page) . '">';
				print '<input type="hidden" name="limit"           value="' . ((int) $limit) . '">';
				print '<input type="hidden" name="sortfield"       value="' . dol_escape_htmltag($sortfield) . '">';
				print '<input type="hidden" name="sortorder"       value="' . dol_escape_htmltag($sortorder) . '">';
				print '<input type="hidden" name="search_status"   value="' . dol_escape_htmltag($searchStatus) . '">';
				print '<input type="hidden" name="search_supplier" value="' . dol_escape_htmltag($searchSupplier) . '">';
				print '<input type="hidden" name="search_vat"      value="' . dol_escape_htmltag($searchVat) . '">';
				print '<input type="hidden" name="search_ref"      value="' . dol_escape_htmltag($searchRef) . '">';
				print '<input type="hidden" name="search_amount"   value="' . dol_escape_htmltag($searchAmount) . '">';
				print '<input type="hidden" name="search_dol_ref"  value="' . dol_escape_htmltag($searchDolRef) . '">';
				print '<input type="hidden" name="search_note"     value="' . dol_escape_htmltag($searchNote) . '">';
				print '<input type="hidden" name="document_rowid"  value="' . $documentRowId . '">';
				print '<input type="hidden" name="manual_note"     value="' . dol_escape_htmltag($manualNote) . '">';
				print '<select class="flat krea-manual-match-select" name="invoice_rowid">';
				if ($selectedInvoiceRowIdForAction <= 0) {
					print '<option value="" selected></option>';
				}
				foreach ($manualOptions as $optionInvoiceId => $optionLabel) {
					$selected = ((int) $optionInvoiceId === $selectedInvoiceRowIdForAction) ? ' selected' : '';
					print '<option value="' . ((int) $optionInvoiceId) . '"' . $selected . '>' . dol_escape_htmltag((string) $optionLabel) . '</option>';
				}
				print '</select>';
				print '<button type="submit" class="krea-inline-icon-btn" title="' . dol_escape_htmltag($langs->trans('Save')) . '"><span class="fas fa-save"></span></button>';
				print '</form>';
			}

			if ($canWrite && $manualMatch && $documentRowId > 0) {
				print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" class="krea-action-delete-form">';
				print '<input type="hidden" name="token"           value="' . newToken() . '">';
				print '<input type="hidden" name="action"          value="delete_manual_match">';
				print '<input type="hidden" name="mainmenu"        value="' . dol_escape_htmltag($mainmenu !== '' ? $mainmenu : 'billing') . '">';
				print '<input type="hidden" name="leftmenu"        value="' . dol_escape_htmltag($leftmenu !== '' ? $leftmenu : 'kreataxmatch') . '">';
				print '<input type="hidden" name="date_start"      value="' . dol_escape_htmltag($dateStart) . '">';
				print '<input type="hidden" name="date_end"        value="' . dol_escape_htmltag($dateEnd) . '">';
				print '<input type="hidden" name="page"            value="' . ((int) $page) . '">';
				print '<input type="hidden" name="limit"           value="' . ((int) $limit) . '">';
				print '<input type="hidden" name="sortfield"       value="' . dol_escape_htmltag($sortfield) . '">';
				print '<input type="hidden" name="sortorder"       value="' . dol_escape_htmltag($sortorder) . '">';
				print '<input type="hidden" name="search_status"   value="' . dol_escape_htmltag($searchStatus) . '">';
				print '<input type="hidden" name="search_supplier" value="' . dol_escape_htmltag($searchSupplier) . '">';
				print '<input type="hidden" name="search_vat"      value="' . dol_escape_htmltag($searchVat) . '">';
				print '<input type="hidden" name="search_ref"      value="' . dol_escape_htmltag($searchRef) . '">';
				print '<input type="hidden" name="search_amount"   value="' . dol_escape_htmltag($searchAmount) . '">';
				print '<input type="hidden" name="search_dol_ref"  value="' . dol_escape_htmltag($searchDolRef) . '">';
				print '<input type="hidden" name="search_note"     value="' . dol_escape_htmltag($searchNote) . '">';
				print '<input type="hidden" name="document_rowid"  value="' . $documentRowId . '">';
				print '<button type="submit" class="krea-inline-icon-btn krea-danger" title="' . dol_escape_htmltag($langs->trans('Delete')) . '"><span class="fas fa-trash-alt"></span></button>';
				print '</form>';
			}

			if ((!$canWrite || empty($manualOptions)) && !$manualMatch) {
				print '<span class="opacitymedium">-</span>';
			}

			print '</div>';
			print '</td>';

			// Col 10 – Note / reason
			if ($reason !== '') {
				$t      = $langs->trans($reason);
				$reason = ($t !== $reason) ? $t : $reason;
			}
			$noteToShow = $manualNote;
			print '<td class="krea-note-cell">';
			if ($canEditInlineNote) {
				print '<div id="' . dol_escape_htmltag($noteViewerId) . '" class="krea-note-view">';
				if ($noteToShow !== '') {
					print '<span class="krea-note-text" title="' . dol_escape_htmltag($noteToShow) . '">' . dol_escape_htmltag(dol_trunc($noteToShow, 90)) . '</span>';
				} else {
					print '<span class="krea-note-text opacitymedium">-</span>';
				}
				print '<button type="button" class="krea-inline-icon-btn krea-note-edit-toggle" title="' . dol_escape_htmltag($langs->trans('Edit')) . '" data-editor="' . dol_escape_htmltag($noteEditorId) . '" data-view="' . dol_escape_htmltag($noteViewerId) . '"><span class="fas fa-edit"></span></button>';
				print '</div>';
				print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '" id="' . dol_escape_htmltag($noteEditorId) . '" class="krea-note-edit-form">';
				print '<input type="hidden" name="token"           value="' . newToken() . '">';
				print '<input type="hidden" name="action"          value="save_manual_note">';
				print '<input type="hidden" name="mainmenu"        value="' . dol_escape_htmltag($mainmenu !== '' ? $mainmenu : 'billing') . '">';
				print '<input type="hidden" name="leftmenu"        value="' . dol_escape_htmltag($leftmenu !== '' ? $leftmenu : 'kreataxmatch') . '">';
				print '<input type="hidden" name="date_start"      value="' . dol_escape_htmltag($dateStart) . '">';
				print '<input type="hidden" name="date_end"        value="' . dol_escape_htmltag($dateEnd) . '">';
				print '<input type="hidden" name="page"            value="' . ((int) $page) . '">';
				print '<input type="hidden" name="limit"           value="' . ((int) $limit) . '">';
				print '<input type="hidden" name="sortfield"       value="' . dol_escape_htmltag($sortfield) . '">';
				print '<input type="hidden" name="sortorder"       value="' . dol_escape_htmltag($sortorder) . '">';
				print '<input type="hidden" name="search_status"   value="' . dol_escape_htmltag($searchStatus) . '">';
				print '<input type="hidden" name="search_supplier" value="' . dol_escape_htmltag($searchSupplier) . '">';
				print '<input type="hidden" name="search_vat"      value="' . dol_escape_htmltag($searchVat) . '">';
				print '<input type="hidden" name="search_ref"      value="' . dol_escape_htmltag($searchRef) . '">';
				print '<input type="hidden" name="search_amount"   value="' . dol_escape_htmltag($searchAmount) . '">';
				print '<input type="hidden" name="search_dol_ref"  value="' . dol_escape_htmltag($searchDolRef) . '">';
				print '<input type="hidden" name="search_note"     value="' . dol_escape_htmltag($searchNote) . '">';
				print '<input type="hidden" name="document_rowid"  value="' . $documentRowId . '">';
				print '<input type="text" class="flat" name="manual_note" maxlength="90" value="' . dol_escape_htmltag($manualNote) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaTaxMatchManualMatchNote')) . '">';
				print '<button type="submit" class="krea-inline-icon-btn" title="' . dol_escape_htmltag($langs->trans('Save')) . '"><span class="fas fa-save"></span></button>';
				print '<button type="button" class="krea-inline-icon-btn krea-note-edit-cancel" title="' . dol_escape_htmltag($langs->trans('Cancel')) . '" data-editor="' . dol_escape_htmltag($noteEditorId) . '" data-view="' . dol_escape_htmltag($noteViewerId) . '"><span class="fas fa-times"></span></button>';
				print '</form>';
			} else {
				if ($noteToShow !== '') {
					print '<span title="' . dol_escape_htmltag($noteToShow) . '">' . dol_escape_htmltag(dol_trunc($noteToShow, 90)) . '</span>';
				} else {
					print '<span class="opacitymedium">-</span>';
				}
			}
			print '</td>';
			print '</tr>';
	}

	print '</table>';
	print '</div>';
}

print '<script>
jQuery(function ($) {
	function initManualMatchSelects(context) {
		$(context).find(".krea-manual-match-select").each(function () {
			var $select = $(this);
			if ($select.hasClass("select2-hidden-accessible") || typeof $select.select2 !== "function") {
				return;
			}

			$select.select2({
				width: "style",
				dropdownAutoWidth: true,
				minimumResultsForSearch: 0,
				dropdownCssClass: "ui-dialog"
			});
			$select.next(".select2-container").addClass("krea-manual-match-select2");
		});
	}

	initManualMatchSelects(document);
	$("a.reposition[href*=\"sortfield=dol_ref\"]").attr({
		target: "_blank",
		rel: "noopener noreferrer"
	});

	$(document).off("click.kreaNoteEdit", ".krea-note-edit-toggle").on("click.kreaNoteEdit", ".krea-note-edit-toggle", function () {
		var editorId = $(this).data("editor");
		var viewId = $(this).data("view");
		if (!editorId || !viewId) return;

		$("#" + viewId).hide();
		$("#" + editorId).css("display", "flex");
		$("#" + editorId).find(\'input[name="manual_note"]\').trigger("focus");
	});

	$(document).off("click.kreaNoteCancel", ".krea-note-edit-cancel").on("click.kreaNoteCancel", ".krea-note-edit-cancel", function () {
		var editorId = $(this).data("editor");
		var viewId = $(this).data("view");
		if (!editorId || !viewId) return;

		$("#" + editorId).hide();
		$("#" + viewId).css("display", "flex");
	});
});
</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
