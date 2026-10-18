import fs from 'node:fs';

const read = (file) => fs.readFileSync(file, 'utf8');
const mobile = read('app/Http/Controllers/Accounting/MobileAccountsController.php');
const native = read('app/Http/Controllers/Accounting/CashVoucherController.php');
const service = read('app/Services/Accounting/CashVoucherService.php');
const routes = read('routes/erp103179.php');
const release = read('config/et_erp_release.php');
let assertions = 0;
const ok = (condition, message) => {
  assertions += 1;
  if (!condition) {
    console.error(`not ok ${assertions} - ${message}`);
    process.exitCode = 1;
  } else {
    console.log(`ok ${assertions} - ${message}`);
  }
};

ok(!mobile.includes("'/cash-vouchers/(\\d+)/?(?:\\?.*)?$/'"), 'invalid historical voucher regex is absent');
ok(mobile.includes('parse_url($url, PHP_URL_PATH)'), 'redirect parsing separates the URL path');
ok(mobile.includes("'#/cash-vouchers/(\\d+)/?$#'"), 'voucher regex uses a non-conflicting delimiter');
ok(mobile.includes("'#/advance-adjustments/(\\d+)/?$#'"), 'adjustment regex uses a non-conflicting delimiter');
ok(mobile.includes("'#/customer-advance-returns/(\\d+)/?$#'"), 'return regex uses a non-conflicting delimiter');
ok(mobile.includes("preg_match($patterns[$kind][0], $path, $m)"), 'matching is performed against the parsed path');
ok(mobile.includes("if (! is_string($path)) return $response;"), 'unexpected URL paths fail safely');

const resolve = (url, pattern) => {
  const path = new URL(url, 'https://erp.test').pathname;
  const match = path.match(pattern);
  return match?.[1] ?? null;
};
ok(resolve('/accounting/cash-vouchers/123', /\/cash-vouchers\/(\d+)\/?$/) === '123', 'cash voucher path resolves');
ok(resolve('/accounting/cash-vouchers/123/', /\/cash-vouchers\/(\d+)\/?$/) === '123', 'cash voucher trailing slash resolves');
ok(resolve('/accounting/cash-vouchers/123?x=1', /\/cash-vouchers\/(\d+)\/?$/) === '123', 'cash voucher query string is ignored');
ok(resolve('https://erp.test/accounting/cash-vouchers/123?x=1', /\/cash-vouchers\/(\d+)\/?$/) === '123', 'absolute cash voucher URL resolves');
ok(resolve('/accounting/cash-vouchers/not-an-id', /\/cash-vouchers\/(\d+)\/?$/) === null, 'malformed voucher URL does not match');
ok(resolve('/accounting/advance-adjustments/45/', /\/advance-adjustments\/(\d+)\/?$/) === '45', 'adjustment path resolves');
ok(resolve('/accounting/customer-advance-returns/67?x=1', /\/customer-advance-returns\/(\d+)\/?$/) === '67', 'customer return path resolves');

ok((mobile.match(/mobileRedirect\(/g) || []).length === 4, 'native store wrappers remain single-call paths');
ok(mobile.includes("->store($request), 'voucher'"), 'payment and advance stores use the voucher redirect');
ok(mobile.includes("'customer-advance' => 'customer_advance'") && mobile.includes("'supplier-advance' => 'supplier_advance'"), 'customer and supplier advance types remain mapped');
ok(routes.includes("Route::post('/supplier-advance'"), 'supplier advance route remains present');
ok(native.includes("'status' => 'draft'"), 'native voucher creation remains Draft');
ok(native.includes("$this->service->transition($id, 'submit'"), 'native Submit transition remains delegated');
ok(service.includes("'submit' => ['draft', 'pending_approval']"), 'Submit remains draft to pending approval');
ok(service.includes("'post' => ['approved', 'posted']"), 'Post remains a separate workflow transition');
ok(!mobile.includes('CashVoucherService::') || !mobile.includes('transition('), 'mobile corrective adds no accounting workflow logic');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C90.2.2'"), 'release asset identity is C90.2.2');
ok(release.includes("'corrective_build' => 'C90.2.2'"), 'corrective build identity is C90.2.2');
ok(release.includes("'corrective_name' => 'Mobile Accounts Post Save Redirect Correction'"), 'corrective name is correct');

console.log(`C90.2.2 redirect regression: ${assertions} assertions`);
