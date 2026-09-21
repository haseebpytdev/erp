ERP-11.3.329 Air Itinerary Type Width Correction

Active release: v1.1.33.329-ERP11.3.329

ERP-11.3.329 corrects the Flight Itinerary Type control width discovered during
ERP-11.3.328 production visual UAT. The scoped desktop Type column is widened
from 82px to 108px so Outbound, Return and Connection remain fully readable
while existing itinerary row geometry, the contained Remove action and
responsive breakpoints are preserved. Air JavaScript, Ticket Group ownership,
segment persistence, commercial formulas, draft/save lifecycle, locking and
Travel Readiness remain unchanged. ERP-11.3.329 introduces no new database
migration.

NEW_MIGRATION_REQUIRED=NO
Existing booking_service_id migration remains part of cumulative source;
current live .328 already has it applied. Before .329 Air UAT,
confirm System Health reports the database schema is up to date. Take a fresh
database backup before deployment, but do not treat this as a new migration
requirement or manually modify the database.

Deployment order:
1. Stop Air multi-ticket-group data entry during deployment.
2. Take/confirm a fresh database backup before deployment.
3. Upload/extract the authoritative ERP-11.3.329 ZIP through cPanel over the existing ERP application.
4. Open System Health & Updates and confirm the database schema is up to date; no new .329 migration is required.
5. Verify booking_itinerary_segments has nullable indexed booking_service_id ownership through ERP migration/health evidence.
6. If the schema is not current, HOLD deployment and resolve through the established migration process; do not improvise manual database edits.
7. Clear Application Cache, perform Ctrl+F5 / hard refresh, then run focused production UAT.

Focused ERP-11.3.329 production UAT (not yet production-verified):
- Use a safe Draft GENERAL Air booking.
- System Health shows v1.1.33.329-ERP11.3.329, database Connected and schema up to date.
- Flight Itinerary Type displays Connection, Outbound and Return fully; Remove remains contained without overlapping Airline and there is no page-level horizontal overflow.
- The .328 Ticket Group sequence remains Booking Data -> PNR Fare Commercials -> Passenger Tickets -> PNR totals; Applies To Flight Segments, commercial scrollbar absence and zero-group bootstrap remain unchanged.
- Air page: verify one booking-level Flight Itinerary, all saved itinerary segments visible, and Add Flight Segment / Remove Flight Segment work in Draft.
- Flight Itinerary uses balanced field widths; Remove remains fully inside the segment row without overlapping Arrival or leaving the action cell.
- Applies To Flight Segments uses compact readable assignment controls; route and flight-number labels are readable.
- Segment selection remains exclusive: each segment belongs to exactly one Ticket Group.
- Booking / Ticket Data is full width; PNR Fare Commercials is full width immediately after it, with no normal desktop/laptop internal horizontal scrollbar and all commercial columns readable without overlap.
- Passenger Tickets is full width below PNR Fare Commercials; PNR Customer Total / PNR Vendor Total / Gross Margin remain below both tables.
- There is no page-level horizontal overflow; zero-group bootstrap opens the Ticket Group workspace; save/reload and multi-group persistence remain unchanged.
- Multi-ticket groups: verify at least two Ticket Groups can exist, each with an independent Vendor / Supplier, independent PNR, independent Airline PNR / GDS Source where applicable, independent segment assignment, passenger ticket numbers and PNR Fare Commercials.
- Segment ownership: each itinerary segment belongs to exactly one Ticket Group; duplicate ownership is prevented; a group with zero assigned segments or an unowned submitted segment cannot save.
- Commercials: aggregate Air Customer Total and Vendor Total include all groups; Gross Margin is correct; Customer Minus and Vendor Minus remain based on Basic Rate; V O Cost remains one fare-row total after passenger multiplication; commercial formulas are unchanged.
- Save/reload: final page Save succeeds; refresh/reload preserves every group, itinerary ownership and persisted native service IDs; a second Save does not create duplicate Ticket Groups.
- KPI acceptance example only: 7 unique passengers × 2 fully ticketed groups = Passenger KPI remains 7 and Air Ticket KPI becomes 14. Do not manufacture or alter live records solely to create this example.
- Locking: Pending Approval, Approved and Travel Ready are read-only; itinerary controls, Add/Remove Segment, Add/Duplicate/Delete Group, group common/ticket/commercial controls and final Save are locked.
- Travel readiness: every Ticket Group participates in readiness; missing group segment, missing group PNR or missing/unissued required passenger ticket blocks readiness.
- Legacy single-group compatibility: an existing single-group Air booking still opens, saved values remain correct and single-group Save still works (legacy single-group path verified).
- Network/navigation: confirm the Air fragment request, no full Air document on the successful path, one Air product API GET, no general-progressive-step1.js/.css, normal Air URL, modified clicks, native Hotel/Transport/Visa links, fallback/deep-link behavior, dirty/draft confirmation, save-in-flight guard and browser back/forward behavior.

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Confirm the database schema is up to date; ERP-11.3.328 introduces no new migration.
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
