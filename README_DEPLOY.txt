ERP-11.3.221 DIRECT UPLOAD — PROFIT & LOSS RENDER HOTFIX

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.221 fixes the Profit & Loss report HTTP 500 introduced in ERP-11.3.220.
Unsafe inline multi-statement Blade `@php(...)` directives were replaced with
valid block `@php` / `@endphp` syntax. Current Period, Previous Period, Variance
and Variance % values retain the same read-only posted-journal calculations.

No accounting formula, posted-journal authority, Management Overview, Balance
Sheet, native Trial Balance, ledger, print or workflow behavior changes. This
release introduces no migration and performs no accounting mutation.

After manual deployment, clear Application Cache and Ctrl+F5. Open
/accounting/reports/profit-and-loss?from=2026-09-01&to=2026-09-11 and confirm
HTTP 200 plus Revenue, Direct Cost, Gross Profit, Operating Expenses, Operating
Profit, Other Income, Other Expense and Net Profit / Loss. Then confirm
Management Overview loads, Balance Sheet remains balanced and native Trial
Balance remains balanced. Do not post or mutate accounting records during UAT.

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
