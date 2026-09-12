ERP-11.3.247 DIRECT UPLOAD - DAY-ONE NUMBERING FROM 1000

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Do not run Safe Database Upgrade; this release has no migration.
4. Click Clear Application Cache.
5. Ctrl+F5.
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
