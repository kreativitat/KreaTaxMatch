--
-- Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License,
-- or (at your option) any later version.
--
-- KreaTaxMatch persistent storage
--

CREATE TABLE IF NOT EXISTS llx_kreataxmatch_sync_batch (
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
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreataxmatch_document (
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
) ENGINE=innodb;

CREATE TABLE IF NOT EXISTS llx_kreataxmatch_manual_match (
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
) ENGINE=innodb;
