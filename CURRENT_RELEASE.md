# Easy Ticket ERP — Current Local Authority

```text
CURRENT_VERSION=ERP-11.3.251
APPLICATION_VERSION=v1.1.33.251-ERP11.3.251
SOURCE_BASELINE=ERP-11.3.156 FINAL
BASELINE_SHA256=82d6a91af3256a94babbdc907fee89f77af2fc48c98ce341d92b7f8c10b87c83
WORKSPACE=D:\Easy Ticket\ERP\CURRENT
PRODUCTION_STATUS=NOT VERIFIED FROM THIS CLEANUP
LAST_PACKAGED_RELEASE=ERP-11.3.251
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
ERP-11.3.234 corrects sidebar first-paint flashing observed during ERP-11.3.233
live UAT. Native sidebar navigation is hidden only during professional sidebar
normalization, preserving its layout footprint, then revealed immediately after
the final UI sequence completes. A 2-second CSS failsafe reveals the native
navigation if JavaScript fails. Versioned professional UI assets now use private
immutable browser caching so repeated ERP navigation does not unnecessarily
refetch unchanged CSS and JavaScript. Final ERP-11.3.233 sidebar spacing remains
unchanged: headings use 16px 9px 9px margins, zero padding, line-height 1.15,
and the first permitted row retains 9px top separation. No accounting, booking,
permission, voucher, ledger, persistence or database behavior is changed. No
migration is required.
ERP-11.3.235 refreshes the accounting cash/bank voucher workspace using the
approved professional ERP visual direction. Voucher modes now use compact
segmented navigation, voucher creation actions use a separate compact toolbar,
search and filtering use a structured card treatment, and summary values use a
balanced three-column dashboard rhythm. The same card, field and action styling
is applied consistently to Receipt, Payment, Expense, Contra, Customer Advance,
Supplier Advance, voucher detail/workflow and Advance Adjustment workspaces.
Print voucher presentation is intentionally unchanged. Existing accounting
posting authority, allocations, workflow states, permissions, journals,
ledgers, reversals, persistence and database behavior remain unchanged. No
migration is required.
ERP-11.3.236 unifies the three primary operational registers — Bookings, Sales
Invoices and Supplier Costing — under the approved professional ERP visual
system. The exact register routes now share consistent page-heading hierarchy,
primary actions, KPI/status cards where already rendered, structured Search &
Filter treatment, register-table geometry, semantic status pills, compact row
actions and responsive behavior. The enhancement is strictly presentation-only:
existing Booking, Sales Invoice and Supplier Costing controllers, permissions,
workflow transitions, posting authority, calculations, persistence and database
behavior remain unchanged. ERP-11.3.235 accounting voucher styling and
ERP-11.3.234 sidebar first-paint stabilization remain intact. No migration is
required.
ERP-11.3.237 rebuilds the Booking Register presentation to match the approved
professional reference. The register now provides Total Bookings, Pending
Confirmation, Confirmed and Cancelled KPI cards; quick status filters; Search,
Customer, Travel Type, Travel Date From/To and Status filters; a dynamic
Bookings count; safe client-side CSV export; row selection; compact row action
menus; and 15-row pagination. Read-only Quick Workflow, Bookings by Type and
Recent Activity panels complete the reference layout. Existing authoritative
Booking rows and links remain the data source. Booking create/edit, confirmation,
workflow, invoicing, permissions, posting, persistence and database behavior are
unchanged. ERP-11.3.236 shared Sales Invoice/Supplier Costing register styling,
ERP-11.3.235 accounting voucher styling and ERP-11.3.234 sidebar stabilization
remain intact. No migration is required.
ERP-11.3.238 extends the approved ERP-11.3.237 Booking Register reference visual
system to the Sales Invoice and Supplier Costing registers. Sales Invoices now
use Total Invoices, Pending Approval, Approved and Posted KPI cards; Customer,
Booking, Date and Status filtering; quick workflow filters; dynamic register
count; safe client-side CSV export; row selection; compact action menus; 15-row
pagination; Quick Workflow; Invoices by Status; and Recent Activity. Supplier
Costing receives the equivalent structure using Total Costings, Supplier,
Product and workflow-specific presentation plus Costings by Product. Both pages
reuse the approved Booking Register visual classes for consistent geometry and
spacing. Existing invoice and supplier-costing rows, native Open links, workflow
authority, posting, accounting, persistence and database behavior remain
unchanged. ERP-11.3.237 Booking Register design, ERP-11.3.235 voucher design and
ERP-11.3.234 sidebar stabilization remain intact. No migration is required.
ERP-11.3.239 consolidates the Booking Register, Sales Invoice Register and
Supplier Costing Register into server-rendered professional workspaces. The
native controllers, middleware, permissions, row data and server pagination
remain authoritative; final register markup is normalized on the server before
professional CSS and JavaScript are injected, eliminating the previous
old-layout-to-new-layout browser reconstruction path. The obsolete ERP-11.3.236,
ERP-11.3.237 and ERP-11.3.238 register reconstruction JavaScript and the old
shared register patch stylesheet are no longer delivered by the professional
asset controller. One shared register visual system remains, with a small
interaction-only script for filtering, row selection, CSV export, menus and
15-row client interaction pagination over the authoritative rendered rows.
Accounting Cash/Bank Vouchers and Advance Adjustments remain server-rendered and
continue using the authoritative ERP-11.3.235 accounting visual stylesheet.
No accounting formulas, workflow authority, posting, booking persistence,
permissions, database schema or business logic changed. ERP-11.3.234 sidebar
stabilization remains protected. No migration is required.
ERP-11.3.240 shell and spacing consolidation establishes one authoritative
application frame across the ERP. Normal desktop pages use one 24px horizontal
content gutter and one 20px vertical page gutter, while duplicate wrapper and
nested container spacing is removed. The native authenticated utility topbar is
preserved on Booking Register, Sales Invoice Register and Supplier Costing while
the ERP-11.3.239 server-rendered register architecture remains authoritative.
Accounting register, action, filter and summary spacing is normalized to the same
outer canvas. Sidebar grouping remains presentation-only, but runtime JavaScript
no longer writes section-heading or first-row margins; final sidebar geometry is
owned by CSS. Existing booking, Sales Invoice, Supplier Costing, voucher,
accounting, journal, ledger, reporting, permission and database authorities are
unchanged. No migration is required.
ERP-11.3.241 sidebar and global UI stabilization removes the duplicate
post-load navigation regrouping that could cause sidebar row movement, incorrect
temporary active states and heading/submenu overlap during navigation. Native
permission-rendered sidebar row order remains authoritative. A single hidden
prepaint finalization pass inserts presentation-only section headings in place,
classifies root and nested navigation levels, and uses native active state first
with an exact path/query fallback only when no native active state exists. Root,
nested and section geometry now use separate CSS contracts, the brand/logo area
is more compact, and an active nested child cannot visually promote its parent
into a second selected destination. Shared page-title, form-control, card and tab
tokens are normalized across ERP modules while preserving the ERP-11.3.240 outer
frame and ERP-11.3.239 server-rendered register architecture. Booking,
Sales Invoice, Supplier Costing, voucher, accounting, journal, ledger,
permission, persistence and database behavior are unchanged. No migration is
required.
ERP-11.3.242 Day-Zero reset preview restores the accepted ERP-11.3.240 UI
implementation after ERP-11.3.241 failed visual UAT. The release introduces a
Super Admin / Owner-only fresh-production database inspection workflow at the
existing Production Data Reset route. The live schema is classified table by
table as CLEAR, RESET COUNTER, PRESERVE or REVIEW with row counts shown before
any reset is considered. Unknown tables fail closed to REVIEW. User accounts,
roles and permission infrastructure remain preserved. A separate full compressed
database backup can be downloaded before any future Day-Zero reset.

ERP-11.3.242 is PREVIEW + BACKUP ONLY. Destructive execution is hard-disabled in
the service and the reset button is disabled. No table deletion, truncation,
counter reset or other business-data mutation can execute from this release.
A later explicitly reviewed release will be required before Day-Zero execution
can be enabled. No migration is required.
ERP-11.3.243 Day-Zero classification preview resolves the complete live
ERP-11.3.242 production REVIEW set. All 31 previously unclassified tables now
have explicit approved actions: 20 CLEAR, 10 PRESERVE and 1 RESET COUNTER.
Existing user accounts, user-role/branch links, roles, permissions, staff,
organization and required accounting/security foundations remain preserved.
Business/UAT identities, business-entered Day-1 masters and historical runtime
residue are explicitly classified for clearing. Number-sequence counters are
classified for restart.

This remains a classification PREVIEW ONLY. EXECUTION_ENABLED remains false,
the service contains no destructive database operation, and future unknown
tables continue to fail closed to REVIEW. ERP-11.3.240 UI behavior remains the
accepted UI baseline. No migration is required and deployment itself performs
no business-data mutation.
ERP-11.3.244 Day-Zero execution-safety preview retains the production-confirmed
zero-REVIEW classification plan from ERP-11.3.243 and adds the final runtime
safety inspection required before destructive execution can be considered. The
planner discovers live foreign keys, blocks any preserved/counter/review child
that references a CLEAR parent, computes child-before-parent deletion order for
CLEAR tables, detects dependency cycles and inspects the exact live numbering
columns that would be reset.

The Day-Zero page also reports fresh-backup validation using the same service
authority used by the future execute path, and its header now derives the current
release dynamically instead of displaying the stale ERP-11.3.242 label.

ERP-11.3.244 remains PREVIEW ONLY. EXECUTION_ENABLED is false, the reset control
remains locked, and the new service contains no delete, truncate or other
destructive database operation. No migration is required and deployment itself
performs no business-data mutation.
ERP-11.3.245 Day-Zero FK-resolution preview addresses the live ERP-11.3.244
foreign-key safety findings while keeping destructive execution locked.
service_cost_allocations is corrected from PRESERVE to CLEAR because it is
transactional allocation data linked to bookings, booking services, ticket
details, sales invoices, invoice lines and vendor parties.

Preserved airlines and booking_sources remain intact, while their
default_vendor_party_id links to CLEAR parties are inspected at runtime for
schema-proven NULL neutralization. Exact cyclic strongly-connected FK edges are
now exposed with column nullability, and only safely nullable cycle edges are
excluded from the effective dependency graph when recalculating the proposed
child-before-parent delete order.

ERP-11.3.245 remains PREVIEW ONLY. EXECUTION_ENABLED is false, the reset control
remains disabled and no delete, truncate, counter mutation or FK update executes
from this release. No migration is required.
ERP-11.3.246 controlled Day-Zero execution is the approved one-time production
fresh-start release following the successful ERP-11.3.245 live FK-resolution
audit.

Execution remains protected by Super Admin / Owner authorization, exact
confirmation phrase, explicit acknowledgement and a fresh validated full backup.
Immediately before mutation the live Day-Zero planner is rebuilt and every
safety gate is checked again.

Approved preserved-master FK references are neutralized only where the live
schema proved them nullable. Any required nullable cycle edges are neutralized
before deletion. CLEAR tables are deleted in the verified child-before-parent
order inside a transaction. Numbering counters are then restarted from the
approved preview values.

Before commit, every CLEAR table is verified empty and all counter values are
verified. A concurrent execution lock prevents duplicate attempts and a
permanent completion marker records the backup SHA256 and permanently prevents
a second Day-Zero execution.

No migration is required. Normal booking, invoice, voucher, supplier-costing
and accounting formulas are unchanged.
ERP-11.3.247 Day-One production numbering finalization follows the completed
ERP-11.3.246 Day-Zero reset.

The first genuine production transactional IDs and business document sequences
start at plain 1000, followed by 1001, 1002 and onward. Six-digit business
number padding is removed from the affected booking, invoice, voucher,
supplier-costing and posting-reference authorities; 001000 is not used.

The one-time Day-One action requires the Day-Zero completion marker and refuses
to execute if any CLEAR business table contains newly entered production data.
Database identity state is normalized per supported database engine and native
counter tables are set according to last-used 999 / next-value 1000 semantics.

Native Sales Invoice creation includes a narrow number normalizer so a host
formatter that produces SI-YEAR-001000 is persisted as SI-YEAR-1000 without
changing invoice amounts, workflow, posting or accounting behavior.

A permanent Day-One sequence completion marker prevents replay. No migration is
required and the ERP-11.3.240 accepted UI baseline remains unchanged.

ERP-11.3.248 unified shell consolidation replaces the fragmented authenticated
page-shell geometry with one final CSS authority loaded after module styles.

The same outer frame now applies to Dashboard, registers, Accounting,
New Booking / General Progressive Booking and Air focus workspaces. The previous
shell stylesheet excluded gp-focus-mode and et-air-focus-mode-103172, which left
booking screens on a different spacing model.

Desktop geometry:
- Sidebar width: 224px
- Utility header: 56px
- Main horizontal gutter: 24px
- Main top rhythm: 18px
- Brand row: 64px
- Logo footprint: 36px
- Navigation row: 36px
- Navigation horizontal inset: 10px

Generated sidebar headings and active-link appearance are CSS-owned. JavaScript
continues to own grouping/state behavior but no longer injects sidebar spacing,
geometry or active-link presentation with style.setProperty.

No migration, booking/accounting business logic, database schema, print layout
or voucher layout is changed.

ERP-11.3.249 final Day-One production numbering authority follows the completed
Day-Zero reset. The first production number and business identity is plain
1000; last-used counter authorities are normalized to 999 and next-number
authorities to 1000. `number_sequences.padding` is explicitly enforced and
verified as 4, so neither 000001 nor 001000 is used.

`audit_logs` and `login_events` are recognized only by Day-One as post-reset
runtime/security telemetry. Their rows are not deleted, their identities are
not reseeded, and they do not weaken the fail-closed emptiness gate for every
genuine business CLEAR table. Day-Zero classification remains unchanged.

Execution still requires the exact `RESET DAY ONE SEQUENCES` confirmation,
the completed Day-Zero marker, all counter and identity verification, and the
permanent one-time Day-One completion lock. No migration, booking/accounting
logic, shell UI, print or voucher layout changes are included.

ERP-11.3.251 promotes the approved live UI geometry corrections under the
same final authenticated ERP shell authority:
`public/erp-ui/erp-shell-spacing.css`. It owns the 208px desktop/laptop
sidebar, 8px navigation inset, 64px brand region, 36px logo footprint and
navigation rows, 56px utility header, 24px desktop gutter and 16px responsive
gutter.

Booking, General Progressive Booking, Air and Group Package outer canvases now
use that shared responsive authority without a 1280px shell cap, independent
calculated gutter or runtime workspace-width adjustment. Sidebar headings and
root row wrappers remain in normal flow; the focus utility header has no
negative bleed; Booking Register row menus portal outside the table scroll
viewport; and Cash Voucher bottom grid cards align. Module-internal cards,
forms and tables otherwise remain unchanged.

Sales Invoice focused workspace, Day-One/reset logic, booking and accounting
business behavior, public vouchers and print/PDF layouts remain unchanged.
No migration is required.
