import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const transformer = read('app/Services/Operations/OrganizationHubBankAccountCardTransformer.php');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const page = read('resources/views/accounting/our-bank-accounts/index.blade.php');
const sidebar = read('app/Services/Operations/ServerSidebarComposer.php');
const release = read('config/et_erp_release.php');

const checks = [
  [transformer.includes("trim($request->path(), '/') !== 'organization'"), 'transformer is limited to Organization hub'],
  [transformer.includes('settings-grid') && transformer.includes('settings-card'), 'native Organization card structure is used'],
  [transformer.includes('Our Bank Accounts'), 'card title is present'],
  [transformer.includes('Manage company bank account metadata linked to existing cash / bank ledgers.'), 'card description is present'],
  [transformer.includes("route('accounting.our-bank-accounts.index')"), 'card uses named bank-account route'],
  [transformer.includes("moduleAllowed($user, 'foundation')"), 'existing COA/foundation visibility is reused'],
  [middleware.includes('OrganizationHubBankAccountCardTransformer'), 'transformer is in the live response pipeline'],
  [sidebar.includes("'ORGANIZATION' => ['our bank accounts']"), 'top-level sidebar behavior remains represented'],
  [page.includes('＋ Add Bank Account') && page.includes('color:#fff!important'), 'C90.6A.2 button fix remains'],
  [release.includes('ERP-11.3.378-C90.6A.3') && release.includes("'corrective_build' => 'C90.6A.3'"), 'C90.6A.3 identity is present'],
  [release.includes("'corrective_name' => 'Our Bank Accounts Organization Hub Card'"), 'corrective name is present'],
];
for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C90.6A.3 regression PASS (${checks.length} assertions)`);
