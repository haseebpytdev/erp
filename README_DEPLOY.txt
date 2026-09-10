ERP-11.3.217 DIRECT UPLOAD — CASH VOUCHER RELEASE LABEL FIX

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.217 introduces no new migration. The cumulative package still contains
database/migrations/2026_09_10_120000_create_cash_voucher_expense_lines.php,
which belongs to ERP-11.3.216; run Safe Database Upgrade only when it remains
pending on the host.

ERP-11.3.217 fixes the Cash Voucher / Expense Voucher form release indicator so
it uses the current ERP release metadata instead of the stale hard-coded
ERP-11.3.27 label. No accounting logic, layout or workflow changes are included.

After manual deployment, clear Application Cache and Ctrl+F5. Open Accounting >
Expense Vouchers > New Expense Voucher and confirm the top label shows
ACCOUNTING · ERP-11.3.217. Then proceed with the first Expense Voucher UAT.

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
