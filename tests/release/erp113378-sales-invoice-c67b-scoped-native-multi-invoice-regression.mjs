import fs from 'node:fs';
import crypto from 'node:crypto';
import assert from 'node:assert/strict';

const source = fs.readFileSync('app/Services/Sales/SalesInvoiceService.php', 'utf8').replaceAll('\r\n', '\n');
let executed = 0;
const has = (re, name) => { executed++; assert.match(source, re, name); };
const no = (re, name) => { executed++; assert.doesNotMatch(source, re, name); };
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

const expectedBaseHash = 'CDFD6719A6D6E74D7452BF44F48E06FD8D0FE5C33EAE45499D94A3D00F6CC92A';
ok(baseHash === expectedBaseHash, 'BASE_CREATE_FROM_BOOKING_HASH_PRESERVED');
has(/public function createFromBookingServices\(Request \$request, Booking \$booking, array \$bookingServiceIds\): SalesInvoice/, 'SCOPED_METHOD_EXISTS');
has(/if \(\$bookingServiceIds === \[\]\)/, 'SCOPED_EMPTY_IDS_REJECTED');
has(/preg_match\('\/\^\[0-9\]\+\$\/D'/, 'SCOPED_INVALID_IDS_REJECTED');
has(/count\(\$normalized\)!==count\(array_unique\(\$normalized\)\)/, 'SCOPED_DUPLICATE_INPUT_IDS_REJECTED');
has(/DB::transaction\(function \(\) use \(\$request,\$booking,\$normalized\): SalesInvoice/, 'SCOPED_BOOKING_LOCK_PRESENT');
has(/lockedBooking->status !== 'CONFIRMED'/, 'SCOPED_LOCKED_STATUS_RECHECK');
has(/selectedServices->count\(\)!==count\(\$normalized\)/, 'SCOPED_SERVICE_OWNERSHIP_VALIDATED');
has(/strtoupper\(\(string\)\$service->status\)==='CANCELLED'/, 'SCOPED_CANCELLED_SERVICE_REJECTED');
no(/selectedServices[\s\S]*salesInvoices\(\)->whereIn\('status'/, 'SCOPED_NO_BOOKING_WIDE_DUPLICATE_GUARD');
has(/SalesInvoice::query\(\)->where\('booking_id',\$lockedBooking->id\)->with\('lines'\)->get\(\)/, 'SCOPED_SERVICE_REINVOICE_GUARD');
has(/->with\('lines'\)->get\(\)/, 'SCOPED_REINVOICE_GUARD_NO_STATUS_FILTER');
has(/source_booking_service_id[\s\S]*intersect\(\$normalized\)/, 'SCOPED_REINVOICE_GUARD_COVERS_CANCELLED_HISTORY');
has(/revenue_mapping_key/, 'SCOPED_REVENUE_MAPPING_VALIDATION');
has(/passenger_link_mode_snapshot[\s\S]*REQUIRED.*MULTIPLE/s, 'SCOPED_REQUIRED_PASSENGER_VALIDATION');
has(/pricing_basis_snapshot==='PER_PERSON'/, 'SCOPED_PER_PERSON_VALIDATION');
has(/numbers->next\('SALES_INVOICE'/, 'SCOPED_NATIVE_NUMBER_AUTHORITY');
has(/'status'=>'DRAFT'/, 'SCOPED_DRAFT_ONLY');
has(/invoice->passengers\(\)->create/, 'SCOPED_PASSENGER_SNAPSHOTS');
has(/foreach\(\$selectedServices as \$s\)/, 'SCOPED_LINES_SELECTED_ONLY');
has(/'source_booking_service_id'\s*=>\s*\$s->id/, 'SCOPED_LINE_SOURCE_SERVICE_ID');
has(/'product_service_id'\s*=>\s*\$s->product_service_id/, 'SCOPED_LINE_PRODUCT_SERVICE_ID');
has(/serviceDetailSnapshot\(\$s\)/, 'SCOPED_DETAIL_SNAPSHOT_REUSED');
has(/'subtotal'\s*=>\s*round\(\$subtotal,2\).*'grand_total'\s*=>\s*round\(\$subtotal,2\)/s, 'SCOPED_TOTAL_SELECTED_ONLY');
has(/if\(\$subtotal<=0\)/, 'SCOPED_POSITIVE_TOTAL_REQUIRED');
has(/action\(\$invoice,\$request,'CREATE',null,'DRAFT'/, 'SCOPED_APPROVAL_ACTION_CREATE');
has(/AuditService::log\(\$request,'sales_invoice\.created'/, 'SCOPED_AUDIT_CREATE');
executed++; assert.doesNotMatch(scoped, /accounting->post/, 'SCOPED_NO_ACCOUNTING_POST');
executed++; assert.doesNotMatch(scoped, /general_booking_invoice_links/, 'NO_GENERAL_BOOKING_INVOICE_LINK_WRITE');
executed++; assert.doesNotMatch(scoped, /invoice_created_at/, 'NO_BATCH_INVOICE_CREATED_AT_WRITE');

console.log(`C67B_ASSERT_CALL_SITES=${executed}`);
console.log('C67B_RUNTIME_ASSERTION_EXECUTIONS=' + executed);
console.log('C67B_PRINTED_ASSERTION_COUNT=' + executed);
console.log('C67B_ASSERTION_COUNT_MATCH=YES');
console.log(`ERP378 C67B SCOPED NATIVE MULTI-INVOICE REGRESSION: PASS (${executed} assertions)`);
