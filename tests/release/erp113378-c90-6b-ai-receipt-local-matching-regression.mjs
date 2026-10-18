import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
const root = path.resolve(import.meta.dirname, '..', '..');
const read = p => fs.readFileSync(path.join(root, p), 'utf8');
const provider = read('app/Services/Accounting/OpenAiReceiptExtractionProvider.php');
const service = read('app/Services/Accounting/ReceiptExtractionService.php');
const matcher = read('app/Services/Accounting/ReceiptTransactionMatcher.php');
const view = read('resources/views/mobile/accounts/form.blade.php');
const release = read('config/et_erp_release.php');
const checks = [
 [provider.includes('from_account_name') && provider.includes('to_account_name'), 'strict FROM/TO schema'],
 [provider.includes('from_account_last_digits') && provider.includes('to_account_last_digits'), 'masked suffix fields'],
 [provider.includes('bank_transaction_type'), 'informational transaction type'],
 [provider.includes("$payload['output']") && provider.includes("'output_text'"), 'Responses output parser preserved'],
 [matcher.includes('safeForMatching') && matcher.includes('count($toMatches) === 1'), 'local authorized bank matching'],
 [matcher.includes("$direction = 'REVIEW REQUIRED'") && matcher.includes("$direction = 'RECEIPT'") && matcher.includes("$direction = 'PAYMENT'"), 'direction fails closed'],
 [matcher.includes('partyOptions') && matcher.includes('=== $needle'), 'exact role-filtered party matching'],
 [view.includes('Apply Safe Fields') && view.includes('Review Details'), 'professional review actions'],
 [view.includes('FROM') && view.includes('TO') && view.includes('ERP MATCHES'), 'FROM/TO and ERP matches UI'],
 [view.includes('scan.disabled = true') && view.includes('finally { scan.disabled = false; }'), 'duplicate scan protection'],
 [view.includes('No voucher was saved') && !view.includes("CashVoucherController::store"), 'scan/apply separated from save'],
 [release.includes('ERP-11.3.378-C90.6B') && release.includes("'corrective_build' => 'C90.6B'"), 'C90.6B release identity'],
];
for (const [ok,label] of checks) assert.ok(ok,label);
console.log(`C90.6B regression PASS (${checks.length} assertions)`);
