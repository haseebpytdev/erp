ERP-11.3.168 DIRECT UPLOAD

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.168 introduces no new migration. Safe Database Upgrade remains required
if the cumulative Air Vendor, public voucher token or Travel Status migrations
included in this package are still pending on the host.

Sales Invoice creation is enabled only when the production host provides the
native SalesInvoiceService::createFromBooking contract. The first controlled
runtime test must use BK-2026-000054 and confirm one Draft invoice for PKR
931,200. Any linkage, status, line or total mismatch must roll back.

Before that controlled invoice test, confirm BK-2026-000054 shows the same
native Customer / Party on Booking Review as the main Booking page. For this
previously-approved booking, Reopen, make no changes, Send to Approval, and
Approve once so the native host confirmation state is persisted. Verify the
Visa block has its two-level passenger/detail layout, fitted desktop headers,
balanced totals and read-only Approved/Ready presentation.

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
