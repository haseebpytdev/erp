ERP-11.3.220 DIRECT UPLOAD — MANAGEMENT ACCOUNTING AND PROFIT REPORTING

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.220 adds read-only Management Overview, Profit & Loss and Balance Sheet
reporting over the installed native posted journals. It preserves the native
Trial Balance, General Ledger, Customer Ledger, Vendor Ledger and Report & Print
Center as accounting authorities and adds safe navigation and Account Ledger
drill-downs.

Management Overview provides Today, Yesterday, month-to-date, previous-month
and fiscal-year-to-date posted profit. Revenue, direct supplier cost, gross
profit, operating expenses and net profit remain separate from Cash/Bank inflow
and outflow. Cash/Bank balances, Customer Receivables, Vendor Payables, Customer
Advances and Supplier Advances are shown without netting control accounts.
Product profitability comes from posted Revenue and Direct Cost journals, not
booking commercial snapshots.

This release introduces no new migration and performs no accounting mutation.
After manual deployment, clear Application Cache and Ctrl+F5. Browser-test
Management Overview for Today and MTD, Profit & Loss for the current month,
native Trial Balance for the same period, and Balance Sheet as of today. Confirm
Account Ledger drill-downs for 1130 Customer Receivables, 2110 Vendor Payables,
one active Bank account, one Revenue account and one Direct Cost account.

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
