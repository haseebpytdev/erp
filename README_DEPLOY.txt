ERP-11.3.252 DIRECT UPLOAD - SHELL GRID TRACK AND PAGE RHYTHM

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Do not run Safe Database Upgrade; this release has no migration.
4. Click Clear Application Cache.
5. Ctrl+F5.
6. Verify the Dashboard and representative register, accounting and booking
   pages at desktop, laptop, tablet and mobile widths.

ERP-11.3.252 preserves one final external authenticated shell geometry authority:
public/erp-ui/erp-shell-spacing.css.

- Desktop/laptop sidebar: 208px
- Desktop brand region: 64px
- Logo footprint and navigation rows: 36px
- Utility header: 56px
- Desktop main gutter: 24px
- Responsive main gutter: 16px
- Desktop/laptop app-shell first grid track: shared 208px sidebar authority
- Stale 250px native grid track and 42px dead space removed
- Standard native page host duplicate top padding removed
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
