import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const responsive = read('resources/views/mobile/accounts/_responsive.blade.php');
const index = read('resources/views/mobile/accounts/index.blade.php');
const form = read('resources/views/mobile/accounts/form.blade.php');
const advance = read('resources/views/mobile/accounts/advance.blade.php');
const release = read('config/et_erp_release.php');
const routes = read('routes/erp103179.php');
const controller = read('app/Http/Controllers/Accounting/MobileAccountsController.php');
let assertions = 0;
const ok = (value, message) => { assertions += 1; assert.ok(value, message); };

ok(responsive.includes('body:has(.mobile-accounts-page) header') && responsive.includes('display: none !important'), 'desktop topbar is hidden at the mobile breakpoint');
ok(responsive.includes('margin-top: 0 !important') && responsive.includes('padding-top: 0 !important'), 'hidden topbar leaves no layout gap');
ok(index.includes('Easy Ticket ERP') && form.includes('Easy Ticket ERP') && advance.includes('Easy Ticket ERP'), 'one compact Easy Ticket header is present on every mobile page');
ok(index.includes('Back to ERP'), 'mobile home has Back to ERP');
ok(form.includes('← Accounts') && advance.includes('← Accounts'), 'child pages have Accounts back navigation');
ok(!responsive.includes('mobile-header') || responsive.match(/mobile-brand/g)?.length === undefined, 'no duplicate compact header component was added');
ok(responsive.includes('clamp(1.35rem, 7vw, 1.85rem)'), 'mobile page titles use responsive typography');
ok(responsive.includes('grid-template-columns: 1fr') && form.includes('mobile-fields'), 'single-column form responsiveness remains intact');
ok(responsive.includes('overflow-x: hidden') && responsive.includes('max-width: 100%'), 'mobile horizontal overflow remains prevented');
ok(responsive.includes('.mobile-actions { display: grid; grid-template-columns: 1fr; }') && responsive.includes('min-height: 44px'), 'save actions remain touch-friendly and responsive');
ok(!responsive.includes('body:not(.mobile-accounts-page)') && !responsive.includes('body .sidebar'), 'normal ERP header/sidebar selectors are not globally modified');
ok(index.includes("@extends($layoutMeta['layout'])") && form.includes("@extends($layoutMeta['layout'])"), 'desktop layout remains the existing ERP layout');
ok(fs.existsSync(path.join(root, 'tests/release/erp113378-mobile-accounts-quick-posting-regression.mjs')), 'C90.1 functional regression remains present');
ok(fs.existsSync(path.join(root, 'tests/release/erp113378-c90-1-1-mobile-accounts-responsive-regression.mjs')), 'C90.1.1 responsive regression remains present');
ok(!fs.existsSync(path.join(root, 'app/Http/Controllers/Accounting/MobileAccountsController.php.changed')), 'no controller change marker exists');
ok(!routes.includes('mobile.accounts.header') && !controller.includes('mobile header'), 'routes and controller behavior remain unchanged');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C90.1.2'"), 'C90.1.2 asset identity is active');
ok(release.includes("'corrective_build' => 'C90.1.2'"), 'C90.1.2 corrective build is active');
ok(release.includes("'corrective_name' => 'Mobile Accounts Compact Header Correction'"), 'C90.1.2 corrective name is active');
ok(!responsive.includes('service-worker') && !responsive.includes('bottom-nav') && !responsive.includes('PWA'), 'no app or PWA navigation was added');

console.log(`C90.1.2 Mobile Accounts Compact Header regression: PASS (${assertions} assertions)`);
