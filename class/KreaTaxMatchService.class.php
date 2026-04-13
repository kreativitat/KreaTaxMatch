<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once __DIR__ . '/KreaTaxMatchMatcher.class.php';

/**
 * Data access and write operations for KreaTaxMatch.
 */
class KreaTaxMatchService
{
	/**
	 * @var bool
	 */
	private static $schemaChecked = false;

	/**
	 * @var DoliDB
	 */
	private $db;

	/**
	 * @var KreaTaxMatchMatcher
	 */
	private $matcher;

	/**
	 * @param DoliDB $db
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->matcher = new KreaTaxMatchMatcher();
	}

	/**
	 * Persist downloaded AT documents with deduplication.
	 *
	 * @param array<int,array<string,mixed>> $atDocuments
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @param string $source
	 * @param int $fkUser
	 * @return array{batch_id:int,received:int,inserted:int,updated:int}
	 * @throws Exception
	 */
	public function syncAtDocuments(array $atDocuments, $dateFrom, $dateTo, $source = 'manual', $fkUser = 0)
	{
		$this->ensureStorageSchema();

		$now = $this->db->idate(dol_now());
		$entity = $this->currentEntity();
		$source = in_array((string) $source, array('manual', 'cron'), true) ? (string) $source : 'manual';
		$batchId = $this->createSyncBatch($source, $dateFrom, $dateTo, (int) $fkUser);

		$received = 0;
		$inserted = 0;
		$updated = 0;

		try {
			$this->db->begin();

			foreach ($atDocuments as $rawDocument) {
				if (!is_array($rawDocument)) {
					continue;
				}
				$received++;

				$normalized = $this->matcher->normalizeAtDocument($rawDocument);
				$atDocumentId = $this->extractAtDocumentId($rawDocument);
				$sourceHash = $this->buildDocumentHash($rawDocument, $normalized, $atDocumentId);
				$payloadJson = json_encode($rawDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
				if ($payloadJson === false) {
					$payloadJson = '{}';
				}

				$existingId = $this->findStoredDocumentId($atDocumentId, $sourceHash);
				if ($existingId > 0) {
					$sql = "UPDATE " . MAIN_DB_PREFIX . "kreataxmatch_document";
					$sql .= " SET supplier_vat='" . $this->db->escape((string) $normalized['supplier_vat']) . "'";
					$sql .= ", supplier_name='" . $this->db->escape((string) $normalized['supplier_name']) . "'";
					$sql .= ", supplier_ref='" . $this->db->escape((string) $normalized['supplier_ref']) . "'";
					$sql .= ", document_type='" . $this->db->escape((string) $this->extractRawField($rawDocument, array('tipoDocumento'))) . "'";
					$sql .= ", invoice_date=" . ($normalized['invoice_date'] !== '' ? "'" . $this->db->escape($normalized['invoice_date']) . "'" : "NULL");
					$sql .= ", total_ttc=" . ($normalized['total_ttc'] !== null ? ((float) $normalized['total_ttc']) : "NULL");
					$sql .= ", raw_payload='" . $this->db->escape($payloadJson) . "'";
					$sql .= ", date_last_seen='" . $now . "'";
					$sql .= ", fk_last_batch=" . ((int) $batchId);
					$sql .= " WHERE rowid=" . ((int) $existingId);
					$sql .= " AND entity=" . $entity;

					$resql = $this->db->query($sql);
					if (!$resql) {
						throw new Exception('Unable to update stored AT document #' . ((int) $existingId) . ': ' . $this->db->lasterror());
					}
					$updated++;
				} else {
					$sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreataxmatch_document (";
					$sql .= "entity, at_document_id, source_hash, supplier_vat, supplier_name, supplier_ref,";
					$sql .= "document_type, invoice_date, total_ttc, raw_payload, date_downloaded, date_last_seen,";
					$sql .= "fk_last_batch, datec";
					$sql .= ") VALUES (";
					$sql .= $entity;
					$sql .= ", " . ($atDocumentId > 0 ? ((int) $atDocumentId) : "NULL");
					$sql .= ", '" . $this->db->escape($sourceHash) . "'";
					$sql .= ", '" . $this->db->escape((string) $normalized['supplier_vat']) . "'";
					$sql .= ", '" . $this->db->escape((string) $normalized['supplier_name']) . "'";
					$sql .= ", '" . $this->db->escape((string) $normalized['supplier_ref']) . "'";
					$sql .= ", '" . $this->db->escape((string) $this->extractRawField($rawDocument, array('tipoDocumento'))) . "'";
					$sql .= ", " . ($normalized['invoice_date'] !== '' ? "'" . $this->db->escape($normalized['invoice_date']) . "'" : "NULL");
					$sql .= ", " . ($normalized['total_ttc'] !== null ? ((float) $normalized['total_ttc']) : "NULL");
					$sql .= ", '" . $this->db->escape($payloadJson) . "'";
					$sql .= ", '" . $now . "'";
					$sql .= ", '" . $now . "'";
					$sql .= ", " . ((int) $batchId);
					$sql .= ", '" . $now . "'";
					$sql .= ")";

					$resql = $this->db->query($sql);
					if (!$resql) {
						throw new Exception('Unable to insert AT document: ' . $this->db->lasterror());
					}
					$inserted++;
				}
			}

			$this->updateSyncBatch($batchId, 'done', $received, $inserted, $updated, '');
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			$this->updateSyncBatch($batchId, 'error', $received, $inserted, $updated, $e->getMessage());
			throw $e;
		}

		return array(
			'batch_id' => (int) $batchId,
			'received' => (int) $received,
			'inserted' => (int) $inserted,
			'updated' => (int) $updated,
		);
	}

	/**
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @return array<int,array<string,mixed>>
	 * @throws Exception
	 */
	public function fetchStoredAtDocuments($dateFrom, $dateTo)
	{
		$this->ensureStorageSchema();

		$sql = "SELECT rowid, at_document_id, supplier_vat, supplier_name, supplier_ref, invoice_date, total_ttc, sent_to_accountant, raw_payload";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreataxmatch_document";
		$sql .= " WHERE entity IN (" . $this->matchingEntityList() . ")";
		if ($dateFrom !== '') {
			$sql .= " AND invoice_date >= '" . $this->db->escape($dateFrom) . "'";
		}
		if ($dateTo !== '') {
			$sql .= " AND invoice_date <= '" . $this->db->escape($dateTo) . "'";
		}
		$sql .= " ORDER BY invoice_date DESC, rowid DESC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to load stored AT documents: ' . $this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$raw = json_decode((string) $obj->raw_payload, true);
			if (!is_array($raw)) {
				$raw = array();
			}

			// Internal metadata to preserve a stable row identifier across synchronizations.
			$raw['__stored_rowid'] = (int) $obj->rowid;
			$raw['__at_document_id'] = (int) $obj->at_document_id;
			$raw['__sent_to_accountant'] = !empty($obj->sent_to_accountant) ? 1 : 0;

			if (!isset($raw['nifEmitente']) && (string) $obj->supplier_vat !== '') {
				$raw['nifEmitente'] = $obj->supplier_vat;
			}
			if (!isset($raw['nomeEmitente']) && (string) $obj->supplier_name !== '') {
				$raw['nomeEmitente'] = $obj->supplier_name;
			}
			if (!isset($raw['numerodocumento']) && (string) $obj->supplier_ref !== '') {
				$raw['numerodocumento'] = $obj->supplier_ref;
			}
			if (!isset($raw['dataEmissaoDocumento']) && !empty($obj->invoice_date)) {
				$raw['dataEmissaoDocumento'] = $this->formatDateOnly($obj->invoice_date);
			}
			if (!isset($raw['valorTotal']) && $obj->total_ttc !== null) {
				$raw['valorTotal'] = (int) round(((float) $obj->total_ttc) * 100);
			}

			$rows[] = $raw;
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @param string $searchVat
	 * @param string $searchName
	 * @param string $searchRef
	 * @return int
	 * @throws Exception
	 */
	public function countStoredAtDocuments($dateFrom, $dateTo, $searchVat = '', $searchName = '', $searchRef = '')
	{
		$this->ensureStorageSchema();

		$sql = "SELECT COUNT(rowid) AS nb FROM " . MAIN_DB_PREFIX . "kreataxmatch_document";
		$sql .= " WHERE entity IN (" . $this->matchingEntityList() . ")";
		$sql .= $this->buildAtDocumentFilterSql($dateFrom, $dateTo, $searchVat, $searchName, $searchRef);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to count AT documents: ' . $this->db->lasterror());
		}
		$nb = 0;
		$obj = $this->db->fetch_object($resql);
		if ($obj && isset($obj->nb)) {
			$nb = (int) $obj->nb;
		}
		$this->db->free($resql);
		return $nb;
	}

	/**
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @param string $searchVat
	 * @param string $searchName
	 * @param string $searchRef
	 * @param string $sortfield
	 * @param string $sortorder
	 * @param int    $limit
	 * @param int    $offset
	 * @return array<int,array<string,mixed>>
	 * @throws Exception
	 */
	public function fetchStoredAtDocumentsList($dateFrom, $dateTo, $searchVat = '', $searchName = '', $searchRef = '', $sortfield = 'invoice_date', $sortorder = 'DESC', $limit = 25, $offset = 0)
	{
		$this->ensureStorageSchema();

		$allowedFields = array('invoice_date', 'supplier_name', 'supplier_vat', 'supplier_ref', 'total_ttc', 'at_document_id');
		if (!in_array($sortfield, $allowedFields, true)) {
			$sortfield = 'invoice_date';
		}
		$sortorder = (strtoupper($sortorder) === 'ASC') ? 'ASC' : 'DESC';
		$limit  = max(0, (int) $limit);
		$offset = max(0, (int) $offset);

		$sql = "SELECT rowid, at_document_id, supplier_vat, supplier_name, supplier_ref, invoice_date, total_ttc, sent_to_accountant";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreataxmatch_document";
		$sql .= " WHERE entity IN (" . $this->matchingEntityList() . ")";
		$sql .= $this->buildAtDocumentFilterSql($dateFrom, $dateTo, $searchVat, $searchName, $searchRef);
		$sql .= " ORDER BY " . $sortfield . " " . $sortorder . ", rowid " . $sortorder;
		if ($limit > 0) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to fetch AT documents: ' . $this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid'          => (int)    $obj->rowid,
				'at_document_id' => (int)    $obj->at_document_id,
				'supplier_vat'   => (string) $obj->supplier_vat,
				'supplier_name'  => (string) $obj->supplier_name,
				'supplier_ref'   => (string) $obj->supplier_ref,
				'invoice_date'   => (string) $obj->invoice_date,
				'total_ttc'      => ($obj->total_ttc !== null) ? (float) $obj->total_ttc : null,
				'sent_to_accountant' => !empty($obj->sent_to_accountant) ? 1 : 0,
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @param string $searchVat
	 * @param string $searchName
	 * @param string $searchRef
	 * @return string
	 */
	private function buildAtDocumentFilterSql($dateFrom, $dateTo, $searchVat, $searchName, $searchRef)
	{
		$sql = '';
		if ($dateFrom !== '' && preg_match('/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}$/', $dateFrom)) {
			$sql .= " AND invoice_date >= '" . $this->db->escape($dateFrom) . "'";
		}
		if ($dateTo !== '' && preg_match('/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}$/', $dateTo)) {
			$sql .= " AND invoice_date <= '" . $this->db->escape($dateTo) . "'";
		}
		if ($searchVat !== '') {
			$sql .= " AND supplier_vat LIKE '%" . $this->db->escape($searchVat) . "%'";
		}
		if ($searchName !== '') {
			$sql .= " AND supplier_name LIKE '%" . $this->db->escape($searchName) . "%'";
		}
		if ($searchRef !== '') {
			$sql .= " AND supplier_ref LIKE '%" . $this->db->escape($searchRef) . "%'";
		}
		return $sql;
	}

	/**
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @return array<int,array<string,mixed>>
	 * @throws Exception
	 */
	public function fetchDolibarrSupplierInvoices($dateFrom, $dateTo)
	{
		$sql = 'SELECT f.rowid, f.ref, f.ref_supplier, f.datef, f.total_ttc, f.fk_statut,';
		$sql .= ' s.rowid AS supplier_id, s.nom AS supplier_name, s.tva_intra AS supplier_vat';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'facture_fourn AS f';
		$sql .= ' LEFT JOIN ' . MAIN_DB_PREFIX . 'societe AS s ON s.rowid = f.fk_soc';
		$sql .= ' WHERE f.entity IN (' . $this->entityList('facture_fourn') . ')';
		if ($dateFrom !== '') {
			$sql .= " AND f.datef >= '" . $this->db->escape($dateFrom) . "'";
		}
		if ($dateTo !== '') {
			$sql .= " AND f.datef <= '" . $this->db->escape($dateTo) . "'";
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to read supplier invoices for matching: ' . $this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'ref' => (string) $obj->ref,
				'ref_supplier' => $this->matcher->normalizeReference((string) $obj->ref_supplier),
				'invoice_date' => $this->formatDateOnly($obj->datef),
				'total_ttc' => (float) $obj->total_ttc,
				'status' => (int) $obj->fk_statut,
				'supplier_id' => (int) $obj->supplier_id,
				'supplier_name' => (string) $obj->supplier_name,
				'supplier_vat' => $this->matcher->normalizeVat((string) $obj->supplier_vat),
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * Save or update a manual match (preserved across imports).
	 *
	 * @param int $documentRowId
	 * @param int $invoiceRowId
	 * @param int $fkUser
	 * @param string $note
	 * @return int
	 * @throws Exception
	 */
	public function saveManualMatch($documentRowId, $invoiceRowId, $fkUser = 0, $note = '')
	{
		$this->ensureStorageSchema();

		$documentRowId = (int) $documentRowId;
		$invoiceRowId = (int) $invoiceRowId;
		$fkUser = (int) $fkUser;
		$note = $this->normalizeManualNote($note);
		if ($documentRowId <= 0 || $invoiceRowId <= 0) {
			throw new Exception('Invalid manual match parameters.');
		}
		$documentEntity = $this->getStoredDocumentEntity($documentRowId);
		if ($documentEntity <= 0) {
			throw new Exception('Stored AT document was not found for visible entities.');
		}
		$invoiceEntity = $this->getInvoiceEntity($invoiceRowId);
		if ($invoiceEntity <= 0) {
			throw new Exception('Supplier invoice does not exist in visible entities.');
		}
		if ($documentEntity !== $invoiceEntity) {
			throw new Exception('Stored AT document and supplier invoice do not belong to the same entity.');
		}

		$now = $this->db->idate(dol_now());
		$existingRowId = $this->findManualMatchRowId($documentRowId, $documentEntity);
		if ($existingRowId > 0) {
			$sql = "UPDATE " . MAIN_DB_PREFIX . "kreataxmatch_manual_match";
			$sql .= " SET fk_facture_fourn=" . $invoiceRowId;
			$sql .= ", note_private='" . $this->db->escape((string) $note) . "'";
			$sql .= ", fk_user=" . ($fkUser > 0 ? $fkUser : "NULL");
			$sql .= " WHERE rowid=" . ((int) $existingRowId);
			$sql .= " AND entity=" . $documentEntity;

			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new Exception('Unable to update manual match: ' . $this->db->lasterror());
			}

			return (int) $existingRowId;
		}

		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreataxmatch_manual_match (";
		$sql .= "entity, fk_document, fk_facture_fourn, note_private, fk_user, datec";
		$sql .= ") VALUES (";
		$sql .= $documentEntity;
		$sql .= ", " . $documentRowId;
		$sql .= ", " . $invoiceRowId;
		$sql .= ", '" . $this->db->escape((string) $note) . "'";
		$sql .= ", " . ($fkUser > 0 ? $fkUser : "NULL");
		$sql .= ", '" . $now . "'";
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to create manual match: ' . $this->db->lasterror());
		}

		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'kreataxmatch_manual_match');
	}

	/**
	 * Save or clear a note for one stored AT document without forcing a manual invoice match.
	 *
	 * @param int $documentRowId
	 * @param string $note
	 * @param int $fkUser
	 * @return int
	 * @throws Exception
	 */
	public function saveDocumentNote($documentRowId, $note = '', $fkUser = 0)
	{
		$this->ensureStorageSchema();

		$documentRowId = (int) $documentRowId;
		$fkUser = (int) $fkUser;
		$note = $this->normalizeManualNote($note);
		if ($documentRowId <= 0) {
			throw new Exception('Invalid note parameters.');
		}

		$documentEntity = $this->getStoredDocumentEntity($documentRowId);
		if ($documentEntity <= 0) {
			throw new Exception('Stored AT document was not found for visible entities.');
		}

		$existingRow = $this->findManualMatchRow($documentRowId, $documentEntity);
		if ((int) $existingRow['rowid'] > 0) {
			if ($note === '' && (int) $existingRow['fk_facture_fourn'] <= 0) {
				$this->deleteManualMatch($documentRowId);
				return 0;
			}

			$sql = "UPDATE " . MAIN_DB_PREFIX . "kreataxmatch_manual_match";
			$sql .= " SET note_private='" . $this->db->escape($note) . "'";
			$sql .= ", fk_user=" . ($fkUser > 0 ? $fkUser : "NULL");
			$sql .= " WHERE rowid=" . ((int) $existingRow['rowid']);
			$sql .= " AND entity=" . $documentEntity;

			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new Exception('Unable to update document note: ' . $this->db->lasterror());
			}

			return (int) $existingRow['rowid'];
		}

		if ($note === '') {
			return 0;
		}

		$now = $this->db->idate(dol_now());
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreataxmatch_manual_match (";
		$sql .= "entity, fk_document, fk_facture_fourn, note_private, fk_user, datec";
		$sql .= ") VALUES (";
		$sql .= $documentEntity;
		$sql .= ", " . $documentRowId;
		$sql .= ", NULL";
		$sql .= ", '" . $this->db->escape($note) . "'";
		$sql .= ", " . ($fkUser > 0 ? $fkUser : "NULL");
		$sql .= ", '" . $now . "'";
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to create document note: ' . $this->db->lasterror());
		}

		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'kreataxmatch_manual_match');
	}

	/**
	 * Save the accountant-delivery flag for one stored AT document.
	 *
	 * @param int $documentRowId
	 * @param int $sentToAccountant
	 * @return bool
	 * @throws Exception
	 */
	public function saveDocumentSentToAccountant($documentRowId, $sentToAccountant = 0)
	{
		$this->ensureStorageSchema();

		$documentRowId = (int) $documentRowId;
		$sentToAccountant = !empty($sentToAccountant) ? 1 : 0;
		if ($documentRowId <= 0) {
			throw new Exception('Invalid accountant-delivery parameters.');
		}

		$documentEntity = $this->getStoredDocumentEntity($documentRowId);
		if ($documentEntity <= 0) {
			throw new Exception('Stored AT document was not found for visible entities.');
		}

		$sql = "UPDATE " . MAIN_DB_PREFIX . "kreataxmatch_document";
		$sql .= " SET sent_to_accountant=" . $sentToAccountant;
		$sql .= " WHERE rowid=" . $documentRowId;
		$sql .= " AND entity=" . $documentEntity;

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to update accountant-delivery flag: ' . $this->db->lasterror());
		}

		return true;
	}

	/**
	 * Normalize operator notes to a stable size accepted by the reconciliation UI.
	 *
	 * @param mixed $note
	 * @return string
	 */
	private function normalizeManualNote($note)
	{
		$note = trim((string) $note);
		if ($note === '') {
			return '';
		}

		if (function_exists('mb_substr')) {
			return (string) mb_substr($note, 0, 90, 'UTF-8');
		}

		return substr($note, 0, 90);
	}

	/**
	 * @param int $documentRowId
	 * @return bool
	 * @throws Exception
	 */
	public function deleteManualMatch($documentRowId)
	{
		$this->ensureStorageSchema();

		$documentRowId = (int) $documentRowId;
		if ($documentRowId <= 0) {
			return false;
		}
		$documentEntity = $this->getStoredDocumentEntity($documentRowId);
		if ($documentEntity <= 0) {
			return false;
		}

		$sql = "DELETE FROM " . MAIN_DB_PREFIX . "kreataxmatch_manual_match";
		$sql .= " WHERE entity=" . $documentEntity;
		$sql .= " AND fk_document=" . $documentRowId;

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to delete manual match: ' . $this->db->lasterror());
		}

		return true;
	}

	/**
	 * @param array<int,int> $documentRowIds
	 * @return array<int,array<string,mixed>>
	 * @throws Exception
	 */
	public function getManualMatchesIndexed(array $documentRowIds)
	{
		$this->ensureStorageSchema();

		$documentRowIds = array_values(array_unique(array_map('intval', $documentRowIds)));
		$documentRowIds = array_filter($documentRowIds, function ($id) {
			return $id > 0;
		});
		if (empty($documentRowIds)) {
			return array();
		}

		$sql = "SELECT m.rowid AS match_rowid, m.fk_document, m.note_private, m.fk_facture_fourn,";
		$sql .= " f.ref, f.datef, f.total_ttc, s.nom AS supplier_name, s.tva_intra AS supplier_vat";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreataxmatch_manual_match AS m";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "facture_fourn AS f ON f.rowid = m.fk_facture_fourn";
		$sql .= " AND f.entity IN (" . $this->entityList('facture_fourn') . ")";
		$sql .= " LEFT JOIN " . MAIN_DB_PREFIX . "societe AS s ON s.rowid = f.fk_soc";
		$sql .= " WHERE m.entity IN (" . $this->matchingEntityList() . ")";
		$sql .= " AND m.fk_document IN (" . implode(',', $documentRowIds) . ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to read manual matches: ' . $this->db->lasterror());
		}

		$matches = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$documentId = (int) $obj->fk_document;
			$invoice = null;
			if (!empty($obj->fk_facture_fourn)) {
				$invoice = array(
					'rowid' => (int) $obj->fk_facture_fourn,
					'ref' => (string) $obj->ref,
					'invoice_date' => $this->formatDateOnly($obj->datef),
					'total_ttc' => ($obj->total_ttc !== null) ? (float) $obj->total_ttc : null,
					'supplier_name' => (string) $obj->supplier_name,
					'supplier_vat' => $this->matcher->normalizeVat((string) $obj->supplier_vat),
				);
			}
			$matches[$documentId] = array(
				'rowid' => (int) $obj->match_rowid,
				'note_private' => (string) $obj->note_private,
				'invoice' => $invoice,
			);
		}
		$this->db->free($resql);

		return $matches;
	}

	/**
	 * Manual matches indexed by supplier invoice rowid.
	 *
	 * @param array<int,int> $invoiceRowIds
	 * @return array<int,array<string,mixed>>
	 * @throws Exception
	 */
	public function getManualMatchesByInvoiceIndexed(array $invoiceRowIds)
	{
		$this->ensureStorageSchema();

		$invoiceRowIds = array_values(array_unique(array_map('intval', $invoiceRowIds)));
		$invoiceRowIds = array_filter($invoiceRowIds, function ($id) {
			return $id > 0;
		});
		if (empty($invoiceRowIds)) {
			return array();
		}

		$sql = "SELECT m.rowid AS match_rowid, m.fk_facture_fourn, m.fk_document, m.note_private,";
		$sql .= " d.raw_payload, d.rowid AS document_rowid, d.at_document_id, d.supplier_vat, d.supplier_name,";
		$sql .= " d.supplier_ref, d.invoice_date, d.total_ttc, d.sent_to_accountant";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreataxmatch_manual_match AS m";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "kreataxmatch_document AS d ON d.rowid = m.fk_document AND d.entity = m.entity";
		$sql .= " INNER JOIN " . MAIN_DB_PREFIX . "facture_fourn AS f ON f.rowid = m.fk_facture_fourn";
		$sql .= " WHERE m.entity IN (" . $this->matchingEntityList() . ")";
		$sql .= " AND f.entity IN (" . $this->entityList('facture_fourn') . ")";
		$sql .= " AND m.fk_facture_fourn IN (" . implode(',', $invoiceRowIds) . ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to read manual matches by invoice: ' . $this->db->lasterror());
		}

		$matches = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$invoiceRowId = (int) $obj->fk_facture_fourn;
			$raw = json_decode((string) $obj->raw_payload, true);
			if (!is_array($raw)) {
				$raw = array();
			}

			$raw['__stored_rowid'] = (int) $obj->document_rowid;
			$raw['__at_document_id'] = (int) $obj->at_document_id;
			$raw['__sent_to_accountant'] = !empty($obj->sent_to_accountant) ? 1 : 0;
			if (!isset($raw['nifEmitente']) && (string) $obj->supplier_vat !== '') {
				$raw['nifEmitente'] = $obj->supplier_vat;
			}
			if (!isset($raw['nomeEmitente']) && (string) $obj->supplier_name !== '') {
				$raw['nomeEmitente'] = $obj->supplier_name;
			}
			if (!isset($raw['numerodocumento']) && (string) $obj->supplier_ref !== '') {
				$raw['numerodocumento'] = $obj->supplier_ref;
			}
			if (!isset($raw['dataEmissaoDocumento']) && !empty($obj->invoice_date)) {
				$raw['dataEmissaoDocumento'] = $this->formatDateOnly($obj->invoice_date);
			}
			if (!isset($raw['valorTotal']) && $obj->total_ttc !== null) {
				$raw['valorTotal'] = (int) round(((float) $obj->total_ttc) * 100);
			}

			$matches[$invoiceRowId] = array(
				'rowid' => (int) $obj->match_rowid,
				'fk_document' => (int) $obj->fk_document,
				'note_private' => (string) $obj->note_private,
				'document' => $this->matcher->normalizeAtDocument($raw),
			);
		}
		$this->db->free($resql);

		return $matches;
	}

	/**
	 * Apply manual matches to matcher output and recalculate stats.
	 *
	 * @param array{rows:array<int,array<string,mixed>>,stats:array<string,int>} $matchResult
	 * @param array<int,array<string,mixed>> $manualMatchesByDocument
	 * @return array{rows:array<int,array<string,mixed>>,stats:array<string,int>}
	 */
	public function applyManualMatchesToResult(array $matchResult, array $manualMatchesByDocument)
	{
		if (!isset($matchResult['rows']) || !is_array($matchResult['rows'])) {
			return $matchResult;
		}

		foreach ($matchResult['rows'] as $index => $row) {
			$storedRowId = 0;
			if (!empty($row['at']['stored_rowid'])) {
				$storedRowId = (int) $row['at']['stored_rowid'];
			}
			if ($storedRowId <= 0 || !isset($manualMatchesByDocument[$storedRowId])) {
				$matchResult['rows'][$index]['manual_match'] = 0;
				$matchResult['rows'][$index]['manual_match_note'] = '';
				continue;
			}

			$manual = $manualMatchesByDocument[$storedRowId];
			$matchResult['rows'][$index]['manual_match_note'] = isset($manual['note_private']) ? (string) $manual['note_private'] : '';
			if (!empty($manual['invoice']) && is_array($manual['invoice']) && !empty($manual['invoice']['rowid'])) {
				$matchResult['rows'][$index]['status'] = 'manual';
				$matchResult['rows'][$index]['reason'] = 'KreaTaxMatchReasonManualMatchPreserved';
				$matchResult['rows'][$index]['dolibarr'] = $manual['invoice'];
				$matchResult['rows'][$index]['manual_match'] = 1;
				continue;
			}

			$matchResult['rows'][$index]['manual_match'] = 0;
		}

		$stats = array(
			'total' => 0,
			'matched' => 0,
			'manual' => 0,
			'missing' => 0,
			'discrepancy' => 0,
		);
		foreach ($matchResult['rows'] as $row) {
			$status = isset($row['status']) ? (string) $row['status'] : 'discrepancy';
			if (!isset($stats[$status])) {
				$status = 'discrepancy';
			}
			$stats['total']++;
			$stats[$status]++;
		}
		$matchResult['stats'] = $stats;

		return $matchResult;
	}

	/**
	 * @param int $limit
	 * @param int $offset
	 * @param array<string,string> $filters
	 * @return array<int,array<string,mixed>>
	 * @throws Exception
	 */
	public function fetchSyncBatches($limit = 20, $offset = 0, array $filters = array())
	{
		$this->ensureStorageSchema();

		$limit = (int) $limit;
		$offset = (int) $offset;
		if ($offset < 0) {
			$offset = 0;
		}
		if ($limit < 0) {
			$limit = 20;
		}
		if ($limit > 1000) {
			$limit = 1000;
		}

		$sql = "SELECT rowid, source, date_start, date_end, rows_received, rows_inserted, rows_updated, status, error_message, datec";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreataxmatch_sync_batch";
		$sql .= " WHERE entity=" . $this->currentEntity();
		$sql .= $this->buildSyncBatchFilterSql($filters);
		$allowedSortFields = array('datec', 'source', 'date_start', 'date_end', 'rows_received', 'rows_inserted', 'rows_updated', 'status');
		$sortfield = (isset($filters['sortfield']) && in_array($filters['sortfield'], $allowedSortFields, true)) ? $filters['sortfield'] : 'rowid';
		$sortorder = (isset($filters['sortorder']) && strtoupper((string) $filters['sortorder']) === 'ASC') ? 'ASC' : 'DESC';
		$sql .= " ORDER BY " . $sortfield . " " . $sortorder;
		if ($limit > 0) {
			$sql .= $this->db->plimit($limit, $offset);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to read synchronization batches: ' . $this->db->lasterror());
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => (int) $obj->rowid,
				'source' => (string) $obj->source,
				'date_start' => (string) $obj->date_start,
				'date_end' => (string) $obj->date_end,
				'rows_received' => (int) $obj->rows_received,
				'rows_inserted' => (int) $obj->rows_inserted,
				'rows_updated' => (int) $obj->rows_updated,
				'status' => (string) $obj->status,
				'error_message' => (string) $obj->error_message,
				'datec' => dol_print_date($this->db->jdate($obj->datec), '%Y-%m-%d %H:%M:%S'),
			);
		}
		$this->db->free($resql);

		return $rows;
	}

	/**
	 * @return int
	 * @throws Exception
	 */
	public function countSyncBatches(array $filters = array())
	{
		$this->ensureStorageSchema();

		$sql = "SELECT COUNT(rowid) AS nb";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreataxmatch_sync_batch";
		$sql .= " WHERE entity=" . $this->currentEntity();
		$sql .= $this->buildSyncBatchFilterSql($filters);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to count synchronization batches: ' . $this->db->lasterror());
		}

		$nb = 0;
		$obj = $this->db->fetch_object($resql);
		if ($obj && isset($obj->nb)) {
			$nb = (int) $obj->nb;
		}
		$this->db->free($resql);

		return $nb;
	}

	/**
	 * @param array<string,string> $filters
	 * @return string
	 */
	private function buildSyncBatchFilterSql(array $filters)
	{
		$sql = '';

		$dateFrom = isset($filters['date_from']) ? trim((string) $filters['date_from']) : '';
		$dateTo = isset($filters['date_to']) ? trim((string) $filters['date_to']) : '';
		$source = isset($filters['source']) ? trim((string) $filters['source']) : '';
		$status = isset($filters['status']) ? trim((string) $filters['status']) : '';
		$summary = isset($filters['summary']) ? trim((string) $filters['summary']) : '';

		if ($dateFrom !== '' && preg_match('/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}$/', $dateFrom)) {
			$sql .= " AND datec >= '" . $this->db->escape($dateFrom) . " 00:00:00'";
		}
		if ($dateTo !== '' && preg_match('/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}$/', $dateTo)) {
			$sql .= " AND datec <= '" . $this->db->escape($dateTo) . " 23:59:59'";
		}
		if ($source !== '') {
			$sql .= " AND source LIKE '%" . $this->db->escape($source) . "%'";
		}
		if ($status !== '' && in_array($status, array('done', 'running', 'error'), true)) {
			$sql .= " AND status='" . $this->db->escape($status) . "'";
		}
		if ($summary !== '') {
			$needle = $this->db->escape($summary);
			$sql .= " AND (source LIKE '%" . $needle . "%'";
			$sql .= " OR error_message LIKE '%" . $needle . "%'";
			$sql .= " OR date_start LIKE '%" . $needle . "%'";
			$sql .= " OR date_end LIKE '%" . $needle . "%')";
		}

		return $sql;
	}

	/**
	 * @param User $user
	 * @param array<string,mixed> $atDocument
	 * @return int
	 * @throws Exception
	 */
	public function createDraftFromAtDocument($user, array $atDocument)
	{
		$normalized = $this->matcher->normalizeAtDocument($atDocument);
		if ($normalized['supplier_vat'] === '' || $normalized['supplier_ref'] === '') {
			throw new Exception('Cannot create draft: AT document has no supplier VAT and/or supplier invoice reference.');
		}

		$supplier = $this->findSupplierByVat($normalized['supplier_vat']);
		if ($supplier === null) {
			throw new Exception('Cannot create draft: no supplier found in Dolibarr with VAT ' . $normalized['supplier_vat'] . '.');
		}

		$existingInvoiceId = $this->findExistingInvoice($supplier['rowid'], $normalized['supplier_ref']);
		if ($existingInvoiceId > 0) {
			return $existingInvoiceId;
		}

		$invoiceDate = $normalized['invoice_date'];
		if ($invoiceDate === '') {
			$invoiceDate = dol_print_date(dol_now(), '%Y-%m-%d');
		}
		$invoiceTimestamp = dol_stringtotime($invoiceDate . ' 00:00:00');
		if ($invoiceTimestamp <= 0) {
			$invoiceTimestamp = dol_now();
		}

		$amount = ($normalized['total_ttc'] !== null) ? (float) $normalized['total_ttc'] : 0.0;
		$lineDescription = 'Auto-created by KreaTaxMatch from AT e-Fatura import (Supplier ref: ' . $normalized['supplier_ref'] . ').';

		$invoice = new FactureFournisseur($this->db);
		$invoice->socid = (int) $supplier['rowid'];
		$invoice->date = $invoiceTimestamp;
		$invoice->ref_supplier = $normalized['supplier_ref'];
		$invoice->note_private = 'Source: AT e-Fatura KreaTaxMatch. Supplier VAT: ' . $normalized['supplier_vat'];
		$invoice->lines = array(
			array(
				'desc' => $lineDescription,
				'subprice' => $amount,
				'tva_tx' => 0,
				'localtax1_tx' => 0,
				'localtax2_tx' => 0,
				'qty' => 1,
				'fk_product' => 0,
				'product_type' => 1,
				'remise_percent' => 0,
				'special_code' => 0,
				'fk_remise_except' => 0,
				'array_options' => array(),
			),
		);

		$newInvoiceId = $invoice->create($user);
		if ($newInvoiceId <= 0) {
			$error = $invoice->error;
			if (!empty($invoice->errors) && is_array($invoice->errors)) {
				$error = implode(' | ', $invoice->errors);
			}
			throw new Exception('Failed to create supplier draft invoice: ' . $error);
		}

		return (int) $newInvoiceId;
	}

	/**
	 * @param string $source
	 * @param string $dateFrom
	 * @param string $dateTo
	 * @param int $fkUser
	 * @return int
	 * @throws Exception
	 */
	private function createSyncBatch($source, $dateFrom, $dateTo, $fkUser)
	{
		$sql = "INSERT INTO " . MAIN_DB_PREFIX . "kreataxmatch_sync_batch (";
		$sql .= "entity, source, date_start, date_end, status, fk_user, datec";
		$sql .= ") VALUES (";
		$sql .= $this->currentEntity();
		$sql .= ", '" . $this->db->escape((string) $source) . "'";
		$sql .= ", '" . $this->db->escape((string) $dateFrom) . "'";
		$sql .= ", '" . $this->db->escape((string) $dateTo) . "'";
		$sql .= ", 'running'";
		$sql .= ", " . ($fkUser > 0 ? ((int) $fkUser) : "NULL");
		$sql .= ", '" . $this->db->idate(dol_now()) . "'";
		$sql .= ")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to create synchronization batch row: ' . $this->db->lasterror());
		}

		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX . 'kreataxmatch_sync_batch');
	}

	/**
	 * @param int $batchId
	 * @param string $status
	 * @param int $received
	 * @param int $inserted
	 * @param int $updated
	 * @param string $errorMessage
	 * @return void
	 */
	private function updateSyncBatch($batchId, $status, $received, $inserted, $updated, $errorMessage)
	{
		$sql = "UPDATE " . MAIN_DB_PREFIX . "kreataxmatch_sync_batch";
		$sql .= " SET rows_received=" . ((int) $received);
		$sql .= ", rows_inserted=" . ((int) $inserted);
		$sql .= ", rows_updated=" . ((int) $updated);
		$sql .= ", status='" . $this->db->escape((string) $status) . "'";
		$sql .= ", error_message=" . ($errorMessage !== '' ? "'" . $this->db->escape((string) $errorMessage) . "'" : "NULL");
		$sql .= " WHERE rowid=" . ((int) $batchId);
		$sql .= " AND entity=" . $this->currentEntity();

		$this->db->query($sql);
	}

	/**
	 * @param array<string,mixed> $rawDocument
	 * @return int
	 */
	private function extractAtDocumentId(array $rawDocument)
	{
		$candidates = array('idDocumento', 'id_documento', 'id');
		foreach ($candidates as $key) {
			if (!isset($rawDocument[$key])) {
				continue;
			}
			$value = (string) $rawDocument[$key];
			if (preg_match('/^[0-9]+$/', $value)) {
				return (int) $value;
			}
		}

		return 0;
	}

	/**
	 * @param array<string,mixed> $rawDocument
	 * @param array<string,mixed> $normalized
	 * @param int $atDocumentId
	 * @return string
	 */
	private function buildDocumentHash(array $rawDocument, array $normalized, $atDocumentId)
	{
		$signature = array(
			'entity' => $this->currentEntity(),
			'at_document_id' => (int) $atDocumentId,
			'supplier_vat' => (string) $normalized['supplier_vat'],
			'supplier_ref' => (string) $normalized['supplier_ref'],
			'invoice_date' => (string) $normalized['invoice_date'],
			'total_ttc' => ($normalized['total_ttc'] !== null ? (string) $normalized['total_ttc'] : ''),
			'document_type' => (string) $this->extractRawField($rawDocument, array('tipoDocumento')),
		);

		return hash('sha256', json_encode($signature));
	}

	/**
	 * @param int $atDocumentId
	 * @param string $sourceHash
	 * @return int
	 */
	private function findStoredDocumentId($atDocumentId, $sourceHash)
	{
		$sql = "SELECT rowid";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreataxmatch_document";
		$sql .= " WHERE entity=" . $this->currentEntity();
		if ((int) $atDocumentId > 0) {
			$sql .= " AND at_document_id=" . ((int) $atDocumentId);
		} else {
			$sql .= " AND source_hash='" . $this->db->escape((string) $sourceHash) . "'";
		}
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}

		$rowid = 0;
		$obj = $this->db->fetch_object($resql);
		if ($obj) {
			$rowid = (int) $obj->rowid;
		}
		$this->db->free($resql);

		return $rowid;
	}

	/**
	 * @param array<string,mixed> $rawDocument
	 * @param array<int,string> $keys
	 * @return string
	 */
	private function extractRawField(array $rawDocument, array $keys)
	{
		foreach ($keys as $key) {
			if (isset($rawDocument[$key]) && $rawDocument[$key] !== null && $rawDocument[$key] !== '') {
				return (string) $rawDocument[$key];
			}
		}

		return '';
	}

	/**
	 * @param int $documentRowId
	 * @return bool
	 */
	private function getStoredDocumentEntity($documentRowId)
	{
		$sql = "SELECT entity FROM " . MAIN_DB_PREFIX . "kreataxmatch_document";
		$sql .= " WHERE rowid=" . ((int) $documentRowId);
		$sql .= " AND entity IN (" . $this->matchingEntityList() . ")";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$entity = 0;
		$obj = $this->db->fetch_object($resql);
		if ($obj && isset($obj->entity)) {
			$entity = (int) $obj->entity;
		}
		$this->db->free($resql);

		return $entity;
	}

	/**
	 * @param int $invoiceRowId
	 * @return int
	 */
	private function getInvoiceEntity($invoiceRowId)
	{
		$sql = "SELECT entity FROM " . MAIN_DB_PREFIX . "facture_fourn";
		$sql .= " WHERE rowid=" . ((int) $invoiceRowId);
		$sql .= " AND entity IN (" . $this->entityList('facture_fourn') . ")";
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return 0;
		}
		$entity = 0;
		$obj = $this->db->fetch_object($resql);
		if ($obj && isset($obj->entity)) {
			$entity = (int) $obj->entity;
		}
		$this->db->free($resql);

		return $entity;
	}

	/**
	 * @param int $documentRowId
	 * @param int $entity
	 * @return int
	 */
	private function findManualMatchRowId($documentRowId, $entity)
	{
		$row = $this->findManualMatchRow($documentRowId, $entity);

		return (int) $row['rowid'];
	}

	/**
	 * @param int $documentRowId
	 * @param int $entity
	 * @return array{rowid:int,fk_facture_fourn:int,note_private:string}
	 */
	private function findManualMatchRow($documentRowId, $entity)
	{
		$sql = "SELECT rowid, fk_facture_fourn, note_private";
		$sql .= " FROM " . MAIN_DB_PREFIX . "kreataxmatch_manual_match";
		$sql .= " WHERE entity=" . ((int) $entity);
		$sql .= " AND fk_document=" . ((int) $documentRowId);
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			return array('rowid' => 0, 'fk_facture_fourn' => 0, 'note_private' => '');
		}

		$row = array('rowid' => 0, 'fk_facture_fourn' => 0, 'note_private' => '');
		$obj = $this->db->fetch_object($resql);
		if ($obj) {
			$row = array(
				'rowid' => (int) $obj->rowid,
				'fk_facture_fourn' => isset($obj->fk_facture_fourn) ? (int) $obj->fk_facture_fourn : 0,
				'note_private' => isset($obj->note_private) ? (string) $obj->note_private : '',
			);
		}
		$this->db->free($resql);

		return $row;
	}

	/**
	 * @param string $vat
	 * @return array<string,mixed>|null
	 */
	private function findSupplierByVat($vat)
	{
		$normalizedVat = $this->matcher->normalizeVat($vat);
		$digitsVat = preg_replace('/[^0-9]/', '', $normalizedVat);
		$searchValues = array();
		if ($normalizedVat !== '') {
			$searchValues[] = $normalizedVat;
		}
		if ($digitsVat !== '') {
			$searchValues[] = $digitsVat;
			$searchValues[] = 'PT' . $digitsVat;
		}
		$searchValues = array_values(array_unique($searchValues));
		if (empty($searchValues)) {
			return null;
		}

		$sqlValues = array();
		foreach ($searchValues as $searchValue) {
			$sqlValues[] = "'" . $this->db->escape($searchValue) . "'";
		}

		$sql = 'SELECT s.rowid, s.nom, s.tva_intra';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'societe AS s';
		$sql .= ' WHERE s.entity IN (' . $this->entityList('societe') . ')';
		$sql .= ' AND s.fournisseur > 0';
		$sql .= " AND s.tva_intra IS NOT NULL AND s.tva_intra <> ''";
		$sql .= " AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(s.tva_intra, ' ', ''), '-', ''), '.', ''), '/', '')) IN (" . implode(',', $sqlValues) . ')';
		$sql .= ' ORDER BY s.rowid ASC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to read suppliers by VAT: ' . $this->db->lasterror());
		}

		$row = null;
		$obj = $this->db->fetch_object($resql);
		if ($obj) {
			$row = array(
				'rowid' => (int) $obj->rowid,
				'name' => (string) $obj->nom,
				'vat' => (string) $obj->tva_intra,
			);
		}
		$this->db->free($resql);

		return $row;
	}

	/**
	 * @param int $supplierId
	 * @param string $refSupplier
	 * @return int
	 */
	private function findExistingInvoice($supplierId, $refSupplier)
	{
		$sql = 'SELECT rowid';
		$sql .= ' FROM ' . MAIN_DB_PREFIX . 'facture_fourn';
		$sql .= ' WHERE entity IN (' . $this->entityList('facture_fourn') . ')';
		$sql .= ' AND fk_soc = ' . ((int) $supplierId);
		$sql .= " AND ref_supplier = '" . $this->db->escape($refSupplier) . "'";
		$sql .= ' ORDER BY rowid DESC';
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to verify existing supplier invoice: ' . $this->db->lasterror());
		}

		$existingId = 0;
		$obj = $this->db->fetch_object($resql);
		if ($obj) {
			$existingId = (int) $obj->rowid;
		}
		$this->db->free($resql);

		return $existingId;
	}

	/**
	 * @return int
	 */
	private function currentEntity()
	{
		global $conf;

		return (int) $conf->entity;
	}

	/**
	 * @param string $element
	 * @return string
	 */
	private function entityList($element)
	{
		global $conf;

		if (function_exists('getEntity')) {
			$entityList = getEntity($element);
			if (!empty($entityList)) {
				return $entityList;
			}
		}

		return (string) ((int) $conf->entity);
	}

	/**
	 * Reconciliation must use the same visible entity perimeter as supplier invoices.
	 * This avoids false negatives when invoices are shared across entities.
	 *
	 * @return string
	 */
	private function matchingEntityList()
	{
		return $this->entityList('facture_fourn');
	}

	/**
	 * Normalize DATE/DATETIME database values to stable YYYY-MM-DD without timezone drift.
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function formatDateOnly($value)
	{
		if ($value === null || $value === '') {
			return '';
		}

		$stringValue = trim((string) $value);
		if (preg_match('/^[0-9]{4}\-[0-9]{2}\-[0-9]{2}/', $stringValue)) {
			return substr($stringValue, 0, 10);
		}

		$timestamp = $this->db->jdate($value);
		if ($timestamp > 0) {
			return dol_print_date($timestamp, '%Y-%m-%d');
		}

		return '';
	}

	/**
	 * Ensure persistence tables exist for upgraded environments where module was already enabled.
	 *
	 * @return void
	 * @throws Exception
	 */
	private function ensureStorageSchema()
	{
		if (self::$schemaChecked) {
			return;
		}

		$sqlStatements = array(
			"CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "kreataxmatch_sync_batch (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER NOT NULL DEFAULT 1,
				source VARCHAR(16) NOT NULL,
				date_start DATE NOT NULL,
				date_end DATE NOT NULL,
				rows_received INTEGER NOT NULL DEFAULT 0,
				rows_inserted INTEGER NOT NULL DEFAULT 0,
				rows_updated INTEGER NOT NULL DEFAULT 0,
				status VARCHAR(16) NOT NULL DEFAULT 'running',
				error_message TEXT NULL,
				fk_user INTEGER NULL,
				datec DATETIME NOT NULL,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				KEY idx_kreataxmatch_batch_entity_date (entity, datec),
				KEY idx_kreataxmatch_batch_status (entity, status)
			) ENGINE=innodb",
			"CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "kreataxmatch_document (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER NOT NULL DEFAULT 1,
				at_document_id BIGINT NULL,
				source_hash CHAR(64) NOT NULL,
				supplier_vat VARCHAR(20) NULL,
				supplier_name VARCHAR(255) NULL,
				supplier_ref VARCHAR(255) NOT NULL,
				document_type VARCHAR(16) NULL,
				invoice_date DATE NULL,
				total_ttc DECIMAL(24,8) NULL,
				raw_payload LONGTEXT NOT NULL,
				date_downloaded DATETIME NOT NULL,
				date_last_seen DATETIME NOT NULL,
				fk_last_batch INTEGER NULL,
				sent_to_accountant TINYINT(1) NOT NULL DEFAULT 0,
				datec DATETIME NOT NULL,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				UNIQUE KEY uk_kreataxmatch_doc_entity_id (entity, at_document_id),
				UNIQUE KEY uk_kreataxmatch_doc_entity_hash (entity, source_hash),
				KEY idx_kreataxmatch_doc_entity_date (entity, invoice_date),
				KEY idx_kreataxmatch_doc_entity_ref (entity, supplier_ref),
				KEY idx_kreataxmatch_doc_entity_vat (entity, supplier_vat)
			) ENGINE=innodb",
			"CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "kreataxmatch_manual_match (
				rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
				entity INTEGER NOT NULL DEFAULT 1,
				fk_document INTEGER NOT NULL,
				fk_facture_fourn INTEGER NULL,
				note_private TEXT NULL,
				fk_user INTEGER NULL,
				datec DATETIME NOT NULL,
				tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				UNIQUE KEY uk_kreataxmatch_manual_document (entity, fk_document),
				KEY idx_kreataxmatch_manual_invoice (entity, fk_facture_fourn)
			) ENGINE=innodb",
		);

		foreach ($sqlStatements as $sql) {
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new Exception('Unable to ensure KreaTaxMatch storage schema: ' . $this->db->lasterror());
			}
		}

		$this->ensureNullableManualMatchInvoiceColumn();
		$this->ensureSentToAccountantColumn();

		self::$schemaChecked = true;
	}

	/**
	 * Allow note-only rows by making manual-match invoice linkage nullable on upgraded databases.
	 *
	 * @return void
	 * @throws Exception
	 */
	private function ensureNullableManualMatchInvoiceColumn()
	{
		$sql = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "kreataxmatch_manual_match LIKE 'fk_facture_fourn'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to inspect manual match schema: ' . $this->db->lasterror());
		}

		$column = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if ($column && isset($column->Null) && strtoupper((string) $column->Null) !== 'YES') {
			$sql = "ALTER TABLE " . MAIN_DB_PREFIX . "kreataxmatch_manual_match MODIFY fk_facture_fourn INTEGER NULL";
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new Exception('Unable to migrate manual match schema for note-only rows: ' . $this->db->lasterror());
			}
		}
	}

	/**
	 * Ensure accountant-delivery tracking exists for upgraded databases.
	 *
	 * @return void
	 * @throws Exception
	 */
	private function ensureSentToAccountantColumn()
	{
		$sql = "SHOW COLUMNS FROM " . MAIN_DB_PREFIX . "kreataxmatch_document LIKE 'sent_to_accountant'";
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception('Unable to inspect AT document schema: ' . $this->db->lasterror());
		}

		$column = $this->db->fetch_object($resql);
		$this->db->free($resql);

		if (!$column) {
			$sql = "ALTER TABLE " . MAIN_DB_PREFIX . "kreataxmatch_document ADD COLUMN sent_to_accountant TINYINT(1) NOT NULL DEFAULT 0 AFTER fk_last_batch";
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new Exception('Unable to add accountant-delivery flag to stored AT documents: ' . $this->db->lasterror());
			}
			return;
		}

		$mustNormalize = false;
		if (isset($column->Null) && strtoupper((string) $column->Null) !== 'NO') {
			$mustNormalize = true;
		}
		if (!isset($column->Default) || (string) $column->Default !== '0') {
			$mustNormalize = true;
		}

		if ($mustNormalize) {
			$sql = "ALTER TABLE " . MAIN_DB_PREFIX . "kreataxmatch_document MODIFY sent_to_accountant TINYINT(1) NOT NULL DEFAULT 0";
			$resql = $this->db->query($sql);
			if (!$resql) {
				throw new Exception('Unable to normalize accountant-delivery flag schema: ' . $this->db->lasterror());
			}
		}
	}
}
