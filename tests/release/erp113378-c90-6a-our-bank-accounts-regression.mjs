import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const routes = read('routes/erp103179.php');
const service = read('app/Services/Accounting/OurBankAccountService.php');
const controller = read('app/Http/Controllers/Accounting/OurBankAccountController.php');
const migration = read('database/migrations/2026_10_18_000000_create_our_bank_accounts_table.php');
const index = read('resources/views/accounting/our-bank-accounts/index.blade.php');
const form = read('resources/views/accounting/our-bank-accounts/form.blade.php');
const config = read('config/et_erp_release.php');

const checks = [
  [/accounting\/our-bank-accounts/.test(routes), 'Our Bank Accounts routes exist'],
  [routes.includes("middleware($coaReadMiddleware)") && routes.includes("middleware($coaWriteMiddleware)"), 'native accounting middleware reused'],
  [routes.includes("->name('accounting.our-bank-accounts.index')") && routes.includes("->name('accounting.our-bank-accounts.create')"), 'list/create routes named'],
  [routes.includes("->name('accounting.our-bank-accounts.edit')") && routes.includes("->name('accounting.our-bank-accounts.deactivate')"), 'edit/deactivate routes named'],
  [controller.includes('OurBankAccountService') && controller.includes('withErrors'), 'controller delegates and fails safely'],
  [service.includes('account_last_digits') && service.includes("/^\\d{4,6}$/"), 'suffix required and bounded numeric'],
  [index.includes('••••') && form.includes('no full account number'), 'masked suffix only'],
  [!form.includes('account_number') && !form.includes('iban'), 'full account number/IBAN absent'],
  [service.includes('cashBankAccounts()'), 'eligible ledgers reuse native cash/bank authority'],
  [service.includes('use_for_ai_receipt_matching') && service.includes('is_active'), 'future matching/status flags'],
  [service.includes('identical bank account already exists'), 'duplicate protection'],
  [service.includes('mayAccessBranch') && service.includes("where('company_id',$companyId)"), 'native branch/company scope'],
  [migration.includes("Schema::create('our_bank_accounts'") && migration.includes('dropIfExists'), 'isolated reversible migration'],
  [migration.includes('linked_ledger_id') && !migration.includes('foreign'), 'no unsafe adaptive COA foreign key'],
  [!service.includes('CashVoucherController') && !service.includes('Journal'), 'no posting/journal implementation'],
  [config.includes('ERP-11.3.378-C90.6A') && config.includes("'corrective_build' => 'C90.6A'"), 'release identity'],
  [index.includes('Bank') && index.includes('Account Title') && index.includes('Linked Ledger'), 'required list columns'],
  [form.includes('Create Bank Account') && form.includes('Save Changes'), 'create/edit UI'],
  [service.includes('safeForMatching') && service.includes('account_last_digits'), 'future-safe masked lookup'],
];
for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C90.6A regression PASS (${checks.length} assertions)`);
