import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const routes = read('routes/erp103179.php');
const controller = read('app/Http/Controllers/Accounting/MobileReceiptCaptureController.php');
const service = read('app/Services/Accounting/ReceiptExtractionService.php');
const provider = read('app/Services/Accounting/OpenAiReceiptExtractionProvider.php');
const form = read('resources/views/mobile/accounts/form.blade.php');
const release = read('config/et_erp_release.php');

let assertions = 0;
const ok = (condition, message) => { assertions++; assert.ok(condition, message); };

ok(routes.includes("Route::post('/receipt-capture/preview'"), 'preview route exists');
ok(routes.includes("auth', C89ManagedBusinessRoute::class"), 'managed route middleware retained');
ok(!routes.includes("Route::get('/receipt-capture/preview'"), 'preview is not GET');
ok(controller.includes("'payment_proof' => ['required', 'file'"), 'uploaded file required');
ok(controller.includes("mimes:pdf,jpg,jpeg,png,webp"), 'MIME allowlist retained');
ok(controller.includes("config('cash_vouchers.proof_max_kb', 5120)"), '5MB cap retained');
ok(provider.includes('OPENAI_API_KEY') || provider.includes("config('services.receipt_ai.key'"), 'secret is server-side');
ok(!form.includes('OPENAI_API_KEY'), 'secret absent from Blade');
ok(!form.includes('api.openai.com'), 'provider not called by browser');
ok(provider.includes("'type' => 'json_schema'"), 'structured output requested');
ok(provider.includes("'PAYMENT','RECEIPT','UNKNOWN'"), 'voucher suggestion is bounded');
ok(service.includes("$value === null ? 0 : 0.5"), 'missing values remain unresolved');
ok(service.includes("$value = preg_replace('/\\D+/', '', (string) $value)"), 'account digits sanitized');
ok(service.includes('partyCandidates'), 'local party candidates generated');
ok(service.includes('partyOptions'), 'native party authority used');
ok(service.includes('cashBankAccounts'), 'native account authority used');
ok(!service.includes('insertGetId') && !service.includes('DB::'), 'preview service has no database write');
for (const forbidden of ['CashVoucherController::store', 'transition(', 'createVoucherPosting', 'postVoucher', 'reverseVoucher']) ok(!controller.includes(forbidden) && !service.includes(forbidden), `no mutation call: ${forbidden}`);
ok(form.includes('Scan with AI'), 'scan control exists');
ok(form.includes('credentials: \'same-origin\''), 'same-origin request');
ok(form.includes('No voucher was saved'), 'manual lifecycle remains explicit');
ok(form.includes('if (input && !input.value'), 'manual values are not overwritten');
ok(form.includes('voucher type suggestion') || form.includes('Voucher type suggestion'), 'voucher type remains suggestion');
ok(provider.includes('timeout(') && provider.includes('connectTimeout('), 'bounded provider timeout');
ok(provider.includes('catch (ConnectionException)'), 'timeout failure is nonfatal');
ok(!provider.includes('Log::') && !service.includes('Log::'), 'raw receipt data is not logged');
ok(!fs.existsSync(path.join(root, 'database/migrations/receipt_capture')), 'no receipt migration');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C90.5'"), 'C90.5 asset identity');
ok(release.includes("'corrective_build' => 'C90.5'"), 'C90.5 build identity');
ok(release.includes("'corrective_name' => 'AI Receipt Capture Preview'"), 'C90.5 corrective name');

console.log(`C90.5 AI receipt capture preview regression: PASS (${assertions} assertions)`);
