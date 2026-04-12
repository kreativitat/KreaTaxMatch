# KreaTaxMatch for Dolibarr

KreaTaxMatch reconciles Portuguese Tax Authority e-Fatura purchase rows with Dolibarr supplier invoices in a Dolibarr-native workflow.

## Features

- Authenticates against AT e-Fatura using the module configuration stored in Dolibarr constants.
- Downloads purchaser rows for an operator-selected date range and stores them with deduplication.
- Matches AT rows against supplier invoices using supplier VAT plus supplier reference, then validates invoice date and total amount before accepting an automatic match.
- Preserves manual matches and manual reconciliation notes per stored AT document.
- Creates supplier draft invoices for AT rows that exist in AT but not yet in Dolibarr.
- Includes a daily cron synchronization to refresh recent AT data without duplicating rows.

## Requirements

- Dolibarr 19 or later
- Supplier invoices module enabled
- Outbound HTTPS access to `acesso.gov.pt` and `faturas.portaldasfinancas.gov.pt`

## Configuration

1. Enable module `KreaTaxMatch`.
2. Open `Setup > Modules > KreaTaxMatch`.
3. Save the AT NIF, AT password, and cron lookback period.

## Daily Use

Open `Billing > Tax Match` and use the module tabs to:

- download AT data for a chosen period
- review stored AT documents
- reconcile AT documents against Dolibarr supplier invoices

## Public Repository Policy

- The public repository intentionally excludes live AT captures, raw taxpayer samples, and generated build artifacts.
- Keep any local debugging fixtures under ignored paths only.
- If the AT account requires two-factor authentication, login is rejected with a clear error because that flow is not yet automated.
