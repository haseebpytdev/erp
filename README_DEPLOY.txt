ERP-11.3.353 Operating Expense Chart of Accounts

ERP-11.3.353 adds the controlled Operating Expense Chart of Accounts hierarchy
under the reserved 6000 series. It adds non-posting group accounts and posting
child expense ledgers without changing existing accounts, account-code
generation, direct-cost mappings, AR/AP controls or subledger architecture.
ERP-11.3.353 is not deployed.

Active release: v1.1.33.353-ERP11.3.353
CURRENT_LIVE_RELEASE=ERP-11.3.352
NEW_MIGRATION_REQUIRED=YES

HISTORICAL RELEASE NOTES BELOW ARE POINT-IN-TIME RECORDS. THE CURRENT
DEPLOYMENT AUTHORITY IS THE HEADER ABOVE.

ERP-11.3.350 Departure Report and Travel Reports Navigation Corrective

ERP-11.3.350 repairs the Departure Report filter include that caused HTTP 500
and places REPORTS / Travel Reports between ACCOUNTING and SYSTEM without
requiring the release footer to share the navigation parent. Movement Reports
are not synthesized as a sidebar item. ERP-11.3.349 title behavior and
ERP-11.3.348 Arrival/Departure movement rows, date/time separation, one-line
filters and movement tabs remain preserved. At that stage ERP-11.3.350 was not deployed. No
booking, product, authorization, calculation, persistence, database schema or
migration changes are included.

Active release: v1.1.33.350-ERP11.3.350

ERP-11.3.350 was the prior live production release. ERP-11.3.337 was the
restored former baseline. ERP-11.3.338 failed startup at TravelReportService.php line 55 and was rolled back. ERP-11.3.339
failed startup at line 101 and was rolled back. ERP-11.3.340 repaired both
syntax defects, but its PHP 8.5.7 parser preflight was held for an implicit
nullable parameter; .340 was not packaged or deployed. ERP-11.3.341 applies
the explicit nullable correction and passed the server PHP 8.5.7 parser check
for all six files. ERP-11.3.341 was subsequently confirmed live with
System Health PASS, PHP 8.5.7, Database Connected and schema up to date.
ERP-11.3.351 is the current live production release. ERP-11.3.352 is the
finalized release candidate and is not deployed.
CURRENT_LIVE_RELEASE=ERP-11.3.351
CURRENT_DEVELOPMENT_RELEASE=ERP-11.3.352
NEW_MIGRATION_REQUIRED=NO

Deployment remains manual cPanel direct upload after final package
certification. No database migration is required for .348.

ERP-11.3.346 scope includes safe sidebar/title fragment mutation, Reports
section positioning, a professional Travel Reports landing workspace, safe
non-Air dedicated reveals, canonical passport search, and Arrival filter
structure repair. Existing report routes, calculations, authorization,
actions, pagination, shared filter layout and operational-only data remain unchanged.

Deployment order after final certification:
1. Confirm ERP-11.3.341 is currently serving production.
2. Stop relevant ERP editing and take a fresh full database backup.
3. Upload/extract ONE authoritative ERP-11.3.346 ZIP through cPanel.
4. Open System Health & Updates; confirm version v1.1.33.346-ERP11.3.346,
   Database = Connected and schema up to date.
5. Confirm ERP-11.3.346 introduces NO new migration.
6. Clear Application Cache, Ctrl+F5, then run focused Arrival / Movement
   Reports UAT, including sidebar access, native shell geometry, filters,
   Arrival Date and Arrival Time, enrichment, CSV, Print, View, table-only
   scrolling and no financial fields.

ERP-11.3.346 is NOT FINALIZED / NOT PACKAGED; it is not deployed.

ERP-11.3.340 Travel Reports Remaining Startup Parse Hotfix

ERP-11.3.340 was metadata-finalized but was not packaged or deployed. Its
PHP 8.5.7 server preflight was held because of the implicit-nullability
deprecation. It is superseded by ERP-11.3.341 and must not be deployed.

ERP-11.3.339 Travel Reports Startup Parse Hotfix

Active release: v1.1.33.339-ERP11.3.339

At that stage ERP-11.3.337 had been restored after rollback. Its visual UAT was accepted,
but .337 System Health verification was not supplied. ERP-11.3.338 was
deployed but failed Laravel startup with HTTP 500 because of the confirmed
TravelReportService.php ParseError; System Health and Travel Reports UAT were
not completed. ERP-11.3.339 repairs that syntax defect and is NOT deployed.
NEW_MIGRATION_REQUIRED=NO

Deployment order:
1. At that stage, confirm the then-current ERP-11.3.337 baseline is serving production.
2. Stop relevant ERP editing and take a fresh full database backup.
3. Upload/extract ONE authoritative ERP-11.3.339 ZIP through cPanel.
4. Do not run a migration solely for ERP-11.3.339.
5. Load the ERP root/dashboard and confirm the HTTP 500 startup failure is gone.
6. Open System Health & Updates; confirm version v1.1.33.339-ERP11.3.339,
   Database = Connected and schema up to date.
7. Confirm ERP-11.3.339 introduces NO new migration.
8. Clear Application Cache, Ctrl+F5, then run focused Travel Reports UAT.
If startup still returns HTTP 500, HOLD and capture the newest Laravel error.

Focused .339 UAT (not yet completed): first verify root, login/dashboard and
System Health; then run the existing .338 Travel Reports checks for Report
Center, Booking, Passenger, Air, Hotel, Visa, Transport, Group Umrah,
movements, dimensions, CSV, Browser Print, RBAC and no financial fields.
Exercise Group Umrah Makkah/Madinah Check-in/Check-out date filters explicitly.
Group Umrah Profitability and Accounting Reports remain separate.

ERP-11.3.338 Operational Travel Reports

Active release: v1.1.33.338-ERP11.3.338

ERP-11.3.337 was the live predecessor at that stage. Its visual UAT was accepted,
but System Health verification for .337 was not supplied and remains
unverified. ERP-11.3.338 was deployed, failed Laravel startup with HTTP 500
from the TravelReportService.php ParseError, and was rolled back before
System Health or Travel Reports UAT completed.

NEW_MIGRATION_REQUIRED=NO

Deployment order:
1. Stop relevant ERP editing during deployment.
2. Take a fresh full database backup.
3. Upload/extract the ONE authoritative ERP-11.3.338 ZIP through cPanel.
4. Open System Health & Updates.
5. Confirm application version: v1.1.33.338-ERP11.3.338.
6. Confirm Database = Connected.
7. Confirm schema is up to date.
8. Confirm ERP-11.3.338 introduces NO new migration.
9. If health/schema is incorrect, HOLD.
10. Clear Application Cache.
11. Ctrl+F5 / hard refresh.
12. Run focused Travel Reports live UAT.

Focused ERP-11.3.338 production UAT (not yet completed):
- Report Center opens from authorized navigation, shows the complete inventory,
  and contains no financial figures.
- Booking filters, pagination, Product filter and View action work.
- Passenger active-only filtering and Branch/Customer/Passenger/Pax Type/
  Gender/Nationality filters have HTML/CSV consistency.
- Air has one row per Ticket Group with Vendor/Airline/Origin/Destination/
  Sector/Ticket Status/PNR/Ticket No filters and no duplicates.
- Hotel is one row per stay with check-in/check-out filtering; Visa exposes
  passenger, visa, company, IATA, vendor and date filters; Transport is one row
  per segment with operational travel-date filtering.
- Group Umrah is one row per booking with package/company/IATA/status/branch/
  customer/agent/salesperson and child hotel/date filters; display and filter
  values remain consistent.
- Movement reports show one row per Arrival, Departure, Makkah CI/CO and
  Madinah CI/CO event with HTML/CSV parity.
- Customer, Supplier/Vendor, Branch, Agent/Salesperson, Airline and Sector
  dimensions use canonical pagination and filters.
- CSV exports the full filtered result with formula-injection protection;
  dimensions use the materialized implementation.
- Full-width report presentation has internal table scrolling and no page-level
  horizontal overflow.
- Existing ERP RBAC denies unauthorized direct routes according to native
  authority while authorized navigation remains available.
- Group Umrah Profitability and Accounting Reports remain separate; no
  financial values appear in Travel Reports.

ERP-11.3.337 User Management Presentation Corrective

Active release: v1.1.33.337-ERP11.3.337

ERP-11.3.336 is deployed and currently undergoing live UAT; full System Health
and production verification remain pending. ERP-11.3.337 is the next
deployment candidate and has NOT been deployed. It preserves the native ERP
host, Native Roles, Branch Access, direct-route authorization and the existing
RBAC authority while correcting the Manage User presentation contract.

Manage User has a neutral Role Template placeholder, truthful Preset not
applied state and fourteen presets. When direct permission storage is
unavailable, Apply and Reset are disabled while Native Roles and Branches
remain usable. Scoped 14px checkbox presentation is used on Manage User and on
the positively detected native user page. Travel Reports remains a zero-state;
no report permissions are created. No migration is introduced.

The native Accounts workflow, host, vendor and framework remain authoritative.
The presentation adapter only targets exact Roles and Allowed Branches card
parents, requires at least two cards, and never replaces or mutates native
fields, values, checked state, actions or submission. A one-branch page is not
forced into four columns. Existing Users compaction is not claimed because no
safe deterministic target was available. Overlay/detail behavior remains
unchanged.

NEW_MIGRATION_REQUIRED=NO
Existing booking_service_id migration remains part of cumulative source;
At that stage the live .336 baseline already had it applied. Before .337 UAT, confirm System
Health reports the database schema is up to date. Take a fresh database backup
before deployment, but do not treat this as a new migration requirement or
manually modify the database.

Deployment order:
1. Stop relevant Booking/Air editing during deployment.
2. Take a fresh full database backup.
3. Upload/extract the ONE authoritative ERP-11.3.337 ZIP through cPanel.
4. Open System Health & Updates.
5. Confirm displayed application version is v1.1.33.337-ERP11.3.337.
6. Confirm Database = Connected.
7. Confirm schema is up to date; verify booking_itinerary_segments has nullable indexed booking_service_id ownership.
8. Confirm ERP-11.3.337 introduces NO new migration.
9. If health/schema is not correct, HOLD. Do not manually alter production DB.
10. Clear Application Cache.
11. Ctrl+F5 / hard refresh.
12. Run focused ERP-11.3.337 production UAT.

Focused ERP-11.3.337 production UAT (not yet production-verified):

User Management UI:
- Open Manage User and verify the neutral Role Template placeholder, Preset not applied state and fourteen presets.
- Confirm Apply and Reset are disabled when direct permission storage is unavailable; Native Roles and Branches remain usable.
- Verify scoped 14px checkbox presentation, fallback messaging, identity/password controls and responsive layouts without page-level overflow.
- Verify the native Accounts workflow retains its host/vendor ownership, exact Roles and Allowed Branches card targeting, and no unintended four-column layout for one branch.
- Confirm native Create ERP User, values, checked state, actions and submission remain untouched; overlay/detail navigation remains unchanged.
- Confirm Travel Reports remains a zero-state and no report permissions are created.

User Permission Matrix:
- Confirm Role Template, Native Roles, Branch Access and the controlled Custom
  Permissions fallback remain available without changing native persistence.
- Confirm the native page adapter is presentation-only and does not replace
  native create, input, values, checked state, actions or submission.

Historical active-passenger authority:
- Open BK-2026-0023 and verify one current booking passenger is visible.
- Passenger KPI is 1 with Adult 1 / Child 0 / Infant 0 unless stored fare type differs.
- Air Passenger Tickets, Operational Summary passenger_count and Booking Review Total Passengers are all 1.
- Refresh/reopen and verify inactive historical snapshots do not reappear.

History preservation:
- Do not delete Passenger Master records. Retain ISSUED, ticket/document, Issue Date/issued_at, VOID, REFUNDED and CANCELLED evidence.

Current booking regression:
- Verify safe Draft removal, same-page KPI update, Passenger Master re-add with fare change, Pending pre-ticket Air save, ISSUED Issue Date validation, Outbound/Return/Connection defaults, commercial totals and non-Air immutability.

Passenger removal consistency:
- Use a safe Draft GENERAL booking, add one passenger, remove it, and verify the row disappears without manual refresh.
- Passenger KPI and Adult/Child/Infant counts become zero; browser refresh remains consistent.
- With multiple passengers, removing one decrements exactly once; failed DELETE leaves UI unchanged; locked booking blocks removal server-side.

Passenger re-add:
- Add a saved Passenger Master as ADULT, remove it in Draft, immediately select the same Master as CHILD, and verify Add succeeds.
- Master is reused with no duplicate; exactly one active booking passenger exists; passenger table, KPI and Air display agree.
- Old Air ticket rows and generic links are not resurrected.

Pending Air pre-issuance:
- In a safe Draft GENERAL Air booking configure a passenger, segment, Ticket Group, Vendor, PNR, Ticket Status=PENDING, blank Ticket No. and zero commercials.
- Save succeeds without native-link errors, fake air_ticket_details rows or fake generic links; group, Vendor, PNR, ownership and refresh persist; second Save reuses the Air service.

Segment defaults:
- First new segment is Outbound, second Return, third and fourth-plus Connection.
- Type remains editable and the saved Type persists after save/reload.

ISSUED / Issue Date:
- Ticket Status=ISSUED displays Issue Date *, makes the field required, and blocks blank-date save.
- A valid date saves and survives refresh; BOOKED/PENDING still allow blank Issue Date.
- Multi-group validation is group-specific: an ISSUED group missing its own date is rejected against that group.

Issued-history passenger removal safety:
- Removal is blocked for ISSUED, real ticket/document evidence after downgrade, issue-date/issued-at evidence, VOID, REFUNDED, CANCELLED and unknown/incomplete Air status.
- Changing genuine ISSUED history to BOOKED/PENDING cannot bypass the evidence guard.

Commercial reconciliation / non-Air immutability:
- With Air plus Hotel, Transport and Visa where practical, remove one draft-safe Air passenger and verify only affected Air totals recalculate.
- Remaining Air customer/supplier totals, Booking Value, Supplier Cost and Gross Margin remain authoritative; Hotel, Transport, Visa and Other Services remain unchanged.
- Remove the final draft-safe Air passenger: native rows may reach zero, established Air snapshot quantity remains intact, Ticket Group/PNR/Vendor/itinerary/ownership remain, and unrelated totals do not change.

Preserved ERP-11.3.331 Air regression checks:
- Airline Master search/resolution, multi-group ownership, save/reload, second-save service reuse, Booking RBAC, locking/readiness, layout/no horizontal overflow and legacy single-group compatibility.
- Use a safe Draft GENERAL Air booking.
- System Health shows v1.1.33.337-ERP11.3.337, database Connected and schema up to date.
- Flight Itinerary Type displays Connection, Outbound and Return fully; Remove remains contained without overlapping Airline and there is no page-level horizontal overflow.
- Booking Data -> PNR Fare Commercials -> Passenger Tickets -> PNR totals remains separated by the controlled 12px rhythm; Applies To Flight Segments, commercial scrollbar absence and zero-group bootstrap remain unchanged.
- Air page: verify one booking-level Flight Itinerary, all saved itinerary segments visible, and Add Flight Segment / Remove Flight Segment work in Draft.
- Flight Itinerary uses balanced field widths; Type defaults are Outbound, Return, then Connection by current row count; Remove remains fully inside the segment row without overlapping Arrival or leaving the action cell.
- Applies To Flight Segments uses compact readable assignment controls; route and flight-number labels are readable.
- Segment selection remains exclusive: each segment belongs to exactly one Ticket Group.
- Booking / Ticket Data is full width; PNR Fare Commercials is full width immediately after it, with no normal desktop/laptop internal horizontal scrollbar and all commercial columns readable without overlap.
- Passenger Tickets is full width below PNR Fare Commercials; PNR Customer Total / PNR Vendor Total / Gross Margin remain below both tables.
- There is no page-level horizontal overflow; zero-group bootstrap opens the Ticket Group workspace; save/reload, multi-group persistence and delete-then-add Type defaults remain unchanged.
- Multi-ticket groups: verify at least two Ticket Groups can exist, each with an independent Vendor / Supplier, independent PNR, independent Airline PNR / GDS Source where applicable, independent segment assignment, passenger ticket numbers and PNR Fare Commercials.
- Segment ownership: each itinerary segment belongs to exactly one Ticket Group; duplicate ownership is prevented; a group with zero assigned segments or an unowned submitted segment cannot save.
- Commercials: aggregate Air Customer Total and Vendor Total include all groups; Gross Margin is correct; Customer Minus and Vendor Minus remain based on Basic Rate; V O Cost remains one fare-row total after passenger multiplication; commercial formulas are unchanged.
- Save/reload: final page Save succeeds; refresh/reload preserves every group, itinerary ownership and persisted native service IDs; a second Save does not create duplicate Ticket Groups.
- KPI acceptance example only: 7 unique passengers × 2 fully ticketed groups = Passenger KPI remains 7 and Air Ticket KPI becomes 14. Do not manufacture or alter live records solely to create this example.
- Locking: Pending Approval, Approved and Travel Ready are read-only; itinerary controls, Add/Remove Segment, Add/Duplicate/Delete Group, group common/ticket/commercial controls and final Save are locked.
- Travel readiness: every Ticket Group participates in readiness; missing group segment, missing group PNR or missing/unissued required passenger ticket blocks readiness.
- Legacy single-group compatibility: an existing single-group Air booking still opens, saved values remain correct and single-group Save still works (legacy single-group path verified).
- Network/navigation: confirm the Air fragment request, no full Air document on the successful path, one Air product API GET, no general-progressive-step1.js/.css, normal Air URL, modified clicks, native Hotel/Transport/Visa links, fallback/deep-link behavior, dirty/draft confirmation, save-in-flight guard and browser back/forward behavior.
- Airline Master: search Saudia and SV, immediately select Saudia, search Emirates and EK, verify keyboard selection, and confirm arbitrary or invalid Airline text cannot save.
- Persistence: create Outbound Saudia/SV SV739 LHE -> JED and Return Saudia/SV SV738 JED -> LHE; create one Ticket Group, assign both segments, enter Vendor, PNR, passenger ticket numbers and commercials, Final Save, refresh, and verify both segments, types, Airline Master values, flights, routes, group, Vendor, PNR, commercials and assignments remain. Save again and verify NO duplicate Ticket Group/service.
- Booking RBAC: with appropriate Booking permissions verify Booking -> Air -> Hotel -> Transport -> Visa. A staff user without Products & Services Master permission must retain these Booking product workspaces despite /products URLs. A user with only unrelated Travel Masters / Passenger-style permission must not directly GET known Booking Air/Hotel/Transport/Visa product APIs through the generic JSON/AJAX fallback.

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Confirm the database schema is up to date; ERP-11.3.337 introduces no new migration.
4. Click Clear Application Cache.
5. Ctrl+F5.
6. Verify the Dashboard and representative register, accounting and booking
   pages at desktop, laptop, tablet and mobile widths.

ERP-11.3.261 preserves one final external authenticated shell geometry authority:
public/erp-ui/erp-shell-spacing.css.

- Desktop/laptop sidebar: 208px
- Desktop brand region: 64px
- Logo footprint and navigation rows: 36px
- Utility header: 56px
- Desktop main gutter: 24px
- Responsive main gutter: 16px
- Desktop/laptop app-shell first grid track: shared 208px sidebar authority
- Stale 250px native grid track and 42px dead space removed
- Native authenticated `.app-shell > main.main` participates in the shared main gutter authority
- Standard main gutter: 24px desktop / 16px responsive
- Standard native page host duplicate top padding removed
- Accounting bottom rows use 18px outer separation
- Sidebar root/brand/menu spacing and icon treatment are normalized
- Booking Register action popover remains a compact fixed body portal
- System Health page-level wrapper is removed without altering inner cards
- Dashboard KPI accents are contained inside their cards
- System Health inner panels resolve safely without tagging shell hosts
- Sidebar rows use 32px height with 2px vertical margins
- Shell top rhythm remains 18px desktop / 16px responsive
- Booking focus remains one-column

Module cards, forms and tables, Sales Invoice focus, Day-One/reset logic,
booking/accounting business behavior and print/voucher layouts are unchanged.
Do not execute Day-One as part of this visual release deployment.
ERP-11.3.245 is the locked Day-Zero FK RESOLUTION PREVIEW release. It resolves
the structural issues discovered by the ERP-11.3.244 production safety audit.
service_cost_allocations is classified CLEAR rather than PRESERVE. Preserved
airlines and booking_sources keep their master rows, while their
default_vendor_party_id links to CLEAR parties are inspected for schema-proven
nullable neutralization. Exact cyclic FK edges are exposed with runtime
nullability and the effective child-before-parent delete order is recalculated
after only safely breakable nullable edges are previewed.

IMPORTANT: destructive execution remains intentionally disabled in ERP-11.3.245.
The reset button remains locked. This preview does not delete rows, set any live
foreign key to NULL, truncate tables or reset counters. Deploy it only to verify
the final production FK-resolution plan. No migration is required.

Public /voucher/*, print and PDF routes remain excluded from the shared UI
injection. No accounting formula, posted-journal authority, booking workflow,
voucher workflow, ledger, permission or persistence behavior changes. This
release introduces no migration and performs no data mutation.

After manual deployment, clear Application Cache and Ctrl+F5. Verify the
Dashboard shell first, then visually inspect navigation, representative booking,
accounting, ledger, reporting, master-data and system pages. Confirm Profit &
Loss remains HTTP 200, Balance Sheet and Trial Balance remain balanced, and no
records are posted or modified during visual UAT.

First verify booking BK-2026-000054. Internal Client Voucher Preview and its
opaque public /voucher/<token> QR destination must both load. The voucher must use the
saved /organization/company name and report logo, not its fallback mark. With
The real logo must appear once only, preserve its aspect ratio, and remain within
an 80 x 80 pixel maximum box in both Client Preview and Print / Save PDF. With
the selected Pakistan Visa / IATA footer blank, the saved Company Default
Voucher Footer must appear once below Special Instructions and must not overlap
the controlled QR placeholder.

Then use Print / Save PDF and verify the same logo/footer and A4 flow. Visa No.
must remain mapped only by stable passenger ID; no standalone Visa table or
Vendor Account, cost, exchange-rate, margin, or supplier accounting data may
appear.
ERP-11.3.246 is the controlled one-time Day-Zero execution release.

IMPORTANT:
- This release can permanently delete the approved business/UAT data.
- Stop staff activity before execution.
- Do not run Safe Database Upgrade; there is no migration.
- Download a fresh full Day-Zero database backup after deployment.
- Execution requires the exact phrase RESET ERP TO DAY ZERO.
- Super Admin / Owner authorization and acknowledgement are mandatory.
- The live safety plan is rebuilt immediately before mutation.
- Any failed live safety gate prevents execution.
- The reset is one-time only and a permanent completion marker prevents replay.
ERP-11.3.247 is the one-time Day-One numbering release.

IMPORTANT:
- Keep all staff out of transactional data entry until this action completes.
- Day-Zero must already be permanently completed.
- The Day-One action refuses to run if any new business row has been entered.
- The first production business number is 1000, then 1001, 1002 and onward.
- Business document numbers use plain 1000; 001000 is not used.
- Do not run Safe Database Upgrade; this release has no migration.
- Clear Application Cache after deployment.
- Open the Day-One sequence screen from the completed Day-Zero page.
- Confirm every live sequence/identity preview resolves to 1000.
- Execute the one-time action only after the screen reports READY.
- The completion marker permanently prevents a second sequence reset.

ERP-11.3.248 unified ERP shell:

- Manual cPanel deployment only.
- No migration is required.
- Clear Application Cache after deployment.
- Ctrl+F5 after cache clear.
- Validate New Booking first at desktop width.
- Sidebar must remain 224px.
- Brand/logo row must be compact and vertically aligned.
- Utility header must be 56px high.
- Main application content must use equal 24px left/right gutters.
- No extra nested Bootstrap/native outer gutter should remain.
- Sidebar generated headings and active-link appearance are CSS-owned.
- General Progressive Booking and Air focus workspaces use the same shell.
- Module business UI and print/voucher layouts are unchanged.
