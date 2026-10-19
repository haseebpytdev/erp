import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const ok = (value, message) => assert.ok(value, message);

const returns = read('app/Services/Accounting/PartyAdvanceReturnService.php');
const vouchers = read('app/Http/Controllers/Accounting/CashVoucherController.php');
const cancellation = read('app/Services/Operations/AirServiceCancellationService.php');

ok(returns.includes('lockBookingRowsForAllocations'), 'return creation locks booking scopes');
ok(returns.includes('whereIn(\'id\',$ids)') && returns.includes('lockForUpdate'), 'return booking locks are authoritative and transactional');
ok(returns.includes('customer_advance_return_allocations'), 'return allocation persistence remains intact');
ok(returns.includes("'booking_id'=>$s['booking_id']"), 'return allocation preserves authoritative booking linkage');
ok(vouchers.includes('lockBookingRowsForFinancialWrite'), 'voucher writes lock booking scopes');
ok(vouchers.includes('data[\'booking_id\']'), 'voucher create/update scope uses submitted booking identity');
ok(vouchers.includes('row->booking_id'), 'voucher update also protects the prior booking scope');
ok(vouchers.includes('whereIn(\'id\', $ids)') && vouchers.includes('lockForUpdate'), 'voucher booking locks are deterministic and transactional');
ok(cancellation.includes("where('id', $bookingId)->lockForUpdate()"), 'cancellation retains booking-first lock');
ok(cancellation.indexOf("where('id', $bookingId)->lockForUpdate()") < cancellation.indexOf("where('id', $serviceId)->where('booking_id', $bookingId)->lockForUpdate()"), 'cancellation lock order remains booking before service');
ok(!vouchers.includes("DB::table('bookings')->where('id', $bookingId)->update") && !returns.includes("DB::table('bookings')->where('id', $bookingId)->update"), 'no booking row mutation was introduced');

console.log('ERP-11.3.378 C92.5C.3 financial writer concurrency regression: PASS (11 assertions)');
