ERP-11.3.241 DIRECT UPLOAD - SIDEBAR AND GLOBAL UI STABILIZATION

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Do not run Safe Database Upgrade; this release has no migration.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.241 stabilizes the sidebar and shared ERP component system. Native
permission-rendered sidebar row order is preserved and the previous duplicate
browser regrouping/reordering passes are removed. One hidden prepaint finalizer
adds presentation section headings in place, separates root and nested menu
levels, preserves native active-state authority and uses an exact path/query
fallback only when necessary. Root rows, nested rows and section headings now
have independent geometry, the brand/logo area is more compact, and active
nested children no longer make their parent appear as a second selected page.
Shared page-title, form-control, card and tab tokens are also normalized across
modules. ERP-11.3.240 shell geometry, ERP-11.3.239 server-rendered registers and
all booking/accounting/business authorities remain unchanged. No migration is
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
