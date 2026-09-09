ERP-11.3.212 DIRECT UPLOAD — AIR INVOICE LINE NUMBER HOTFIX

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.212 introduces no new migration. Safe Database Upgrade remains required
if the cumulative Air Vendor, public voucher token or Travel Status migrations
included in this package are still pending on the host.

ERP-11.3.212 fixes grouped Air invoice line numbering during Sales Invoice
Submit for Approval. Existing persisted Air line numbers are preserved. Any
newly-created grouped Air line receives an invoice-wide unique line number
based on all currently occupied native invoice line numbers. Hotel, Transport,
Visa and other non-Air invoice line numbers are not renumbered.

Air ordering remains deterministic and repeated synchronization remains
idempotent. Transaction rollback and the ERP-11.3.211 authoritative currency
synchronization correction remain preserved. There are no accounting,
profitability, SalesInvoiceService, workflow or booking-data changes. No
migration is added.

After deployment, clear Application Cache, press Ctrl+F5, and open
https://erp.easyticket.pk/sales/invoices/7. Before workflow, confirm Invoice
Total PKR 931,200, Total Cost PKR 938,600, Gross Margin PKR -7,400 and Products
4. Then click Submit for Approval once. It must complete without a duplicate
line_no or currency_code SQL error, preserve the existing Adult Air line number,
allocate a safe invoice-wide line number to Child Air, leave non-Air line
numbers unchanged, preserve Air PKR 718,000, Hotel PKR 8,000, Transport PKR 200,
Visa PKR 205,000 and invoice PKR 931,200, create no duplicate Air lines, and
transition Draft to Pending Approval. If synchronization fails, the invoice
must remain Draft and no partial grouped Air row or line-number mutation may
persist.

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
