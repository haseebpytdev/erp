ERP-11.3.219 DIRECT UPLOAD — BOOKING-DRIVEN SUPPLIER COSTING

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.219 includes the new migration
database/migrations/2026_09_10_140000_create_supplier_costing_source_links.php,
which creates deterministic booking-source traceability for Supplier Costing.
Run Safe Database Upgrade after manual deployment and confirm this migration
completes before Supplier Costing UAT.

ERP-11.3.219 makes Supplier Costing booking-driven and vendor-safe. Selecting a
booking resolves authoritative Air, Hotel, Transport and Visa supplier-cost
obligations. The supplier selector is limited to actual booking vendors, and
the selected supplier receives only its own automatically populated product
source lines. One document therefore creates one supplier payable; a supplier
may carry multiple product types, but different suppliers cannot be mixed.

Booking base cost remains controlled by the persisted booking source. Draft Tax
and Other Charges remain editable. Deterministic source keys prevent duplicate
costing across Draft, Pending Approval, Approved and Posted documents. Before
each workflow transition, source ownership and cost are revalidated. Existing
posting remains debit to the applicable product Cost Account(s) and credit to
Vendor Payable for the same supplier through the native journal bridge. Posted
Supplier Costing documents remain available to the existing Payment Voucher
allocation flow.

After manual deployment, run Safe Database Upgrade, clear Application Cache and
Ctrl+F5. Open Operations > Supplier Costing > New Supplier Cost and select only
BK-2026-000054. Do not select a supplier or save a Draft yet. First capture and
review the Booking Supplier Obligations screen, verifying actual persisted
vendors, source statuses and the expected PKR 938,600 UAT cost reconciliation.

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
