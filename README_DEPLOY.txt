ERP-11.3.243 DIRECT UPLOAD - DAY-ZERO CLASSIFICATION PREVIEW

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Do not run Safe Database Upgrade; this release has no migration.
4. Click Clear Application Cache.
5. Ctrl+F5.
ERP-11.3.243 is the locked Day-Zero CLASSIFICATION PREVIEW release. The live
ERP-11.3.242 production inspection identified 31 REVIEW tables; all 31 are now
resolved explicitly into 20 CLEAR, 10 PRESERVE and 1 RESET COUNTER actions.
Existing users, roles, permissions, user-role/branch links, staff and required
organization/accounting/security foundation remain preserved. Approved Day-1
business/UAT data and business-entered operational masters are classified for
clearing, while numbering counters are classified for restart. Future unknown
tables still fail closed to REVIEW.

IMPORTANT: destructive execution remains intentionally disabled in ERP-11.3.243.
The reset button remains locked and the service contains no table deletion,
truncate, counter mutation or destructive database statement. Deploy this
release only to verify that the production plan has zero REVIEW tables before
authorizing any later execution-enabled Day-Zero release. No migration is
required.

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
