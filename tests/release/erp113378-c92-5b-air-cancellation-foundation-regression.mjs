import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const ok = (condition, message) => assert.ok(condition, message);
const files = {
  guard: 'app/Services/Operations/AirServiceRemovalEligibilityResolver.php',
  auth: 'app/Services/Operations/AirServiceRemovalAuthorization.php',
  concurrency: 'app/Services/Operations/AirServiceRemovalConcurrencyContract.php',
  audit: 'app/Services/Operations/AirCancellationAuditContract.php',
  test: 'tests/release/erp113378-c92-5b-air-cancellation-foundation-regression.mjs',
};
const sources = Object.fromEntries(Object.entries(files).map(([key, file]) => [key, read(file)]));

ok(sources.auth.includes('hasExactPermission') && sources.auth.includes("booking_services.manage"), 'explicit existing service-management permission is required');
ok(!sources.auth.includes("hasExactPermission($user, ['bookings.cancel'])"), 'full-booking cancellation permission is not required for one service');
ok(sources.auth.includes('companyIdForUser') && sources.auth.includes('mayAccessBranch'), 'company and branch scope are enforced');
ok(sources.auth.includes("where('id', $serviceId)->where('booking_id', $bookingId)"), 'booking-service ownership is enforced');
ok(sources.auth.includes('UNKNOWN_AUTHORITY'), 'authorization fails closed on unresolved authority');
ok(sources.concurrency.includes('STALE_SERVICE_STATE') && sources.concurrency.includes('ALREADY_CANCELLED'), 'stale and cancelled states are rejected');
ok(sources.concurrency.includes('lockForUpdate()') && sources.concurrency.includes('DB::transaction()'), 'future lock contract documents transaction boundary');
ok(sources.concurrency.includes('hash_equals') && sources.concurrency.includes('hash_hmac'), 'signed authoritative snapshot comparison is strict');
ok(sources.concurrency.includes('financial_recheck_required') && sources.concurrency.includes('eligibility->resolve'), 'future transaction must recheck financial eligibility');
ok(sources.audit.includes("'user_id'") && sources.audit.includes("'company_id'") && sources.audit.includes("'branch_id'"), 'audit actor and scope fields are present');
ok(sources.audit.includes("'booking_id'") && sources.audit.includes("'booking_service_id'") && sources.audit.includes("'previous_status'") && sources.audit.includes("'new_status'"), 'audit booking/service transition fields are present');
ok(sources.audit.includes('nativePayload') && sources.audit.includes("'auditable_type'") && sources.audit.includes("'old_values'") && sources.audit.includes("'new_values'"), 'audit contract maps to native audit_logs columns');
ok(sources.audit.includes('mb_strlen(trim($reason)) > 500'), 'audit reason is bounded');
ok(!sources.audit.includes('passport') && !sources.audit.includes('payment') && !sources.audit.includes('ticket_number'), 'audit payload excludes confidential passenger/payment data');
for (const [key, source] of Object.entries(sources).filter(([key]) => key !== 'test')) {
  ok(!/->(?:delete|update|insert|upsert|save|create)\s*\(/.test(source), `${key} contract contains no mutation calls`);
}
ok(sources.guard.includes('final class AirServiceRemovalEligibilityResolver'), 'C92.5A guard remains present');
ok(sources.guard.includes('public function resolve(int $bookingId, int $serviceId'), 'C92.5A guard entry point remains intact');
ok(!sources.auth.includes('GeneralBookingAirProductController') && !sources.concurrency.includes('GeneralBookingAirProductController'), 'existing Air deletion endpoint remains unwired');
ok(!sources.auth.includes('DB::statement') && !sources.concurrency.includes('DB::statement'), 'no raw SQL mutation escape hatch exists');

console.log('ERP-11.3.378 C92.5B cancellation foundation regression: PASS (16 assertions)');
