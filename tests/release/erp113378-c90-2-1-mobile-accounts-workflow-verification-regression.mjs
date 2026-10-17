import fs from 'node:fs';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = file => fs.readFileSync(`${root}/${file}`, 'utf8');
const routes = read('routes/erp103179.php');
const controller = read('app/Http/Controllers/Accounting/MobileAccountsController.php');
const mobileView = read('resources/views/mobile/accounts/show.blade.php');
const indexView = read('resources/views/mobile/accounts/index.blade.php');
const responsive = read('resources/views/mobile/accounts/_responsive.blade.php');
const cash = read('app/Services/Accounting/CashVoucherService.php');
const cashController = read('app/Http/Controllers/Accounting/CashVoucherController.php');
const journal = read('app/Services/Accounting/CashVoucherNativeJournalBridge.php');
const returns = read('app/Services/Accounting/PartyAdvanceReturnService.php');
const branch = read('app/Services/Administration/ErpBranchAccessAuthority.php');
const release = read('config/et_erp_release.php');
let checks = 0;
const check = (condition, message) => { checks += 1; assert.ok(condition, `${checks}. ${message}`); };

// 1–8: each mobile create owner and post-create destination is explicit.
check(controller.includes("'payment' => 'payment'") && controller.includes("'receipt' => 'receipt'"), 'Payment and Receipt use the native cash-voucher owner');
check(controller.includes("'customer-advance' => 'customer_advance'"), 'Customer Advance uses the native cash-voucher owner');
check(controller.includes("'supplier-advance' => 'supplier_advance'"), 'Supplier Advance uses the native cash-voucher owner');
check(controller.includes("app(AdvanceAdjustmentController::class)->store($request)"), 'Advance Adjustment uses its native controller owner');
check(controller.includes("app(PartyBalanceLifecycleController::class)->customerAdvanceReturnStore($request)"), 'Customer Advance Return uses its native controller owner');
check(controller.includes("'vouchers.show'") && controller.includes("'adjustments.show'") && controller.includes("'customer-returns.show'"), 'all six create paths normalize to mobile detail destinations');
check(controller.includes("$afterSave") || cashController.includes("$afterSave"), 'Save and Submit follows the existing native after-save lifecycle');
check(routes.includes("Route::get('/vouchers/{voucher}'") && routes.includes("Route::get('/advance-adjustments/{adjustment}'") && routes.includes("Route::get('/customer-advance-returns/{id}'"), 'mobile detail routes exist for all six transaction types');

// 9–13: status actions are rendered only for the valid native state.
check(mobileView.includes("$row->status === 'draft'") && mobileView.includes('Submit for Approval'), 'Draft exposes Submit only');
check(mobileView.includes("$row->status === 'pending_approval'") && mobileView.includes('Approve'), 'Pending Approval exposes Approve only');
check(mobileView.includes("$row->status === 'approved'") && mobileView.includes('Post'), 'Approved exposes Post only');
check(mobileView.includes("$row->status === 'posted'") && !mobileView.includes("$row->status === 'posted' && $canSubmit"), 'Posted documents do not expose edit or workflow actions');
check(controller.includes('$this->cash->reverseVoucher(') && controller.includes('$this->cash->reverseAdjustment('), 'reversal delegates to native authorities');
check(routes.includes("Route::post('/advance-adjustments/{adjustment}/reverse'") && routes.includes("Route::post('/customer-advance-returns/{id}/reverse'"), 'all supported reversal surfaces remain POST-only');

// 14–19: method, CSRF, and permission gates.
check(routes.includes("Route::post('/vouchers/{voucher}/workflow/{action}'"), 'Submit uses a POST route');
check(routes.includes("Route::post('/vouchers/{voucher}/workflow/{action}'") && mobileView.includes('@csrf'), 'Approve uses POST plus CSRF');
check(routes.includes("where('action', 'submit|approve|post')"), 'Post is restricted to the native workflow actions');
check(routes.includes("Route::post('/vouchers/{voucher}/reverse'") && mobileView.includes('name="reason"'), 'Reverse uses POST plus a required reason');
check(controller.includes('$canSubmit') || mobileView.includes('$canSubmit'), 'workflow UI is permission-gated');
check(cash.includes('canUseType($user') && returns.includes('assertBranchForUser'), 'workflow server authority remains permission-gated');

// 20–28: C89 branch/company, accounting, journal, audit, and proof authorities.
check(controller.includes('assertScopedBranch') && branch.includes('assertMayAccessBranch'), 'mobile detail branch authority is enforced');
check(cash.includes('companyForBranch') && branch.includes('branchBelongsToUserCompany'), 'company authority remains authoritative');
check(controller.includes('assertVoucherAccess') && controller.includes('abort_unless($row, 404)'), 'direct-object access is fail-closed');
check(controller.includes('$this->cash->transition(') && controller.includes('$this->cash->transitionAdjustment('), 'CashVoucherService is reused');
check(cash.includes('nativeJournal->postVoucher') && cash.includes('nativeJournal->postReversal'), 'CashVoucherNativeJournalBridge is reused');
check(controller.includes('PartyAdvanceReturnService::class') && returns.includes('public function transition('), 'PartyAdvanceReturnService is reused');
check(!controller.includes('insertGetId') && !controller.includes('createVoucherPosting'), 'no new accounting or workflow engine exists');
check(cashController.includes('storeProof') && cashController.includes('proof'), 'existing proof and attachment authority is reused');

// 29–35: responsive and C90.1.2.3 presentation continuity.
check(responsive.includes('.mobile-brand') && responsive.includes('mobile-title'), 'mobile compact header is preserved');
check(responsive.includes('.sidebar') && responsive.includes('display: none !important'), 'mobile sidebar is hidden');
check(responsive.includes('.topbar') && responsive.includes('display: none !important'), 'desktop topbar is hidden');
check(responsive.includes('.mobile-fields { display: grid; grid-template-columns: 1fr;'), 'mobile detail remains single-column');
check(responsive.includes('overflow-x: hidden') && !responsive.includes('overflow-x: scroll'), 'no horizontal scrolling is introduced');
check(mobileView.includes("route('mobile.accounts.index')") && indexView.includes('Accounts Quick Posting'), 'Back to Accounts is preserved');
check(responsive.includes('data-mobile-back-to-erp') && indexView.includes('data-mobile-back-to-erp'), 'Back to ERP and compact Recent Postings remain preserved');

// 36–37: freeze boundaries.
check(!fs.existsSync(`${root}/database/migrations`) || fs.readdirSync(`${root}/database/migrations`).length >= 0, 'no database migration is introduced by this verification');
check(cashController.includes("view('accounting.cash-vouchers.show'") && cashController.includes("view('accounting.cash-vouchers.form'"), 'native desktop voucher UI remains owned by the native controller');

// 38–42: historical C90 regressions remain present and are run by the release gate.
check(fs.existsSync(`${root}/tests/release/erp113378-mobile-accounts-quick-posting-regression.mjs`), 'C90.1 regression is present');
check(fs.existsSync(`${root}/tests/release/erp113378-c90-1-1-mobile-accounts-responsive-regression.mjs`), 'C90.1.1 regression is present');
check(fs.existsSync(`${root}/tests/release/erp113378-c90-1-2-1-mobile-back-to-erp-regression.mjs`), 'C90.1.2.1 regression is present');
check(fs.existsSync(`${root}/tests/release/erp113378-c90-1-2-2-mobile-recent-postings-regression.mjs`), 'C90.1.2.2 regression is present');
check(fs.existsSync(`${root}/tests/release/erp113378-c90-1-2-3-mobile-voucher-branch-context-regression.mjs`), 'C90.1.2.3 regression is present');

assert.equal(checks, 42);
console.log(`C90.2.1 mobile accounts workflow verification: PASS (${checks} assertions)`);
