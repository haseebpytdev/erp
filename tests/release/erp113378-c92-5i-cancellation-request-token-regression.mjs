import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const controllerPath = 'app/Http/Controllers/Operations/AirServiceCancellationController.php';
const controller = read(controllerPath);
const service = read('app/Services/Operations/AirServiceCancellationService.php');
const routes = read('routes/erp103179.php');
const air = read('public/erp-theme/js/products/air.js');
const ok = (condition, message) => assert.ok(condition, message);

ok(controller.includes('final class AirServiceCancellationController'), 'isolated controller exists');
ok(controller.includes('public function preview') && controller.includes('public function confirm'), 'preview and confirmation handlers exist');
ok(controller.includes('$request->user()'), 'actor is request-authenticated');
ok(controller.includes('$this->authorization->authorize'), 'authorization is checked');
ok(controller.includes('$this->eligibility->resolve'), 'financial eligibility is checked before preview token issuance');
ok(controller.includes('$this->cancellation->previewOperationalState'), 'ticket operational state is checked before token issuance');
ok(controller.includes('$this->concurrency->capture'), 'server snapshot token is captured');
ok(controller.includes("'concurrency_token' => (string) $snapshot['token']"), 'token is returned only from eligible snapshot');
ok(!controller.includes('ticket_number') && !controller.includes('passenger'), 'preview response excludes sensitive details');
ok(controller.includes('AIR_SERVICE_CANCELLATION') && controller.includes('confirmation_acknowledged'), 'confirmation intent and acknowledgement are required');
ok(controller.includes("$this->cancellation->cancel(["), 'validated confirmation invokes cancellation service');
ok(controller.includes("'outcome' => 'unknown'") && controller.includes('TRANSACTION_OUTCOME_UNKNOWN'), 'unknown transaction outcome is explicit and non-success');
ok(service.includes('previewOperationalState') && service.includes("'status' => $cancelledStatus"), 'service exposes safe preview and authoritative success status');
ok(service.includes('DB::transaction') && service.includes('lockForUpdate'), 'transactional locking remains in service');
ok(!routes.includes('AirServiceCancellationController') && !/air[^\n]*cancel/i.test(routes), 'controller is not publicly routed');
ok(air.includes('authorized cancellation workflow') && air.includes('deleted_group_service_ids'), 'ordinary Air Save rejects deletion instructions');
ok(air.includes('persisted-group removal remains deliberately unavailable'), 'persisted remove UI remains disabled');
ok(!controller.includes('->delete(') && !service.includes("booking_services')->delete"), 'no physical deletion path is introduced');

console.log('ERP-11.3.378 C92.5I cancellation request/token regression: PASS (18 assertions)');
