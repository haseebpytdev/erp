import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const ok = (value, message) => assert.ok(value, message);
const service = read('app/Services/Operations/AirServiceCancellationService.php');
const concurrency = read('app/Services/Operations/AirServiceRemovalConcurrencyContract.php');
const audit = read('app/Services/Operations/AirCancellationAuditContract.php');
const auth = read('app/Services/Operations/AirServiceRemovalAuthorization.php');
const status = execFileSync('git', ['status', '--short'], { cwd: root, encoding: 'utf8' });

ok(service.includes('final class AirServiceCancellationService'), 'isolated cancellation service exists');
ok(service.includes("AIR_SERVICE_CANCELLATION") && service.includes('confirmation_acknowledged') && service.includes('cancellation_reason'), 'explicit command contract exists');
ok(service.includes('DB::transaction') && service.includes('lockForUpdate'), 'booking and service locks are transactional');
ok(service.includes('snapshotForLockedRow') && service.includes('validateLockedToken'), 'signed concurrency token is verified against locked state');
ok(service.includes('eligibility->resolve'), 'financial eligibility is rechecked in transaction');
ok(service.includes("$updated = ['status' => $cancelledStatus]"), 'only service status transitions to native CANCELLED value');
ok(!service.includes("DB::table('air_ticket_details')->delete") && !service.includes("DB::table('booking_service_passengers')->delete") && !service.includes("DB::table('booking_itinerary_segments')->delete"), 'child history is never physically deleted');
ok(service.includes('audit->nativePayload') && service.includes("DB::table('audit_logs')->insert"), 'audit is inserted in the same transaction');
ok(service.includes('AUDIT_STORAGE_UNAVAILABLE') && service.includes('AUDIT_SCHEMA_UNAVAILABLE'), 'audit failure blocks and rolls back');
ok(service.includes("'outcome' => 'UNKNOWN'") && service.includes('TRANSACTION_OUTCOME_UNKNOWN'), 'ambiguous transaction outcomes are not retried');
ok(service.includes('ticketHistoryAllowsCancellation') && service.includes('TICKET_HISTORY_PROTECTED'), 'terminal ticket history blocks cancellation');
ok(service.includes('cancelledStatusValue'), 'native cancellation status compatibility is checked');
ok(service.includes('AIR_SERVICE_CANCELLED') && audit.includes('nativePayload'), 'native audit event contract is reused');
ok(concurrency.includes('hash_hmac') && concurrency.includes('snapshotForLockedRow'), 'token is server-signed and lock-bound');
ok(auth.includes("booking_services.manage") && auth.includes('companyIdForUser') && auth.includes('mayAccessBranch'), 'existing permission and scope authority is reused');
ok(!service.includes('GeneralBookingAirProductController') && !service.includes('Route::'), 'operation remains disconnected from public routes/controllers');
ok(status.includes('routes/erp103179.php') === false, 'no route file is changed');

console.log('ERP-11.3.378 C92.5C safe Air cancellation regression: PASS (17 assertions)');
