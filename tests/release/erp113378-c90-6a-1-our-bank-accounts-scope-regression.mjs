import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
const root = path.resolve(import.meta.dirname, '..', '..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const service = read('app/Services/Accounting/OurBankAccountService.php');
const controller = read('app/Http/Controllers/Accounting/OurBankAccountController.php');
const authority = read('app/Services/Administration/ErpBranchAccessAuthority.php');
const config = read('config/et_erp_release.php');
const routes = read('routes/erp103179.php');
const checks = [
  [service.includes('findAuthorized') && controller.includes('findAuthorized'), 'edit resolves authorized record through service'],
  [service.includes('assertRowScope($row, $user)'), 'existing ownership checked'],
  [service.includes('companyIdForUser') && service.includes("where('company_id', $companyId)"), 'rows company scoped'],
  [service.includes('whereIn(\'branch_id\', $allowed ?: [-1])->orWhereNull(\'branch_id\')'), 'company-wide rows remain branch scoped'],
  [service.includes('companyOptions(mixed $user)') && service.includes('$q->where(\'id\', $companyId)'), 'company options scoped'],
  [authority.includes('public function companyIdForUser'), 'canonical company accessor additive'],
  [service.includes('safeForMatching') && service.includes('is_active') && service.includes('use_for_ai_receipt_matching'), 'safe matching filters'],
  [service.includes('cashBankAccounts()'), 'COA source unchanged'],
  [controller.includes("The bank account could not be saved.") && controller.includes('report($e)'), 'unexpected exceptions are hidden and reported'],
  [routes.includes('our-bank-accounts'), 'routes remain present'],
  [config.includes('ERP-11.3.378-C90.6A.1') && config.includes("'corrective_build' => 'C90.6A.1'"), 'release identity'],
  [!service.includes('CashVoucherController') && !service.includes('JournalBridge'), 'accounting engine untouched'],
  [!service.includes('account_number') && !service.includes('iban'), 'full account number absent'],
  [service.includes('abort(403'), 'unauthorized records fail closed'],
  [service.includes('if ($id !== null)') && service.includes('before any submitted destination scope'), 'update ownership precedes destination validation'],
];
for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C90.6A.1 regression PASS (${checks.length} assertions)`);
