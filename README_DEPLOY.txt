ERP-11.3.215 DIRECT UPLOAD — CUSTOMER LEDGER 503 PHASE 2 DIAGNOSTIC

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.215 introduces no new migration. Safe Database Upgrade remains required
if the cumulative Air Vendor, public voucher token or Travel Status migrations
included in this package are still pending on the host.

ERP-11.3.215 extends the temporary Super-Admin-only rollback/read-only runtime
diagnostic for the native Customer Ledger HTTP 503. Phase 2 derives the actual
route/model binding, renders the native Customer Ledger View separately,
resolves and inspects each middleware authority, checks journals.view, and runs
progressive safe middleware probes to identify the first failing production
HTTP stage. Middleware that may perform an irreversible write is reported but
is not duplicated by the diagnostic. Credential-like exception content is
redacted; environment, cookie, session and database-credential values are not
exposed.

This diagnostic release does not fix or alter the Customer Ledger. It does not
change Sales Invoice posting, journal accounting, Customer Receivables, ledger
balances, customer accounting data or mappings. No migration is added.

After deployment, clear Application Cache and open as Super Admin:
https://erp.easyticket.pk/system/erp-diagnostics/customer-ledger/9?invoice=7
Capture the complete JSON result for engineering. Do not retry, reverse or
repost SI-2026-000014 or JV-2026-000013. The diagnostic request must not create,
update or delete accounting data.

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
