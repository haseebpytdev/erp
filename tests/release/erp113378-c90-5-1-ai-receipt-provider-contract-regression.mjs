import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const provider = read('app/Services/Accounting/OpenAiReceiptExtractionProvider.php');
const controller = read('app/Http/Controllers/Accounting/MobileReceiptCaptureController.php');
const service = read('app/Services/Accounting/ReceiptExtractionService.php');
const release = read('config/et_erp_release.php');
let assertions = 0;
const ok = (value, message) => { assertions++; assert.ok(value, message); };

ok(provider.includes("'type' => 'json_schema'"), 'strict JSON schema remains enabled');
ok(provider.includes("'additionalProperties' => false"), 'strict objects reject extra properties');
ok(provider.includes("'warnings','confidence']"), 'root confidence is required');
ok(provider.includes("'required' => ['receipt_date','receipt_time','amount','currency','transaction_reference','counterparty_name','bank_name','payment_method','visible_account_last_digits','voucher_type_suggestion','narration']"), 'root receipt fields remain required');
ok(provider.includes("'required' => ['receipt_date','receipt_time','amount','currency','transaction_reference','counterparty_name','bank_name','payment_method','visible_account_last_digits','voucher_type_suggestion','narration']"), 'confidence object is complete');
ok(provider.includes("['string', 'null']"), 'nullable extraction values remain supported');
ok(provider.includes("str_starts_with($mime, 'image/')") && provider.includes("'image_url'"), 'image request contract preserved');
ok(provider.includes("$payload['output']") && provider.includes("$item['content']") && provider.includes("'output_text'"), 'raw Responses output parsing implemented');
ok(provider.includes("$content['type'] ?? null) === 'output_text'"), 'output item type is checked');
ok(provider.includes("$payload['output_text'] ?? null"), 'top-level output_text is fallback only');
ok(provider.includes('trim($fallback) !=='), 'empty output safely fails');
ok(provider.includes('json_decode($json, true)'), 'structured text is decoded');
ok(provider.includes('Str::uuid()'), 'diagnostic correlation ID generated');
ok(provider.includes("Log::warning('receipt_ai provider failure'"), 'safe provider failure logging exists');
for (const field of ['http_status','failure_category','duration_ms','provider_error_type','provider_error_code','provider_request_id']) ok(provider.includes("'" + field + "'"), `safe diagnostic field: ${field}`);
for (const forbidden of ['Authorization', 'error.message', 'receipt bytes']) ok(!provider.includes(forbidden), `sensitive diagnostic excluded: ${forbidden}`);
ok(!provider.includes("'data' => $data") && !provider.includes("'receipt_bytes'"), 'receipt data is excluded from diagnostics');
ok(provider.includes("$status === 429") && provider.includes('$status >= 500'), 'status categories are classified');
ok(controller.includes('receipt-capture/preview') === false, 'controller remains extraction-only and route-owned');
ok(!controller.includes('CashVoucherController::store') && !service.includes('CashVoucherController::store'), 'voucher store is not called');
ok(!service.includes('DB::') && !service.includes('insertGetId'), 'no database mutation');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C90.5.1'"), 'C90.5.1 asset identity');
ok(release.includes("'corrective_build' => 'C90.5.1'"), 'C90.5.1 build identity');
ok(release.includes("'corrective_name' => 'AI Receipt Capture Provider Contract Correction'"), 'C90.5.1 name');

console.log(`C90.5.1 provider contract regression: PASS (${assertions} assertions)`);
