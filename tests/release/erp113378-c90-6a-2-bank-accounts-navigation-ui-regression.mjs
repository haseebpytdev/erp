import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = (p) => fs.readFileSync(path.join(root, p), 'utf8');
const sidebar = read('app/Services/Operations/ServerSidebarComposer.php');
const page = read('resources/views/accounting/our-bank-accounts/index.blade.php');
const release = read('config/et_erp_release.php');

const checks = [
  [sidebar.includes("'ORGANIZATION' => ['our bank accounts']"), 'Our Bank Accounts is grouped under Organization'],
  [sidebar.includes("['Our Bank Accounts','/accounting/our-bank-accounts']"), 'navigation route is correct'],
  [sidebar.includes("$anchor->setAttribute('class', 'nav-item')"), 'native nav-item class is preserved'],
  [sidebar.includes('et-ui-nav-icon'), 'native navigation icon structure is preserved'],
  [page.includes('＋ Add Bank Account'), 'Add Bank Account label remains'],
  [page.includes('color:#fff!important'), 'primary action text is white'],
  [page.includes('background:#1769e0') && page.includes('background:#0f5cc7'), 'primary action states are styled'],
  [page.includes('Chart of Accounts') && page.includes('oba-btn"'), 'Chart of Accounts remains secondary'],
  [page.includes('@media(max-width:700px)') && page.includes('flex:1 1 180px'), 'responsive action layout remains'],
  [release.includes('ERP-11.3.378-C90.6A.2') && release.includes("'corrective_build' => 'C90.6A.2'"), 'C90.6A.2 release identity'],
];
for (const [ok, label] of checks) assert.ok(ok, label);
console.log(`C90.6A.2 regression PASS (${checks.length} assertions)`);
