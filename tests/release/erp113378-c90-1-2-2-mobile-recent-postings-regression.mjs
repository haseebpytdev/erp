import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const index = read('resources/views/mobile/accounts/index.blade.php');
const responsive = read('resources/views/mobile/accounts/_responsive.blade.php');
const controller = read('app/Http/Controllers/Accounting/MobileAccountsController.php');
const routes = read('routes/erp103179.php');
const release = read('config/et_erp_release.php');
let assertions = 0;
const ok = (value, message) => { assertions += 1; assert.ok(value, message); };

ok(index.includes('mobile-posting-row') && index.includes('mobile-posting-summary'), 'one posting renders as one compact row with one summary');
ok(responsive.includes('tr.mobile-posting-row { display: grid;'), 'stacked mobile posting cards are removed');
ok(index.includes('mobile-posting-number') && index.includes('mobile-posting-party'), 'voucher and party remain in the left summary');
ok(responsive.includes('grid-template-columns: minmax(0, 1fr) auto auto'), 'row reserves visible amount and status columns');
ok(responsive.includes('text-overflow: ellipsis') && responsive.includes('mobile-posting-summary'), 'long party text truncates safely');
ok(responsive.includes('.mobile-posting-amount { grid-column: 2;'), 'amount remains visible on mobile');
ok(responsive.includes('.mobile-posting-status { grid-column: 3;'), 'status remains visible on mobile');
ok(responsive.includes('overflow-x: hidden') && !responsive.includes('overflow-x: scroll'), 'mobile page has no horizontal scroll');
ok(responsive.includes('min-height: 52px') && responsive.includes('min-height: 28px'), 'rows and badges remain touch-friendly');
ok(index.includes('$recent') && index.includes('voucher_no') && index.includes('amount') && index.includes('status'), 'Recent Postings query/display source remains unchanged');
ok(!controller.includes('mobile-posting-row') && !controller.includes('mobile recent'), 'controller behavior remains unchanged');
ok(routes.includes("prefix('mobile/accounts')") && routes.includes("name('mobile.accounts.')"), 'mobile route and scope remain unchanged');
ok(index.includes('data-mobile-back-to-erp') && !index.includes("url('/dashboard')"), 'C90.1.2.1 Back to ERP navigation remains intact');
ok(fs.existsSync(path.join(root, 'tests/release/erp113378-mobile-accounts-quick-posting-regression.mjs')), 'C90.1 functional regression remains present');
ok(fs.existsSync(path.join(root, 'tests/release/erp113378-c90-1-1-mobile-accounts-responsive-regression.mjs')), 'C90.1.1 responsive regression remains present');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C90.1.2.2'") && release.includes("'corrective_build' => 'C90.1.2.2'") && release.includes("'corrective_name' => 'Mobile Recent Postings Compact Rows'"), 'C90.1.2.2 release identity is active');

console.log(`C90.1.2.2 Mobile Recent Postings Compact Rows regression: PASS (${assertions} assertions)`);
