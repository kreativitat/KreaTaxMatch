<?php
/* Copyright (C) 2004-2017 Laurent Destailleur <eldy@users.sourceforge.net>
 * Copyright (C) 2024 Frédéric France <frederic.france@free.fr>
 * Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    kreataxmatch/admin/setup.php
 * \ingroup kreataxmatch
 * \brief   KreaTaxMatch setup page.
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
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/security.lib.php';
require_once '../lib/kreataxmatch.lib.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'kreataxmatch@kreataxmatch'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

if ($action === 'save') {
	if (!kreataxmatchIsTokenValid()) {
		accessforbidden('Invalid CSRF token');
	}

	$atNif = preg_replace('/[^0-9]/', '', (string) GETPOST('KREATAXMATCH_AT_NIF', 'alphanohtml'));
	$atPassword = GETPOST('KREATAXMATCH_AT_PASSWORD', 'password');
	$cronLookbackDays = (int) GETPOST('KREATAXMATCH_CRON_LOOKBACK_DAYS', 'int');
	$accountingColumnEnabled = GETPOSTINT('KREATAXMATCH_ENABLE_ACCOUNTING_COLUMN') ? 1 : 0;

	$error = 0;
	if (!preg_match('/^[0-9]{9}$/', (string) $atNif)) {
		setEventMessages($langs->trans('KreaTaxMatchErrorInvalidNif'), null, 'errors');
		$error++;
	}
	if ($cronLookbackDays <= 0 || $cronLookbackDays > 31) {
		setEventMessages($langs->trans('KreaTaxMatchErrorInvalidCronLookbackDays'), null, 'errors');
		$error++;
	}

	if (!$error) {
		$resultNif = dolibarr_set_const($db, 'KREATAXMATCH_AT_NIF', $atNif, 'chaine', 0, '', $conf->entity);
		if ($resultNif <= 0) {
			$error++;
		}

		if ((string) $atPassword !== '') {
			$encrypted = dolEncrypt((string) $atPassword);
			$resultPassword = dolibarr_set_const($db, 'KREATAXMATCH_AT_PASSWORD', $encrypted, 'chaine', 0, '', $conf->entity);
			if ($resultPassword <= 0) {
				$error++;
			}
		}

		$resultLookback = dolibarr_set_const($db, 'KREATAXMATCH_CRON_LOOKBACK_DAYS', (string) $cronLookbackDays, 'integer', 0, '', $conf->entity);
		if ($resultLookback <= 0) {
			$error++;
		}

		$resultAccountingColumn = dolibarr_set_const($db, 'KREATAXMATCH_ENABLE_ACCOUNTING_COLUMN', (string) $accountingColumnEnabled, 'yesno', 0, '', $conf->entity);
		if ($resultAccountingColumn <= 0) {
			$error++;
		}
	}

	if ($error) {
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
}

$configuredNif = (string) getDolGlobalString('KREATAXMATCH_AT_NIF');
$configuredPassword = (string) getDolGlobalString('KREATAXMATCH_AT_PASSWORD');
$hasPassword = ($configuredPassword !== '');
$maskedPassword = $hasPassword ? str_repeat('*', 12) : '';
$configuredCronLookbackDays = (int) getDolGlobalInt('KREATAXMATCH_CRON_LOOKBACK_DAYS', 2);
if ($configuredCronLookbackDays <= 0) {
	$configuredCronLookbackDays = 2;
}
$configuredAccountingColumnEnabled = getDolGlobalInt('KREATAXMATCH_ENABLE_ACCOUNTING_COLUMN', 1) > 0 ? 1 : 0;

$title = 'KreaTaxMatchSetupTitle';

llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-kreataxmatch page-admin_setup');

$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = kreataxmatchAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), 0, 'generic');

print '<form method="POST" action="' . dol_escape_htmltag($_SERVER['PHP_SELF']) . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="save">';

print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<th colspan="2">' . $langs->trans('KreaTaxMatchAtCredentials') . '</th>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="fieldrequired width40">' . $langs->trans('KreaTaxMatchAtNif') . '</td>';
print '<td>';
print '<input type="text" class="flat width200" name="KREATAXMATCH_AT_NIF" value="' . dol_escape_htmltag($configuredNif) . '" maxlength="9" inputmode="numeric" pattern="[0-9]{9}">';
print '<div class="opacitymedium">' . $langs->trans('KreaTaxMatchAtNifHelp') . '</div>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('KreaTaxMatchAtPassword') . '</td>';
print '<td>';
print '<input type="password" class="flat minwidth300" name="KREATAXMATCH_AT_PASSWORD" value="" autocomplete="new-password">';
if ($hasPassword) {
	print '<div class="opacitymedium">' . $langs->trans('KreaTaxMatchPasswordConfigured', dol_escape_htmltag($maskedPassword)) . '</div>';
}
print '<div class="opacitymedium">' . $langs->trans('KreaTaxMatchAtPasswordHelp') . '</div>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="fieldrequired">' . $langs->trans('KreaTaxMatchCronLookbackDays') . '</td>';
print '<td>';
print '<input type="number" class="flat width100" name="KREATAXMATCH_CRON_LOOKBACK_DAYS" value="' . ((int) $configuredCronLookbackDays) . '" min="1" max="31">';
print '<div class="opacitymedium">' . $langs->trans('KreaTaxMatchCronLookbackDaysHelp') . '</div>';
print '</td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td>' . $langs->trans('KreaTaxMatchSentToAccountant') . '</td>';
print '<td>';
print '<input type="checkbox" class="flat" name="KREATAXMATCH_ENABLE_ACCOUNTING_COLUMN" value="1"' . ($configuredAccountingColumnEnabled ? ' checked' : '') . '>';
print '<div class="opacitymedium">' . $langs->trans('KreaTaxMatchAccountingFeatureHelp') . '</div>';
print '</td>';
print '</tr>';

print '</table>';
print '<div class="center marginbottomonly">';
print '<input class="button button-save" type="submit" value="' . dol_escape_htmltag($langs->trans('Save')) . '">';
print '</div>';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
