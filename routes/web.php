<?php

use App\Http\Controllers\Administration\AdministrationController;
use App\Http\Controllers\Administration\ApprovalAuthorityController;
use App\Http\Controllers\Administration\AuditLogController;
use App\Http\Controllers\Administration\DepartmentController;
use App\Http\Controllers\Administration\RoleController;
use App\Http\Controllers\Administration\StaffController;
use App\Http\Controllers\Administration\UserController;
use App\Http\Controllers\Accounting\AccountMappingController;
use App\Http\Controllers\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\LedgerController;
use App\Http\Controllers\Accounting\ReportController;
use App\Http\Controllers\Account\PasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MasterData\PartyController;
use App\Http\Controllers\MasterData\AirlineController;
use App\Http\Controllers\MasterData\BookingSourceController;
use App\Http\Controllers\MasterData\HotelController;
use App\Http\Controllers\MasterData\ProductServiceController;
use App\Http\Controllers\MasterData\TravelMasterController;
use App\Http\Controllers\MasterData\TravelPartnerController;
use App\Http\Controllers\MasterData\TransportRateMasterController;
use App\Http\Controllers\Operations\BookingController;
use App\Http\Controllers\Operations\AirTicketWorkspaceController;
use App\Http\Controllers\Operations\AirTicketDraftController;
use App\Http\Controllers\Operations\BookingCostingController;
use App\Http\Controllers\Operations\BookingPassengerController;
use App\Http\Controllers\Operations\BookingServiceController;
use App\Http\Controllers\Operations\BookingServiceDetailController;
use App\Http\Controllers\Operations\HotelWorkspaceController;
use App\Http\Controllers\Operations\GroupTravelPackageController;
use App\Http\Controllers\Operations\HotelVoucherController;
use App\Http\Controllers\Operations\TravelVoucherController;
use App\Http\Controllers\Operations\BookingPreviewController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\Foundation\CurrencyRateController;
use App\Http\Controllers\Foundation\FinancialYearAutomationController;
use App\Http\Controllers\Organization\BranchController;
use App\Http\Controllers\Organization\CompanyController;
use App\Http\Controllers\Organization\CompanyVoucherFooterController;
use App\Http\Controllers\Organization\CurrencyController;
use App\Http\Controllers\Organization\FiscalYearController;
use App\Http\Controllers\Organization\NumberSequenceController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\SystemUpdateController;
use App\Http\Controllers\Sales\SalesInvoiceController;
use Illuminate\Support\Facades\Route;
use App\Http\Middleware\NormalizeAirTicketBatchPricing;
use App\Http\Middleware\NormalizeAirTicketEditPricing;
use App\Http\Middleware\InjectCompanyVoucherFooterPanel;
use App\Http\Middleware\EnsureNextFiscalYear;

Route::middleware('guest')->group(function (): void {
    Route::get('/login',[LoginController::class,'create'])->name('login');
    Route::post('/login',[LoginController::class,'store'])->name('login.store');
});

// Public read-only Travel Voucher. No login and no edit actions.
Route::get('/v/{publicCode}',[TravelVoucherController::class,'publicCompactShow'])->where('publicCode','[A-Za-z0-9]{20}')->middleware('throttle:120,1')->name('public.travel-voucher.compact');
Route::get('/voucher/{publicReference}',[TravelVoucherController::class,'publicShow'])->where('publicReference','[A-Za-z0-9-]{8,80}')->middleware('throttle:120,1')->name('public.travel-voucher.show');

Route::middleware(['auth',EnsureNextFiscalYear::class])->group(function (): void {
    Route::get('/account/password',[PasswordController::class,'edit'])->name('account.password.edit');
    Route::put('/account/password',[PasswordController::class,'update'])->name('account.password.update');
    Route::get('/',DashboardController::class)->name('dashboard');
    Route::get('/foundation',[FoundationController::class,'edit'])->name('foundation.edit');
    Route::put('/foundation',[FoundationController::class,'update'])->name('foundation.update');
    Route::get('/foundation/currency-rates',[CurrencyRateController::class,'index'])->name('foundation.currency-rates.index');
    Route::post('/foundation/currency-rates',[CurrencyRateController::class,'store'])->name('foundation.currency-rates.store');
    Route::delete('/foundation/currency-rates/{rate}',[CurrencyRateController::class,'destroy'])->name('foundation.currency-rates.destroy');
    Route::get('/foundation/financial-years',[FinancialYearAutomationController::class,'index'])->name('foundation.financial-years.index');
    Route::post('/foundation/financial-years/create-next',[FinancialYearAutomationController::class,'createNext'])->name('foundation.financial-years.create-next');

    Route::prefix('administration')->name('administration.')->group(function (): void {
        Route::get('/',[AdministrationController::class,'index'])->middleware('permission:administration.view')->name('index');
        Route::get('/staff',[StaffController::class,'index'])->middleware('permission:staff.view')->name('staff.index');
        Route::post('/staff',[StaffController::class,'store'])->middleware('permission:staff.create')->name('staff.store');
        Route::put('/staff/{staff}',[StaffController::class,'update'])->middleware('permission:staff.update')->name('staff.update');
        Route::get('/departments',[DepartmentController::class,'index'])->middleware('permission:staff.view')->name('departments.index');
        Route::post('/departments',[DepartmentController::class,'store'])->middleware('permission:staff.create')->name('departments.store');
        Route::put('/departments/{department}',[DepartmentController::class,'update'])->middleware('permission:staff.update')->name('departments.update');
        Route::get('/users',[UserController::class,'index'])->middleware('permission:users.view')->name('users.index');
        Route::post('/users',[UserController::class,'store'])->middleware('permission:users.create')->name('users.store');
        Route::put('/users/{user}',[UserController::class,'update'])->middleware('permission:users.update')->name('users.update');
        Route::post('/users/{user}/reset-password',[UserController::class,'resetPassword'])->middleware('permission:users.reset_password')->name('users.reset-password');
        Route::get('/roles',[RoleController::class,'index'])->middleware('permission:roles.view')->name('roles.index');
        Route::post('/roles',[RoleController::class,'store'])->middleware('permission:roles.manage')->name('roles.store');
        Route::put('/roles/{role}',[RoleController::class,'update'])->middleware('permission:roles.manage')->name('roles.update');
        Route::get('/approvals',[ApprovalAuthorityController::class,'index'])->middleware('permission:approvals.view')->name('approvals.index');
        Route::post('/approvals',[ApprovalAuthorityController::class,'store'])->middleware('permission:approvals.manage')->name('approvals.store');
        Route::delete('/approvals/{limit}',[ApprovalAuthorityController::class,'destroy'])->middleware('permission:approvals.manage')->name('approvals.destroy');
        Route::get('/audit',[AuditLogController::class,'index'])->middleware('permission:audit.view')->name('audit.index');
    });

    Route::prefix('master-data')->name('master-data.')->group(function (): void {
        Route::get('/parties',[PartyController::class,'index'])->middleware('permission:parties.view')->name('parties.index');
        Route::post('/parties',[PartyController::class,'store'])->middleware('permission:parties.create')->name('parties.store');
        Route::get('/parties/{party}',[PartyController::class,'show'])->middleware('permission:parties.view')->name('parties.show');
        Route::put('/parties/{party}',[PartyController::class,'update'])->middleware('permission:parties.update')->name('parties.update');
        Route::get('/travel-masters',[TravelMasterController::class,'index'])->middleware('permission:travel_masters.view')->name('travel.index');
        Route::get('/travel-masters/export/{section}',[TravelMasterController::class,'export'])->middleware('permission:travel_masters.view')->name('travel.export');
        Route::post('/travel-partners',[TravelPartnerController::class,'store'])->middleware('permission:travel_masters.manage')->name('travel-partners.store');
        Route::put('/travel-partners/{partner}',[TravelPartnerController::class,'update'])->middleware('permission:travel_masters.manage')->name('travel-partners.update');
        Route::post('/airlines',[AirlineController::class,'store'])->middleware('permission:travel_masters.manage')->name('airlines.store');
        Route::put('/airlines/{airline}',[AirlineController::class,'update'])->middleware('permission:travel_masters.manage')->name('airlines.update');
        Route::post('/booking-sources',[BookingSourceController::class,'store'])->middleware('permission:travel_masters.manage')->name('booking-sources.store');
        Route::put('/booking-sources/{bookingSource}',[BookingSourceController::class,'update'])->middleware('permission:travel_masters.manage')->name('booking-sources.update');
        Route::post('/hotels',[HotelController::class,'store'])->middleware('permission:travel_masters.manage')->name('hotels.store');
        Route::put('/hotels/{hotel}',[HotelController::class,'update'])->middleware('permission:travel_masters.manage')->name('hotels.update');
        Route::post('/transport-vehicle-types',[TransportRateMasterController::class,'storeVehicleType'])->middleware('permission:travel_masters.manage')->name('transport-vehicle-types.store');
        Route::put('/transport-vehicle-types/{vehicleType}',[TransportRateMasterController::class,'updateVehicleType'])->middleware('permission:travel_masters.manage')->name('transport-vehicle-types.update');
        Route::post('/transport-vehicle-types/{vehicleType}/toggle',[TransportRateMasterController::class,'toggleVehicleType'])->middleware('permission:travel_masters.manage')->name('transport-vehicle-types.toggle');
        Route::post('/transport-routes',[TransportRateMasterController::class,'storeRoute'])->middleware('permission:travel_masters.manage')->name('transport-routes.store');
        Route::put('/transport-routes/{transportRoute}',[TransportRateMasterController::class,'updateRoute'])->middleware('permission:travel_masters.manage')->name('transport-routes.update');
        Route::post('/transport-routes/{transportRoute}/toggle',[TransportRateMasterController::class,'toggleRoute'])->middleware('permission:travel_masters.manage')->name('transport-routes.toggle');
        Route::post('/transport-rate-cards',[TransportRateMasterController::class,'storeRateCard'])->middleware('permission:travel_masters.manage')->name('transport-rate-cards.store');
        Route::post('/transport-rate-cards/{rateCard}/duplicate',[TransportRateMasterController::class,'duplicateRateCard'])->middleware('permission:travel_masters.manage')->name('transport-rate-cards.duplicate');
        Route::put('/transport-rate-cards/{rateCard}',[TransportRateMasterController::class,'updateRateCard'])->middleware('permission:travel_masters.manage')->name('transport-rate-cards.update');
        Route::post('/transport-rate-cards/{rateCard}/bulk-adjust',[TransportRateMasterController::class,'bulkAdjust'])->middleware('permission:travel_masters.manage')->name('transport-rate-cards.bulk-adjust');
        Route::post('/transport-rate-cards/{rateCard}/activate',[TransportRateMasterController::class,'activateRateCard'])->middleware('permission:travel_masters.manage')->name('transport-rate-cards.activate');
        Route::post('/transport-rate-cards/{rateCard}/deactivate',[TransportRateMasterController::class,'deactivateRateCard'])->middleware('permission:travel_masters.manage')->name('transport-rate-cards.deactivate');
        Route::delete('/transport-rate-cards/{rateCard}/draft',[TransportRateMasterController::class,'destroyDraft'])->middleware('permission:travel_masters.manage')->name('transport-rate-cards.destroy-draft');
        Route::post('/transport-rates',[TransportRateMasterController::class,'storeRate'])->middleware('permission:travel_masters.manage')->name('transport-rates.store');
        Route::post('/products-services',[ProductServiceController::class,'store'])->middleware('permission:products.manage')->name('products.store');
        Route::put('/products-services/{productService}',[ProductServiceController::class,'update'])->middleware('permission:products.manage')->name('products.update');
    });


    Route::prefix('operations')->name('operations.')->group(function (): void {
        Route::get('/bookings',[BookingController::class,'index'])->middleware('permission:bookings.view')->name('bookings.index');
        Route::get('/bookings/create',[BookingController::class,'create'])->middleware('permission:bookings.create')->name('bookings.create');
        Route::post('/bookings',[BookingController::class,'store'])->middleware('permission:bookings.create')->name('bookings.store');
        Route::get('/bookings/{booking}',[BookingController::class,'show'])->middleware('permission:bookings.view')->name('bookings.show');
        Route::get('/bookings/{booking}/diagnostic',[BookingController::class,'diagnostic'])->middleware('permission:bookings.view')->name('bookings.diagnostic');
        Route::get('/bookings/{booking}/preview',[BookingPreviewController::class,'show'])->middleware('permission:bookings.view')->name('bookings.preview');
        Route::get('/bookings/{booking}/hotel-voucher',[HotelVoucherController::class,'show'])->middleware('permission:bookings.view')->name('bookings.hotel-voucher');
        Route::get('/bookings/{booking}/services/{service}/hotel-voucher',[HotelVoucherController::class,'stay'])->middleware('permission:bookings.view')->name('bookings.services.hotel-voucher');
        Route::get('/bookings/{booking}/group-package',[GroupTravelPackageController::class,'edit'])->middleware('permission:bookings.view')->name('bookings.group-package.edit');
        Route::put('/bookings/{booking}/group-package',[GroupTravelPackageController::class,'update'])->middleware('permission:booking_services.manage')->name('bookings.group-package.update');
        Route::get('/bookings/{booking}/travel-voucher',[TravelVoucherController::class,'show'])->middleware('permission:bookings.view')->name('bookings.travel-voucher.show');
        Route::get('/bookings/{booking}/travel-voucher/diagnostic',[TravelVoucherController::class,'diagnostic'])->middleware('permission:bookings.view')->name('bookings.travel-voucher.diagnostic');
        Route::get('/bookings/{booking}/travel-voucher/edit',[TravelVoucherController::class,'edit'])->middleware('permission:bookings.view')->name('bookings.travel-voucher.edit');
        Route::put('/bookings/{booking}/travel-voucher',[TravelVoucherController::class,'update'])->middleware('permission:service_details.manage')->name('bookings.travel-voucher.update');
        Route::post('/bookings/{booking}/travel-voucher/issue',[TravelVoucherController::class,'issue'])->middleware('permission:service_details.manage')->name('bookings.travel-voucher.issue');
        Route::get('/bookings/{booking}/travel-voucher/revisions/{revision}',[TravelVoucherController::class,'revision'])->whereNumber('revision')->middleware('permission:bookings.view')->name('bookings.travel-voucher.revision');
        Route::post('/bookings/{booking}/travel-voucher/void',[TravelVoucherController::class,'void'])->middleware('permission:service_details.manage')->name('bookings.travel-voucher.void');
        Route::post('/bookings/{booking}/travel-voucher/items',[TravelVoucherController::class,'storeItem'])->middleware('permission:service_details.manage')->name('bookings.travel-voucher.items.store');
        Route::post('/bookings/{booking}/travel-voucher/transport-companies',[TravelVoucherController::class,'storeTransportCompany'])->middleware('permission:service_details.manage')->name('bookings.travel-voucher.transport-companies.store');
        Route::put('/bookings/{booking}/travel-voucher/transport-companies/{partner}',[TravelVoucherController::class,'updateTransportCompany'])->middleware('permission:service_details.manage')->name('bookings.travel-voucher.transport-companies.update');
        Route::delete('/bookings/{booking}/travel-voucher/items/{item}',[TravelVoucherController::class,'removeItem'])->middleware('permission:service_details.manage')->name('bookings.travel-voucher.items.remove');
        Route::get('/costing',[BookingCostingController::class,'index'])->middleware('permission:costing.view')->name('costing.index');
        Route::get('/bookings/{booking}/costing',[BookingCostingController::class,'show'])->middleware('permission:costing.view')->name('bookings.costing.show');
        Route::post('/bookings/{booking}/costing',[BookingCostingController::class,'store'])->middleware('permission:costing.manage')->name('bookings.costing.store');
        Route::put('/bookings/{booking}/costing/{allocation}',[BookingCostingController::class,'update'])->middleware('permission:costing.manage')->name('bookings.costing.update');
        Route::post('/bookings/{booking}/costing/{allocation}/confirm',[BookingCostingController::class,'confirm'])->middleware('permission:costing.confirm')->name('bookings.costing.confirm');
        Route::post('/bookings/{booking}/costing/{allocation}/cancel',[BookingCostingController::class,'cancel'])->middleware('permission:costing.manage')->name('bookings.costing.cancel');
        Route::put('/bookings/{booking}',[BookingController::class,'update'])->middleware('permission:bookings.update')->name('bookings.update');
        Route::post('/bookings/{booking}/submit',[BookingController::class,'submit'])->middleware('permission:bookings.submit')->name('bookings.submit');
        Route::post('/bookings/{booking}/confirm',[BookingController::class,'confirm'])->middleware('permission:bookings.confirm')->name('bookings.confirm');
        Route::post('/bookings/{booking}/reopen',[BookingController::class,'reopen'])->middleware('permission:bookings.confirm')->name('bookings.reopen');
        Route::post('/bookings/{booking}/cancel',[BookingController::class,'cancel'])->middleware('permission:bookings.cancel')->name('bookings.cancel');
        Route::get('/bookings/{booking}/passengers/search',[BookingPassengerController::class,'searchProfiles'])->middleware('permission:passengers.manage')->name('bookings.passengers.search');
        Route::post('/bookings/{booking}/passengers/from-profile',[BookingPassengerController::class,'attachProfiles'])->middleware('permission:passengers.manage')->name('bookings.passengers.attach-profile');
        Route::post('/bookings/{booking}/passengers',[BookingPassengerController::class,'store'])->middleware('permission:passengers.manage')->name('bookings.passengers.store');
        Route::put('/bookings/{booking}/passengers/{passenger}',[BookingPassengerController::class,'update'])->middleware('permission:passengers.manage')->name('bookings.passengers.update');
        Route::put('/bookings/{booking}/passengers/{passenger}/fare-type',[BookingPassengerController::class,'updateFareType'])->middleware('permission:passengers.manage')->name('bookings.passengers.fare-type');
        Route::post('/bookings/{booking}/passengers/{passenger}/deactivate',[BookingPassengerController::class,'deactivate'])->middleware('permission:passengers.manage')->name('bookings.passengers.deactivate');
        Route::post('/bookings/{booking}/services',[BookingServiceController::class,'store'])->middleware('permission:booking_services.manage')->name('bookings.services.store');
        Route::put('/bookings/{booking}/services/{service}',[BookingServiceController::class,'update'])->middleware('permission:booking_services.manage')->name('bookings.services.update');
        Route::post('/bookings/{booking}/services/{service}/cancel',[BookingServiceController::class,'cancel'])->middleware('permission:booking_services.manage')->name('bookings.services.cancel');
        Route::get('/bookings/{booking}/services/{service}/details',[BookingServiceDetailController::class,'edit'])->middleware('permission:service_details.view')->name('bookings.services.details.edit');
        Route::put('/bookings/{booking}/services/{service}/details',[BookingServiceDetailController::class,'update'])->middleware('permission:service_details.manage')->name('bookings.services.details.update');
        Route::put('/bookings/{booking}/services/{service}/hotel-workspace',[HotelWorkspaceController::class,'update'])->middleware('permission:service_details.manage')->name('bookings.services.hotel-workspace.update');
        Route::post('/bookings/{booking}/services/{service}/air-tickets/batch',[AirTicketWorkspaceController::class,'batchStore'])->middleware(['permission:ticket_commercial.manage', NormalizeAirTicketBatchPricing::class])->name('bookings.air-tickets.batch');
        Route::post('/bookings/{booking}/services/{service}/air-tickets/draft',[AirTicketDraftController::class,'store'])->middleware('permission:ticket_commercial.manage')->name('bookings.air-tickets.draft.store');
        Route::delete('/bookings/{booking}/services/{service}/air-tickets/draft',[AirTicketDraftController::class,'destroy'])->middleware('permission:ticket_commercial.manage')->name('bookings.air-tickets.draft.destroy');
        // ERP10.27.2 compatibility alias: older compiled Booking Workspace views may still reference
        // operations.bookings.air-tickets.bulk-update until the Blade/view cache is purged.
        Route::put('/bookings/{booking}/services/{service}/air-tickets/bulk-update',[AirTicketWorkspaceController::class,'bulkNumbers'])->middleware('permission:ticket_commercial.manage')->name('bookings.air-tickets.bulk-update');
        Route::put('/bookings/{booking}/services/{service}/air-tickets/numbers',[AirTicketWorkspaceController::class,'bulkNumbers'])->middleware('permission:ticket_commercial.manage')->name('bookings.air-tickets.bulk-numbers');
        Route::put('/bookings/{booking}/services/{service}/air-tickets/{ticket}',[AirTicketWorkspaceController::class,'updateTicket'])->middleware(['permission:ticket_commercial.manage', NormalizeAirTicketEditPricing::class])->name('bookings.air-tickets.update');
    });

    Route::prefix('sales')->name('sales.')->group(function (): void {
        Route::get('/invoices',[SalesInvoiceController::class,'index'])->middleware('permission:sales_invoices.view')->name('invoices.index');
        Route::post('/invoices/from-booking/{booking}',[SalesInvoiceController::class,'fromBooking'])->middleware('permission:sales_invoices.create')->name('invoices.from-booking');
        Route::get('/invoices/{invoice}',[SalesInvoiceController::class,'show'])->middleware('permission:sales_invoices.view')->name('invoices.show');
        Route::put('/invoices/{invoice}',[SalesInvoiceController::class,'update'])->middleware('permission:sales_invoices.edit')->name('invoices.update');
        Route::post('/invoices/{invoice}/submit',[SalesInvoiceController::class,'submit'])->middleware('permission:sales_invoices.create')->name('invoices.submit');
        Route::post('/invoices/{invoice}/approve',[SalesInvoiceController::class,'approve'])->middleware('permission:sales_invoices.approve')->name('invoices.approve');
        Route::post('/invoices/{invoice}/post',[SalesInvoiceController::class,'post'])->middleware('permission:sales_invoices.post')->name('invoices.post');
        Route::post('/invoices/{invoice}/cancel',[SalesInvoiceController::class,'cancel'])->middleware('permission:sales_invoices.edit')->name('invoices.cancel');
        Route::get('/invoices/{invoice}/print',[SalesInvoiceController::class,'print'])->middleware('permission:sales_invoices.view')->name('invoices.print');
    });

    Route::prefix('accounting')->name('accounting.')->group(function (): void {
        Route::get('/chart-of-accounts',[ChartOfAccountsController::class,'index'])->middleware('permission:coa.view')->name('accounts.index');
        Route::post('/chart-of-accounts',[ChartOfAccountsController::class,'store'])->middleware('permission:coa.manage')->name('accounts.store');
        Route::put('/chart-of-accounts/{account}',[ChartOfAccountsController::class,'update'])->middleware('permission:coa.manage')->name('accounts.update');
        Route::get('/account-mappings',[AccountMappingController::class,'index'])->middleware('permission:coa.view')->name('mappings.index');
        Route::put('/account-mappings/{mapping}',[AccountMappingController::class,'update'])->middleware('permission:coa.manage')->name('mappings.update');
        Route::get('/ledgers',[LedgerController::class,'index'])->middleware('permission:journals.view')->name('ledgers.index');
        Route::get('/ledgers/accounts/{account}',[LedgerController::class,'account'])->middleware('permission:journals.view')->name('ledgers.account');
        Route::get('/ledgers/customers/{party}',[LedgerController::class,'customer'])->middleware('permission:journals.view')->name('ledgers.customer');
        Route::get('/trial-balance',[LedgerController::class,'trialBalance'])->middleware('permission:journals.view')->name('trial-balance');
        Route::get('/reports',[ReportController::class,'index'])->middleware('permission:journals.view')->name('reports.index');
        Route::get('/reports/preview',[ReportController::class,'preview'])->middleware('permission:journals.view')->name('reports.preview');
        Route::get('/reports/print',[ReportController::class,'print'])->middleware('permission:journals.view')->name('reports.print');
        Route::get('/reports/pdf',[ReportController::class,'pdf'])->middleware('permission:journals.view')->name('reports.pdf');
        Route::get('/reports/excel',[ReportController::class,'excel'])->middleware('permission:journals.view')->name('reports.excel');
        Route::get('/journals',[JournalController::class,'index'])->middleware('permission:journals.view')->name('journals.index');
        Route::get('/journals/create',[JournalController::class,'create'])->middleware('permission:journals.create')->name('journals.create');
        Route::post('/journals',[JournalController::class,'store'])->middleware('permission:journals.create')->name('journals.store');
        Route::get('/journals/{journal}',[JournalController::class,'show'])->middleware('permission:journals.view')->name('journals.show');
        Route::get('/journals/{journal}/edit',[JournalController::class,'edit'])->middleware('permission:journals.create')->name('journals.edit');
        Route::put('/journals/{journal}',[JournalController::class,'update'])->middleware('permission:journals.create')->name('journals.update');
        Route::post('/journals/{journal}/submit',[JournalController::class,'submit'])->middleware('permission:journals.create')->name('journals.submit');
        Route::post('/journals/{journal}/approve',[JournalController::class,'approve'])->middleware('permission:journals.approve')->name('journals.approve');
        Route::post('/journals/{journal}/post',[JournalController::class,'post'])->middleware('permission:journals.post')->name('journals.post');
        Route::post('/journals/{journal}/reverse',[JournalController::class,'reverse'])->middleware('permission:journals.reverse')->name('journals.reverse');
    });

    Route::prefix('organization')->name('organization.')->group(function (): void {
        Route::get('/',[OrganizationController::class,'index'])->middleware('permission:organization.view')->name('index');
        Route::get('/company',[CompanyController::class,'edit'])->middleware(['permission:organization.company.view',InjectCompanyVoucherFooterPanel::class])->name('company.edit');
        Route::put('/company',[CompanyController::class,'update'])->middleware('permission:organization.company.manage')->name('company.update');
        Route::put('/company/voucher-footer',[CompanyVoucherFooterController::class,'update'])->middleware('permission:organization.company.manage')->name('company.voucher-footer');
        Route::get('/branches',[BranchController::class,'index'])->middleware('permission:organization.branches.view')->name('branches.index');
        Route::post('/branches',[BranchController::class,'store'])->middleware('permission:organization.branches.manage')->name('branches.store');
        Route::put('/branches/{branch}',[BranchController::class,'update'])->middleware('permission:organization.branches.manage')->name('branches.update');
        Route::get('/currencies',[CurrencyController::class,'index'])->middleware('permission:organization.currencies.view')->name('currencies.index');
        Route::post('/currencies',[CurrencyController::class,'store'])->middleware('permission:organization.currencies.manage')->name('currencies.store');
        Route::put('/currencies/{currency}',[CurrencyController::class,'update'])->middleware('permission:organization.currencies.manage')->name('currencies.update');
        Route::get('/fiscal-years',[FiscalYearController::class,'index'])->middleware('permission:organization.fiscal.view')->name('fiscal-years.index');
        Route::post('/fiscal-years',[FiscalYearController::class,'store'])->middleware('permission:organization.fiscal.manage')->name('fiscal-years.store');
        Route::post('/periods/{period}/lock',[FiscalYearController::class,'lockPeriod'])->middleware('permission:organization.period.lock')->name('periods.lock');
        Route::post('/periods/{period}/unlock',[FiscalYearController::class,'unlockPeriod'])->middleware('permission:organization.period.reopen')->name('periods.unlock');
        Route::post('/fiscal-years/{fiscalYear}/close',[FiscalYearController::class,'close'])->middleware('permission:organization.fiscal.manage')->name('fiscal-years.close');
        Route::post('/fiscal-years/{fiscalYear}/reopen',[FiscalYearController::class,'reopen'])->middleware('permission:organization.fiscal.manage')->name('fiscal-years.reopen');
        Route::get('/number-sequences',[NumberSequenceController::class,'index'])->middleware('permission:organization.sequences.view')->name('sequences.index');
        Route::put('/number-sequences/{sequence}',[NumberSequenceController::class,'update'])->middleware('permission:organization.sequences.manage')->name('sequences.update');
    });

    Route::get('/system/update',[SystemUpdateController::class,'index'])->name('system.update');
    Route::post('/system/update/migrate',[SystemUpdateController::class,'migrate'])->name('system.update.migrate');
    Route::post('/system/update/clear-cache',[SystemUpdateController::class,'clearCache'])->name('system.update.clear-cache');
    Route::post('/logout',[LoginController::class,'destroy'])->name('logout');
});

// ERP-10.28.6 additive operational routes
require __DIR__.'/erp10286.php';
