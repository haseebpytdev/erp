ERP-11.3.216 DIRECT UPLOAD — EXPENSE VOUCHER

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.216 includes the new migration
database/migrations/2026_09_10_120000_create_cash_voucher_expense_lines.php,
which creates cash_voucher_expense_lines. Run Safe Database Upgrade after
manual deployment and confirm the migration completes before Expense Voucher
UAT.

ERP-11.3.216 introduces Expense Voucher accounting. Expense Vouchers support EV
year/sequence numbering, direct business-expense recording, multiple
Chart-of-Accounts-backed Expense Account lines, optional Payee and Booking
references, a Cash/Bank payment account, currency and exchange rate, payment
method, reference/narration, proof attachment, the Draft to Pending Approval to
Approved to Posted workflow, balanced native journal posting, controlled
reversal, printing, and dedicated navigation and permissions.

Expense Voucher posting debits Expense Accounts and credits the selected
Cash/Bank account. It does not create Supplier Payables, Customer Receivables,
Supplier Costing allocations or Sales Invoice allocations.

After manual deployment, run Safe Database Upgrade, clear Application Cache,
and Ctrl+F5. Open Accounting > Expense Vouchers and create one draft UAT voucher
for PKR 15,000 using a real posting Bank account and Electricity Expense. Verify
the preview debits Electricity Expense and credits the selected Bank for PKR
15,000. Do not submit, approve or post it before review.

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
