import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/Operations/GeneralBookingAdditionalServiceController.php'), 'utf8');
const routes = fs.readFileSync(path.join(root, 'routes/erp103179.php'), 'utf8');
const view = fs.readFileSync(path.join(root, 'resources/views/operations/bookings/additional-services/show.blade.php'), 'utf8');
const coordinator = fs.readFileSync(path.join(root, 'app/Services/Operations/GeneralBookingAdditionalServiceSalesInvoiceCoordinator.php'), 'utf8');
const salesInvoiceService = fs.readFileSync(path.join(root, 'app/Services/Sales/SalesInvoiceService.php'), 'utf8');
const baseController = fs.readFileSync(path.join(root, 'app/Http/Controllers/Sales/StableBookingSalesInvoiceController.php'), 'utf8');
const rbac = fs.readFileSync(path.join(root, 'app/Services/Administration/ErpRoleAccessPolicy.php'), 'utf8');
const approvedView = view.match(/@elseif\(\$batchStatus === 'approved'\)([\s\S]*?)@elseif\(\$batchStatus === 'rejected'\)/)?.[1] ?? '';
const beforeApprovedView = view.split("@elseif($batchStatus === 'approved')")[0] ?? '';
const afterApprovedView = view.split("@elseif($batchStatus === 'rejected')")[1] ?? '';

let assertions = 0;
const ok = (condition, message) => { assertions += 1; assert.equal(condition, true, message); };
const includes = (source, needle, message) => ok(source.includes(needle), message);
const excludes = (source, needle, message) => ok(!source.includes(needle), message);

includes(controller, 'public function invoice(', 'C67D_CONTROLLER_ACTION_EXISTS');
includes(controller, '$coordinator->create($request, $booking, $batch)', 'C67D_CONTROLLER_USES_COORDINATOR');
includes(controller, "$status = strtolower(trim((string) ($result['status'] ?? '')))", 'C67D_COORDINATOR_STATUS_NORMALIZED');
includes(controller, "in_array($status, ['created', 'already_invoiced'], true)", 'C67D_ALLOWED_COORDINATOR_STATUSES');
includes(controller, 'operation returned an invalid status.', 'C67D_UNKNOWN_COORDINATOR_STATUS_FAILS_CLOSED');
ok(controller.indexOf("in_array($status, ['created', 'already_invoiced'], true)") < controller.indexOf("$salesInvoiceId = (int)"), 'C67D_STATUS_VALIDATION_BEFORE_INVOICE_ID');
ok(controller.indexOf("in_array($status, ['created', 'already_invoiced'], true)") < controller.indexOf('nativeInvoiceUrl($salesInvoiceId)'), 'C67D_STATUS_VALIDATION_BEFORE_REDIRECT');
excludes(controller, 'createFromBookingServices(', 'C67D_CONTROLLER_DOES_NOT_USE_NATIVE_BASE_CREATOR');
excludes(controller, 'createFromBooking(', 'C67D_CONTROLLER_DOES_NOT_USE_CREATE_FROM_BOOKING');
excludes(controller, 'NativeSalesInvoiceRuntimeBridge', 'C67D_CONTROLLER_DOES_NOT_USE_RUNTIME_BRIDGE');
includes(controller, '$salesInvoiceId = (int) ($result[\'sales_invoice_id\'] ?? 0)', 'C67D_VALID_INVOICE_ID_REQUIRED');
includes(controller, '$invoices->nativeInvoiceUrl($salesInvoiceId)', 'C67D_NATIVE_INVOICE_URL_AUTHORITY');
includes(controller, "status === 'already_invoiced'", 'C67D_ALREADY_INVOICED_STATUS_USES_EXISTING_MESSAGE');
includes(controller, 'Supplementary Sales Invoice created successfully.', 'C67D_CREATED_STATUS_USES_CREATED_MESSAGE');
includes(controller, 'Supplementary Sales Invoice already exists. Existing invoice opened.', 'C67D_ALREADY_INVOICED_STATUS_USES_EXISTING_MESSAGE');
includes(controller, 'catch (ValidationException $e)', 'C67D_VALIDATION_EXCEPTION_HANDLED');
includes(controller, 'catch (Throwable $e)', 'C67D_UNEXPECTED_EXCEPTION_REPORTED');
includes(controller, 'report($e)', 'C67D_UNEXPECTED_EXCEPTION_REPORTED');
includes(controller, "route('bookings.additional-services.show', ['booking' => $booking, 'batch' => $batch])", 'C67D_FAILURE_REDIRECTS_TO_BATCH');

includes(routes, "Route::post('/operations/bookings/{booking}/additional-services/{batch}/sales-invoice'", 'C67D_POST_ONLY_ROUTE');
includes(routes, "->whereNumber('booking')->whereNumber('batch')", 'C67D_BOOKING_AND_BATCH_ROUTE_CONSTRAINTS');
includes(routes, 'EnforceErpRoleScopedAccess::class', 'C67D_ROLE_MIDDLEWARE_PRESENT');
includes(routes, "->name('bookings.additional-services.invoice')", 'C67D_ROUTE_NAME');
excludes(routes, "Route::get('/operations/bookings/{booking}/additional-services/{batch}/sales-invoice'", 'C67D_NO_GET_INVOICE_CREATION_ROUTE');

includes(view, '<form method="POST"', 'C67D_CSRF_FORM');
includes(view, '@csrf', 'C67D_CSRF_FORM');
includes(approvedView, 'Approved — Ready for Supplementary Invoice.', 'C67D_APPROVED_UNLINKED_CREATE_BUTTON');
includes(approvedView, 'Create Draft Supplementary Sales Invoice', 'C67D_CREATE_BUTTON_SAYS_DRAFT_OR_EQUIVALENT');
includes(approvedView, 'Supplementary Sales Invoice Created', 'C67D_APPROVED_LINKED_OPEN_BUTTON');
includes(approvedView, 'Open Supplementary Sales Invoice', 'C67D_APPROVED_LINKED_OPEN_BUTTON');
excludes(approvedView, 'Approved — Awaiting Supplementary Invoice.', 'C67D_LINKED_STATE_NOT_AWAITING');
ok((approvedView.match(/route\('bookings\.additional-services\.invoice'/g) ?? []).length === 2, 'C67D_SAME_IDEMPOTENT_ENDPOINT_FOR_CREATE_AND_OPEN');
includes(view, "$batchStatus === 'approved'", 'C67D_APPROVED_STATE_ACTION_GATE');
excludes(beforeApprovedView, 'Create Draft Supplementary Sales Invoice', 'C67D_NO_ACTION_FOR_DRAFT');
excludes(beforeApprovedView, 'Open Supplementary Sales Invoice', 'C67D_NO_ACTION_FOR_PENDING');
excludes(afterApprovedView, 'Create Draft Supplementary Sales Invoice', 'C67D_NO_ACTION_FOR_REJECTED');
excludes(afterApprovedView, 'Open Supplementary Sales Invoice', 'C67D_NO_ACTION_FOR_REJECTED');
includes(view, 'Posting remains under the normal Sales Invoice workflow.', 'C67D_DRAFT_NOT_POSTED_LANGUAGE');

excludes(controller, 'submit(', 'C67D_NO_SUBMIT_APPROVE_POST');
excludes(controller, 'approve(', 'C67D_NO_SUBMIT_APPROVE_POST');
excludes(controller, 'post(', 'C67D_NO_SUBMIT_APPROVE_POST');
excludes(controller, 'accounting->post', 'C67D_NO_ACCOUNTING_POST');
excludes(controller, 'payment', 'C67D_NO_PAYMENT_RECEIPT');
excludes(controller, 'receipt', 'C67D_NO_PAYMENT_RECEIPT');
excludes(controller, 'journal', 'C67D_NO_JOURNAL');

includes(coordinator, 'return [', 'C67D_COORDINATOR_UNCHANGED');
includes(salesInvoiceService, 'function createFromBookingServices(', 'C67D_SALES_INVOICE_SERVICE_UNCHANGED');
includes(baseController, 'class StableBookingSalesInvoiceController', 'C67D_BASE_INVOICE_CONTROLLER_UNCHANGED');
ok(!/ErpRoleAccessPolicy[\s\S]*customer_reimbursement/.test(rbac), 'C67D_RBAC_POLICY_UNCHANGED');

console.log(`C67D additional-services invoice UI integration regression: PASS (${assertions} assertions)`);
