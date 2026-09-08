ERP-11.3.202 DIRECT UPLOAD

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.202 introduces no new migration. Safe Database Upgrade remains required
if the cumulative Air Vendor, public voucher token or Travel Status migrations
included in this package are still pending on the host.

Before retrying Sales Invoice creation for BK-2026-000054, do not reopen the
booking and do not save Air again. As Super Admin, first open the read-only
/system/diagnostics/air-link-db/13 endpoint and inspect
ACTIVE_SERVICE_PASSENGER_LINK_AUDIT. Confirm whether every active
REQUIRED/MULTIPLE service has generic passenger links or identify the next
product-specific blocker without fabricating passenger assignments.

The controlled invoice test must confirm one Draft invoice for PKR 931,200.
Any linkage, status, line or total mismatch must roll back. The host-native
SalesInvoiceService validator remains authoritative and unchanged.

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
