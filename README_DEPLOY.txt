ERP-11.3.242 DIRECT UPLOAD - DAY-ZERO RESET PREVIEW

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Do not run Safe Database Upgrade; this release has no migration.
4. Click Clear Application Cache.
5. Ctrl+F5.
ERP-11.3.242 is a Day-Zero / Fresh Production database PREVIEW release. It
restores the accepted ERP-11.3.240 UI implementation after ERP-11.3.241 failed
visual UAT. The existing Super Admin / Owner-only Production Data Reset page now
inspects the live database and classifies every table as CLEAR, RESET COUNTER,
PRESERVE or REVIEW with current row counts. Unknown tables always fail closed to
REVIEW. User accounts, roles, permissions and required ERP/system foundation are
preserved by the plan. A separate full compressed database backup can be
downloaded before any future reset.

IMPORTANT: destructive execution is intentionally disabled in ERP-11.3.242.
The Day-Zero reset button is locked and the service contains no table deletion,
truncate, counter reset or destructive database statement. This deployment is
only for production-schema inspection and backup. Do not attempt a Day-Zero
reset until the table plan has been reviewed and a later execution-enabled
release has been explicitly approved. No migration is required.

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
