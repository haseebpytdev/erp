ERP-11.3.213 DIRECT UPLOAD — MULTI-PRODUCT AIR INTEGRITY HOTFIX

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.213 introduces no new migration. Safe Database Upgrade remains required
if the cumulative Air Vendor, public voucher token or Travel Status migrations
included in this package are still pending on the host.

ERP-11.3.213 corrects Sales Invoice Air commercial integrity checking for
multi-product invoices. Air integrity compares the authoritative saved-ticket
Air total with native Air invoice lines. Whole-invoice integrity compares the
native Sales Invoice header with the sum of all native invoice lines. A correct
multi-product invoice is therefore not falsely blocked because its complete
invoice total is larger than the Air-only portion.

Authoritative currency inheritance, required native structural-field
validation, invoice-wide unique Air line numbering, deterministic/idempotent
grouped synchronization, transaction rollback and post-sync safeguards remain
preserved. There are no accounting, profitability, SalesInvoiceService,
workflow or booking-data changes. No migration is added.

After deployment, clear Application Cache, press Ctrl+F5, and open
https://erp.easyticket.pk/sales/invoices/7. Before workflow, confirm Invoice
Total PKR 931,200, Total Cost PKR 938,600, Gross Margin PKR -7,400 and Products
4. Then click Submit for Approval once. It must complete without currency,
line_no or false commercial-synchronization errors; preserve Air PKR 718,000,
Hotel PKR 8,000, Transport PKR 200 and Visa PKR 205,000; keep all native lines
and the header at PKR 931,200; create no duplicate Air lines; and transition
Draft to Pending Approval. If a genuine mismatch occurs, the invoice must
remain Draft and no partial mutation may persist.

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
