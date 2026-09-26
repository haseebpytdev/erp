import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const service = fs.readFileSync(path.join(root, 'app/Services/Accounting/PartyStatementService.php'), 'utf8');
const resolver = fs.readFileSync(path.join(root, 'app/Services/Accounting/PartyStatementEnrichmentResolver.php'), 'utf8');
const screen = fs.readFileSync(path.join(root, 'resources/views/accounting/party-statement/index.blade.php'), 'utf8');
const print = fs.readFileSync(path.join(root, 'resources/views/accounting/party-statement/print.blade.php'), 'utf8');
const checks = [
  ['party default resolves earliest posted movement', service.includes('earliestPartyMovement') && service.includes("where('je.status', 'posted')")],
  ['explicit from remains authoritative', service.includes("$rawFrom = $request->query('from')")],
  ['customer account scopes', service.includes("['CUSTOMER_AR', '1130']") && service.includes("['CUSTOMER_ADVANCE', '2120']")],
  ['vendor account scopes', service.includes("['VENDOR_AP', '2110']") && service.includes("['VENDOR_ADVANCE', '1140']")],
  ['posted journal authority', service.includes("where('je.status', 'posted')")],
  ['enrichment is display-only', service.includes('Enrichment is deliberately applied after journal netting')],
  ['cash voucher source', resolver.includes("return ['cash_vouchers']")],
  ['cash voucher reference priority', resolver.includes('transaction_reference') && resolver.includes('instrument_no') && resolver.includes('posting_reference')],
  ['cash voucher product mapping', resolver.includes('RECEIPT') && resolver.includes('PAYMENT') && resolver.includes('ADVANCE')],
  ['advance adjustment source', resolver.includes("return ['advance_adjustments']")],
  ['advance adjustment reference', resolver.includes('adjustment_no') && resolver.includes('target_number')],
  ['itinerary segment source', resolver.includes('booking_itinerary_segments')],
  ['itinerary service ownership', resolver.includes('booking_service_id')],
  ['air adaptive aliases', resolver.includes('from_code') && resolver.includes('to_code') && resolver.includes('record_locator')],
  ['air description includes PNR', resolver.includes("' · PNR '")],
  ['air service reference ticket-only', resolver.includes('if (! empty($row[\'__itinerary\'])) continue')],
  ['ten-column screen header', (screen.match(/<th>/g) || []).length === 10],
  ['screen fixed width table', screen.includes('table-layout:fixed') && screen.includes('max-width:100%')],
  ['screen no horizontal overflow', !screen.includes('overflow-x:auto') && screen.includes('overflow:visible')],
  ['balance right aligned', screen.includes('nth-last-child(-n+3)')],
  ['portrait print', print.includes('A4 portrait') && !print.includes('landscape')],
  ['print ten-column header', (print.match(/<th>/g) || []).length === 10],
  ['no schema or writes', !resolver.includes('insert(') && !resolver.includes('update(') && !service.includes('Schema::create')],
];
for (const [name, ok] of checks) assert.ok(ok, name);
console.log(`360_REGRESSION=PASS (${checks.length} assertions)`);
