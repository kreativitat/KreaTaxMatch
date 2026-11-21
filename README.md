# KreaTaxMatch for Dolibarr

KreaTaxMatch helps accounting teams compare Portuguese Tax Authority e-Fatura purchases with supplier invoices recorded in Dolibarr. It brings downloaded AT documents, invoice matching and missing-invoice follow-up into one workspace.

## Features

- Download purchaser records from e-Fatura for a selected date range.
- Store imported records with duplicate detection.
- Match against supplier invoices by supplier VAT number and invoice reference, with date and total checks before accepting an automatic match.
- Keep manual matches and reconciliation notes.
- Create a supplier invoice draft when an AT document has no recorded invoice and the supplier already exists in Dolibarr.
- Refresh recent AT records through a daily Dolibarr scheduled job.

## Requirements

- Dolibarr 19 or later and PHP 7.1 or later.
- Dolibarr's supplier invoicing module enabled.
- An AT account for the company whose purchases you want to retrieve.
- Outbound HTTPS access to `acesso.gov.pt` and `faturas.portaldasfinancas.gov.pt`.

AT accounts that require two-factor authentication are not supported by the current login flow. The module reports that condition instead of completing the download.

## Installation and configuration

1. Place the module directory at `htdocs/custom/kreataxmatch`.
2. Enable **KreaTaxMatch** in Dolibarr's module administration.
3. Open the module setup and save the company's AT NIF, AT password and synchronization lookback period.
4. Grant the required read or write permissions to the users who will review documents and run matching.
5. Review the daily synchronization job in Dolibarr's Scheduled Jobs area. Automatic downloads require Dolibarr's scheduler to be running.

Configuration and document review take place in the active Dolibarr company.

## Reconcile purchases

Open **Billing → Tax Match**:

1. Download AT documents for the period you want to review.
2. Inspect the stored documents and run invoice matching.
3. Review unmatched or conflicting records and add a manual match or note where appropriate.
4. For a missing invoice, create a supplier draft and complete it using the original supplier document before validation.

An AT purchase record is summary evidence. A generated draft starts with a summary line; it does not reconstruct the original invoice's item lines or VAT breakdown. Review those details in Dolibarr before validating the invoice.

## Project structure

- `download.php`, `documents.php` and `match.php`: download and reconciliation screens.
- `class/`: AT client, matching rules, document service and scheduled synchronization.
- `admin/`: module setup.
- `sql/`: installation schema.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE) and [COPYING](COPYING).

Maintained by [Kreativität Works](https://kreativitat.com).
