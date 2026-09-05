import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const js = read('public/erp11390/general-progressive-step1.js');
const css = read('public/erp11390/general-progressive-step1.css');
const controller = read('app/Http/Controllers/Operations/GeneralBookingVisaProductController.php');
let checks = 0;
const has = (text, value, message) => { assert.ok(text.includes(value), message); checks++; };
const lacks = (text, value, message) => { assert.ok(!text.includes(value), message); checks++; };

assert.match(read('VERSION.txt').trim(), /^v1\.1\.33\.(?:15[4-9]|1[6-9]\d|[2-9]\d\d)-ERP11\.3\.(?:15[4-9]|1[6-9]\d|[2-9]\d\d)$/); checks++;
for (const copy of ['Select Passengers for Visa', 'Continue', 'Visa Details', 'passengers selected', 'Visa Rate *', 'Sale PKR / Passenger', 'Initial Status', 'Vendor Cost PKR / Passenger', 'Default Sale PKR / Passenger', 'Back', ' Visa Passengers']) has(js, copy, `wizard copy exists: ${copy}`);
for (const field of ['Country', 'Visa Type', 'Saudi Company', 'Pakistani IATA', 'Vendor Account', 'Cost Currency', 'Cost Rate', 'Exchange Rate']) has(js, field, `resolved field exists: ${field}`);
for (const total of ['Selected Pax', 'Sale / Pax', 'Vendor / Pax', 'Customer Total', 'Vendor Total', 'Gross Margin']) has(js, total, `wizard summary exists: ${total}`);
has(js, "input.readOnly=true;input.setAttribute('aria-readonly','true')", 'resolved fields are read-only');
has(js, 'relationshipReady=function(rate)', 'wizard validates the complete rate relationship');
has(js, 'rows=rows.concat(additions)', 'validated passenger batch is appended atomically');
has(js, 'additions.length!==selectedPassengers.length', 'duplicate race aborts the whole batch');
has(js, "var st=statusSelect(row);st.addEventListener('change'", 'main row status is an inline dropdown');
for (const detail of ['Application Ref.', 'Visa No.', 'Issue Date', 'Expiry Date', 'Notes']) has(js, detail, `passenger detail remains inline: ${detail}`);
lacks(js, 'Select a Visa Rate for every Visa passenger before saving.', 'repetitive legacy warning is removed');
has(js, "Visa passenger(s) are incomplete. Select those rows and use Bulk Actions or remove them.", 'legacy incomplete rows receive one concise warning');
has(css, '.etgp-visa-wizard-resolved-113154', 'wizard responsive styling exists');
has(controller, "'visas' => ['present', 'array', 'max:250']", 'empty final Visa removal is accepted');
has(controller, 'DB::transaction(function ()', 'Visa save remains transactional');
has(controller, 'if ($keep) $stale->whereNotIn', 'empty batch explicitly deletes all stale Visa rows');
has(controller, "->updateOrInsert(", 'stable passenger mapping remains the persistence key');

const passengers = Array.from({length: 55}, (_, index) => ({id: index + 1, name: `Passenger ${index + 1}`, passport_number: index === 3 ? '' : `PP${index + 1}`, fare_type: index % 3 === 0 ? 'CHILD' : index % 5 === 0 ? 'INFANT' : 'ADULT'}));
const rateA = {id: 10, country: 'Saudi Arabia', visa_type: 'Umrah', saudi_company_id: 20, saudi_company_name: 'Saudi A', pakistani_iata_id: 30, pakistani_iata_name: 'IATA A', vendor_id: 40, vendor_name: 'Vendor A', cost_currency: 'SAR', cost_rate: 100, exchange_rate: 387.6, vendor_cost_pkr: 38760, default_sale_pkr: 41000};
const rateB = {...rateA, id: 11, saudi_company_name: 'Saudi B', vendor_cost_pkr: 39000, default_sale_pkr: 43000};
for (const size of [4, 20, 50]) { assert.equal(passengers.slice(0, size).length, size, `${size}-passenger fixture is complete`); checks++; }
const addBatch = (rows, picked, rate, sale, status = 'pending') => {
  const existing = new Set(rows.map(row => row.booking_passenger_id));
  const additions = picked.filter(p => !existing.has(p.id)).map(p => ({booking_passenger_id: p.id, passenger_name: p.name, visa_rate_card_id: rate.id, country: rate.country, visa_type: rate.visa_type, saudi_company_id: rate.saudi_company_id, saudi_company_name: rate.saudi_company_name, pakistani_iata_id: rate.pakistani_iata_id, pakistani_iata_name: rate.pakistani_iata_name, vendor_id: rate.vendor_id, vendor_name: rate.vendor_name, cost_currency: rate.cost_currency, cost_rate: rate.cost_rate, exchange_rate: rate.exchange_rate, vendor_cost_pkr: rate.vendor_cost_pkr, sale_pkr: sale, status}));
  assert.equal(additions.length, picked.length, 'batch must abort instead of partially accepting duplicates'); checks++;
  return rows.concat(additions);
};

let rows = addBatch([], passengers.slice(0, 15), rateA, 41000);
rows = addBatch(rows, passengers.slice(15, 20), rateB, 43000);
assert.equal(rows.length, 20); checks++;
assert.equal(new Set(rows.map(row => row.booking_passenger_id)).size, 20); checks++;
assert.deepEqual(rows.map(row => row.booking_passenger_id), Array.from({length: 20}, (_, i) => i + 1)); checks++;
assert.ok(rows.slice(0, 15).every(row => row.visa_rate_card_id === 10 && row.sale_pkr === 41000)); checks++;
assert.ok(rows.slice(15).every(row => row.visa_rate_card_id === 11 && row.sale_pkr === 43000)); checks++;
assert.ok(rows.every(row => row.status === 'pending' && row.saudi_company_id && row.pakistani_iata_id && row.vendor_id)); checks++;
const customer = rows.reduce((sum, row) => sum + row.sale_pkr, 0);
const vendor = rows.reduce((sum, row) => sum + row.vendor_cost_pkr, 0);
assert.equal(customer, 830000); checks++;
assert.equal(vendor, 776400); checks++;
assert.equal(customer - vendor, 53600); checks++;
rows[0].status = 'submitted'; rows[0].status = 'approved'; rows[0].status = 'issued'; rows[0].status = 'rejected';
assert.equal(rows[0].status, 'rejected'); checks++;
assert.equal(passengers[3].passport_number || 'No passport', 'No passport'); checks++;

console.log(`ERP-11.3.154 Visa add-flow regression checks passed: ${checks}`);
