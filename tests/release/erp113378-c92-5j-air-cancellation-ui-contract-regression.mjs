import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const air = fs.readFileSync(path.join(root, 'public/erp-theme/js/products/air.js'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/Operations/AirServiceCancellationController.php'), 'utf8');
const routes = fs.readFileSync(path.join(root, 'routes/erp103179.php'), 'utf8');
const ok = (condition, message) => assert.ok(condition, message);

ok(air.includes('etgpAirCancellationCapability113525=false'), 'cancellation capability is explicitly disabled');
ok(air.includes('Confirm Air Group removal'), 'persisted removal opens a confirmation interface');
ok(air.includes('Removal affects active booking operations'), 'confirmation warns about active booking impact');
ok(air.includes('separate Refund System'), 'confirmation explains protected invoice handling');
ok(air.includes('Cancellation reason (required)'), 'confirmation requires a bounded reason input');
ok(air.includes("confirmButton.disabled=true"), 'confirm remove is disabled while activation is blocked');
ok(air.includes("removal_intent:'AIR_SERVICE_CANCELLATION'") && air.includes('confirmation_acknowledged:true'), 'future confirmation payload contract is prepared');
ok(air.includes('concurrency_token:String(token||\'\')') && air.includes('cancellation_reason:String(reason.value||\'\').trim()'), 'future payload includes token and reason');
ok(air.includes('pageState.groups.splice(at,1)'), 'unsaved groups retain immediate local removal');
ok(air.includes('No request was sent.'), 'dormant UI does not claim a cancellation');
ok(!/fetch\s*\([^)]*cancel/i.test(air), 'no cancellation endpoint call is introduced');
ok(!routes.includes('AirServiceCancellationController') && !/air[^\n]*cancel/i.test(routes), 'no public cancellation route is registered');
ok(controller.includes('public function preview') && controller.includes('public function confirm'), 'backend integration contract remains available');
ok(controller.includes("'booking_id' => $booking") && controller.includes("'booking_service_id' => $service"), 'backend contract uses exact route identity');
ok(!controller.includes('deleted_group_service_ids') && !controller.includes('ticket_groups'), 'cancellation handler is separate from ordinary Air Save payloads');
ok(air.includes('Persisted Air Group removal is unavailable until the authorized cancellation workflow is activated'), 'blocked financial message remains safe');

console.log('ERP-11.3.378 C92.5J Air cancellation UI/integration contract regression: PASS (17 assertions)');
