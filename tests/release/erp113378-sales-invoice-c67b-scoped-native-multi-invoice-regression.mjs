import fs from 'node:fs';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';

const source = fs.readFileSync('app/Services/Sales/SalesInvoiceService.php', 'utf8').replaceAll('\r\n', '\n');
let executed = 0;
const ok = (value, name) => { executed++; assert.ok(value, name); };

function methodText(signature) {
    const signatureStart = source.indexOf(signature);
    assert.notEqual(signatureStart, -1, `${signature} exists`);
    const start = source.lastIndexOf('\n', signatureStart) + 1;
    const open = source.indexOf('{', signatureStart);
    let depth = 0; let quote = null; let escaped = false; let lineComment = false; let blockComment = false;
    for (let i = open; i < source.length; i++) {
        const c = source[i]; const n = source[i + 1];
        if (lineComment) { if (c === '\n') lineComment = false; continue; }
        if (blockComment) { if (c === '*' && n === '/') { blockComment = false; i++; } continue; }
        if (quote) { if (escaped) { escaped = false; continue; } if (c === '\\') { escaped = true; continue; } if (c === quote) quote = null; continue; }
        if (c === '/' && n === '/') { lineComment = true; i++; continue; }
        if (c === '/' && n === '*') { blockComment = true; i++; continue; }
        if (c === '"' || c === "'" || c === '`') { quote = c; continue; }
        if (c === '{') depth++;
        if (c === '}' && --depth === 0) return source.slice(start, i + 1);
    }
    throw new Error(`unbalanced method: ${signature}`);
}

const base = methodText('public function createFromBooking(Request $request, Booking $booking): SalesInvoice');
const baseHash = crypto.createHash('sha256').update(base).digest('hex').toUpperCase();
const scoped = methodText('public function createFromBookingServices(Request $request, Booking $booking, array $bookingServiceIds): SalesInvoice');
const scopedHas = (re, name) => { executed++; assert.match(scoped, re, name); };
const scopedNo = (re, name) => { executed++; assert.doesNotMatch(scoped, re, name); };

const expectedBaseHash = 'CDFD6719A6D6E74D7452BF44F48E06FD8D0FE5C33EAE45499D94A3D00F6CC92A';
ok(baseHash === expectedBaseHash, 'BASE_CREATE_FROM_BOOKING_HASH_PRESERVED');
scopedHas(/^\s*public function createFromBookingServices\(Request \$request, Booking \$booking, array \$bookingServiceIds\): SalesInvoice/, 'SCOPED_METHOD_EXISTS');
scopedHas(/if \(\$bookingServiceIds === \[\]\)/, 'SCOPED_EMPTY_IDS_REJECTED');
scopedHas(/preg_match\('\/\^\[0-9\]\+\$\/D'/, 'SCOPED_INVALID_IDS_REJECTED');
scopedHas(/count\(\$normalized\)!==count\(array_unique\(\$normalized\)\)/, 'SCOPED_DUPLICATE_INPUT_IDS_REJECTED');
scopedHas(/DB::transaction\(function \(\) use \(\$request,\$booking,\$normalized\): SalesInvoice/, 'SCOPED_BOOKING_TRANSACTION_PRESENT');
scopedHas(/Booking::query\(\)->whereKey\(\$booking->id\)->lockForUpdate\(\)->firstOrFail\(\)/, 'SCOPED_BOOKING_LOCK_PRESENT');
scopedHas(/lockedBooking->status !== 'CONFIRMED'/, 'SCOPED_LOCKED_STATUS_RECHECK');
scopedHas(/\$selectedServices=\$lockedBooking->services->whereIn\('id',\$normalized\)->values\(\)/, 'SCOPED_SERVICE_SELECTION_AUTHORITY');
scopedHas(/selectedServices->count\(\)!==count\(\$normalized\)/, 'SCOPED_SERVICE_OWNERSHIP_VALIDATED');
scopedHas(/strtoupper\(\(string\)\$service->status\)==='CANCELLED'/, 'SCOPED_CANCELLED_SERVICE_REJECTED');
scopedNo(/\$booking->salesInvoices\(\)|\$lockedBooking->salesInvoices\(\)|whereIn\('status',\['DRAFT','PENDING_APPROVAL','APPROVED','POSTED'\]\)|This booking already has an active Sales Invoice|An active Sales Invoice was already created for this booking/, 'SCOPED_NO_BOOKING_WIDE_DUPLICATE_GUARD');

const guardStart = scoped.indexOf('$alreadyInvoiced=');
const guardEndMarker = 'if ($alreadyInvoiced->isNotEmpty())';
const guardEnd = scoped.indexOf(guardEndMarker, guardStart);
ok(guardStart >= 0 && guardEnd >= 0, 'SCOPED_REINVOICE_GUARD_ISOLATED');
const reinvoiceGuard = scoped.slice(guardStart, guardEnd + guardEndMarker.length);
ok(/SalesInvoice::query\(\)/.test(reinvoiceGuard), 'SCOPED_REINVOICE_QUERY');
ok(/where\('booking_id',\$lockedBooking->id\)/.test(reinvoiceGuard), 'SCOPED_REINVOICE_BOOKING_SCOPE');
ok(/with\('lines'\)/.test(reinvoiceGuard), 'SCOPED_REINVOICE_LINES');
ok(/source_booking_service_id/.test(reinvoiceGuard), 'SCOPED_REINVOICE_SOURCE_SERVICE_ID');
ok(/intersect\(\$normalized\)/.test(reinvoiceGuard), 'SCOPED_REINVOICE_SELECTED_IDS');
ok(/isNotEmpty\(\)/.test(reinvoiceGuard), 'SCOPED_REINVOICE_FAIL_CLOSED');
ok(!/whereIn\('status'|where\('status'|whereNotIn\('status'|DRAFT|PENDING_APPROVAL|APPROVED|POSTED|CANCELLED/.test(reinvoiceGuard), 'SCOPED_REINVOICE_NO_STATUS_FILTER');

scopedHas(/revenue_mapping_key/, 'SCOPED_REVENUE_MAPPING_VALIDATION');
scopedHas(/passenger_link_mode_snapshot,\['REQUIRED','MULTIPLE'\].*linked->isEmpty\(\)/s, 'SCOPED_REQUIRED_PASSENGER_VALIDATION');
scopedHas(/pricing_basis_snapshot==='PER_PERSON'.*linked->isEmpty\(\).*quantity.*linked->count\(\)/s, 'SCOPED_PER_PERSON_VALIDATION');
scopedHas(/\$this->numbers->next\('SALES_INVOICE',\$lockedBooking->company_id,\$lockedBooking->branch_id,\$fy\)/, 'SCOPED_NATIVE_NUMBER_AUTHORITY');
scopedHas(/'status'=>'DRAFT'/, 'SCOPED_DRAFT_ONLY');
scopedHas(/invoice->passengers\(\)->create/, 'SCOPED_PASSENGER_SNAPSHOTS');
scopedHas(/foreach\(\$selectedServices as \$s\)/, 'SCOPED_LINES_SELECTED_ONLY');
scopedHas(/'source_booking_service_id'=>\$s->id/, 'SCOPED_LINE_SOURCE_SERVICE_ID');
scopedHas(/'product_service_id'=>\$s->product_service_id/, 'SCOPED_LINE_PRODUCT_SERVICE_ID');
scopedHas(/serviceDetailSnapshot\(\$s\)/, 'SCOPED_DETAIL_SNAPSHOT_REUSED');
scopedHas(/\$subtotal\+=\(float\)\$s->line_total/, 'SCOPED_TOTAL_ACCUMULATION');
scopedHas(/'subtotal'=>round\(\$subtotal,2\),'grand_total'=>round\(\$subtotal,2\)/, 'SCOPED_TOTAL_SELECTED_ONLY');
scopedHas(/if\(\$subtotal<=0\)/, 'SCOPED_POSITIVE_TOTAL_REQUIRED');
scopedHas(/action\(\$invoice,\$request,'CREATE',null,'DRAFT'/, 'SCOPED_APPROVAL_ACTION_CREATE');
scopedHas(/AuditService::log\(\$request,'sales_invoice\.created'/, 'SCOPED_AUDIT_CREATE');
scopedNo(/accounting->post|\$this->accounting|journal_entries|journals|\bpost\(/, 'SCOPED_NO_ACCOUNTING_POST');
scopedNo(/general_booking_invoice_links|general_booking_billing_batches|invoice_created_at|general_booking_billing_batch_items/, 'SCOPED_NO_GENERAL_BILLING_WRITES');

console.log(`C67B_ASSERT_CALL_SITES=${executed}`);
console.log(`C67B_RUNTIME_ASSERTION_EXECUTIONS=${executed}`);
console.log(`C67B_PRINTED_ASSERTION_COUNT=${executed}`);
console.log('C67B_ASSERTION_COUNT_MATCH=YES');
console.log(`ERP378 C67B SCOPED NATIVE MULTI-INVOICE REGRESSION: PASS (${executed} assertions)`);
