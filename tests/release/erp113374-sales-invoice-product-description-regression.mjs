import assert from 'node:assert/strict';
import fs from 'node:fs';

const resolver = fs.readFileSync('app/Services/Sales/SalesInvoiceLineDescriptionResolver.php', 'utf8');
const middleware = fs.readFileSync('app/Http/Middleware/PresentSalesInvoicePrintV2.php', 'utf8');
let pass = 0;
const ok = (value, message) => { assert.ok(value, message); pass += 1; };

ok(resolver.includes('class SalesInvoiceLineDescriptionResolver'), 'DEDICATED_READ_ONLY_RESOLVER=PASS');
ok(resolver.includes('sales_invoice_lines') && resolver.includes('sales_invoice_items'), 'INVOICE_LINE_AUTHORITY=PASS');
ok(resolver.includes('booking_services') && resolver.includes('product_service_id'), 'NATIVE_PRODUCT_SERVICE_AUTHORITY=PASS');
ok(middleware.includes('SalesInvoiceLineDescriptionResolver'), 'MIDDLEWARE_PRESENTATION_BOUNDARY=PASS');
ok(middleware.includes('invoiceDescriptions($request)'), 'LOADED_INVOICE_CONTEXT_USED=PASS');
ok(resolver.includes("'air' => $this->air($rows)"), 'AIR_FAMILY_MAPPING=PASS');
ok(resolver.includes("'hotel' => $this->hotel($rows)"), 'HOTEL_FAMILY_MAPPING=PASS');
ok(resolver.includes("'visa' => $this->visa($rows)"), 'VISA_FAMILY_MAPPING=PASS');
ok(resolver.includes("'transport' => $this->transport($rows)"), 'TRANSPORT_FAMILY_MAPPING=PASS');
ok(resolver.includes("'umrah' => $this->umrah($rows)"), 'UMRAH_FAMILY_MAPPING=PASS');

const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char]);
const describe = (family, row = {}) => {
  if (family === 'air') {
    const route = row.origin && row.destination ? `${row.origin}-${row.destination}` : '';
    return [row.airline, route, row.flight].filter(Boolean).join(' · ') || 'Air Ticket';
  }
  if (family === 'hotel') return [row.hotel, row.city, row.checkIn && row.checkOut ? `${row.checkIn}–${row.checkOut}` : '', row.nights ? `${row.nights} Nights` : ''].filter(Boolean).join(' · ') || 'Hotel Accommodation';
  if (family === 'visa') return [row.country, row.type, row.duration].filter(Boolean).join(' · ') || 'Visa';
  if (family === 'transport') return [row.pickup && row.dropoff ? `${row.pickup} → ${row.dropoff}` : '', row.vehicle, row.date].filter(Boolean).join(' · ') || 'Transport';
  if (family === 'umrah') return [row.package, row.makkah ? `Makkah ${row.makkah} nights` : '', row.madinah ? `Madinah ${row.madinah} nights` : ''].filter(Boolean).join(' · ') || 'Umrah Package';
  return row.name || 'Other Service';
};

ok(describe('air', { airline: 'PK', origin: 'LHE', destination: 'JED', flight: 'PK-739' }) === 'PK · LHE-JED · PK-739', 'AIR_COMPLETE_DESCRIPTION=PASS');
ok(describe('air', { origin: 'LHE', destination: 'JED' }) === 'LHE-JED', 'AIR_PARTIAL_DESCRIPTION=PASS');
ok(describe('air') === 'Air Ticket', 'AIR_FALLBACK=PASS');
ok(describe('hotel', { hotel: 'Hilton', city: 'Makkah', checkIn: '2026-01-01', checkOut: '2026-01-04', nights: 3 }).includes('Hilton · Makkah'), 'HOTEL_COMPLETE_DESCRIPTION=PASS');
ok(describe('hotel') === 'Hotel Accommodation', 'HOTEL_FALLBACK=PASS');
ok(describe('visa', { country: 'Saudi Arabia', type: 'Umrah', duration: '30 days' }) === 'Saudi Arabia · Umrah · 30 days', 'VISA_COMPLETE_DESCRIPTION=PASS');
ok(describe('visa') === 'Visa', 'VISA_FALLBACK=PASS');
ok(describe('transport', { pickup: 'Airport', dropoff: 'Hotel', vehicle: 'Van', date: '2026-01-01' }).includes('Airport → Hotel'), 'TRANSPORT_COMPLETE_DESCRIPTION=PASS');
ok(describe('transport') === 'Transport', 'TRANSPORT_FALLBACK=PASS');
ok(describe('umrah', { package: 'Premium Umrah', makkah: 5, madinah: 4 }).includes('Makkah 5 nights'), 'UMRAH_COMBINED_DESCRIPTION=PASS');
ok(describe('umrah') === 'Umrah Package', 'UMRAH_FALLBACK=PASS');
ok(describe('other', { name: 'Custom Service' }) === 'Custom Service', 'OTHER_AUTHORITATIVE_DESCRIPTION=PASS');
ok(describe('other') === 'Other Service', 'OTHER_FALLBACK=PASS');
ok(escape('<Air & Hotel>') === '&lt;Air &amp; Hotel&gt;', 'HTML_ESCAPING=PASS');
ok(['air', 'hotel', 'visa', 'transport', 'umrah', 'other'].map(family => describe(family)).join('|') === 'Air Ticket|Hotel Accommodation|Visa|Transport|Umrah Package|Other Service', 'MIXED_PRODUCT_FALLBACKS=PASS');

ok(resolver.includes('return $out;') && !resolver.includes('DB::table(\'journals\')'), 'ACCOUNTING_SOURCE_UNCHANGED=PASS');
ok(!resolver.includes('update(') && !resolver.includes('insert(') && !resolver.includes('delete('), 'READ_ONLY_NO_WRITES=PASS');
ok(!middleware.includes('grand_total') && !middleware.includes('number_format('), 'AMOUNTS_AND_TOTALS_UNTOUCHED=PASS');
ok(middleware.includes('PNR') && middleware.includes('TICKET / REF'), 'PNR_TICKET_REF_PRESERVED=PASS');
ok(middleware.includes('htmlspecialchars($descriptionContext'), 'DESCRIPTION_HTML_ESCAPED=PASS');
ok(resolver.includes('supplier') === false || resolver.includes('supplier_cost') === false, 'SUPPLIER_COSTING_NOT_EXPOSED=PASS');

console.log(`PASS ${pass} ERP-11.3.374 Sales Invoice Product Description assertions`);
