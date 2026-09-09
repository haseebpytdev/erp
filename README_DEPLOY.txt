ERP-11.3.208 DIRECT UPLOAD

Audited cumulative overlay based on deployed ERP-11.3.151.

Deployment without SSH:
1. Upload/extract this ZIP over the current ERP application.
2. Open System Health & Updates.
3. Run Safe Database Upgrade only when pending migrations are reported.
4. Click Clear Application Cache.
5. Ctrl+F5.

ERP-11.3.208 introduces no new migration. Safe Database Upgrade remains required
if the cumulative Air Vendor, public voucher token or Travel Status migrations
included in this package are still pending on the host.

ERP-11.3.208 adds read-only Sales Invoice product profitability visibility.
Invoice sale amounts come from the native Sales Invoice snapshot. Product cost
sources remain:

- Air: air_ticket_details.net_supplier_cost
- Hotel: persisted Hotel vendor_total, with persisted cost_rate * nights fallback
- Transport: persisted PKR cost_amount
- Visa: booking_visa_services.vendor_cost_pkr

Missing product cost remains visibly incomplete and is not silently treated as
zero. Product costs do not change the invoice grand total, customer receivable,
revenue journal, posting, workflow or booking data.

After deployment, first open Sales Invoice SI-2026-000014 and review the visual
layout before using workflow actions. Confirm the top Invoice Total is PKR
931,200, Products is 4, and Product Commercial Summary shows sale, cost and
margin for Air, Hotel, Transport and Visa. These values are UAT references only;
the implementation does not hard-code them.

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
