<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Prepare admin pages header.
 *
 * @return array<array{string,string,string}>
 */
function kreataxmatchAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('kreataxmatch@kreataxmatch');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/custom/kreataxmatch/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/custom/kreataxmatch/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'kreataxmatch@kreataxmatch');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'kreataxmatch@kreataxmatch', 'remove');

	return $head;
}

/**
 * Prepare operational pages header.
 *
 * @return array<array{string,string,string}>
 */
function kreataxmatchPrepareHead()
{
	global $langs;

	$langs->load('kreataxmatch@kreataxmatch');

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/custom/kreataxmatch/match.php', 1).'?mainmenu=billing&leftmenu=kreataxmatch&action=view';
	$head[$h][1] = $langs->trans('KreaTaxMatchInvoiceTab');
	$head[$h][2] = 'dashboard';
	$h++;

	$head[$h][0] = dol_buildpath('/custom/kreataxmatch/documents.php', 1).'?mainmenu=billing&leftmenu=kreataxmatch';
	$head[$h][1] = $langs->trans('KreaTaxMatchDocumentsTab');
	$head[$h][2] = 'documents';
	$h++;

	$head[$h][0] = dol_buildpath('/custom/kreataxmatch/download.php', 1).'?mainmenu=billing&leftmenu=kreataxmatch';
	$head[$h][2] = 'download';
	$head[$h][1] = $langs->trans('KreaTaxMatchDownloadTab');

	return $head;
}

/**
 * Validate anti-CSRF token with compatibility across Dolibarr versions.
 *
 * @param string|null $token CSRF token
 * @return bool
 */
function kreataxmatchIsTokenValid($token = null)
{
	$token = (string) ($token !== null ? $token : GETPOST('token', 'alphanohtml'));
	if ($token === '') {
		return false;
	}

	$currentToken = function_exists('currentToken') ? (string) currentToken() : '';
	$newToken = function_exists('newToken') ? (string) newToken() : '';

	if ($currentToken !== '' && hash_equals($currentToken, $token)) {
		return true;
	}
	if ($newToken !== '' && hash_equals($newToken, $token)) {
		return true;
	}

	return false;
}
