ERP-11.3.237 DIRECT UPLOAD - BOOKING REGISTER REFERENCE MATCH

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Do not run Safe Database Upgrade; this release has no migration.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.237 rebuilds the Booking Register presentation to match the approved
professional reference. The page now includes Total Bookings, Pending
Confirmation, Confirmed and Cancelled KPI cards, quick filters, full Search /
Customer / Travel Type / Travel Date / Status filtering, a dynamic register
count, safe client-side CSV export, row selection and action menus, 15-row
pagination, Quick Workflow, Bookings by Type and Recent Activity. Existing
authoritative Booking rows and links remain unchanged as the source of truth.
ERP-11.3.236 shared Sales Invoice and Supplier Costing register styling,
ERP-11.3.235 voucher styling and ERP-11.3.234 sidebar stabilization remain
unchanged.

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
