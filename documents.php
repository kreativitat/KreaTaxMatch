<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    kreataxmatch/documents.php
 * \ingroup kreataxmatch
 * \brief   List of AT documents stored in the database.
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

require_once './lib/kreataxmatch.lib.php';
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

$mainmenu = GETPOST('mainmenu', 'aZ09');
$leftmenu = GETPOST('leftmenu', 'aZ09');

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
if (GETPOST('button_search', 'alpha') !== '' || GETPOST('button_search_x', 'alpha') !== '' || GETPOST('button_removefilter', 'alpha') !== '' || GETPOST('button_removefilter_x', 'alpha') !== '' || GETPOST('removefilter', 'alpha') !== '') {
	$page = 0;
}

// Sort
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper((string) GETPOST('sortorder', 'aZ09comma'));
if (!$sortfield) {
	$sortfield = 'invoice_date';
}
if (!in_array($sortorder, array('ASC', 'DESC'), true)) {
	$sortorder = 'DESC';
}

// Filters
$defaultStart = '';
$defaultEnd   = '';

// Reset filters
if (GETPOST('button_removefilter_x', 'alpha') !== '' || GETPOST('button_removefilter', 'alpha') !== '' || GETPOST('removefilter', 'alpha') !== '') {
	$dateStart   = $defaultStart;
	$dateEnd     = $defaultEnd;
	$searchVat   = '';
	$searchName  = '';
	$searchRef   = '';
} else {
	$dateStart  = GETPOST('date_start',    'alphanohtml');
	$dateEnd    = GETPOST('date_end',      'alphanohtml');
	$searchVat  = trim((string) GETPOST('search_vat',  'alphanohtml'));
	$searchName = trim((string) GETPOST('search_name', 'restricthtml'));
	$searchRef  = trim((string) GETPOST('search_ref',  'restricthtml'));
	if ($dateStart === '') {
		$dateStart = $defaultStart;
	}
	if ($dateEnd === '') {
		$dateEnd = $defaultEnd;
	}
}

// ---------------------------------------------------------------------------
// Load data
// ---------------------------------------------------------------------------
$service       = new KreaTaxMatchService($db);
$documents     = array();
$totalDocuments = 0;
$num = 0;

try {
	$totalDocuments = $service->countStoredAtDocuments($dateStart, $dateEnd, $searchVat, $searchName, $searchRef);
	if ($totalDocuments > 0) {
		$maxPage = (int) floor(($totalDocuments - 1) / $limit);
		if ($page > $maxPage) {
			$page = $maxPage;
		}
	}
	$offset    = $page * $limit;
	$documents = $service->fetchStoredAtDocumentsList($dateStart, $dateEnd, $searchVat, $searchName, $searchRef, $sortfield, $sortorder, $limit + 1, $offset);
	$num = count($documents);
	if ($num > $limit) {
		$documents = array_slice($documents, 0, $limit);
	}
} catch (Exception $e) {
	dol_syslog('KreaTaxMatch documents tab error: ' . $e->getMessage(), LOG_ERR);
	setEventMessages($e->getMessage(), null, 'errors');
}

// $param for pager/sort links (no sortfield/sortorder — framework adds them)
$param  = '&mainmenu=' . urlencode($mainmenu !== '' ? $mainmenu : 'billing');
$param .= '&leftmenu=' . urlencode($leftmenu !== '' ? $leftmenu : 'kreataxmatch');
$param .= '&date_start=' . urlencode($dateStart);
$param .= '&date_end='   . urlencode($dateEnd);
if ($searchVat !== '') {
	$param .= '&search_vat=' . urlencode($searchVat);
}
if ($searchName !== '') {
	$param .= '&search_name=' . urlencode($searchName);
}
if ($searchRef !== '') {
	$param .= '&search_ref=' . urlencode($searchRef);
}
if ($limit > 0 && $limit !== (int) $conf->liste_limit) {
	$param .= '&limit=' . ((int) $limit);
}

// ---------------------------------------------------------------------------
// View
// ---------------------------------------------------------------------------
$title = $langs->trans('KreaTaxMatchDocumentsTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-kreataxmatch page-documents bodyforlist');

print '<style>
.mod-kreataxmatch.page-documents tr.liste_titre th,
.mod-kreataxmatch.page-documents tr.liste_titre td,
.mod-kreataxmatch.page-documents tr.liste_titre_filter td {
	background-color: #F1F1F1 !important;
}
.mod-kreataxmatch.page-documents tr.liste_titre th,
.mod-kreataxmatch.page-documents tr.liste_titre td {
	padding-top: 9px !important;
	padding-bottom: 9px !important;
	vertical-align: middle !important;
	line-height: 1.2;
}
</style>' . "\n";

print load_fiche_titre($title, '', 'generic');

$head = kreataxmatchPrepareHead();
print dol_get_fiche_head($head, 'documents', $title, 0, 'generic');

print_barre_liste($langs->trans('KreaTaxMatchDocumentsTab'), $page, $_SERVER['PHP_SELF'],
	$param, $sortfield, $sortorder, '', $num, $totalDocuments, 'title_setup',
	0, '', '', $limit, 0, 0, 1);

// Self-closing GET filter form (inputs associated via form="docSearchForm")
print '<form method="GET" id="docSearchForm" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="mainmenu"  value="' . dol_escape_htmltag($mainmenu !== '' ? $mainmenu : 'billing') . '">';
print '<input type="hidden" name="leftmenu"  value="' . dol_escape_htmltag($leftmenu !== '' ? $leftmenu : 'kreataxmatch') . '">';
print '<input type="hidden" name="page"      value="' . ((int) $page) . '">';
print '<input type="hidden" name="pageplusone" value="' . ((int) ($page + 1)) . '">';
print '<input type="hidden" name="limit"     value="' . ((int) $limit) . '">';
print '<input type="hidden" name="sortfield" value="' . dol_escape_htmltag($sortfield) . '">';
print '<input type="hidden" name="sortorder" value="' . dol_escape_htmltag($sortorder) . '">';
print '</form>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filter row
print '<tr class="liste_titre_filter">';
// Date range — two date pickers stacked
print '<td class="liste_titre">';
print '<div style="display:flex;gap:4px;flex-direction:column;min-width:130px">';
print '<input class="flat" type="date" name="date_start" value="' . dol_escape_htmltag($dateStart) . '" title="' . $langs->trans('DateStart') . '" form="docSearchForm">';
print '<input class="flat" type="date" name="date_end"   value="' . dol_escape_htmltag($dateEnd)   . '" title="' . $langs->trans('DateEnd')   . '" form="docSearchForm">';
print '</div>';
print '</td>';
// Supplier name
print '<td class="liste_titre"><input class="flat minwidth150" type="text" name="search_name" value="' . dol_escape_htmltag($searchName) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaTaxMatchFilterNamePlaceholder')) . '" form="docSearchForm"></td>';
// Supplier VAT
print '<td class="liste_titre"><input class="flat width100" type="text" name="search_vat" value="' . dol_escape_htmltag($searchVat) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaTaxMatchFilterVatPlaceholder')) . '" form="docSearchForm"></td>';
// Supplier ref
print '<td class="liste_titre"><input class="flat minwidth100" type="text" name="search_ref" value="' . dol_escape_htmltag($searchRef) . '" placeholder="' . dol_escape_htmltag($langs->trans('KreaTaxMatchFilterRefPlaceholder')) . '" form="docSearchForm"></td>';
// Amount — no filter
print '<td class="liste_titre">&nbsp;</td>';
// AT Doc ID — no filter
print '<td class="liste_titre center maxwidthsearch">';
print '<div class="nowraponall">';
print '<button type="submit" class="liste_titre button_search reposition" name="button_search_x" value="x" form="docSearchForm"><span class="fas fa-search"></span></button>';
print '<button type="submit" class="liste_titre button_removefilter reposition" name="button_removefilter_x" value="x" form="docSearchForm"><span class="fas fa-times"></span></button>';
print '</div>';
print '</td>';
print '</tr>';

// Header row
print '<tr class="liste_titre">';
print_liste_field_titre($langs->trans('Date'),                     $_SERVER['PHP_SELF'], 'invoice_date',   '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('ThirdParty'),               $_SERVER['PHP_SELF'], 'supplier_name',  '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('VATIntra'),                 $_SERVER['PHP_SELF'], 'supplier_vat',   '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('RefSupplier'),              $_SERVER['PHP_SELF'], 'supplier_ref',   '', $param, '',              $sortfield, $sortorder);
print_liste_field_titre($langs->trans('AmountTTC'),                $_SERVER['PHP_SELF'], 'total_ttc',      '', $param, 'class="right"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans('KreaTaxMatchAtDocumentId'), $_SERVER['PHP_SELF'], 'at_document_id', '', $param, 'class="right"', $sortfield, $sortorder);
print '</tr>';

if (empty($documents)) {
	print '<tr class="oddeven"><td colspan="6" class="opacitymedium">' . $langs->trans('KreaTaxMatchNoDocuments') . '</td></tr>';
} else {
	foreach ($documents as $doc) {
		$ttc = $doc['total_ttc'];
		print '<tr class="oddeven">';
		print '<td>' . dol_escape_htmltag((string) $doc['invoice_date']) . '</td>';
		print '<td>' . dol_escape_htmltag((string) $doc['supplier_name']) . '</td>';
		print '<td>' . dol_escape_htmltag((string) $doc['supplier_vat']) . '</td>';
		print '<td>' . dol_escape_htmltag((string) $doc['supplier_ref']) . '</td>';
		print '<td class="right">' . ($ttc !== null ? price($ttc) : '-') . '</td>';
		print '<td class="right">' . ((int) $doc['at_document_id']) . '</td>';
		print '</tr>';
	}
}

print '</table>';
print '</div>';

print '<script>
jQuery(function ($) {
	var $limitSelect = $(\'form[name="formlimit"] select[name="limit"], select[name="limit"][id="limit"]\');
	if ($limitSelect.length) {
		$limitSelect.off("change.kreaDocumentsLimit").on("change.kreaDocumentsLimit", function () {
			var value = $(this).val();
			var $form = $("#docSearchForm");
			if (!$form.length) return;

			var $hiddenLimit = $form.find(\'input[name="limit"]\');
			if (!$hiddenLimit.length) {
				$hiddenLimit = $(\'<input type="hidden" name="limit">\').appendTo($form);
			}
			$hiddenLimit.val(value);

			var $page = $form.find(\'input[name="page"]\');
			if ($page.length) {
				$page.val(0);
			}
			var $pagePlusOne = $form.find(\'input[name="pageplusone"]\');
			if ($pagePlusOne.length) {
				$pagePlusOne.val(1);
			}

			$form.trigger("submit");
		});
	}

	var $pageInput = $(\'input.pageplusone\');
	if ($pageInput.length) {
		$pageInput.off("change.kreaDocumentsPage keyup.kreaDocumentsPage").on("change.kreaDocumentsPage keyup.kreaDocumentsPage", function (e) {
			if (e.type === "keyup" && e.key !== "Enter") return;
			var value = parseInt($(this).val(), 10);
			if (isNaN(value)) return;

			var $form = $("#docSearchForm");
			if (!$form.length) return;

			var $page = $form.find(\'input[name="page"]\');
			if ($page.length) {
				$page.val(Math.max(0, value - 1));
			}
			var $pagePlusOne = $form.find(\'input[name="pageplusone"]\');
			if ($pagePlusOne.length) {
				$pagePlusOne.val(Math.max(1, value));
			}

			$form.trigger("submit");
		});
	}
});
</script>';

print dol_get_fiche_end();
llxFooter();
$db->close();
