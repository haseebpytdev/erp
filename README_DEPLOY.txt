ERP-11.3.218 DIRECT UPLOAD — COMPLETE VOUCHER MODULE

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.218 includes the new migration
database/migrations/2026_09_10_130000_create_cash_voucher_contra_details.php,
which creates the one-to-one Contra destination and transfer-detail authority.
Run Safe Database Upgrade after manual deployment and confirm the migration
completes before Contra Voucher UAT.

ERP-11.3.218 completes the controlled Voucher accounting module. It adds Contra
Voucher for Cash-to-Bank, Bank-to-Cash and Bank-to-Bank transfers using CV
year/sequence numbering. Contra uses the existing Draft to Pending Approval to
Approved to Posted lifecycle, posts a debit to the destination Cash/Bank account
and an equal credit to the source Cash/Bank account through the native journal
bridge, and supports controlled equal-and-opposite reversal.

Contra adds dedicated permissions, navigation, register and print support. It
does not create customer, supplier, expense, revenue, receivable, payable,
Sales Invoice or Supplier Costing allocations. Voucher detail pages now link
resolvable accounts to the native Account Ledger and posting references to the
actual native Journal Entry, with plain text when a safe link cannot resolve.
Cash Voucher release indicators are dynamic. The existing native manual Journal
Entry remains the authority for arbitrary balanced non-cash journals, so no
duplicate Journal Voucher is added.

After manual deployment, run Safe Database Upgrade, clear Application Cache and
Ctrl+F5. Open Accounting > Contra Vouchers > New Contra Voucher. Choose two
different real Cash/Bank posting accounts, enter PKR 10,000 at exchange rate 1,
and save a Draft only. Confirm the preview debits the destination and credits the
source for PKR 10,000. Do not submit, approve or post before reviewing the Draft.

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
