ERP-11.3.206 DIRECT UPLOAD — DIAGNOSTIC

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.206 introduces no new migration. Safe Database Upgrade remains required
if the cumulative Air Vendor, public voucher token or Travel Status migrations
included in this package are still pending on the host.

During the guarded invoice transaction, Air service 17 must reconcile from its
five native ticket passenger IDs, while Hotel service 18 and Transport service
20 must reconcile from the exact active booking-passenger set. Visa must be
materialized from its native Product/Service master and authoritative child rows,
with customer total PKR 205,000 and exact passenger IDs 20,21,22,23,24. Its
native row must persist quantity, unit_price and line_total so that quantity
times unit_price equals PKR 205,000, plus description, PKR currency and only an
unambiguous child-derived Vendor ID. All four services must be complete before
the unchanged host invoice creator runs.

After deployment, do not click Create Sales Invoice and do not reopen or resave
the booking. As Super Admin, first open:
https://erp.easyticket.pk/system/diagnostics/air-link-db/13

Capture PRE_NATIVE_SERVICE_COMMERCIAL_AUDIT and
HOST_SALES_INVOICE_METHOD_SOURCE. The pre-native audit must always roll back its
temporary service/passenger reconciliation and must never invoke the invoice
creator. Existing Sales Invoice validation and accounting behavior remain
authoritative and unchanged.

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
