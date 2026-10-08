# Contracts module — developer notes

## 1. Architecture assessment (before this phase)

| Area | State found | Gap against requirements |
|---|---|---|
| Schema | `contracts` with reference, type, dates, amount, billing cycle, currency, status (draft/active/completed/cancelled), scope, terms, signed file, e-signature fields | No payment terms, notice period, signatories, approval, termination, versions, audit log, consent flag |
| Lifecycle | Status edited freely on the form; client signing sets `signed_at` | No state machine, no approval step, no expiry or termination, no renewals, no amendment history |
| Finance | `dueToDate()` / `balance()` from verified payments on the contract | No invoiced total, receivables, overdue, remaining value, forecast, cancellable split; dashboard only summed `amount` |
| Access | Role middleware plus `client_id` checks in controllers | No policy class, no access audit |
| Reporting | None | Dashboard, renewal alerts, banking package, CSV |
| Jobs | None (shared hosting, `QUEUE_CONNECTION=sync`) | Scheduled expiry and reminders |

Nothing was rewritten. Existing routes, records, URLs, the e-signature flow, invoices and payments all behave as before.

## 2. Schema changes (migration `2026_10_08_000003`, additive)

`contracts`: `payment_terms_days` (default 14), `notice_period_days`, `auto_renew`, `renewal_of_id`, `client_signatory`, `company_signatory`, `confidential`, `version` (default 1), `approved_at/by`, `activated_at`, `terminated_at`, `termination_reason`.
`clients`: `disclosure_consent_at`.
New tables: `contract_versions` (immutable JSON snapshot per version, unique per contract+version) and `activity_logs` (user, subject, action, changes JSON, IP, time).
Status values added: `approved`, `expired`, `terminated`. Old values keep working.

## 3. Lifecycle (`App\Services\ContractLifecycle`)

```
draft --approve--> approved --activate--> active --complete--> completed
  |                   |                     |--expire (scheduler)--> expired
  |--cancel           |--cancel             |--terminate(reason)--> terminated
  |--activate (skip approval)               
approved/cancelled --reopen--> draft
```

- "Send for signature" approves a draft; the client's e-signature activates an approved contract.
- Every transition writes an `activity_logs` row. Terminate requires a reason and sets `end_date` to the termination date so future billing stops.
- **Amendments**: editing any material field (`Contract::MATERIAL_FIELDS`) on a non-draft contract stores the previous values in `contract_versions`, bumps `version`, and logs a diff. Versions are never edited. `/admin/contracts/{id}/versions/{n}` shows a side-by-side.
- **Renewal**: `renew()` drafts a linked contract starting the day after the end date with the same term and terms. `auto_renew` contracts are renewed and activated by the scheduler when they expire.
- Signed, invoiced or paid contracts cannot be deleted (HTTP 403); terminate or cancel instead.

## 4. Financials (`App\Services\ContractFinance`)

Everything is computed from invoices and verified payments; nothing is stored twice.

| Metric | Definition |
|---|---|
| contracted value | one-off price, or cycle price × cycles in the term; `null` for open-ended |
| invoiced | invoices on the contract not draft/cancelled |
| collected | verified payments on the contract |
| receivable / overdue | invoice balances / balances on invoices past due |
| remaining value | contracted − invoiced |
| monthly recurring | cycle price per month, live contracts only |
| forecast (12m) | cycle starts in the next 12 months for live contracts + un-invoiced remainder of live one-offs |
| cancellable | forecast on open-ended contracts beyond the notice period |

Expired (end date passed), terminated, cancelled and completed contracts contribute nothing to forecasts or MRR. `effectiveStatus()` reads an active contract past its end date as expired before the scheduler marks it.

## 5. Dashboard and reports

- `/admin/reports/revenue (Reports → Revenue)`: filters (status, type, billing, client, currency), eight KPI tiles, a 12-month forecast bar chart with a table view, renewal alerts (`company.renewal_alert_days`), notice deadlines, unsigned live contracts, and a per-contract table.
- `/admin/reports/banking`: Banking Readiness Report. Includes only contracts whose client has `disclosure_consent_at` and that are not `confidential`. `?all=1` previews unconsented clients for internal use (confidential contracts are never shown). Sections: summary, signed register with signature evidence, contract summaries, receivables history, verified payments, 12-month cash-flow forecast, supporting documents, assumptions, verification hash. The report states it is **not certified**. Views and exports are audited.
- `/admin/reports/banking/export?part=register|summaries|receivables|payments|forecast` streams CSV with an issue-date header line.
- Documents are paginated client-side with Paged.js (`resources/js/document.js`, `resources/css/document.css`): true Letter pages on screen and in print, running footers with page counts, `break-inside: avoid` on table rows, totals and signature blocks, and a page break before the standard terms. PDFs come from the browser's print dialog, so the Hostinger install needs no PHP PDF library.
- Standard terms live in the `settings` table (editable under Admin → Terms, defaults in `config/company.php`) and are frozen onto each contract in `contracts.standard_terms` when it is signed.

## 6. Access control and audit

- Policies for Contract, Invoice, Quotation and Payment (registered in `AppServiceProvider`) hold every tenant rule; controllers call `authorizeView()` (clients get 404, admins pass) or `can('sign')` / `can('answer')`. Deactivating a client ends their users' sessions on the next request.
- One date rule (`DateRules::dateHasPassed`): a due, valid-until or end date is current through the end of its own day; invoices become overdue, quotations expire and contracts expire the following day, matching the scheduler's `whereDate(... < today)` queries.
- Private files stay on the `local` disk and stream through `FileController` after a policy check.
- `App\Support\Audit::log()` records: created, approve, activate, complete, expire, terminate, cancel, reopen, amended, renewed, sent_for_signature, signed_by_client, viewed_by_client, document_viewed, file_downloaded, banking_report_viewed, banking_report_exported.

## 7. Jobs and scheduling

`App\Jobs\ExpireContracts` (daily 01:00) and `App\Jobs\SendRenewalReminders` (Mondays 08:00) are queued jobs. On Hostinger `QUEUE_CONNECTION=sync` runs them inline from the scheduler, so add one cron entry:

```
* * * * * cd ~/public_html && php artisan schedule:run >> /dev/null 2>&1
```

## 8. Tests

`tests/Feature/ContractLifecycleTest.php` (transitions, termination, amendments and versions, renewal, scheduler expiry and auto-renew, approval via signature, delete guard), `ContractFinanceTest.php` (one-off, fixed-term monthly, open-ended with notice, quarterly and yearly, closed contracts, portfolio totals), `BankingReportTest.php` (consent and confidentiality filters, hash, admin-only access, CSV, audit, dashboard filters), plus the earlier portal and signing suites.

## 9. Deferred (next phases)

- Multi-currency conversion in portfolio totals (amounts are summed per contract currency; the dashboard warns when more than one currency is present and offers a currency filter).
- Server-side PDF rendering and a zipped supporting-documents bundle.
- Client-side acknowledgement of amendments (re-signing a new version).
