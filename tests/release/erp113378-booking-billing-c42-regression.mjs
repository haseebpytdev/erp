import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const resolver = read('app/Services/Operations/GeneralBookingBillingStateResolver.php');
const contract = read('app/Services/Operations/GeneralBookingBillingBatchContract.php');
const migration = read('database/migrations/2026_09_30_140000_create_general_booking_billing_foundation.php');
let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };

const inactive = new Set(['cancelled', 'canceled', 'void', 'voided', 'rejected']);
const legacy = (invoices, linked) => {
  if (linked) return { candidate: null, ambiguous: false };
  const active = invoices.filter(invoice => !inactive.has(String(invoice.status).trim().toLowerCase()));
  return { candidate: active.length === 1 ? active[0] : null, ambiguous: active.length > 1 };
};
const activeTotals = invoices => invoices.reduce((result, invoice) => {
  if (!inactive.has(String(invoice.status).trim().toLowerCase())) {
    result.count += 1; result.invoiced += Number(invoice.grand_total || 0);
    if (['posted', 'posted_to_gl', 'final', 'finalized'].includes(String(invoice.status).trim().toLowerCase())) result.posted += Number(invoice.grand_total || 0);
  }
  return result;
}, { count: 0, invoiced: 0, posted: 0 });
const approvedUninvoiced = (status, linked) => String(status).trim().toLowerCase() === 'approved' && !linked;

ok(resolver.includes('legacyAdoptionState($native, $allLinkedInvoices !== [])'), 'legacy detection is delegated to the adoption contract');
ok(contract.includes('if ($hasGeneralInvoiceLinks)'), 'legacy detection stops after adoption');
ok(legacy([{ status: 'posted', grand_total: 100 }], false).candidate?.grand_total === 100, 'one active unlinked native invoice is a legacy candidate');
ok(legacy([{ status: 'posted' }, { status: 'draft' }], false).ambiguous === true, 'multiple active unlinked native invoices are ambiguous');
ok(legacy([{ status: 'cancelled' }], false).candidate === null && legacy([{ status: 'cancelled' }], false).ambiguous === false, 'inactive legacy invoices are not candidates');
ok(legacy([{ status: 'posted' }], true).candidate === null && legacy([{ status: 'posted' }, { status: 'posted' }], true).ambiguous === false, 'linked native invoices never re-enter legacy detection');
ok(approvedUninvoiced('approved', false) && !approvedUninvoiced('approved', true), 'approved-uninvoiced uses link existence');
ok(!approvedUninvoiced('draft', false), 'non-approved batches are not approved-uninvoiced');
ok(approvedUninvoiced('approved', false) && !approvedUninvoiced('approved', true), 'approved no-link is included while every linked status is excluded');
const cancelled = activeTotals([{ status: 'cancelled', grand_total: 210000 }]);
const posted = activeTotals([{ status: 'posted', grand_total: 210000 }]);
ok(cancelled.count === 0 && cancelled.invoiced === 0 && cancelled.posted === 0, 'cancelled links are excluded from active totals');
ok(posted.count === 1 && posted.invoiced === 210000 && posted.posted === 210000, 'posted links contribute to both active totals');
ok(resolver.includes('// Link existence is the issuance/idempotency authority'), 'resolver distinguishes issuance from financial status');
ok(resolver.includes("'has_invoice_link'") && resolver.includes("'active_invoice'"), 'historical inactive invoice state remains exposed');
ok(resolver.includes('approvedBatchNeedsInvoice'), 'approved-uninvoiced formula uses the centralized contract');
ok(contract.includes('UNIQUE') === false && migration.includes("$table->unique('batch_id'"), 'one batch remains limited to one invoice link');
ok(migration.includes("foreign('booking_id', 'gbbb_booking_fk')") && migration.includes('restrictOnDelete'), 'restrictive foreign keys remain intact');
console.log(`ERP113378_BOOKING_BILLING_C42=PASS (${pass} assertions)`);
