<?php

use App\Http\Controllers\Operations\UnifiedGroupPackageBookingController;
use App\Http\Controllers\Operations\GeneralBookingPassengerQuickController;
use App\Http\Controllers\Operations\GeneralBookingAirProductController;
use App\Http\Controllers\Operations\GeneralBookingHotelProductController;
use App\Http\Controllers\Operations\GeneralBookingTransportProductController;
use App\Http\Controllers\Operations\GeneralBookingVisaProductController;
use App\Http\Controllers\Operations\GeneralBookingOperationalSummaryController;
use App\Http\Controllers\Operations\GeneralBookingInvoiceSummaryController;
use App\Http\Controllers\Operations\GeneralBookingReviewController;
use App\Http\Controllers\Operations\VisaMasterController;
use App\Http\Controllers\Operations\GeneralBookingVoucherPreviewController;
use App\Http\Controllers\Operations\GroupUmrahWorkflowController;
use App\Http\Controllers\Operations\GroupUmrahVoucherController;
use App\Http\Controllers\Operations\GroupUmrahCommercialAmendmentController;
use App\Http\Controllers\Operations\GroupUmrahSalesInvoiceBridgeController;
use App\Http\Controllers\Operations\GroupUmrahSalesInvoiceWorkflowController;
use App\Http\Controllers\Reports\GroupUmrahProfitabilityController;
use App\Http\Middleware\InjectGroupPackageCreateEntry;
use App\Http\Middleware\ApplyErpReleaseMetadata;
use App\Http\Middleware\RedirectGroupUmrahDraftToUnified;
use App\Http\Middleware\PresentGroupUmrahBookingRegister;
use App\Http\Middleware\RedirectGroupUmrahLegacyWorkspace;
use App\Http\Middleware\RedirectGroupUmrahLegacyVoucher;
use App\Http\Middleware\PresentGroupUmrahSalesInvoice;
use App\Http\Middleware\PreloadAirTicketFocusedWorkspace;
use App\Http\Middleware\PresentBookingFocusedWorkspace;
use App\Http\Controllers\Sales\SalesInvoiceDraftUpdateBridgeController;
use App\Http\Controllers\Sales\AirTicketInvoiceDraftSyncController;
use App\Http\Controllers\Sales\StableBookingSalesInvoiceController;
use App\Http\Middleware\PresentAirTicketSalesInvoice;
use App\Http\Middleware\EnsureAirTicketCommercialIntegrityBeforeNativeWorkflow;
use App\Http\Middleware\PresentSalesInvoiceFocusedWorkspace;
use App\Http\Controllers\System\ProductionDataResetController;
use App\Http\Controllers\System\PostResetFinancialCleanupController;
use App\Http\Controllers\System\ReportsFilterAssetController;
use App\Http\Controllers\System\BookingFocusAssetController;
use App\Http\Controllers\System\GeneralProgressiveBookingAssetController;
use App\Http\Controllers\System\AccountingJournalDiagnosticController;
use App\Http\Controllers\System\CustomerLedgerDiagnosticController;
use App\Http\Controllers\System\AirLinkDbDiagnosticController;
use App\Http\Controllers\System\TransportRateResolutionDiagnosticController;
use App\Http\Controllers\System\SalesInvoiceWorkflowCompareController;
use App\Http\Controllers\System\CashVoucherNativeJournalRepairController;
use App\Http\Controllers\Administration\ErpUserManagementController;
use App\Http\Controllers\Purchase\SupplierCostingController;
use App\Http\Controllers\Accounting\CashVoucherController;
use App\Http\Controllers\Accounting\AdvanceAdjustmentController;
use App\Http\Controllers\Accounting\ChartOfAccountsWorkspaceController;
use App\Http\Middleware\PresentErpUserManagementLinks;
use App\Http\Middleware\PresentCashVoucherLinks;
use App\Http\Middleware\PresentChartOfAccountsWorkspace;
use App\Http\Middleware\PresentAccountingReportsWorkspace;
use App\Http\Middleware\PresentVisaManagementTravelMasterLink;
use App\Http\Middleware\PresentCompanyVoucherFooterAuthority;
use App\Http\Middleware\GuardApprovedGeneralBookingCommercials;
use App\Http\Middleware\EnforceGeneralBookingEditLock;
use App\Http\Middleware\PresentBookingRegisterInvoiceVisibility;
use App\Http\Middleware\EnforceErpRoleScopedAccess;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Routing\Events\RouteMatched;

Route::get('/voucher/{token}', [GeneralBookingVoucherPreviewController::class, 'publicShow'])
    ->where('token', '[a-f0-9]{48}')
    ->name('public.voucher.show');

/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Unified Group Package Routes
|--------------------------------------------------------------------------
|
| The Group Package CREATE screen itself is rendered through the already
| existing /operations/bookings/create route by middleware when
| ?group_package=1 is present.
|
| Save/Edit endpoints intentionally live OUTSIDE the existing dynamic
| /operations/bookings/{booking} namespace to avoid route collisions.
|
*/

/*
|--------------------------------------------------------------------------
| ERP-11.3.2 Chart of Accounts — Native Route Preservation
|--------------------------------------------------------------------------
|
| Reuse the native read middleware/permission chain for the redesigned GET
| workspace. Account creation has its own narrow endpoint but inherits the
| native Chart-of-Accounts POST middleware when available.
|
*/
$nativeCoaGetRoute = null;
$nativeCoaPostRoute = null;
foreach (Route::getRoutes()->getRoutes() as $candidateRoute) {
    if ($candidateRoute->uri() !== 'accounting/chart-of-accounts') {
        continue;
    }
    if (in_array('GET', $candidateRoute->methods(), true)) {
        $nativeCoaGetRoute = $candidateRoute;
    }
    if (in_array('POST', $candidateRoute->methods(), true)) {
        $nativeCoaPostRoute = $candidateRoute;
    }
}



// ERP-11.3.34: Reports presentation attaches at RouteMatched time.
// Native report routes are registered later by the installed base application.

$coaReadMiddleware = ['auth'];
$coaWriteMiddleware = ['auth'];
try {
    if ($nativeCoaGetRoute) {
        $coaReadMiddleware = $nativeCoaGetRoute->gatherMiddleware();
    }
} catch (\Throwable) {
}
try {
    if ($nativeCoaPostRoute) {
        $coaWriteMiddleware = $nativeCoaPostRoute->gatherMiddleware();
    } elseif ($nativeCoaGetRoute) {
        $coaWriteMiddleware = $coaReadMiddleware;
    }
} catch (\Throwable) {
    $coaWriteMiddleware = $coaReadMiddleware;
}

Route::middleware(['auth'])->group(function () use ($coaReadMiddleware, $coaWriteMiddleware): void {

    // ERP-11.3.34: serve Reports dynamic switch logic through Laravel itself.
    // Production uses a separate cPanel document root, so files under the
    // application public/ directory are not assumed to be directly web-served.
    Route::get(
        '/system/erp-assets/reports-filter.js',
        ReportsFilterAssetController::class
    )->name('system.erp-assets.reports-filter');

    // ERP-11.3.47: booking focused-shell logic is served through Laravel because
    // the application public/ folder is not assumed to be the cPanel docroot.
    Route::get(
        '/system/erp-assets/booking-focus.js',
        BookingFocusAssetController::class
    )->name('system.erp-assets.booking-focus');

    Route::get(
        '/system/erp-assets/general-progressive-step1.js',
        [GeneralProgressiveBookingAssetController::class, 'js']
    )->name('system.erp-assets.general-progressive-step1-js');

    Route::get(
        '/system/erp-assets/general-progressive-step1.css',
        [GeneralProgressiveBookingAssetController::class, 'css']
    )->name('system.erp-assets.general-progressive-step1-css');

    // ERP-11.3.102 GENERAL Passenger quick-add: direct JSON lookup/master-update/booking-snapshot bridge; no native Saved Passenger DOM dependency
    // lookup + booking snapshot write, independent of unstable native editor DOM.
    Route::get(
        '/system/erp-bookings/{booking}/passengers/quick-lookup',
        [GeneralBookingPassengerQuickController::class, 'lookup']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.passengers.quick-lookup');

    // ERP-11.3.103: accept both POST and PATCH during rollout. 11.3.102
    // shipped a POST browser call against a PATCH-only route; keeping PATCH
    // compatibility avoids breakage for any already-loaded asset while POST
    // remains accepted for the quick-create semantic.
    Route::match(['post', 'patch'],
        '/system/erp-bookings/{booking}/passengers/quick-add',
        [GeneralBookingPassengerQuickController::class, 'store']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.passengers.quick-add');

    Route::patch(
        '/system/erp-bookings/{booking}/passengers/quick-master-update',
        [GeneralBookingPassengerQuickController::class, 'updateMaster']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.passengers.quick-master-update');

    // ERP-11.3.137: authoritative Fare As bridge for an EXISTING booking
    // passenger. This updates only the booking snapshot, never Passenger Master.
    Route::patch(
        '/system/erp-bookings/{booking}/passengers/fare-type',
        [GeneralBookingPassengerQuickController::class, 'updateBookingFareType']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.passengers.fare-type');

    // ERP-11.3.107 GENERAL Tickets / Flight Data: PNR/vendor + fare-type commercial Air product
    // workspace backed by booking_services, booking_itinerary_segments and
    // air_ticket_details. No parallel product table / no migration.
    Route::get(
        '/system/erp-bookings/{booking}/air-product',
        [GeneralBookingAirProductController::class, 'show']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.air-product.show');

    Route::put(
        '/system/erp-bookings/{booking}/air-product',
        [GeneralBookingAirProductController::class, 'store']
    )->whereNumber('booking')->middleware([EnforceErpRoleScopedAccess::class, GuardApprovedGeneralBookingCommercials::class])->name('bookings.air-product.store');


    // ERP-11.3.127 GENERAL Hotel Data: one-line multi-stay workspace backed by
    // the installed native Hotel stay store + booking_services. Runtime City /
    // Hotel additions write into existing master tables; no parallel schema.
    Route::get(
        '/system/erp-bookings/{booking}/hotel-product',
        [GeneralBookingHotelProductController::class, 'show']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.hotel-product.show');

    Route::put(
        '/system/erp-bookings/{booking}/hotel-product',
        [GeneralBookingHotelProductController::class, 'store']
    )->whereNumber('booking')->middleware([EnforceErpRoleScopedAccess::class, GuardApprovedGeneralBookingCommercials::class])->name('bookings.hotel-product.store');

    // ERP-11.3.141 GENERAL Transport: master foreign-cost + DB FX-to-PKR on the compact no-scroll workspace.
    // Uses native booking_transport_segments when available and a lossless
    // booking_services snapshot for Driver / Cell / Plate compatibility.
    Route::get(
        '/system/erp-bookings/{booking}/transport-product',
        [GeneralBookingTransportProductController::class, 'show']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.transport-product.show');

    Route::put(
        '/system/erp-bookings/{booking}/transport-product',
        [GeneralBookingTransportProductController::class, 'store']
    )->whereNumber('booking')->middleware([EnforceErpRoleScopedAccess::class, GuardApprovedGeneralBookingCommercials::class])->name('bookings.transport-product.store');

    // Transport selection is server-backed so Remove -> Add Again always
    // resolves one active native booking_services row before the editor loads.
    Route::post(
        '/system/erp-bookings/{booking}/transport-product/selection',
        [GeneralBookingTransportProductController::class, 'activate']
    )->whereNumber('booking')->middleware([EnforceErpRoleScopedAccess::class, GuardApprovedGeneralBookingCommercials::class])->name('bookings.transport-product.activate');

    Route::delete(
        '/system/erp-bookings/{booking}/transport-product/selection',
        [GeneralBookingTransportProductController::class, 'retire']
    )->whereNumber('booking')->middleware([EnforceErpRoleScopedAccess::class, GuardApprovedGeneralBookingCommercials::class])->name('bookings.transport-product.retire');

    // ERP-11.3.142 GENERAL Visa: passenger selection + bulk assignment +
    // Saudi Company -> Pakistani IATA -> Vendor reporting chain and effective-dated rates.
    Route::get(
        '/system/erp-bookings/{booking}/visa-product',
        [GeneralBookingVisaProductController::class, 'show']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.visa-product.show');

    Route::put(
        '/system/erp-bookings/{booking}/visa-product',
        [GeneralBookingVisaProductController::class, 'store']
    )->whereNumber('booking')->middleware([EnforceErpRoleScopedAccess::class, GuardApprovedGeneralBookingCommercials::class])->name('bookings.visa-product.store');

    // ERP-11.3.153: one persisted commercial/readiness summary for every product workspace.
    Route::get(
        '/system/erp-bookings/{booking}/operational-summary',
        [GeneralBookingOperationalSummaryController::class, 'show']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.operational-summary.show');

    Route::get('/system/erp-bookings/{booking}/invoice-summary', [GeneralBookingInvoiceSummaryController::class, 'show'])
        ->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.invoice-summary.show');

    // ERP-11.3.148 Visa Management is anchored to the REAL native Travel Masters
    // route used by production: /master-data/travel-masters. Keep the old
    // /travel-masters Visa URLs only as compatibility aliases for bookmarks/open tabs.
    Route::get('/master-data/travel-masters/visa-management', [VisaMasterController::class, 'index'])
        ->middleware(EnforceErpRoleScopedAccess::class)->name('travel-masters.visa-management');
    Route::post('/master-data/travel-masters/visa-management/pakistani-iata', [VisaMasterController::class, 'storePakistaniIata'])
        ->middleware(EnforceErpRoleScopedAccess::class)->name('travel-masters.visa-management.iata.store');
    Route::post('/master-data/travel-masters/visa-management/saudi-company', [VisaMasterController::class, 'storeSaudiCompany'])
        ->middleware(EnforceErpRoleScopedAccess::class)->name('travel-masters.visa-management.saudi.store');
    Route::post('/master-data/travel-masters/visa-management/rate', [VisaMasterController::class, 'storeRate'])
        ->middleware(EnforceErpRoleScopedAccess::class)->name('travel-masters.visa-management.rate.store');

    Route::get('/travel-masters/visa-management', function (\Illuminate\Http\Request $request) {
        return redirect()->route('travel-masters.visa-management', $request->query());
    })->middleware(EnforceErpRoleScopedAccess::class);
    Route::post('/travel-masters/visa-management/pakistani-iata', [VisaMasterController::class, 'storePakistaniIata'])
        ->middleware(EnforceErpRoleScopedAccess::class);
    Route::post('/travel-masters/visa-management/saudi-company', [VisaMasterController::class, 'storeSaudiCompany'])
        ->middleware(EnforceErpRoleScopedAccess::class);
    Route::post('/travel-masters/visa-management/rate', [VisaMasterController::class, 'storeRate'])
        ->middleware(EnforceErpRoleScopedAccess::class);

    Route::get('/system/visa-masters', [VisaMasterController::class, 'index'])
        ->middleware(EnforceErpRoleScopedAccess::class)->name('visa-masters.index');
    Route::post('/system/visa-masters/pakistani-iata', [VisaMasterController::class, 'storePakistaniIata'])
        ->middleware(EnforceErpRoleScopedAccess::class)->name('visa-masters.iata.store');
    Route::post('/system/visa-masters/saudi-company', [VisaMasterController::class, 'storeSaudiCompany'])
        ->middleware(EnforceErpRoleScopedAccess::class)->name('visa-masters.saudi.store');
    Route::post('/system/visa-masters/rate', [VisaMasterController::class, 'storeRate'])
        ->middleware(EnforceErpRoleScopedAccess::class)->name('visa-masters.rate.store');

    // ERP-11.3.129 GENERAL Client Voucher uses the established Accommodation
    // Voucher visual language while reading saved Air/Hotel native product data.
    Route::get(
        '/operations/bookings/{booking}/client-voucher-preview',
        [GeneralBookingVoucherPreviewController::class, 'show']
    )->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.client-voucher-preview');

    // GENERAL / MULTI-SERVICE Booking Review uses saved product, workflow,
    // accounting and Company Profile authorities; it creates no parallel store.
    Route::get('/operations/bookings/{booking}/review', [GeneralBookingReviewController::class, 'show'])
        ->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.review.show');
    Route::post('/operations/bookings/{booking}/review/{action}', [GeneralBookingReviewController::class, 'action'])
        ->whereNumber('booking')->where('action', 'submit|approve|reopen|notes|ready')
        ->middleware(EnforceErpRoleScopedAccess::class)->name('bookings.review.action');
    Route::get('/system/diagnostics/air-link-db/{booking}', AirLinkDbDiagnosticController::class)->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class);
    Route::get('/system/diagnostics/transport-rate-resolution/{booking}', TransportRateResolutionDiagnosticController::class)->whereNumber('booking')->middleware(EnforceErpRoleScopedAccess::class);

    // ERP-11.3.10 Chart of Accounts canonical workspace.
    // This URI intentionally does not compete with the legacy native Chart route.
    Route::get('/accounting/chart-of-accounts-workspace', [ChartOfAccountsWorkspaceController::class, 'index'])
        ->middleware(EnforceErpRoleScopedAccess::class)
        ->name('accounting.chart-of-accounts.workspace');

    // ERP-11.3.1 Chart of Accounts UX / Performance Completion
    Route::get('/accounting/chart-of-accounts/next-code', [ChartOfAccountsWorkspaceController::class, 'nextCode'])
        ->middleware($coaReadMiddleware)
        ->name('accounting.chart-of-accounts.next-code');
    Route::post('/accounting/chart-of-accounts/auto-create', [ChartOfAccountsWorkspaceController::class, 'store'])
        ->middleware($coaWriteMiddleware)
        ->name('accounting.chart-of-accounts.auto-store');

    // ERP-11.3 Payments, Receipts & Advance Adjustments Core
    Route::get('/accounting/cash-vouchers', [CashVoucherController::class, 'index'])->name('accounting.cash-vouchers.index');
    Route::get('/accounting/cash-vouchers/create', [CashVoucherController::class, 'create'])->name('accounting.cash-vouchers.create');
    Route::post('/accounting/cash-vouchers', [CashVoucherController::class, 'store'])->name('accounting.cash-vouchers.store');
    Route::get('/accounting/cash-vouchers/{voucher}', [CashVoucherController::class, 'show'])->whereNumber('voucher')->name('accounting.cash-vouchers.show');
    Route::get('/accounting/cash-vouchers/{voucher}/edit', [CashVoucherController::class, 'edit'])->whereNumber('voucher')->name('accounting.cash-vouchers.edit');
    Route::put('/accounting/cash-vouchers/{voucher}', [CashVoucherController::class, 'update'])->whereNumber('voucher')->name('accounting.cash-vouchers.update');
    Route::post('/accounting/cash-vouchers/{voucher}/workflow/{action}', [CashVoucherController::class, 'workflow'])->whereNumber('voucher')->where('action', 'submit|approve|post')->name('accounting.cash-vouchers.workflow');
    Route::post('/accounting/cash-vouchers/{voucher}/reverse', [CashVoucherController::class, 'reverse'])->whereNumber('voucher')->name('accounting.cash-vouchers.reverse');
    Route::get('/accounting/cash-vouchers/{voucher}/proof', [CashVoucherController::class, 'proof'])->whereNumber('voucher')->name('accounting.cash-vouchers.proof');
    Route::get('/accounting/cash-vouchers/{voucher}/print', [CashVoucherController::class, 'printVoucher'])->whereNumber('voucher')->name('accounting.cash-vouchers.print');

    Route::get('/accounting/advance-adjustments/create', [AdvanceAdjustmentController::class, 'create'])->name('accounting.advance-adjustments.create');
    Route::post('/accounting/advance-adjustments', [AdvanceAdjustmentController::class, 'store'])->name('accounting.advance-adjustments.store');
    Route::get('/accounting/advance-adjustments/{adjustment}', [AdvanceAdjustmentController::class, 'show'])->whereNumber('adjustment')->name('accounting.advance-adjustments.show');
    Route::get('/accounting/advance-adjustments/{adjustment}/edit', [AdvanceAdjustmentController::class, 'edit'])->whereNumber('adjustment')->name('accounting.advance-adjustments.edit');
    Route::put('/accounting/advance-adjustments/{adjustment}', [AdvanceAdjustmentController::class, 'update'])->whereNumber('adjustment')->name('accounting.advance-adjustments.update');
    Route::post('/accounting/advance-adjustments/{adjustment}/workflow/{action}', [AdvanceAdjustmentController::class, 'workflow'])->whereNumber('adjustment')->where('action', 'submit|approve|post')->name('accounting.advance-adjustments.workflow');
    Route::post('/accounting/advance-adjustments/{adjustment}/reverse', [AdvanceAdjustmentController::class, 'reverse'])->whereNumber('adjustment')->name('accounting.advance-adjustments.reverse');

    Route::get('/supplier-costing', [SupplierCostingController::class, 'index'])->name('purchase.supplier-costing.index');
    Route::get('/supplier-costing/create', [SupplierCostingController::class, 'create'])->name('purchase.supplier-costing.create');
    Route::post('/supplier-costing', [SupplierCostingController::class, 'store'])->name('purchase.supplier-costing.store');
    Route::get('/supplier-costing/{costing}', [SupplierCostingController::class, 'show'])->whereNumber('costing')->name('purchase.supplier-costing.show');
    Route::get('/supplier-costing/{costing}/edit', [SupplierCostingController::class, 'edit'])->whereNumber('costing')->name('purchase.supplier-costing.edit');
    Route::put('/supplier-costing/{costing}', [SupplierCostingController::class, 'update'])->whereNumber('costing')->name('purchase.supplier-costing.update');
    Route::post('/supplier-costing/{costing}/workflow/{action}', [SupplierCostingController::class, 'workflow'])->whereNumber('costing')->where('action','submit|approve|post')->name('purchase.supplier-costing.workflow');


    Route::get(
        '/administration/erp-user-management',
        [ErpUserManagementController::class, 'index']
    )->name('administration.erp-user-management.index');

    Route::post(
        '/administration/erp-user-management/{user}',
        [ErpUserManagementController::class, 'update']
    )->whereNumber('user')->name('administration.erp-user-management.update');

    Route::post(
        '/administration/erp-user-management/{user}/status',
        [ErpUserManagementController::class, 'status']
    )->whereNumber('user')->name('administration.erp-user-management.status');

    Route::get(
        '/system/erp-diagnostics/accounting-journal',
        [AccountingJournalDiagnosticController::class, 'index']
    )->name('system.erp-diagnostics.accounting-journal');

    Route::get(
        '/system/erp-diagnostics/customer-ledger/{customer}',
        [CustomerLedgerDiagnosticController::class, 'index']
    )->whereNumber('customer')
        ->name('system.erp-diagnostics.customer-ledger');

    Route::get(
        '/system/erp-diagnostics/sales-invoice-workflow-compare',
        [SalesInvoiceWorkflowCompareController::class, 'index']
    )->name('system.erp-diagnostics.sales-invoice-workflow-compare');

    Route::get(
        '/system/erp-repair/cash-voucher-native-journals',
        [CashVoucherNativeJournalRepairController::class, 'index']
    )->name('system.erp-repair.cash-voucher-native-journals');

    Route::post(
        '/system/erp-repair/cash-voucher-native-journals',
        [CashVoucherNativeJournalRepairController::class, 'execute']
    )->name('system.erp-repair.cash-voucher-native-journals.execute');

    Route::get(
        '/system/post-reset-financial-cleanup',
        [PostResetFinancialCleanupController::class, 'index']
    )->name('system.post-reset-financial-cleanup.index');

    Route::post(
        '/system/post-reset-financial-cleanup/backup',
        [PostResetFinancialCleanupController::class, 'backup']
    )->name('system.post-reset-financial-cleanup.backup');

    Route::post(
        '/system/post-reset-financial-cleanup/execute',
        [PostResetFinancialCleanupController::class, 'execute']
    )->name('system.post-reset-financial-cleanup.execute');

    Route::get(
        '/system/production-data-reset',
        [ProductionDataResetController::class, 'index']
    )->name('system.production-data-reset.index');

    Route::post(
        '/system/production-data-reset/backup',
        [ProductionDataResetController::class, 'backup']
    )->name('system.production-data-reset.backup');

    Route::post(
        '/system/production-data-reset/execute',
        [ProductionDataResetController::class, 'execute']
    )->name('system.production-data-reset.execute');

    Route::get('/reports/group-umrah-profitability', [GroupUmrahProfitabilityController::class, 'index'])
        ->name('reports.group-umrah-profitability.index');

    Route::get('/reports/group-umrah-profitability/{booking}', [GroupUmrahProfitabilityController::class, 'show'])
        ->whereNumber('booking')
        ->name('reports.group-umrah-profitability.show');

    // Optional safe direct entry for diagnostics/bookmarks.
    Route::get('/operations/group-package-bookings/create', [UnifiedGroupPackageBookingController::class, 'create'])
        ->name('operations.bookings.group-package-unified.create');

    Route::post('/operations/group-package-bookings', [UnifiedGroupPackageBookingController::class, 'store'])
        ->name('operations.bookings.group-package-unified.store');

    Route::get('/operations/group-package-bookings/{booking}/edit', [UnifiedGroupPackageBookingController::class, 'edit'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-package-unified.edit');

    Route::put('/operations/group-package-bookings/{booking}', [UnifiedGroupPackageBookingController::class, 'update'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-package-unified.update');

    Route::post('/operations/group-package-bookings/{booking}/commercial-amendments', [GroupUmrahCommercialAmendmentController::class, 'store'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-umrah-commercial-amendments.store');

    Route::get('/operations/group-package-bookings/{booking}/sales-invoice', GroupUmrahSalesInvoiceBridgeController::class)
        ->whereNumber('booking')
        ->name('operations.bookings.group-umrah-sales-invoice.bridge');

    Route::post('/operations/group-package-bookings/{booking}/sales-invoice/workflow/{action}', GroupUmrahSalesInvoiceWorkflowController::class)
        ->whereNumber('booking')
        ->where('action', 'submit|approve|post')
        ->name('operations.bookings.group-umrah-sales-invoice.workflow');

    Route::get('/operations/group-package-bookings/{booking}/voucher', [GroupUmrahVoucherController::class, 'show'])
        ->whereNumber('booking')
        ->name('operations.bookings.group-umrah-voucher.show');

    Route::post('/operations/group-package-bookings/{booking}/workflow/{action}', [GroupUmrahWorkflowController::class, 'transition'])
        ->whereNumber('booking')
        ->where('action', 'confirm|voucher-submit|voucher-approve|voucher-issue|reopen-editing')
        ->name('operations.bookings.group-umrah-workflow.transition');
});

/*
|--------------------------------------------------------------------------
| ERP-11.3.34 Native Reports — Runtime Presentation Hook
|--------------------------------------------------------------------------
|
| The installed base application registers Accounting ReportController routes
| after this cumulative route file. Capturing/re-registering them here is too
| early and leaves the live ERP-09.3 Reports form untouched.
|
| RouteMatched runs after Laravel has selected the final native route and before
| that route middleware executes. This is the same production-proven strategy
| used by the canonical Chart workspace and focused booking workspaces.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route = $event->route;

    if (! in_array('GET', $route->methods(), true)) {
        return;
    }

    $name = strtolower((string) $route->getName());
    $action = strtolower((string) $route->getActionName());
    $uri = strtolower(trim((string) $route->uri(), '/'));

    $isNativeReportsPage = (
        in_array($name, [
            'accounting.reports.index',
            'accounting.reports.preview',
        ], true)
        || (
            str_contains($action, 'accounting\\reportcontroller')
            && (
                str_ends_with($action, '@index')
                || str_ends_with($action, '@preview')
            )
        )
        || in_array($uri, [
            'accounting/reports',
            'accounting/reports/preview',
        ], true)
    );

    if (! $isNativeReportsPage) {
        return;
    }

    $route->middleware(PresentAccountingReportsWorkspace::class);
});

/* The installed host may already own /voucher/{voucher}. Its different route
 * parameter name creates the same URL matcher without replacing this overlay's
 * /voucher/{token} entry. Normalize every exact public-voucher matcher to the
 * same read-only controller before dispatch, and remove employee middleware. */
foreach (Route::getRoutes()->getRoutes() as $publicVoucherCandidate) {
    if (
        in_array('GET',$publicVoucherCandidate->methods(),true)
        && preg_match('#^voucher/\{[^}]+\}$#',trim((string)$publicVoucherCandidate->uri(),'/'))===1
    ) {
        $publicVoucherCandidate->setAction([
            'uses'=>GeneralBookingVoucherPreviewController::class.'@publicRoute',
            'controller'=>GeneralBookingVoucherPreviewController::class.'@publicRoute',
            'middleware'=>['web'],
        ]);
    }
}

/* One authoritative hard-lock boundary for every booking mutation. Workflow
 * transitions and the accounting bridge remain separate, explicit actions. */
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route = $event->route;
    $methods = array_map('strtoupper', $route->methods());
    if (array_diff($methods, ['GET','HEAD']) === []) return;
    $uri = strtolower(trim((string) $route->uri(), '/'));
    $isBookingWrite = preg_match('#^(?:system/erp-bookings|operations/bookings)/\{[^}]+\}(?:/|$)#', $uri) === 1;
    if (! $isBookingWrite || str_ends_with($uri, '/sales-invoice')) return;
    $route->middleware(EnforceGeneralBookingEditLock::class);
});

// Native Company Profile source is owned by the installed base application.
// Attach one presentation-only copy correction without replacing its route,
// controller, form, persistence, or authorization.
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    if (
        $event->route->uri() === 'organization/company'
        && in_array('GET', $event->route->methods(), true)
    ) {
        $event->route->middleware(PresentCompanyVoucherFooterAuthority::class);
    }
});

/*
|--------------------------------------------------------------------------
| ERP-11.3.10 Chart of Accounts — Legacy URL Compatibility Redirect
|--------------------------------------------------------------------------
|
| Do NOT compete with the native /accounting/chart-of-accounts route. Some production
| installations register that route after this cumulative route file, which
| caused the old page to win even though ERP-11.3.1 release metadata loaded.
|
| RouteMatched runs after Laravel has selected the final route but before the
| route middleware stack executes. Attach the presentation bridge there so the
| native URL, permission middleware, edit routes and account mappings remain
| authoritative while the final GET page always uses the paginated workspace.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route = $event->route;

    if (
        $route->uri() !== 'accounting/chart-of-accounts'
        || ! in_array('GET', $route->methods(), true)
    ) {
        return;
    }

    $route->middleware(PresentChartOfAccountsWorkspace::class);
});

/*
 * Attach the bridge to the ALREADY REGISTERED native New Booking GET route.
 */
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if ($registeredRoute->uri() !== 'operations/bookings/create') {
        continue;
    }

    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    $registeredRoute->middleware(InjectGroupPackageCreateEntry::class);
}





/*
|--------------------------------------------------------------------------
| ERP-11.3.148 Native Travel Masters -> Visa Management — Canonical Route Hook
|--------------------------------------------------------------------------
|
| Production Travel Masters is /master-data/travel-masters. The presentation
| middleware still identifies the native page by content, while every generated
| Visa Management / back / master-authority URL uses that canonical base path.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route = $event->route;
    if (in_array('GET', $route->methods(), true)) {
        $route->middleware(PresentVisaManagementTravelMasterLink::class);
    }
});


/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Dynamic Release Metadata
|--------------------------------------------------------------------------
|
| Normalize the existing legacy hard-coded ERP-10.28.4 shell/health labels
| without replacing the native Easy Ticket layout or health controller.
|
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    $registeredRoute->middleware(ApplyErpReleaseMetadata::class);
}


/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Native Staff / ERP User Management Links
|--------------------------------------------------------------------------
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    $registeredRoute->middleware(PresentErpUserManagementLinks::class);
}


/*
|--------------------------------------------------------------------------
| ERP-11.3 Native Accounting Navigation Links
|--------------------------------------------------------------------------
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }
    $registeredRoute->middleware(PresentCashVoucherLinks::class);
}




foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('POST', $registeredRoute->methods(), true)) continue;
    if (! str_starts_with($registeredRoute->uri(), 'operations/bookings')) continue;
    $registeredRoute->middleware(RedirectGroupUmrahDraftToUnified::class);
}


/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Booking Register / Workspace Integration
|--------------------------------------------------------------------------
|
| Keep the native Booking Register design, but present Group Umrah rows from
| unified source-of-truth tables. Direct legacy workspace opens are redirected
| to the unified editor only for Group Umrah bookings.
|
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    if (preg_match('#^operations/bookings/\\{[^}]+\\}$#', $registeredRoute->uri())) {
        $registeredRoute->middleware(RedirectGroupUmrahLegacyWorkspace::class);
    }
}


/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Legacy Voucher Redirect
|--------------------------------------------------------------------------
|
| Old /operations/bookings/{id}/travel-voucher and /voucher URLs remain valid
| for normal bookings. Unified Group Umrah bookings are redirected to the
| dedicated unified-data voucher preview.
|
*/
foreach (Route::getRoutes()->getRoutes() as $registeredRoute) {
    if (! in_array('GET', $registeredRoute->methods(), true)) {
        continue;
    }

    $uri = $registeredRoute->uri();

    if (
        preg_match('#^operations/bookings/\{[^}]+\}/travel-voucher$#', $uri)
        || preg_match('#^operations/bookings/\{[^}]+\}/voucher$#', $uri)
    ) {
        $registeredRoute->middleware(RedirectGroupUmrahLegacyVoucher::class);
    }
}


/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Group Umrah Sales Invoice Presentation — Runtime Hook
|--------------------------------------------------------------------------
|
| Native Sales Invoice routes may be registered after this ERP route file.
| RouteMatched fires after Laravel knows the final route but before that
| route's middleware stack is executed, so presentation attachment is no
| longer dependent on route-registration order.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route = $event->route;

    $name = strtolower((string) $route->getName());
    $action = strtolower((string) $route->getActionName());
    $uri = strtolower((string) $route->uri());

    $isNativeSalesInvoiceShow = (
        $name === 'sales.invoices.show'
        || (
            str_contains($action, 'salesinvoicecontroller')
            && str_ends_with($action, '@show')
        )
        || (
            preg_match('#^sales/invoices/\{[^}]+\}$#', $uri) === 1
            && in_array('GET', $route->methods(), true)
        )
    );

    if (! $isNativeSalesInvoiceShow) {
        return;
    }

    $route->middleware(
        PresentSalesInvoiceFocusedWorkspace::class
    );

    $route->middleware(
        PresentGroupUmrahSalesInvoice::class
    );

    $route->middleware(
        PresentAirTicketSalesInvoice::class
    );
});



/*
|--------------------------------------------------------------------------
| ERP-11.3.50 Booking Workspace Shell — Native Main Canvas Alignment
|--------------------------------------------------------------------------
|
| Navigation pages keep the permanent ERP sidebar:
|   Dashboard / Booking Register / New Booking product selector.
|
| Actual booking workspaces use one focused shell:
|   native booking Show/Edit, Air workspace, Group Umrah unified workspace,
|   and any native product workspace rendered on the booking route.
|
| The response presenter is deliberately a no-op on the normal New Booking
| selector. Group Umrah's create short-circuit also calls the presenter directly.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route = $event->route;

    if (! in_array('GET', $route->methods(), true)) {
        return;
    }

    $name = strtolower((string) $route->getName());
    $action = strtolower((string) $route->getActionName());
    $uri = strtolower(trim((string) $route->uri(), '/'));

    $isNativeBookingEntry = (
        in_array($name, [
            'operations.bookings.create',
            'operations.bookings.show',
            'operations.bookings.edit',
        ], true)
        || (
            str_contains($action, 'bookingcontroller')
            && (
                str_ends_with($action, '@create')
                || str_ends_with($action, '@show')
                || str_ends_with($action, '@edit')
            )
        )
        || $uri === 'operations/bookings/create'
        || preg_match('#^operations/bookings/\{[^}]+\}(?:/edit)?$#', $uri) === 1
    );

    $isUnifiedGroupWorkspace = (
        in_array($name, [
            'operations.bookings.group-package-unified.create',
            'operations.bookings.group-package-unified.edit',
        ], true)
        || $uri === 'operations/group-package-bookings/create'
        || preg_match(
            '#^operations/group-package-bookings/\{[^}]+\}/edit$#',
            $uri
        ) === 1
    );

    /*
     * ERP-11.3.46: native multi-step booking workflow routes were previously
     * outside the focus hook, which caused the permanent sidebar to reappear
     * when moving from Booking Workspace into Create Group Package.
     */
    $isNestedBookingWorkflow = (
        preg_match(
            '#^operations/bookings/\{[^}]+\}/.+$#',
            $uri
        ) === 1
        && ! str_ends_with(
            $uri,
            '/sales-invoice'
        )
    );

    if (
        ! $isNativeBookingEntry
        && ! $isUnifiedGroupWorkspace
        && ! $isNestedBookingWorkflow
    ) {
        return;
    }

    $route->middleware(PresentBookingFocusedWorkspace::class);
});


/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Sales Invoice Draft Update — Deterministic Override
|--------------------------------------------------------------------------
|
| The native route is already registered before this cumulative route file.
| Re-register the same method+URI last with the same middleware, replacing only
| its update controller action.
|
*/
$nativeSalesInvoiceUpdateRoute = Route::getRoutes()->getByName(
    'sales.invoices.update'
);

if ($nativeSalesInvoiceUpdateRoute !== null) {
    $nativeUri =
        $nativeSalesInvoiceUpdateRoute->uri();

    $nativeMethods = array_values(
        array_filter(
            $nativeSalesInvoiceUpdateRoute->methods(),
            static fn (string $method): bool =>
                strtoupper($method) !== 'HEAD'
        )
    );

    if ($nativeMethods === []) {
        $nativeMethods = ['PUT', 'PATCH'];
    }

    try {
        $nativeMiddleware =
            $nativeSalesInvoiceUpdateRoute->gatherMiddleware();
    } catch (\Throwable) {
        $nativeMiddleware = (array) (
            $nativeSalesInvoiceUpdateRoute
                ->getAction('middleware')
            ?? []
        );
    }

    $bridgeRoute = Route::match(
        $nativeMethods,
        $nativeUri,
        [
            SalesInvoiceDraftUpdateBridgeController::class,
            'update',
        ]
    )
        ->middleware($nativeMiddleware)
        ->name('sales.invoices.update');

    try {
        if ($nativeSalesInvoiceUpdateRoute->wheres !== []) {
            $bridgeRoute->where(
                $nativeSalesInvoiceUpdateRoute->wheres
            );
        }
    } catch (\Throwable) {
    }
}


/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Air Ticket Draft Invoice — Explicit Commercial Sync
|--------------------------------------------------------------------------
*/
if (isset($nativeMiddleware) && is_array($nativeMiddleware)) {
    Route::post(
        '/sales/invoices/{invoice}/sync-air-ticket-pricing',
        AirTicketInvoiceDraftSyncController::class
    )
        ->whereNumber('invoice')
        ->middleware($nativeMiddleware)
        ->name('sales.invoices.air-ticket-sync');
} else {
    Route::post(
        '/sales/invoices/{invoice}/sync-air-ticket-pricing',
        AirTicketInvoiceDraftSyncController::class
    )
        ->whereNumber('invoice')
        ->middleware(['auth'])
        ->name('sales.invoices.air-ticket-sync');
}





/*
|--------------------------------------------------------------------------
| ERP-10.31.79 Permission-Driven RBAC — Navigation + Direct Route Enforcement
|--------------------------------------------------------------------------
|
| Every non-Super-Admin user is governed by effective capability permissions.
| The same middleware controls sidebar/dashboard navigation and direct URLs,
| and enhances the native ERP-02 RBAC screen without changing its backend.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $event->route->middleware(
        EnforceErpRoleScopedAccess::class
    );
});


/*
|--------------------------------------------------------------------------
| ERP-11.3.75 Native Booking Sales Invoice — Stable Domain-Service Entry
|--------------------------------------------------------------------------
|
| Full booking-flow audit conclusion:
| the cumulative overlay does NOT ship the host SalesInvoiceController source.
| Repeated controller/route wrapping (11.3.56 -> 11.3.74) therefore depended on
| runtime route methods and response ordering and produced alternating 500/405
| behavior.
|
| ERP-11.3.75 removes those overlapping from-booking wrappers.
|
| Both the historical URL and a stable booking-scoped URL now enter one
| idempotent controller which calls the host ERP's native
| SalesInvoiceService::createFromBooking() domain operation directly.
|
*/
$nativeFromBookingRoute = Route::getRoutes()->getByName(
    'sales.invoices.from-booking'
);

$stableInvoiceMiddleware = ['auth'];

if ($nativeFromBookingRoute !== null) {
    try {
        $stableInvoiceMiddleware =
            $nativeFromBookingRoute->gatherMiddleware();
    } catch (\Throwable) {
        $stableInvoiceMiddleware = (array) (
            $nativeFromBookingRoute->getAction('middleware')
            ?? ['auth']
        );
    }

    $stableInvoiceMiddleware = array_values(
        array_filter(
            $stableInvoiceMiddleware,
            static function (mixed $middleware): bool {
                if (! is_string($middleware)) {
                    return true;
                }

                $class = strtolower(
                    trim(
                        explode(
                            ':',
                            $middleware,
                            2
                        )[0]
                    )
                );

                /*
                 * Create/open endpoint is redirect-only. Page presentation
                 * transforms and old create-recovery middleware do not belong
                 * in this write boundary.
                 */
                if (
                    str_starts_with(
                        $class,
                        'app\\http\\middleware\\present'
                    )
                    || str_contains(
                        $class,
                        'syncairticketinvoiceaftercreate'
                    )
                    || $class === strtolower(
                        ApplyErpReleaseMetadata::class
                    )
                ) {
                    return false;
                }

                return true;
            }
        )
    );
}

if (! in_array('auth', $stableInvoiceMiddleware, true)) {
    $stableInvoiceMiddleware[] = 'auth';
}

/*
 * Canonical booking-scoped endpoint used by Booking Workspace from 11.3.75.
 */
Route::match(
    ['GET', 'POST'],
    '/operations/bookings/{booking}/sales-invoice',
    StableBookingSalesInvoiceController::class
)
    ->whereNumber('booking')
    ->middleware($stableInvoiceMiddleware)
    ->name('operations.bookings.sales-invoice.stable');

/*
 * Backward compatibility for old buttons, bookmarks and direct URLs.
 * Re-register LAST so /sales/invoices/from-booking/{booking} no longer enters
 * the unstable native controller-route response path.
 */
Route::match(
    ['GET', 'POST'],
    '/sales/invoices/from-booking/{booking}',
    StableBookingSalesInvoiceController::class
)
    ->whereNumber('booking')
    ->middleware($stableInvoiceMiddleware)
    ->name('sales.invoices.from-booking');


/*
|--------------------------------------------------------------------------
| ERP-11.3.80 Sales Invoice Workflow — Native Parity Contract
|--------------------------------------------------------------------------
|
| Production comparison:
|   GENERAL invoice workflow completed through native ERP.
|   AIR ONLY invoice remained Draft while AIR-specific middleware / route
|   wrappers surrounded the exact same native workflow.
|
| Therefore ERP-11.3.80 intentionally DOES NOT register or mutate:
|   sales.invoices.submit
|   sales.invoices.approve
|   sales.invoices.post
|
| The host ERP's already-registered native routes/controllers/services remain
| authoritative for Draft -> Pending Approval -> Approved -> Posted.
|
| AIR commercial synchronization remains available as the explicit
| /sync-air-ticket-pricing Draft action and in its presentation layer. It is no
| longer coupled to, or allowed to pre-empt, the native workflow transition.
|
*/

/*
|--------------------------------------------------------------------------
| ERP-11.3.81 AIR Commercial Integrity — Native Workflow Preflight Only
|--------------------------------------------------------------------------
|
| GENERAL already proves the host-native Sales Invoice workflow works.
| AIR therefore uses the exact same native Submit / Approve / Post routes.
|
| This hook adds ONLY a commercial preflight middleware:
| - Submit: auto-sync stale Draft Air commercial lines/metadata, then pass the
|   untouched request to the native controller.
| - Approve/Post: block only a real monetary/line mismatch.
| - passenger-ticket snapshot-link count alone is non-blocking.
|
| No workflow controller replacement.
| No status/draft field mutation.
|
*/
Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route=$event->route;

    $nonGetMethods=array_values(
        array_filter(
            $route->methods(),
            static fn(string $method): bool =>
                !in_array(
                    strtoupper($method),
                    ['GET','HEAD'],
                    true
                )
        )
    );

    if ($nonGetMethods===[]) {
        return;
    }

    $name=strtolower((string)$route->getName());
    $action=strtolower((string)$route->getActionName());
    $uri=strtolower(trim((string)$route->uri(),'/'));
    $haystack=$name.' '.$action.' '.$uri;

    /*
     * Restrict to the host Sales Invoice workflow only.
     * Group Umrah's booking-scoped wrapper remains separate.
     */
    $isNativeSalesInvoice=(
        str_starts_with($uri,'sales/invoices/')
        || str_starts_with($name,'sales.invoices.')
        || str_contains($action,'salesinvoicecontroller')
    );

    if (!$isNativeSalesInvoice) {
        return;
    }

    $isWorkflow=(
        str_contains($haystack,'submit')
        || str_contains($haystack,'approv')
        || str_contains($haystack,'post')
    );

    if (!$isWorkflow) {
        return;
    }

    $route->middleware(
        EnsureAirTicketCommercialIntegrityBeforeNativeWorkflow::class
    );
});

Event::listen(RouteMatched::class, function (RouteMatched $event): void {
    $route=$event->route;
    if(!in_array('GET',$route->methods(),true)) return;
    $name=strtolower((string)$route->getName());
    $action=strtolower((string)$route->getActionName());
    $uri=strtolower(trim((string)$route->uri(),'/'));
    if(
        in_array($name,['operations.bookings.index','bookings.index'],true)
        || (str_contains($action,'bookingcontroller')&&str_ends_with($action,'@index'))
        || $uri==='operations/bookings'
    ) $route->middleware(PresentBookingRegisterInvoiceVisibility::class);
});
