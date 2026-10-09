import fs from 'node:fs';

const read = (p) => fs.readFileSync(p, 'utf8');
const js = read('public/erp11390/general-progressive-step1.js');
const invoice = read('app/Services/Sales/SalesInvoiceService.php');
const scope = read('app/Services/Sales/BaseBookingInvoiceScopeResolver.php');
const classifier = read('app/Services/Sales/BookingSalesInvoiceScopeResolver.php');
const consistency = read('app/Services/Sales/BaseSalesInvoiceConsistencyResolver.php');
const review = read('app/Http/Controllers/Operations/GeneralBookingReviewController.php');
const view = read('resources/views/operations/bookings/general-booking-review-v113160.blade.php');
const config = read('config/et_erp_release.php');
const inspector = read('app/Services/Operations/NativeSalesInvoiceInspector.php');
let assertions = 0;
const ok = (value, message) => { assertions += 1; if (!value) throw new Error(message); };

ok(/'reopened'/.test(js), 'REOPENED lifecycle is recognized');
ok(/data-et-booking-status/.test(js), 'server-seeded lifecycle attribute is read/written');
ok(/seeded&&allowed\.indexOf/.test(js), 'server status is preferred');
ok(/return 'DRAFT'/.test(js), 'DRAFT remains final fallback');
ok(/etgpSetVisibleBookingStatus113162/.test(js), 'refresh badge helper exists');
ok(/\.etgp-status/.test(js), 'visible status badge is updated');
ok(/data\.booking_status/.test(js), 'operational booking_status is consumed');
ok(/booking_status\)etgpSetVisibleBookingStatus/.test(js), 'billing lock application updates lifecycle badge');

ok(/billingBlocksWorkflow/.test(review), 'review billing workflow guard exists');
ok(/if \(\$action === 'submit'\)[\s\S]*billingLocks->resolve/.test(review), 'Booking Submit resolves billing lock');
ok(/if \(\$action === 'approve'\)[\s\S]*billingLocks->resolve/.test(review), 'Booking Approve resolves billing lock');
ok(/if \(\$action === 'reopen'\)[\s\S]*billingLocks->resolve/.test(review), 'Booking Reopen guard remains');
ok(/draft_invoice.*final_invoice.*approved_supplement.*supplement_invoice/.test(review), 'protected billing codes are fail-closed');
ok(/active Draft Sales Invoice/.test(review), 'draft invoice message is explicit');
ok(/BILLING LOCK/.test(view), 'billing lock panel is rendered');
ok(/\$billingLock\['reason'\]/.test(view), 'billing lock reason is visible');
ok(/Approval.*\$approvalStatus/.test(view), 'lifecycle presentation remains separate');
ok(/Accounting.*\$accounting\['label'\]/.test(view), 'accounting status remains separate');
ok(/\$billingLock\['locked'\]\?\?false/.test(view), 'review actions are suppressed under billing lock');

ok(scope.includes('BaseBookingInvoiceScopeResolver'), 'base scope resolver exists');
ok(scope.includes("where('b.batch_type', 'supplementary')"), 'supplementary batch type is authoritative');
ok(scope.includes("where('i.booking_id', $bookingId)"), 'materialized item booking ownership is enforced');
ok(scope.includes("where('b.booking_id', $bookingId)"), 'materialized batch booking ownership is enforced');
ok(/status\) !== 'CANCELLED'/.test(scope), 'native cancelled service semantics are preserved');
ok(scope.includes('expected_service_ids'), 'expected service IDs are returned');
ok(scope.includes('expected_services'), 'expected service rows are returned');
ok(scope.includes('expected_total'), 'expected total is returned');

ok(classifier.includes("where('link_type', 'supplementary')"), 'supplementary invoice classifier uses link type');
ok(classifier.includes('sales_invoice_id'), 'supplementary classifier uses invoice link identity');
ok(classifier.includes("return $linked ? 'supplementary' : 'base'"), 'base is the non-supplementary booking invoice scope');
ok(consistency.includes('IN_SYNC'), 'consistency resolver supports IN_SYNC');
ok(consistency.includes('MISSING_SERVICES'), 'consistency resolver supports missing services');
ok(consistency.includes('STALE_EXTRA_SERVICES'), 'consistency resolver supports stale extras');
ok(consistency.includes('MISMATCH'), 'consistency resolver supports mismatch');
ok(consistency.includes('source_booking_service_id'), 'service identity is primary');
ok(consistency.includes('total_matches'), 'totals are secondary diagnostics');
ok(consistency.includes('invalidLines'), 'identity-less lines fail closed');

ok(invoice.includes('BaseBookingInvoiceScopeResolver'), 'SalesInvoiceService uses base scope resolver');
ok(/baseScope->resolve\(\$booking\)/.test(invoice), 'createFromBooking uses base scope');
ok(/baseScope->resolve\(\$lockedBooking\)/.test(invoice), 'createFromBooking revalidates after row lock');
ok(/guardBaseConsistency\(\$invoice\)/g.test(invoice), 'workflow guard is invoked');
ok((invoice.match(/guardBaseConsistency\(\$invoice\)/g) || []).length === 3, 'submit approve post are all guarded');
ok(/public function createFromBookingServices/.test(invoice), 'scoped creator remains present');
ok(read('app/Services/Operations/GeneralBookingAdditionalServiceSalesInvoiceCoordinator.php').includes('createFromBookingServices($request, $booking, $serviceIds)'), 'supplementary coordinator contract remains scoped');
ok(invoice.includes("if($invoice->status!=='DRAFT')"), 'cancelDraft still requires Draft');
ok(/status'=>'CANCELLED'/.test(invoice), 'cancelDraft retains cancellation state');
ok(/cancellation_reason/.test(invoice), 'cancellation reason is retained');
ok(/AuditService::log\(\$request,'sales_invoice.cancelled'/.test(invoice), 'cancellation audit is retained');
ok(!/accounting->post\([^)]*cancel/.test(invoice), 'draft cancellation has no GL reversal');
ok(/status === 'draft'[\s\S]*consistency/.test(inspector), 'invoice workflow presentation resolves draft consistency');
ok(/\$action = null/.test(inspector), 'out-of-sync Draft Submit action is suppressed');
ok(inspector.includes("'consistency'=>$consistency"), 'invoice workflow exposes consistency diagnostics');

ok(/cancelled.*canceled.*void.*voided.*rejected/.test(read('app/Services/Operations/NativeSalesInvoiceInspector.php')), 'cancelled history is excluded from active inspector');
ok(config.includes("'asset_version' => 'ERP-11.3.378-C47'"), 'asset revision is bumped for C63 writable-state guard');
ok(!config.includes("'asset_version' => 'ERP-11.3.378-C40'"), 'old active asset revision is removed');
ok(!invoice.includes('general_booking_invoice_links'), 'SalesInvoiceService does not collapse supplementary scope into scoped creator');
ok(read('app/Services/Sales/AirTicketInvoiceCommercialSyncService.php').includes('ticketRows'), 'Air sync is passenger-ticket driven');
ok(read('app/Services/Operations/GeneralBookingAdditionalServiceSalesInvoiceCoordinator.php').includes('createFromBookingServices'), 'supplementary coordinator remains protected');
ok(read('app/Services/Operations/GeneralBookingAdditionalServiceSalesInvoiceCoordinator.php').includes('source_booking_service_id'), 'supplementary exact service linkage remains protected');
ok(read('app/Services/Operations/BookingProductSummaryResolver.php').includes('materializedNativeSourceRepresentsItem'), 'C51 product summary authority remains present');
ok(read('app/Services/Operations/BookingWorkspaceShellPresenter.php').includes('data-et-booking-status'), 'server lifecycle seed remains present');

console.log(`C52 workflow and base invoice consistency regression: PASS (${assertions} assertions)`);
