<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    kreataxmatch/admin/about.php
 * \ingroup kreataxmatch
 * \brief   About page for KreaTaxMatch.
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
require_once '../lib/kreataxmatch.lib.php';
require_once __DIR__ . '/../core/modules/modKreaTaxMatch.class.php';

$langs->loadLangs(array('admin', 'kreataxmatch@kreataxmatch'));

if (!$user->admin) {
	accessforbidden();
}

$title = $langs->trans('KreaTaxMatchAboutTitle');
$module = new modKreaTaxMatch($db);
$moduleName = $langs->trans('ModuleKreaTaxMatchName');
if ($moduleName === 'ModuleKreaTaxMatchName') {
	$moduleName = $module->name;
}
$moduleVersion = (string) $module->version;
$editorName = (string) $module->editor_name;
$editorUrl = (string) $module->editor_url;
$supportEmail = 'mail@kreativitat.com';
$logoPath = '';
if (is_readable(__DIR__ . '/../img/logo.png')) {
	$logoPath = dol_buildpath('/custom/kreataxmatch/img/logo.png', 1);
} elseif (is_readable(__DIR__ . '/../img/object_kreataxmatch.png')) {
	$logoPath = dol_buildpath('/custom/kreataxmatch/img/object_kreataxmatch.png', 1);
}
$aboutCapabilityKeys = array(
	'KreaTaxMatchAboutCapability1',
	'KreaTaxMatchAboutCapability2',
	'KreaTaxMatchAboutCapability3',
	'KreaTaxMatchAboutCapability4',
);

llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-kreataxmatch page-admin_about');

$linkback = '<a href="' . DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($title, $linkback, 'title_setup');

$head = kreataxmatchAdminPrepareHead();
print dol_get_fiche_head($head, 'about', $title, 0, 'generic');

print '<div class="opacitymedium">';
print dol_escape_htmltag($langs->trans('ModuleKreaTaxMatchDescLong'));
print '</div>';
print '<div class="fichecenter" style="margin-top: 18px;">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutContextWhatLabel') . '</td><td>' . dol_escape_htmltag($langs->trans('KreaTaxMatchAboutContextWhatValue')) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutContextGoalLabel') . '</td><td>' . dol_escape_htmltag($langs->trans('KreaTaxMatchAboutContextGoalValue')) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutContextUseLabel') . '</td><td>' . dol_escape_htmltag($langs->trans('KreaTaxMatchAboutContextUseValue')) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutContextCapabilitiesLabel') . '</td><td><ul class="marginleftonly">';
foreach ($aboutCapabilityKeys as $aboutCapabilityKey) {
	print '<li>' . dol_escape_htmltag($langs->trans($aboutCapabilityKey)) . '</li>';
}
print '</ul></td></tr>';
print '</table>';
print '</div>';
print '<div class="fichecenter" style="margin-top: 18px; display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap;">';
if ($logoPath !== '') {
	print '<div style="flex:1 1 260px; max-width:320px; text-align:center;">';
	print '<img src="' . dol_escape_htmltag($logoPath) . '" alt="' . dol_escape_htmltag($moduleName) . '" style="max-width: 260px; height: auto;">';
	print '</div>';
}
print '<div style="flex:2 1 340px; min-width:320px;">';
print '<table class="border centpercent tableforfield">';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutModuleLabel') . '</td><td>' . dol_escape_htmltag($moduleName) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutVersionLabel') . '</td><td>' . dol_escape_htmltag($moduleVersion) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutEditorLabel') . '</td><td><a href="' . dol_escape_htmltag($editorUrl) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($editorName) . '</a></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutLicenseLabel') . '</td><td>' . dol_escape_htmltag($langs->trans('KreaTaxMatchAboutLicenseValue')) . '</td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutSupportLabel') . '</td><td><a href="mailto:' . dol_escape_htmltag($supportEmail) . '">' . dol_escape_htmltag($supportEmail) . '</a></td></tr>';
print '<tr><td class="titlefield">' . $langs->trans('KreaTaxMatchAboutWebsiteLabel') . '</td><td><a href="' . dol_escape_htmltag($editorUrl) . '" target="_blank" rel="noopener noreferrer">' . dol_escape_htmltag($editorUrl) . '</a></td></tr>';
print '</table>';
print '</div>';
print '</div>';

print dol_get_fiche_end();
llxFooter();
$db->close();
