<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Matching logic between AT purchase rows and Dolibarr supplier invoices.
 */
class KreaTaxMatchMatcher
{
	/**
	 * @param array<int,array<string,mixed>> $atDocuments
	 * @param array<int,array<string,mixed>> $dolibarrInvoices
	 * @return array{rows:array<int,array<string,mixed>>,stats:array<string,int>}
	 */
	public function match(array $atDocuments, array $dolibarrInvoices)
	{
		$index = $this->indexDolibarrInvoices($dolibarrInvoices);
		$indexByReference = $this->indexDolibarrInvoicesByReference($dolibarrInvoices);
		$indexByVatDateAmount = $this->indexDolibarrInvoicesByVatDateAmount($dolibarrInvoices);
		$indexByDateAmount = $this->indexDolibarrInvoicesByDateAmount($dolibarrInvoices);
		$rows = array();
		$stats = array(
			'total' => 0,
			'matched' => 0,
			'missing' => 0,
			'discrepancy' => 0,
		);

		foreach ($atDocuments as $rawAtDocument) {
			if (!is_array($rawAtDocument)) {
				continue;
			}

			$normalizedAt = $this->normalizeAtDocument($rawAtDocument);
			$status = 'missing';
			$reason = 'KreaTaxMatchReasonNoDolibarrMatch';
			$selectedInvoice = null;

			if ($normalizedAt['supplier_vat'] === '' || $normalizedAt['supplier_ref'] === '') {
				$status = 'discrepancy';
				$reason = 'KreaTaxMatchReasonAtMissingVatOrRef';
			} else {
				$keys = $this->buildPrimaryKeys($normalizedAt['supplier_vat'], $normalizedAt['supplier_ref']);
				$candidates = $this->collectIndexedCandidates($index, $keys, 'rowid');
				$candidates = $this->filterCandidatesByAmountDirection($candidates, $normalizedAt['total_ttc']);

				if (!empty($candidates)) {
					$selectedInvoice = $this->selectBestCandidate($candidates, $normalizedAt);
					$amountMatches = $this->amountsMatch($normalizedAt['total_ttc'], $selectedInvoice['total_ttc']);
					$dateMatches = $this->datesMatch($normalizedAt['invoice_date'], $selectedInvoice['invoice_date']);

					if ($amountMatches && $dateMatches) {
						$status = 'matched';
					} else {
						$status = 'discrepancy';
						if (!$amountMatches && !$dateMatches) {
							$reason = 'KreaTaxMatchReasonAmountAndDateMismatch';
						} elseif (!$amountMatches) {
							$reason = 'KreaTaxMatchReasonAmountMismatch';
						} else {
							$reason = 'KreaTaxMatchReasonDateMismatch';
						}
					}
				} else {
					$refKeys = $this->referenceVariants($normalizedAt['supplier_ref']);
					$referenceCandidates = $this->collectIndexedCandidates($indexByReference, $refKeys, 'rowid');
					$exactReferenceCandidates = $this->filterExactAtCandidates($referenceCandidates, $normalizedAt);
					if ((count($exactReferenceCandidates) === 1 || $this->hasSingleDolibarrInvoiceSignature($exactReferenceCandidates))
						&& $this->isSafeLooseDolibarrReferenceMatch($normalizedAt, $exactReferenceCandidates[0])) {
						$selectedInvoice = $exactReferenceCandidates[0];
						$status = 'matched';
						$reason = '';
					} else {
						$approximateMatch = $this->findApproximateDolibarrCandidate($normalizedAt, $indexByVatDateAmount, $indexByDateAmount);
						if (!empty($approximateMatch['candidate']) && is_array($approximateMatch['candidate'])) {
							$selectedInvoice = $approximateMatch['candidate'];
							$status = 'matched';
							$reason = '';
						} elseif (!empty($approximateMatch['reason'])) {
							$status = 'discrepancy';
							$reason = (string) $approximateMatch['reason'];
						}
					}
				}
			}

			$row = array(
				'status' => $this->finalizeAtSideStatus($status, $selectedInvoice),
				'reason' => $this->finalizeAtSideReason($status, $reason, $selectedInvoice),
				'at' => $normalizedAt,
				'dolibarr' => $selectedInvoice,
			);
			$rows[] = $row;
			$stats['total']++;
			$stats[$status]++;
		}

		return array(
			'rows' => $rows,
			'stats' => $stats,
		);
	}

	/**
	 * Build invoice-centric matching rows so every Dolibarr supplier invoice is shown
	 * even when AT historical data was not downloaded yet.
	 *
	 * @param array<int,array<string,mixed>> $atDocuments
	 * @param array<int,array<string,mixed>> $dolibarrInvoices
	 * @param array<int,array<string,mixed>> $manualMatchesByInvoice
	 * @return array{rows:array<int,array<string,mixed>>,stats:array<string,int>}
	 */
	public function matchFromDolibarrInvoices(array $atDocuments, array $dolibarrInvoices, array $manualMatchesByInvoice = array())
	{
		$index = $this->indexAtDocuments($atDocuments);
		$indexByReference = $this->indexAtDocumentsByReference($atDocuments);
		$indexByVatDateAmount = $this->indexAtDocumentsByVatDateAmount($atDocuments);
		$indexByDateAmount = $this->indexAtDocumentsByDateAmount($atDocuments);
		$rows = array();
		$stats = array(
			'total' => 0,
			'matched' => 0,
			'missing' => 0,
			'discrepancy' => 0,
		);

		foreach ($dolibarrInvoices as $invoice) {
			if (!is_array($invoice)) {
				continue;
			}

			$invoiceRowId = isset($invoice['rowid']) ? (int) $invoice['rowid'] : 0;
			$status = 'missing';
			$reason = 'KreaTaxMatchReasonNoAtMatch';
			$at = null;
			$manualMatch = 0;
			$manualNote = '';

			if ($invoiceRowId > 0 && isset($manualMatchesByInvoice[$invoiceRowId]) && is_array($manualMatchesByInvoice[$invoiceRowId])) {
				$manual = $manualMatchesByInvoice[$invoiceRowId];
				if (!empty($manual['document']) && is_array($manual['document'])) {
					$at = $manual['document'];
				}
				$status = 'matched';
				$reason = 'KreaTaxMatchReasonManualMatchPreserved';
				$manualMatch = 1;
				$manualNote = isset($manual['note_private']) ? (string) $manual['note_private'] : '';
			} else {
				$vat = $this->normalizeVat(isset($invoice['supplier_vat']) ? $invoice['supplier_vat'] : '');
				$ref = $this->resolveDolibarrInvoiceReference($invoice);

				if ($ref === '') {
					$status = 'discrepancy';
					$reason = 'KreaTaxMatchReasonDolibarrMissingVatOrRef';
				} else {
					$candidates = array();
					if ($vat !== '') {
						$keys = $this->buildPrimaryKeys($vat, $ref);
						$candidates = $this->collectIndexedCandidates($index, $keys, 'stored_rowid');
						$candidates = $this->filterCandidatesByAmountDirection($candidates, isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null);
					}

					if (!empty($candidates)) {
						$at = $this->selectBestAtCandidateForInvoice($candidates, $invoice);
						$amountMatches = $this->amountsMatch(isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null, isset($at['total_ttc']) ? $at['total_ttc'] : null);
						$dateMatches = $this->datesMatch(isset($invoice['invoice_date']) ? $invoice['invoice_date'] : '', isset($at['invoice_date']) ? $at['invoice_date'] : '');

						if ($amountMatches && $dateMatches) {
							$status = 'matched';
							$reason = '';
						} else {
							$status = 'discrepancy';
							if (!$amountMatches && !$dateMatches) {
								$reason = 'KreaTaxMatchReasonAmountAndDateMismatch';
							} elseif (!$amountMatches) {
								$reason = 'KreaTaxMatchReasonAmountMismatch';
							} else {
								$reason = 'KreaTaxMatchReasonDateMismatch';
							}
						}
					} else {
						$refKeys = $this->referenceVariants($ref);
						$referenceCandidates = $this->collectIndexedCandidates($indexByReference, $refKeys, 'stored_rowid');
						$exactReferenceCandidates = $this->filterExactInvoiceCandidates($referenceCandidates, $invoice);
						if ((count($exactReferenceCandidates) === 1 || $this->hasSingleCandidateSignature($exactReferenceCandidates))
							&& $this->isSafeLooseAtReferenceMatch($invoice, $exactReferenceCandidates[0])) {
							$at = $exactReferenceCandidates[0];
							$status = 'matched';
							$reason = '';
						} else {
							$approximateMatch = $this->findApproximateAtCandidate($invoice, $indexByVatDateAmount, $indexByDateAmount);
							if (!empty($approximateMatch['candidate']) && is_array($approximateMatch['candidate'])) {
								$at = $approximateMatch['candidate'];
								$status = 'matched';
								$reason = '';
							} elseif (!empty($approximateMatch['reason'])) {
								$status = 'discrepancy';
								$reason = (string) $approximateMatch['reason'];
							} elseif ($vat === '') {
								$status = 'discrepancy';
								$reason = 'KreaTaxMatchReasonDolibarrMissingVatOrRef';
							}
						}
					}
				}
			}

			$rows[] = array(
				'status' => $this->finalizeInvoiceSideStatus($status, $at),
				'reason' => $this->finalizeInvoiceSideReason($status, $reason, $at),
				'dolibarr' => $invoice,
				'at' => $at,
				'manual_match' => $manualMatch,
				'manual_match_note' => $manualNote,
			);
			$stats['total']++;
			$stats[$status]++;
		}

		return array(
			'rows' => $rows,
			'stats' => $stats,
		);
	}

	/**
	 * @param array<string,mixed> $document
	 * @return array<string,mixed>
	 */
	public function normalizeAtDocument(array $document)
	{
		$supplierVat = $this->normalizeVat($this->findField($document, array(
			'nifEmitente',
			'nifFornecedor',
			'nifVendedor',
			'emitenteNif',
			'fornecedorNif',
			'vatEmitente',
			'numeroContribuinteEmitente',
		)));
		$supplierRef = $this->normalizeReference($this->findField($document, array(
			'numDocumento',
			'numeroDocumento',
			'numDoc',
			'numeroDoc',
			'numeroFatura',
			'numFatura',
			'referenciaDocumento',
			'referencia',
		)));
		$supplierName = trim((string) $this->findField($document, array(
			'nomeEmitente',
			'emitenteDenominacao',
			'designacaoEmitente',
			'nomeFornecedor',
			'fornecedorNome',
		)));
		$invoiceDate = $this->normalizeDate($this->findField($document, array(
			'dataEmissaoDocumento',
			'dataEmissao',
			'dataDocumento',
			'dataDoc',
			'dataFatura',
			'dataFactura',
			'date',
		)));
		$totalField = $this->findFieldWithKey($document, array(
			'valorTotal',
			'totalDocumento',
			'total',
			'totalFatura',
			'valor',
			'valorFatura',
			'importanciaTotal',
			'totalComIva',
		));
		$totalTtc = $this->normalizeAmount($totalField['value'], $totalField['key']);
		$storedRowId = 0;
		if (isset($document['__stored_rowid']) && is_scalar($document['__stored_rowid'])) {
			$storedRowId = (int) $document['__stored_rowid'];
		}
		$atDocumentId = 0;
		if (isset($document['__at_document_id']) && is_scalar($document['__at_document_id'])) {
			$atDocumentId = (int) $document['__at_document_id'];
		}

		return array(
			'supplier_vat' => $supplierVat,
			'supplier_name' => html_entity_decode($supplierName, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'supplier_ref' => $supplierRef,
			'invoice_date' => $invoiceDate,
			'total_ttc' => $totalTtc,
			'stored_rowid' => $storedRowId,
			'at_document_id' => $atDocumentId,
			'raw' => $document,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $invoices
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function indexDolibarrInvoices(array $invoices)
	{
		$index = array();

		foreach ($invoices as $invoice) {
			$vat = $this->normalizeVat(isset($invoice['supplier_vat']) ? $invoice['supplier_vat'] : '');
			$ref = $this->resolveDolibarrInvoiceReference($invoice);
			if ($vat === '' || $ref === '') {
				continue;
			}

			$keys = $this->buildPrimaryKeys($vat, $ref);
			foreach ($keys as $key) {
				if (!isset($index[$key])) {
					$index[$key] = array();
				}
				$index[$key][] = $invoice;
			}
		}

		return $index;
	}

	/**
	 * @param array<int,array<string,mixed>> $atDocuments
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function indexAtDocuments(array $atDocuments)
	{
		$index = array();

		foreach ($atDocuments as $rawAtDocument) {
			if (!is_array($rawAtDocument)) {
				continue;
			}

			$normalizedAt = $this->normalizeAtDocument($rawAtDocument);
			if ($normalizedAt['supplier_vat'] === '' || $normalizedAt['supplier_ref'] === '') {
				continue;
			}

			$keys = $this->buildPrimaryKeys($normalizedAt['supplier_vat'], $normalizedAt['supplier_ref']);
			foreach ($keys as $key) {
				if (!isset($index[$key])) {
					$index[$key] = array();
				}
				$index[$key][] = $normalizedAt;
			}
		}

		return $index;
	}

	/**
	 * Index Dolibarr invoices by reference variants only.
	 *
	 * This is a conservative fallback index used only when VAT-based matching cannot
	 * find candidates. Final auto-match is accepted only on unique exact date+amount.
	 *
	 * @param array<int,array<string,mixed>> $invoices
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function indexDolibarrInvoicesByReference(array $invoices)
	{
		$index = array();

		foreach ($invoices as $invoice) {
			if (!is_array($invoice)) {
				continue;
			}

			$ref = $this->resolveDolibarrInvoiceReference($invoice);
			if ($ref === '') {
				continue;
			}

			$refKeys = $this->referenceVariants($ref);
			foreach ($refKeys as $key) {
				if (!isset($index[$key])) {
					$index[$key] = array();
				}
				$index[$key][] = $invoice;
			}
		}

		return $index;
	}

	/**
	 * Index Dolibarr invoices by exact VAT + date + amount.
	 *
	 * @param array<int,array<string,mixed>> $invoices
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function indexDolibarrInvoicesByVatDateAmount(array $invoices)
	{
		$index = array();

		foreach ($invoices as $invoice) {
			if (!is_array($invoice)) {
				continue;
			}

			$key = $this->buildVatDateAmountKey(
				isset($invoice['supplier_vat']) ? $invoice['supplier_vat'] : '',
				isset($invoice['invoice_date']) ? $invoice['invoice_date'] : '',
				isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null
			);
			if ($key === '') {
				continue;
			}

			if (!isset($index[$key])) {
				$index[$key] = array();
			}
			$index[$key][] = $invoice;
		}

		return $index;
	}

	/**
	 * Index Dolibarr invoices by exact date + amount.
	 *
	 * @param array<int,array<string,mixed>> $invoices
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function indexDolibarrInvoicesByDateAmount(array $invoices)
	{
		$index = array();

		foreach ($invoices as $invoice) {
			if (!is_array($invoice)) {
				continue;
			}

			$key = $this->buildDateAmountKey(
				isset($invoice['invoice_date']) ? $invoice['invoice_date'] : '',
				isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null
			);
			if ($key === '') {
				continue;
			}

			if (!isset($index[$key])) {
				$index[$key] = array();
			}
			$index[$key][] = $invoice;
		}

		return $index;
	}

	/**
	 * Index AT rows by reference variants only.
	 *
	 * This is a conservative fallback index used only when VAT-based matching cannot
	 * find candidates. Final auto-match is accepted only on unique exact date+amount.
	 *
	 * @param array<int,array<string,mixed>> $atDocuments
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function indexAtDocumentsByReference(array $atDocuments)
	{
		$index = array();

		foreach ($atDocuments as $rawAtDocument) {
			if (!is_array($rawAtDocument)) {
				continue;
			}

			$normalizedAt = $this->normalizeAtDocument($rawAtDocument);
			if ($normalizedAt['supplier_ref'] === '') {
				continue;
			}

			$refKeys = $this->referenceVariants($normalizedAt['supplier_ref']);
			foreach ($refKeys as $key) {
				if (!isset($index[$key])) {
					$index[$key] = array();
				}
				$index[$key][] = $normalizedAt;
			}
		}

		return $index;
	}

	/**
	 * Index normalized AT documents by exact VAT + date + amount.
	 *
	 * @param array<int,array<string,mixed>> $atDocuments
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function indexAtDocumentsByVatDateAmount(array $atDocuments)
	{
		$index = array();

		foreach ($atDocuments as $rawAtDocument) {
			if (!is_array($rawAtDocument)) {
				continue;
			}

			$normalizedAt = $this->normalizeAtDocument($rawAtDocument);
			$key = $this->buildVatDateAmountKey($normalizedAt['supplier_vat'], $normalizedAt['invoice_date'], $normalizedAt['total_ttc']);
			if ($key === '') {
				continue;
			}

			if (!isset($index[$key])) {
				$index[$key] = array();
			}
			$index[$key][] = $normalizedAt;
		}

		return $index;
	}

	/**
	 * Index normalized AT documents by exact date + amount.
	 *
	 * @param array<int,array<string,mixed>> $atDocuments
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function indexAtDocumentsByDateAmount(array $atDocuments)
	{
		$index = array();

		foreach ($atDocuments as $rawAtDocument) {
			if (!is_array($rawAtDocument)) {
				continue;
			}

			$normalizedAt = $this->normalizeAtDocument($rawAtDocument);
			$key = $this->buildDateAmountKey($normalizedAt['invoice_date'], $normalizedAt['total_ttc']);
			if ($key === '') {
				continue;
			}

			if (!isset($index[$key])) {
				$index[$key] = array();
			}
			$index[$key][] = $normalizedAt;
		}

		return $index;
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<string,mixed> $at
	 * @return array<string,mixed>
	 */
	private function selectBestCandidate(array $candidates, array $at)
	{
		$bestCandidate = $candidates[0];
		$bestScore = -1;

		foreach ($candidates as $candidate) {
			$score = 0;
			if ($this->amountsMatch($at['total_ttc'], isset($candidate['total_ttc']) ? $candidate['total_ttc'] : null)) {
				$score += 3;
			}
			if ($this->datesMatch($at['invoice_date'], isset($candidate['invoice_date']) ? $candidate['invoice_date'] : '')) {
				$score += 2;
			}

			if ($score > $bestScore) {
				$bestScore = $score;
				$bestCandidate = $candidate;
			}
		}

		return $bestCandidate;
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<string,mixed> $invoice
	 * @return array<string,mixed>
	 */
	private function selectBestAtCandidateForInvoice(array $candidates, array $invoice)
	{
		$bestCandidate = $candidates[0];
		$bestScore = -1;

		foreach ($candidates as $candidate) {
			$score = 0;
			if ($this->amountsMatch(isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null, isset($candidate['total_ttc']) ? $candidate['total_ttc'] : null)) {
				$score += 3;
			}
			if ($this->datesMatch(isset($invoice['invoice_date']) ? $invoice['invoice_date'] : '', isset($candidate['invoice_date']) ? $candidate['invoice_date'] : '')) {
				$score += 2;
			}

			if ($score > $bestScore) {
				$bestScore = $score;
				$bestCandidate = $candidate;
			}
		}

		return $bestCandidate;
	}

	/**
	 * Keep only candidates with exact amount and exact date match for one invoice.
	 *
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<string,mixed> $invoice
	 * @return array<int,array<string,mixed>>
	 */
	private function filterExactInvoiceCandidates(array $candidates, array $invoice)
	{
		$exactCandidates = array();
		foreach ($candidates as $candidate) {
			if (!$this->amountDirectionsAreCompatible(isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null, isset($candidate['total_ttc']) ? $candidate['total_ttc'] : null)) {
				continue;
			}

			$amountMatches = $this->amountsMatch(isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null, isset($candidate['total_ttc']) ? $candidate['total_ttc'] : null);
			$dateMatches = $this->datesMatch(isset($invoice['invoice_date']) ? $invoice['invoice_date'] : '', isset($candidate['invoice_date']) ? $candidate['invoice_date'] : '');
			if ($amountMatches && $dateMatches) {
				$exactCandidates[] = $candidate;
			}
		}

		return $exactCandidates;
	}

	/**
	 * Keep only Dolibarr invoice candidates with exact amount and exact date match
	 * for one AT document.
	 *
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<string,mixed> $atDocument
	 * @return array<int,array<string,mixed>>
	 */
	private function filterExactAtCandidates(array $candidates, array $atDocument)
	{
		$exactCandidates = array();
		foreach ($candidates as $candidate) {
			if (!$this->amountDirectionsAreCompatible(isset($atDocument['total_ttc']) ? $atDocument['total_ttc'] : null, isset($candidate['total_ttc']) ? $candidate['total_ttc'] : null)) {
				continue;
			}

			$amountMatches = $this->amountsMatch(isset($atDocument['total_ttc']) ? $atDocument['total_ttc'] : null, isset($candidate['total_ttc']) ? $candidate['total_ttc'] : null);
			$dateMatches = $this->datesMatch(isset($atDocument['invoice_date']) ? $atDocument['invoice_date'] : '', isset($candidate['invoice_date']) ? $candidate['invoice_date'] : '');
			if ($amountMatches && $dateMatches) {
				$exactCandidates[] = $candidate;
			}
		}

		return $exactCandidates;
	}

	/**
	 * Find a conservative approximate Dolibarr invoice match using exact
	 * date+amount candidates and fuzzy supplier-reference scoring.
	 *
	 * @param array<string,mixed> $atDocument
	 * @param array<string,array<int,array<string,mixed>>> $indexByVatDateAmount
	 * @param array<string,array<int,array<string,mixed>>> $indexByDateAmount
	 * @return array{candidate:array<string,mixed>|null,reason:string}
	 */
	private function findApproximateDolibarrCandidate(array $atDocument, array $indexByVatDateAmount, array $indexByDateAmount)
	{
		$dateAmountKey = $this->buildDateAmountKey(
			isset($atDocument['invoice_date']) ? $atDocument['invoice_date'] : '',
			isset($atDocument['total_ttc']) ? $atDocument['total_ttc'] : null
		);
		if ($dateAmountKey === '') {
			return array('candidate' => null, 'reason' => '');
		}

		$vatDateAmountKey = $this->buildVatDateAmountKey(
			isset($atDocument['supplier_vat']) ? $atDocument['supplier_vat'] : '',
			isset($atDocument['invoice_date']) ? $atDocument['invoice_date'] : '',
			isset($atDocument['total_ttc']) ? $atDocument['total_ttc'] : null
		);
		if ($vatDateAmountKey !== '' && isset($indexByVatDateAmount[$vatDateAmountKey])) {
			return $this->selectBestApproximateDolibarrCandidate($indexByVatDateAmount[$vatDateAmountKey], isset($atDocument['supplier_ref']) ? $atDocument['supplier_ref'] : '', false);
		}

		if (isset($indexByDateAmount[$dateAmountKey])) {
			return $this->selectBestApproximateDolibarrCandidate($indexByDateAmount[$dateAmountKey], isset($atDocument['supplier_ref']) ? $atDocument['supplier_ref'] : '', true);
		}

		return array('candidate' => null, 'reason' => '');
	}

	/**
	 * Find a conservative approximate AT match using exact date+amount candidates
	 * and fuzzy supplier-reference scoring.
	 *
	 * @param array<string,mixed> $invoice
	 * @param array<string,array<int,array<string,mixed>>> $indexByVatDateAmount
	 * @param array<string,array<int,array<string,mixed>>> $indexByDateAmount
	 * @return array{candidate:array<string,mixed>|null,reason:string}
	 */
	private function findApproximateAtCandidate(array $invoice, array $indexByVatDateAmount, array $indexByDateAmount)
	{
		$dateAmountKey = $this->buildDateAmountKey(
			isset($invoice['invoice_date']) ? $invoice['invoice_date'] : '',
			isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null
		);
		if ($dateAmountKey === '') {
			return array('candidate' => null, 'reason' => '');
		}

		$vatDateAmountKey = $this->buildVatDateAmountKey(
			isset($invoice['supplier_vat']) ? $invoice['supplier_vat'] : '',
			isset($invoice['invoice_date']) ? $invoice['invoice_date'] : '',
			isset($invoice['total_ttc']) ? $invoice['total_ttc'] : null
		);
		$reference = $this->resolveDolibarrInvoiceReference($invoice);

		if ($vatDateAmountKey !== '' && isset($indexByVatDateAmount[$vatDateAmountKey])) {
			return $this->selectBestApproximateAtCandidate($indexByVatDateAmount[$vatDateAmountKey], $reference, false);
		}

		if (isset($indexByDateAmount[$dateAmountKey])) {
			return $this->selectBestApproximateAtCandidate($indexByDateAmount[$dateAmountKey], $reference, true);
		}

		return array('candidate' => null, 'reason' => '');
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param string $reference
	 * @param bool $strictMode
	 * @return array{candidate:array<string,mixed>|null,reason:string}
	 */
	private function selectBestApproximateDolibarrCandidate(array $candidates, $reference, $strictMode)
	{
		$uniqueCandidates = $this->deduplicateDolibarrInvoicesBySignature($candidates);
		if (empty($uniqueCandidates)) {
			return array('candidate' => null, 'reason' => '');
		}

		$scoredCandidates = array();
		foreach ($uniqueCandidates as $candidate) {
			$scoredCandidates[] = array(
				'candidate' => $candidate,
				'score' => $this->referenceSimilarity($reference, $this->resolveDolibarrInvoiceReference($candidate)),
			);
		}

		usort($scoredCandidates, static function ($a, $b) {
			if ($a['score'] === $b['score']) {
				return 0;
			}
			return ($a['score'] > $b['score']) ? -1 : 1;
		});

		$best = $scoredCandidates[0];
		$secondScore = isset($scoredCandidates[1]) ? (float) $scoredCandidates[1]['score'] : -1.0;
		$singleCandidate = (count($scoredCandidates) === 1);
		$minimumScore = $strictMode ? ($singleCandidate ? 0.88 : 0.93) : ($singleCandidate ? 0.72 : 0.84);
		$minimumMargin = $strictMode ? 0.10 : 0.08;

		if ((float) $best['score'] < $minimumScore) {
			return array('candidate' => null, 'reason' => 'KreaTaxMatchReasonApproximateReferenceAmbiguous');
		}
		if (!$singleCandidate && (((float) $best['score'] - $secondScore) < $minimumMargin)) {
			return array('candidate' => null, 'reason' => 'KreaTaxMatchReasonApproximateReferenceAmbiguous');
		}

		return array('candidate' => $best['candidate'], 'reason' => '');
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param string $reference
	 * @param bool $strictMode
	 * @return array{candidate:array<string,mixed>|null,reason:string}
	 */
	private function selectBestApproximateAtCandidate(array $candidates, $reference, $strictMode)
	{
		$uniqueCandidates = $this->deduplicateAtDocumentsBySignature($candidates);
		if (empty($uniqueCandidates)) {
			return array('candidate' => null, 'reason' => '');
		}

		$scoredCandidates = array();
		foreach ($uniqueCandidates as $candidate) {
			$scoredCandidates[] = array(
				'candidate' => $candidate,
				'score' => $this->referenceSimilarity($reference, isset($candidate['supplier_ref']) ? $candidate['supplier_ref'] : ''),
			);
		}

		usort($scoredCandidates, static function ($a, $b) {
			if ($a['score'] === $b['score']) {
				return 0;
			}
			return ($a['score'] > $b['score']) ? -1 : 1;
		});

		$best = $scoredCandidates[0];
		$secondScore = isset($scoredCandidates[1]) ? (float) $scoredCandidates[1]['score'] : -1.0;
		$singleCandidate = (count($scoredCandidates) === 1);
		$minimumScore = $strictMode ? ($singleCandidate ? 0.88 : 0.93) : ($singleCandidate ? 0.72 : 0.84);
		$minimumMargin = $strictMode ? 0.10 : 0.08;

		if ((float) $best['score'] < $minimumScore) {
			return array('candidate' => null, 'reason' => 'KreaTaxMatchReasonApproximateReferenceAmbiguous');
		}
		if (!$singleCandidate && (((float) $best['score'] - $secondScore) < $minimumMargin)) {
			return array('candidate' => null, 'reason' => 'KreaTaxMatchReasonApproximateReferenceAmbiguous');
		}

		return array('candidate' => $best['candidate'], 'reason' => '');
	}

	/**
	 * Consider duplicated exact candidates as one logical match when they represent
	 * the same AT invoice signature.
	 *
	 * @param array<int,array<string,mixed>> $candidates
	 * @return bool
	 */
	private function hasSingleCandidateSignature(array $candidates)
	{
		if (empty($candidates)) {
			return false;
		}

		$signatures = array();
		foreach ($candidates as $candidate) {
			$ref = $this->normalizeReference(isset($candidate['supplier_ref']) ? $candidate['supplier_ref'] : '');
			$date = isset($candidate['invoice_date']) ? (string) $candidate['invoice_date'] : '';
			$total = isset($candidate['total_ttc']) && $candidate['total_ttc'] !== null ? sprintf('%.8F', (float) $candidate['total_ttc']) : '';
			$vat = $this->normalizeVat(isset($candidate['supplier_vat']) ? $candidate['supplier_vat'] : '');
			$signatures[$ref . '|' . $date . '|' . $total . '|' . $vat] = 1;
		}

		return count($signatures) === 1;
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @return bool
	 */
	private function hasSingleDolibarrInvoiceSignature(array $candidates)
	{
		return count($this->deduplicateDolibarrInvoicesBySignature($candidates)) === 1;
	}

	/**
	 * @param mixed $expected
	 * @param mixed $actual
	 * @return bool
	 */
	private function amountsMatch($expected, $actual)
	{
		if ($expected === null || $actual === null || $expected === '' || $actual === '') {
			return false;
		}

		return abs((float) $expected - (float) $actual) <= 0.01;
	}

	/**
	 * Reject hard sign conflicts early. A supplier invoice and an AT document
	 * with opposite amount directions represent different accounting events and
	 * must never be paired automatically or as discrepancy candidates.
	 *
	 * Zero amounts are treated as neutral so existing zero-value edge cases keep
	 * the previous behavior.
	 *
	 * @param mixed $expected
	 * @param mixed $actual
	 * @return bool
	 */
	private function amountDirectionsAreCompatible($expected, $actual)
	{
		if ($expected === null || $actual === null || $expected === '' || $actual === '') {
			return true;
		}

		$expectedAmount = (float) $expected;
		$actualAmount = (float) $actual;
		if (abs($expectedAmount) <= 0.0000001 || abs($actualAmount) <= 0.0000001) {
			return true;
		}

		return (($expectedAmount > 0 && $actualAmount > 0) || ($expectedAmount < 0 && $actualAmount < 0));
	}

	/**
	 * In the AT-first workspace, `discrepancy` must only be used when a concrete
	 * Dolibarr invoice candidate exists. If no invoice is attached, the row is a
	 * missing invoice from the operator perspective.
	 *
	 * @param string $status
	 * @param array<string,mixed>|null $invoice
	 * @return string
	 */
	private function finalizeAtSideStatus($status, $invoice)
	{
		if ((string) $status === 'discrepancy' && !$this->hasLinkedDolibarrInvoice($invoice)) {
			return 'missing';
		}

		return (string) $status;
	}

	/**
	 * @param string $status
	 * @param string $reason
	 * @param array<string,mixed>|null $invoice
	 * @return string
	 */
	private function finalizeAtSideReason($status, $reason, $invoice)
	{
		if ((string) $status === 'discrepancy' && !$this->hasLinkedDolibarrInvoice($invoice)) {
			return 'KreaTaxMatchReasonNoDolibarrMatch';
		}

		return (string) $reason;
	}

	/**
	 * Keep inverse-path semantics aligned: `discrepancy` requires a concrete AT
	 * document. Otherwise the invoice is simply missing from downloaded AT data.
	 *
	 * @param string $status
	 * @param array<string,mixed>|null $document
	 * @return string
	 */
	private function finalizeInvoiceSideStatus($status, $document)
	{
		if ((string) $status === 'discrepancy' && !$this->hasLinkedAtDocument($document)) {
			return 'missing';
		}

		return (string) $status;
	}

	/**
	 * @param string $status
	 * @param string $reason
	 * @param array<string,mixed>|null $document
	 * @return string
	 */
	private function finalizeInvoiceSideReason($status, $reason, $document)
	{
		if ((string) $status === 'discrepancy' && !$this->hasLinkedAtDocument($document)) {
			return 'KreaTaxMatchReasonNoAtMatch';
		}

		return (string) $reason;
	}

	/**
	 * @param array<string,mixed>|null $invoice
	 * @return bool
	 */
	private function hasLinkedDolibarrInvoice($invoice)
	{
		return is_array($invoice) && !empty($invoice['rowid']) && (int) $invoice['rowid'] > 0;
	}

	/**
	 * @param array<string,mixed>|null $document
	 * @return bool
	 */
	private function hasLinkedAtDocument($document)
	{
		if (!is_array($document)) {
			return false;
		}

		if (!empty($document['stored_rowid']) && (int) $document['stored_rowid'] > 0) {
			return true;
		}
		if (!empty($document['at_document_id']) && (int) $document['at_document_id'] > 0) {
			return true;
		}

		return false;
	}

	/**
	 * @param string $expected
	 * @param string $actual
	 * @return bool
	 */
	private function datesMatch($expected, $actual)
	{
		if ((string) $expected === '' || (string) $actual === '') {
			return false;
		}

		return (string) $expected === (string) $actual;
	}

	/**
	 * @param mixed $date
	 * @param mixed $amount
	 * @return string
	 */
	private function buildDateAmountKey($date, $amount)
	{
		$date = (string) $date;
		if ($date === '' || $amount === null || $amount === '') {
			return '';
		}

		return $date . '|' . sprintf('%.2F', round((float) $amount, 2));
	}

	/**
	 * @param mixed $vat
	 * @param mixed $date
	 * @param mixed $amount
	 * @return string
	 */
	private function buildVatDateAmountKey($vat, $date, $amount)
	{
		$normalizedVat = $this->normalizeVat($vat);
		$dateAmountKey = $this->buildDateAmountKey($date, $amount);
		if ($normalizedVat === '' || $dateAmountKey === '') {
			return '';
		}

		return $normalizedVat . '|' . $dateAmountKey;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public function normalizeVat($value)
	{
		$value = strtoupper(trim((string) $value));
		if ($value === '') {
			return '';
		}

		$value = preg_replace('/[^A-Z0-9]/', '', $value);
		if ($value === null) {
			return '';
		}

		$digits = preg_replace('/[^0-9]/', '', $value);
		if ($digits !== null && strlen($digits) >= 9) {
			$value = $digits;
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	public function normalizeReference($value)
	{
		$value = strtoupper(trim((string) $value));
		if ($value === '') {
			return '';
		}

		$value = preg_replace('/\s+/', ' ', $value);
		if ($value === null) {
			return '';
		}

		return trim((string) $value);
	}

	/**
	 * @param string $left
	 * @param string $right
	 * @return float
	 */
	private function referenceSimilarity($left, $right)
	{
		$leftVariants = $this->referenceComparisonVariants($left);
		$rightVariants = $this->referenceComparisonVariants($right);
		if (empty($leftVariants) || empty($rightVariants)) {
			return 0.0;
		}

		$bestScore = 0.0;
		foreach ($leftVariants as $leftVariant) {
			foreach ($rightVariants as $rightVariant) {
				$score = $this->stringSimilarity($leftVariant, $rightVariant);
				if ($score > $bestScore) {
					$bestScore = $score;
				}
				if ($bestScore >= 1.0) {
					return 1.0;
				}
			}
		}

		return $bestScore;
	}

	/**
	 * @param string $reference
	 * @return array<int,string>
	 */
	private function referenceComparisonVariants($reference)
	{
		$variants = $this->primaryReferenceVariants($reference);
		$normalized = $this->normalizeReference($reference);
		if ($normalized !== '') {
			$variants[] = $normalized;

			$compact = preg_replace('/[^A-Z0-9]/', '', $normalized);
			if ($compact !== null && $compact !== '') {
				$variants[] = $compact;
			}
		}

		return array_values(array_unique(array_filter($variants, function ($value) {
			return (string) $value !== '';
		})));
	}

	/**
	 * @param string $left
	 * @param string $right
	 * @return float
	 */
	private function stringSimilarity($left, $right)
	{
		$left = (string) $left;
		$right = (string) $right;
		if ($left === '' || $right === '') {
			return 0.0;
		}
		if ($left === $right) {
			return 1.0;
		}

		$maximumLength = max(strlen($left), strlen($right));
		$minimumLength = min(strlen($left), strlen($right));
		if ($maximumLength <= 0) {
			return 0.0;
		}

		$bestScore = 0.0;
		if ($minimumLength >= 4 && (strpos($left, $right) !== false || strpos($right, $left) !== false)) {
			$bestScore = max($bestScore, ((float) $minimumLength / (float) $maximumLength));
		}

		if (function_exists('levenshtein') && $maximumLength <= 255) {
			$distance = levenshtein($left, $right);
			$bestScore = max($bestScore, 1.0 - (min($distance, $maximumLength) / (float) $maximumLength));
		}

		if (function_exists('similar_text') && $maximumLength <= 64) {
			$percent = 0.0;
			similar_text($left, $right, $percent);
			$bestScore = max($bestScore, ((float) $percent / 100.0));
		}

		return $bestScore;
	}

	/**
	 * Resolve supplier reference used for matching:
	 * - prefer supplier reference (`ref_supplier`)
	 * - fallback to Dolibarr invoice reference (`ref`) when supplier reference is empty.
	 *
	 * @param array<string,mixed> $invoice
	 * @return string
	 */
	public function resolveDolibarrInvoiceReference(array $invoice)
	{
		$refSupplier = $this->normalizeReference(isset($invoice['ref_supplier']) ? $invoice['ref_supplier'] : '');
		if ($refSupplier !== '') {
			return $refSupplier;
		}

		return $this->normalizeReference(isset($invoice['ref']) ? $invoice['ref'] : '');
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private function normalizeDate($value)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}

		$value = str_replace('/', '-', $value);
		$timestamp = function_exists('dol_stringtotime') ? dol_stringtotime($value) : strtotime($value);
		if ($timestamp <= 0) {
			return '';
		}

		if (function_exists('dol_print_date')) {
			return dol_print_date($timestamp, '%Y-%m-%d');
		}

		return date('Y-m-d', $timestamp);
	}

	/**
	 * @param mixed $value
	 * @return float|null
	 */
	private function normalizeAmount($value, $fieldName = '')
	{
		if ($value === null) {
			return null;
		}

		$isCentField = in_array((string) $fieldName, array(
			'valorTotal',
			'valorTotalBaseTributavel',
			'valorTotalIva',
		), true);
		if ((is_int($value) || is_float($value)) && $isCentField) {
			return ((float) $value) / 100;
		}
		if ($isCentField && preg_match('/^-?[0-9]+$/', (string) $value)) {
			return ((float) $value) / 100;
		}

		$value = str_replace(array(' ', "\xc2\xa0"), '', (string) $value);
		if (function_exists('price2num')) {
			$amount = price2num($value, 'MT');
		} else {
			$value = str_replace(',', '.', $value);
			$amount = is_numeric($value) ? (float) $value : '';
		}
		if ($amount === '') {
			return null;
		}

		return (float) $amount;
	}

	/**
	 * @param array<string,mixed> $record
	 * @param array<int,string> $keys
	 * @return array{key:string,value:mixed}
	 */
	private function findFieldWithKey(array $record, array $keys)
	{
		foreach ($keys as $key) {
			if (array_key_exists($key, $record) && $record[$key] !== null && $record[$key] !== '') {
				return array('key' => (string) $key, 'value' => $record[$key]);
			}
		}

		foreach ($record as $fieldName => $fieldValue) {
			if (!is_string($fieldName)) {
				continue;
			}
			foreach ($keys as $key) {
				if (strcasecmp($fieldName, $key) === 0 && $fieldValue !== null && $fieldValue !== '') {
					return array('key' => (string) $fieldName, 'value' => $fieldValue);
				}
			}
		}

		return array('key' => '', 'value' => '');
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @return array<int,array<string,mixed>>
	 */
	private function deduplicateDolibarrInvoicesBySignature(array $candidates)
	{
		$unique = array();
		foreach ($candidates as $candidate) {
			if (!is_array($candidate)) {
				continue;
			}

			$signature = $this->buildDolibarrInvoiceSignature($candidate);
			if (!isset($unique[$signature])) {
				$unique[$signature] = $candidate;
			}
		}

		return array_values($unique);
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @return array<int,array<string,mixed>>
	 */
	private function deduplicateAtDocumentsBySignature(array $candidates)
	{
		$unique = array();
		foreach ($candidates as $candidate) {
			if (!is_array($candidate)) {
				continue;
			}

			$signature = $this->buildAtDocumentSignature($candidate);
			if (!isset($unique[$signature])) {
				$unique[$signature] = $candidate;
			}
		}

		return array_values($unique);
	}

	/**
	 * @param array<string,mixed> $invoice
	 * @return string
	 */
	private function buildDolibarrInvoiceSignature(array $invoice)
	{
		$reference = $this->resolveDolibarrInvoiceReference($invoice);
		$date = isset($invoice['invoice_date']) ? (string) $invoice['invoice_date'] : '';
		$total = (isset($invoice['total_ttc']) && $invoice['total_ttc'] !== null) ? sprintf('%.8F', (float) $invoice['total_ttc']) : '';
		$vat = $this->normalizeVat(isset($invoice['supplier_vat']) ? $invoice['supplier_vat'] : '');

		return $reference . '|' . $date . '|' . $total . '|' . $vat;
	}

	/**
	 * @param array<string,mixed> $document
	 * @return string
	 */
	private function buildAtDocumentSignature(array $document)
	{
		$reference = $this->normalizeReference(isset($document['supplier_ref']) ? $document['supplier_ref'] : '');
		$date = isset($document['invoice_date']) ? (string) $document['invoice_date'] : '';
		$total = (isset($document['total_ttc']) && $document['total_ttc'] !== null) ? sprintf('%.8F', (float) $document['total_ttc']) : '';
		$vat = $this->normalizeVat(isset($document['supplier_vat']) ? $document['supplier_vat'] : '');

		return $reference . '|' . $date . '|' . $total . '|' . $vat;
	}

	/**
	 * @param array<string,mixed> $record
	 * @param array<int,string> $keys
	 * @return mixed
	 */
	private function findField(array $record, array $keys)
	{
		foreach ($keys as $key) {
			if (array_key_exists($key, $record) && $record[$key] !== null && $record[$key] !== '') {
				return $record[$key];
			}
		}

		foreach ($record as $fieldName => $fieldValue) {
			if (!is_string($fieldName)) {
				continue;
			}
			foreach ($keys as $key) {
				if (strcasecmp($fieldName, $key) === 0 && $fieldValue !== null && $fieldValue !== '') {
					return $fieldValue;
				}
			}
		}

		return '';
	}

	/**
	 * @param string $vat
	 * @param string $ref
	 * @return string
	 */
	private function buildPrimaryKey($vat, $ref)
	{
		return $vat . '|' . $ref;
	}

	/**
	 * @param string $vat
	 * @param string $ref
	 * @return array<int,string>
	 */
	private function buildPrimaryKeys($vat, $ref)
	{
		$vat = $this->normalizeVat($vat);
		if ($vat === '') {
			return array();
		}

		$keys = array();
		$refVariants = $this->primaryReferenceVariants($ref);
		foreach ($refVariants as $refVariant) {
			if ($refVariant === '') {
				continue;
			}
			$keys[] = $this->buildPrimaryKey($vat, $refVariant);
		}

		return array_values(array_unique($keys));
	}

	/**
	 * Generate strict variants for primary-key matching.
	 *
	 * These variants keep meaningful series information and intentionally exclude
	 * terminal-only fragments like `683`, which are too weak for primary matching.
	 *
	 * @param string $ref
	 * @return array<int,string>
	 */
	private function primaryReferenceVariants($ref)
	{
		$normalized = $this->normalizeReference($ref);
		if ($normalized === '') {
			return array();
		}

		$variants = array($normalized);
		$compact = preg_replace('/[^A-Z0-9]/', '', $normalized);
		if (!empty($compact)) {
			$variants[] = (string) $compact;
		}

		$docPrefixPattern = '(?:FT|FS|FR|FA|FAT|NC|ND|RC|REC|VD|VDC)';
		$withoutPrefix = preg_replace('/^' . $docPrefixPattern . '[\\s\\-_\\/]+/', '', $normalized);
		if ($withoutPrefix !== null && $withoutPrefix !== '' && $withoutPrefix !== $normalized) {
			$variants[] = (string) $withoutPrefix;
			$withoutPrefixCompact = preg_replace('/[^A-Z0-9]/', '', (string) $withoutPrefix);
			if (!empty($withoutPrefixCompact)) {
				$variants[] = (string) $withoutPrefixCompact;
			}
		}

		if (!empty($compact)) {
			$compactWithoutPrefix = preg_replace('/^' . $docPrefixPattern . '/', '', (string) $compact);
			if ($compactWithoutPrefix !== null && $compactWithoutPrefix !== '' && $compactWithoutPrefix !== $compact) {
				$variants[] = (string) $compactWithoutPrefix;
			}
		}

		return array_values(array_unique(array_filter($variants, function ($value) {
			return (string) $value !== '';
		})));
	}

	/**
	 * Generate matching variants for supplier invoice references.
	 * This handles common AT prefixes (FT/FS/FR/...) and punctuation differences.
	 *
	 * @param string $ref
	 * @return array<int,string>
	 */
	private function referenceVariants($ref)
	{
		$normalized = $this->normalizeReference($ref);
		if ($normalized === '') {
			return array();
		}

		$variants = $this->primaryReferenceVariants($normalized);

		$lastSegment = preg_replace('/^.*[\\s\\-_\\/.]/', '', $normalized);
		if ($lastSegment !== null && $lastSegment !== '' && $lastSegment !== $normalized) {
			$variants[] = (string) $lastSegment;
			$lastSegmentTrimmed = ltrim((string) $lastSegment, '0');
			if ($lastSegmentTrimmed !== '' && $lastSegmentTrimmed !== $lastSegment) {
				$variants[] = $lastSegmentTrimmed;
			}
		}

		return array_values(array_unique(array_filter($variants, function ($value) {
			return (string) $value !== '';
		})));
	}

	/**
	 * @param array<string,mixed> $atDocument
	 * @param array<string,mixed> $invoice
	 * @return bool
	 */
	private function isSafeLooseDolibarrReferenceMatch(array $atDocument, array $invoice)
	{
		return $this->isSafeLooseReferenceMatch(
			isset($atDocument['supplier_ref']) ? $atDocument['supplier_ref'] : '',
			$this->resolveDolibarrInvoiceReference($invoice)
		);
	}

	/**
	 * @param array<string,mixed> $invoice
	 * @param array<string,mixed> $atDocument
	 * @return bool
	 */
	private function isSafeLooseAtReferenceMatch(array $invoice, array $atDocument)
	{
		return $this->isSafeLooseReferenceMatch(
			$this->resolveDolibarrInvoiceReference($invoice),
			isset($atDocument['supplier_ref']) ? $atDocument['supplier_ref'] : ''
		);
	}

	/**
	 * @param string $leftReference
	 * @param string $rightReference
	 * @return bool
	 */
	private function isSafeLooseReferenceMatch($leftReference, $rightReference)
	{
		$leftNormalized = $this->normalizeReference($leftReference);
		$rightNormalized = $this->normalizeReference($rightReference);
		if ($leftNormalized === '' || $rightNormalized === '') {
			return false;
		}

		$strictLeft = $this->primaryReferenceVariants($leftNormalized);
		$strictRight = $this->primaryReferenceVariants($rightNormalized);
		if (!empty(array_intersect($strictLeft, $strictRight))) {
			return true;
		}

		$leftTerminal = $this->extractTerminalReferenceSegment($leftNormalized);
		$rightTerminal = $this->extractTerminalReferenceSegment($rightNormalized);
		if ($leftTerminal === '' || $rightTerminal === '' || $leftTerminal !== $rightTerminal) {
			return false;
		}

		$leftCompact = preg_replace('/[^A-Z0-9]/', '', $leftNormalized);
		$rightCompact = preg_replace('/[^A-Z0-9]/', '', $rightNormalized);
		$leftCompact = ($leftCompact === null) ? '' : $leftCompact;
		$rightCompact = ($rightCompact === null) ? '' : $rightCompact;

		return ($leftCompact === $leftTerminal || $rightCompact === $rightTerminal);
	}

	/**
	 * @param string $reference
	 * @return string
	 */
	private function extractTerminalReferenceSegment($reference)
	{
		$normalized = $this->normalizeReference($reference);
		if ($normalized === '') {
			return '';
		}

		$lastSegment = preg_replace('/^.*[\\s\\-_\\/.]/', '', $normalized);
		if ($lastSegment === null || $lastSegment === '') {
			$lastSegment = $normalized;
		}

		$lastSegment = ltrim((string) $lastSegment, '0');
		return ($lastSegment !== '') ? $lastSegment : '';
	}

	/**
	 * @param array<string,array<int,array<string,mixed>>> $index
	 * @param array<int,string> $keys
	 * @param string $idField
	 * @return array<int,array<string,mixed>>
	 */
	private function collectIndexedCandidates(array $index, array $keys, $idField = '')
	{
		$candidates = array();
		$seen = array();

		foreach ($keys as $key) {
			if (!isset($index[$key]) || !is_array($index[$key])) {
				continue;
			}

			foreach ($index[$key] as $candidate) {
				if (!is_array($candidate)) {
					continue;
				}

				$identifier = '';
				if ($idField !== '' && isset($candidate[$idField]) && (int) $candidate[$idField] > 0) {
					$identifier = $idField . ':' . ((int) $candidate[$idField]);
				} elseif (isset($candidate['rowid']) && (int) $candidate['rowid'] > 0) {
					$identifier = 'rowid:' . ((int) $candidate['rowid']);
				} elseif (isset($candidate['at_document_id']) && (int) $candidate['at_document_id'] > 0) {
					$identifier = 'at_document_id:' . ((int) $candidate['at_document_id']);
				} else {
					$identifier = 'hash:' . hash('sha256', (string) json_encode($candidate));
				}

				if (isset($seen[$identifier])) {
					continue;
				}

				$seen[$identifier] = 1;
				$candidates[] = $candidate;
			}
		}

		return $candidates;
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param mixed $expectedAmount
	 * @return array<int,array<string,mixed>>
	 */
	private function filterCandidatesByAmountDirection(array $candidates, $expectedAmount)
	{
		if ($expectedAmount === null || $expectedAmount === '') {
			return $candidates;
		}

		$filtered = array();
		foreach ($candidates as $candidate) {
			if (!is_array($candidate)) {
				continue;
			}

			$candidateAmount = isset($candidate['total_ttc']) ? $candidate['total_ttc'] : null;
			if (!$this->amountDirectionsAreCompatible($expectedAmount, $candidateAmount)) {
				continue;
			}

			$filtered[] = $candidate;
		}

		return $filtered;
	}
}
