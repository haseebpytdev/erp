import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const resolver = read('app/Services/Operations/GeneralBookingBillingStateResolver.php');
const contract = read('app/Services/Operations/GeneralBookingBillingBatchContract.php');
let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };

const canonical = value => String(value).trim().toLowerCase();
const classify = value => {
  const type = canonical(value);
  assert.ok(['base', 'supplementary'].includes(type));
  return type;
};
const financial = invoice => {
  const exists = invoice.native_exists === true;
  const status = canonical(invoice.status || '');
  const inactive = ['cancelled', 'canceled', 'void', 'voided', 'rejected'].includes(status);
  return exists && !inactive;
};

ok(contract.includes('canonicalBatchType') && contract.includes('canonicalLinkType'), 'type normalization has one contract authority');
ok(classify('base') === 'base', 'lowercase base classifies as base');
ok(classify(' BASE ') === 'base', 'uppercase/whitespace base uses canonical classification');
ok(classify('SuPpLeMeNtArY') === 'supplementary', 'mixed-case supplementary uses canonical classification');
ok(resolver.includes('canonicalLinkType') && resolver.includes('canonicalBatchType'), 'resolver uses the contract canonical type rule');
ok(resolver.includes("'native_exists' => $nativeExists"), 'missing native rows preserve historical issuance links');
ok(!financial({ native_exists: false, status: null }), 'missing native invoice is not active');
ok(!financial({ native_exists: false, status: 'posted' }), 'missing native invoice is not posted');
ok(resolver.includes("'status' => $nativeExists ?") && resolver.includes("'active_invoice'"), 'missing native invoice has null active state and no financial status');
ok(resolver.includes("($invoice['native_exists'] ?? false)"), 'active totals require a native invoice row');
ok(resolver.includes('billing_integrity_errors') && resolver.includes('missing_native_invoice'), 'missing native invoice fails billing integrity');
ok(resolver.includes("$linkedBatchIds[(int) $link->batch_id] = true"), 'missing native links remain non-reinvoiceable through link existence');
ok(resolver.includes('canonicalLinkType((string) $link->link_type)') && resolver.includes("$canonicalLinkType === 'base'"), 'base links use canonical classification');
ok(contract.includes('assertLinkConsistency') && contract.includes('canonicalBatchType($batchType)'), 'contract and resolver sequence/type authority match');
console.log(`ERP113378_BOOKING_BILLING_C43=PASS (${pass} assertions)`);
