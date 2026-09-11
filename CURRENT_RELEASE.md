# Easy Ticket ERP — Current Local Authority

```text
CURRENT_VERSION=ERP-11.3.233
APPLICATION_VERSION=v1.1.33.233-ERP11.3.233
SOURCE_BASELINE=ERP-11.3.156 FINAL
BASELINE_SHA256=82d6a91af3256a94babbdc907fee89f77af2fc48c98ce341d92b7f8c10b87c83
WORKSPACE=D:\Easy Ticket\ERP\CURRENT
PRODUCTION_STATUS=NOT VERIFIED FROM THIS CLEANUP
LAST_PACKAGED_RELEASE=ERP-11.3.233
```

`CURRENT` is now the sole editable development authority. Future changes are
made and tested in this directory without creating versioned source copies or
automatic ZIP archives.

Only an explicit `PREPARE DEPLOYMENT` request authorizes release-number review,
full release validation, and creation of one package in `D:\Easy Ticket\ERP\DEPLOY`.
Deployment remains manual; this cleanup did not modify production.

Baseline proof: the former `.156` package matched the SHA-256 above, reported
the same application version, and independently extracted to 151 files that
matched all 151 files in the former `FINAL\source` tree byte-for-byte.

ERP-11.3.157 release candidate: the voucher logo adapter now reuses the native
Company report-logo presentation value (the same data-URI path proven on the
Company Profile page), with controlled blank/invalid fallback. No migration or
non-logo voucher behavior changed.

ERP-11.3.158 release candidate: completes embedded Company report-logo value
resolution and changes General/Multi-Service voucher footer authority to the
approved Saudi Company Footer → Company Default Footer rule. Pakistan IATA
footer inheritance is removed; no migration is added.

ERP-11.3.159 release candidate: constrains the real voucher header logo to an
aspect-ratio-preserving 80 x 80 pixel maximum and gives the initials fallback
the same footprint. Preview and print share the same markup and sizing rules.

Local development after `.159`: adds the authenticated GENERAL / MULTI-SERVICE
Booking Review & Process dashboard using native booking fields, saved product
snapshots, Company Profile, Sales Invoice, payment and central travel-readiness
authorities. No version increment, package or migration was created.

ERP-11.3.160 release candidate: consolidates that Booking Review implementation,
opens Preview/Review links in new tabs, moves the existing native Menu and
Booking Register controls into the width-aligned title toolbar, and removes the
redundant text checklist below the five service cards. No migration is added.

Local development after `.160`: consolidates product-save, Review-card,
Commercial-status and approval-gate rules in one booking commercial-completeness
resolver. Positive supplier cost now requires its existing Vendor authority.
Travel issuance/readiness remains a separate resolver. No version increment,
migration or package was created.

ERP-11.3.161 release candidate packages the shared commercial Vendor authority:
positive supplier costs require an existing cost owner across Air, Hotel,
Transport and Visa; Review status and approval consume the same resolver.

ERP-11.3.162 release candidate adds the Air Vendor, public voucher-token and
Travel Status migrations; separates readiness eligibility from persisted Ready;
enforces Approved/Ready read-only presentation and server locks; fixes internal
voucher/public QR route collisions; and keeps native Sales Invoice creation in
explicit default-off safe mode pending host-runtime verification.

ERP-11.3.163 release candidate adds the guarded native host-runtime Sales
Invoice bridge. Creation remains unavailable unless the host service and
createFromBooking method exist. Eligible creation is serialized and wrapped in
a database transaction, prevents duplicates, verifies the Draft header,
customer, booking, native number, product lines and authoritative total before
commit, and rolls back on any mismatch. No fallback accounting writes, automatic
approval/posting or new migration are added.

ERP-11.3.164 release candidate makes the host Booking model Customer / Party
relationship the first shared authority for the main booking, Booking Review,
Sales Invoice eligibility and native creator input. It also introduces the
approved two-level Visa presentation: clean section actions, one-line desktop
headers, associated passenger detail strips, compact row actions, balanced
totals/pagination and locked read-only behavior. Visa calculations, persistence
and commercial authority are unchanged. No migration is added.

ERP-11.3.165 release candidate persists the native host `confirmed` booking
state only when the GENERAL approval transition succeeds and returns it to an
editable non-confirmed state on submit/reopen. The native Sales Invoice gate,
runtime bridge, customer authority and legacy booking workflow remain intact.
No migration is added.

ERP-11.3.166 release candidate writes `bookings.status` directly on GENERAL
approval, reads it back inside the transaction, and rolls back instead of
reporting success if the host-native confirmation state did not persist. Reopen
returns the native status to an editable state. No migration is added.

ERP-11.3.167 release candidate adds a temporary Super-Admin-only GET diagnostic
for the host SalesInvoiceService confirmation predicate. It is reflection and
read-query only: it cannot invoke invoice creation or write booking/accounting data.
No migration is added.

ERP-11.3.202 direct-upload release adds deterministic native Air-to-generic
passenger-link reconciliation inside the guarded Sales Invoice transaction,
after booking/customer validation and before the unchanged host invoice
creator and validator. Normal Air Save synchronization remains intact. No
commercial values or other product data are changed, and no migration is added.

ERP-11.3.203 direct-upload release extends that reconciliation to the confirmed
booking-wide Hotel product 3 and Transport product 4 contracts. Their exact
active booking-passenger set is synchronized only for REQUIRED/MULTIPLE +
PER_SERVICE services, both during normal product saves and before native Sales
Invoice creation. Air remains ticket-authoritative; no commercial values,
validator logic or migrations change.

ERP-11.3.204 direct-upload release materializes the native Visa booking service
from authoritative `booking_visa_services` child rows. It resolves the native
Visa Product/Service identity and contract at runtime, preserves the exact Visa
passenger subset, synchronizes Visa customer/vendor totals, and supports
historical approved bookings inside the guarded Sales Invoice transaction.
Air, Hotel and Transport values, invoice verification and migrations remain
unchanged.

ERP-11.3.205 direct-upload release aligns that Visa materialization with the
production-native `booking_services` commercial schema. It persists the
authoritative Visa customer total through `line_total`, derives `unit_price`
from the runtime pricing quantity, records the native description and PKR
currency, and writes `vendor_id` only from one unambiguous child-row authority.
Exact Visa passenger links, the PKR 931,200 booking total, Air/Hotel/Transport
values, invoice verification and migrations remain unchanged.

ERP-11.3.206 diagnostic release preserves every existing validation and
accounting rule while exposing the actual native Sales Invoice header total,
line count and line total on mismatch. Its Super-Admin diagnostic adds a
rollback-only pre-native service commercial audit and the full reflected host
`SalesInvoiceService::createFromBooking()` source. It never calls the invoice
creator, never commits temporary reconciliation, and adds no migration.

ERP-11.3.207 direct-upload release persists the native Hotel and Transport
PER_SERVICE quantity, unit price, line total and PKR currency during normal
product saves. Historical approved bookings reconcile the same fields from
each product's own persisted rows inside the guarded Sales Invoice transaction,
before passenger reconciliation and native invoice creation. Air, Visa,
SalesInvoiceService and the native verifier remain unchanged; no migration is
added.

ERP-11.3.208 direct-upload release adds read-only Sales Invoice product
profitability visibility. Invoice sale amounts come from the native Sales
Invoice snapshot. Air cost comes from `air_ticket_details.net_supplier_cost`;
Hotel cost comes from persisted `vendor_total`, with persisted `cost_rate *
nights` fallback; Transport cost comes from persisted PKR `cost_amount`; and
Visa cost comes from `booking_visa_services.vendor_cost_pkr`. Missing product
cost remains visibly incomplete and is never silently treated as zero. These
costs do not change the invoice grand total, customer receivable, revenue
journal, posting, workflow or booking data. No migration is added.

ERP-11.3.209 direct-upload release completes the Sales Invoice profitability
UI polish. It removes the legacy standalone Accounting status card above the
summary row, preserves the approved five-card summary, improves Passenger /
Ticket Summary readability and mobile stacking, and labels the journal section
as Accounting Preview (Journal Lines). Profitability calculations,
SalesInvoiceService, accounting logic, booking/invoice mutations and workflow
remain unchanged. No migration is added.

ERP-11.3.210 hotfix release fixes the Sales Invoice blank-page regression
caused by the `.209` legacy Accounting card cleanup. The cleanup is now safely
bounded to the compact top Accounting / Not Posted card and cannot hide the
shared page container, Review card, lower Accounting Preview or document body.
Profitability calculations, accounting logic, SalesInvoiceService, workflow
and booking/invoice data remain unchanged. No migration is added.

ERP-11.3.211 hotfix release fixes Air Ticket commercial synchronization during
Sales Invoice Submit for Approval. New grouped Adult, Child and Infant Air
invoice lines preserve required native structural fields. Currency authority is
the native Air template line `currency_code` / `currency`, followed by the
native Sales Invoice header `currency_code` / `currency`; no currency is
hard-coded. Unresolved required native structure stops with controlled
validation before database insertion. Accounting, profitability, workflow and
booking data remain unchanged. No migration is added.

ERP-11.3.212 hotfix release fixes grouped Air invoice line numbering during
Sales Invoice Submit for Approval. Existing persisted Air line numbers are
preserved. A newly-created grouped Air line receives an invoice-wide unique
line number based on all occupied native invoice line numbers, without
renumbering Hotel, Transport, Visa or other non-Air lines. Deterministic Air
ordering, idempotent synchronization, transaction rollback and the ERP-11.3.211
currency correction remain preserved. Accounting, profitability, workflow and
booking data remain unchanged. No migration is added.

ERP-11.3.213 hotfix release corrects Sales Invoice Air commercial integrity
checking for multi-product invoices. The authoritative saved-ticket Air total
is compared only with native Air invoice lines, while the native Sales Invoice
header is compared with the sum of all native invoice lines. Correct invoices
are no longer falsely blocked because the complete invoice exceeds its Air-only
portion. Authoritative currency inheritance, required native structural-field
validation, invoice-wide unique Air line numbering, deterministic/idempotent
grouping and post-sync safeguards remain preserved. Accounting, profitability,
workflow and booking data remain unchanged. No migration is added.

ERP-11.3.214 diagnostic release introduces a temporary Super-Admin-only,
rollback/read-only runtime diagnostic for the native Customer Ledger HTTP 503.
It dynamically discovers the installed Customer Ledger route, controller,
middleware and model-binding authority, reports the Sales Invoice customer
identifiers, and probes the native GET action inside a database read-only
transaction that is always rolled back. It redacts credential-like exception
content and exposes no environment, cookie or session data. Sales Invoice
posting, journal accounting, Customer Receivables, ledger balances and customer
accounting data remain unchanged. No migration is added.

ERP-11.3.215 diagnostic release extends the temporary Customer Ledger HTTP 503
diagnostic. Phase 2 dynamically derives the native route/model binding, renders
the native Customer Ledger View as a separate rollback-only stage, resolves and
inspects each middleware authority, checks `journals.view`, and progressively
probes safe middleware stages to identify the first production HTTP pipeline
failure. Middleware whose source indicates an irreversible write is reported
but not duplicated by the diagnostic; later independently safe middleware can
still be isolated. No Customer Ledger functional fix, Sales Invoice posting,
journal accounting, Customer Receivable, ledger balance or accounting-data
change is included. No migration is added.

ERP-11.3.216 introduces Expense Voucher accounting. Expense Vouchers use EV
year/sequence numbering and support direct business-expense recording, multiple
Chart-of-Accounts-backed Expense Account lines, optional Payee and Booking
references, a Cash/Bank payment account, currency and exchange rate, payment
method, reference/narration, proof attachment, the Draft to Pending Approval to
Approved to Posted workflow, balanced native journal posting, controlled
reversal, printing, and dedicated navigation and permissions. Posting debits
Expense Accounts and credits the selected Cash/Bank account. It does not create
Supplier Payables, Customer Receivables, Supplier Costing allocations or Sales
Invoice allocations. The new migration
`database/migrations/2026_09_10_120000_create_cash_voucher_expense_lines.php`
creates `cash_voucher_expense_lines`.

ERP-11.3.217 fixes the Cash Voucher / Expense Voucher form release indicator so
it uses the current ERP release metadata instead of the stale hard-coded
ERP-11.3.27 label. No accounting logic, layout or workflow changes are included,
and no new migration is added.

ERP-11.3.218 completes the controlled Voucher accounting module. It adds Contra
Voucher for Cash-to-Bank, Bank-to-Cash and Bank-to-Bank transfers using CV
year/sequence numbering, the existing controlled workflow, balanced native
journal posting and controlled reversal. Voucher details now provide native GL
Account Ledger and Journal Entry drill-downs with plain-text fallback, and the
remaining Cash Voucher release indicators are dynamic. The existing native
manual Journal Entry remains the authority for arbitrary balanced non-cash
journals, so no duplicate Journal Voucher is introduced. The new migration
`database/migrations/2026_09_10_130000_create_cash_voucher_contra_details.php`
creates the one-to-one Contra destination and transfer-detail authority.

ERP-11.3.219 makes Supplier Costing booking-driven and vendor-safe. Selecting a
booking resolves authoritative Air, Hotel, Transport and Visa vendor-cost
obligations, limits selection to suppliers attached to those obligations and
automatically loads only the selected supplier's source lines. Booking base
cost remains read-only while Draft tax and other charges remain adjustable.
Each line retains deterministic source traceability and a globally unique
source key prevents the same booking obligation from being costed twice across
Draft, Pending Approval, Approved and Posted documents. Workflow transitions
revalidate supplier, source and cost integrity before the existing balanced
native posting of product Cost Accounts to Vendor Payable. The new migration
`database/migrations/2026_09_10_140000_create_supplier_costing_source_links.php`
creates the source-link authority. Payment Voucher allocation remains separate
and available only against Posted Supplier Costing documents.

ERP-11.3.220 adds read-only management accounting and posted-profit reporting.
Management Overview separates revenue from cash movement and provides daily,
month-to-date, previous-month and fiscal-year-to-date profit visibility alongside
Cash/Bank, Customer Receivable, Vendor Payable and advance positions. Profit &
Loss, Balance Sheet, posted product profitability and native Account Ledger
drill-downs all derive from the same posted native journal population. The native
Trial Balance and Report & Print Center remain authoritative and gain direct
navigation to the new reports. No accounting record is mutated and no migration
is added.

ERP-11.3.221 fixes the Profit & Loss report HTTP 500 caused by unsafe inline
multi-statement Blade `@php(...)` directives. The affected comparison-row and
summary calculations now use valid `@php` / `@endphp` blocks. Posted-journal
authority, P&L formulas, Management Overview, Trial Balance, Balance Sheet,
account drill-downs and print behavior remain unchanged. No accounting data is
mutated and no migration is added.

ERP-11.3.222 removes all remaining view-local PHP calculation state from the
Profit & Loss report. The controller now prepares stable section and summary
rows, formatted current/previous values, variances, percentages and native
Account Ledger URLs before rendering. The Blade view only iterates and presents
that supplied data. Posted-journal authority, accounting formulas, Management
Overview, Trial Balance and Balance Sheet remain unchanged. No accounting data
is mutated and no migration is added.

ERP-11.3.223 introduces a shared professional UI system across normal ERP HTML
pages. It provides a compact shell and sidebar, restrained module accents,
consistent typography, forms, buttons, cards, tables and statuses, responsive
layouts, concise labels and safe supplier-name presentation. Public voucher,
print and PDF routes remain excluded from the global UI injection. No business,
accounting, booking, workflow or persistence logic changes, and no migration is
added.

ERP-11.3.224 refines visual-match Phase 1 for the shared shell, compact blue
active navigation, generic utility topbar, single Dashboard page-header
authority, responsive non-truncating KPI layout and denser Dashboard cards. It
also removes obsolete visible System Health wording and isolates the preserved
production reset and financial cleanup tools under Advanced / Dangerous Actions.
Public voucher, print and PDF exclusions remain unchanged. No business,
accounting or database logic changes, and no migration is added.

ERP-11.3.225 serves the professional ERP stylesheet and JavaScript through
authenticated Laravel routes so production installations with a separate cPanel
document root can load the Phase 1 presentation assets. The fixed asset responses
use explicit CSS/JavaScript MIME types, no-sniff protection and no-cache headers.
Existing UI behavior and public voucher, print and PDF exclusions are preserved.
No business, accounting or database logic changes, and no migration is added.

ERP-11.3.226 completes the sidebar presentation polish with a restrained active
navigation tint and thin indicator, consistent icon alignment, clearer section
headings, a quieter interactive scrollbar and more compact logo and release
footer areas. Presentation grouping reuses only links already rendered by native
permission authority and creates no new routes. No business, accounting or
database logic changes, and no migration is added.

ERP-11.3.227 recomposes the sidebar presentation to the approved reference with
a 224px navy shell, compact coloured icon tiles, reference-selected navigation,
real-child chevrons, a release/Live footer and the approved Operations,
Accounting, Master Data and Administration ordering. Existing rendered links,
hrefs, permissions and nested behavior remain authoritative. No business,
accounting or database logic changes, and no migration is added.

ERP-11.3.228 restores deterministic Dashboard/Home discovery in the shared
sidebar and tightens generated section-heading spacing so each heading sits
directly above its first permitted link. Existing rendered links, hrefs,
permissions, active-state behavior and real nested menus remain authoritative.
No business, accounting, booking or database logic changes are included, and
no migration is added.
ERP-11.3.229 finalizes the approved shared ERP sidebar presentation. Dashboard
is restored as the first navigation row, accounting voucher links are normalized
under Accounting using exact label matching, and generated section headings keep
tight child spacing. System Health presentation is also cleaned to use concise
health/database copy while preserving operational maintenance functions and
Dangerous Actions. No business, accounting, booking or database logic changes
are included and no migration is required.
ERP-11.3.230 finalizes the shared ERP navigation runtime corrections. Section
headings now maintain controlled spacing from their first child link; unauthenticated
login pages no longer receive authenticated professional-UI asset URLs; and shared
cash-voucher routes use path + query-string authority so Receipts, Payments,
Expense Vouchers and Contra Vouchers cannot all appear selected simultaneously.
No business, accounting, booking or database logic changes are included and no
migration is required.
ERP-11.3.231 finalizes the approved shared ERP sidebar section-heading rhythm.
Generated section headings retain 16px separation from the preceding group,
9px child padding before the first permitted row and line-height 1.15.
Authenticated professional-UI delivery, guest-login redirect protection,
query-aware cash-voucher active states, native rendered-link authority and
permissions remain unchanged. No business, accounting, booking or database
logic changes are included and no migration is required.
ERP-11.3.232 corrects the remaining sidebar section-heading overlap found during
ERP-11.3.231 live UAT. Section headings retain 16px separation from the preceding
group and line-height 1.15, while the first permitted row in each generated
section now owns an explicit 9px top margin. This prevents an active highlighted
row from intruding into the section heading area. Login protection, query-aware
voucher active states, rendered-link permission authority and all accounting,
booking and persistence behavior remain unchanged. No migration is required.
ERP-11.3.233 finalizes the sidebar section-heading spacing confirmed during
ERP-11.3.232 live UAT and browser DevTools verification. Generated section
headings now use an explicit 16px top, 9px horizontal and 9px bottom margin,
with zero heading padding and line-height 1.15. The first permitted row also
retains its 9px top margin. This provides stable visual separation for both
active and inactive first rows. Login protection, query-aware voucher active
states, rendered-link permission authority and all accounting, booking and
persistence behavior remain unchanged. No migration is required.
