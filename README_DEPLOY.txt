ERP-11.3.210 DIRECT UPLOAD — SALES INVOICE BLANK-PAGE HOTFIX

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.210 introduces no new migration. Safe Database Upgrade remains required
if the cumulative Air Vendor, public voucher token or Travel Status migrations
included in this package are still pending on the host.

ERP-11.3.210 fixes the Sales Invoice blank-page regression caused by the `.209`
legacy Accounting card cleanup. The cleanup is now safely bounded to the
compact top Accounting / Not Posted card and cannot hide the shared page
container, Review card, lower Accounting Preview or document body.

There are no profitability calculation, accounting logic, SalesInvoiceService,
workflow or booking/invoice data changes. No migration is added.

After deployment, clear Application Cache, press Ctrl+F5, and open
https://erp.easyticket.pk/sales/invoices/7 before using any workflow action.
Confirm the page is not blank; the legacy top Accounting / Not Posted card is
absent; the five summary cards, Product Commercial Summary, Air Ticket
Commercial Lines, Passenger / Ticket Summary, Accounting Preview (Journal
Lines), Workflow and Activity are visible; and invoice totals, costs and margins
remain unchanged.

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
