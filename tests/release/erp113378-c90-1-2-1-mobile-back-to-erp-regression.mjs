import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const responsive = read('resources/views/mobile/accounts/_responsive.blade.php');
const index = read('resources/views/mobile/accounts/index.blade.php');
const form = read('resources/views/mobile/accounts/form.blade.php');
const advance = read('resources/views/mobile/accounts/advance.blade.php');
const routes = read('routes/erp103179.php');
const controller = read('app/Http/Controllers/Accounting/MobileAccountsController.php');
const release = read('config/et_erp_release.php');
const sidebar = read('app/Services/Operations/ServerSidebarComposer.php');
let assertions = 0;
const ok = (value, message) => { assertions += 1; assert.ok(value, message); };

ok(!index.includes("url('/dashboard')"), 'mobile Back to ERP no longer hardcodes /dashboard');
ok(index.includes('data-mobile-back-to-erp'), 'mobile Back to ERP has a resolver marker');
ok(responsive.includes("normalize(anchor.textContent) === 'dashboard'"), 'resolver uses the normal Dashboard label');
ok(responsive.includes('sidebar') && responsive.includes('dashboard.href'), 'resolver reads the existing ERP sidebar Dashboard link');
ok(responsive.includes('target.href = dashboard.href'), 'mobile target matches the existing Dashboard href');
ok(sidebar.includes("$known['dashboard']"), 'ServerSidebarComposer preserves the normal Dashboard authority');
ok(!routes.includes("Route::get('/dashboard'") && !routes.includes("name('dashboard')"), 'no new Dashboard route was added');
ok(form.includes("route('mobile.accounts.index')") && advance.includes("route('mobile.accounts.index')"), 'child pages retain named Accounts navigation');
ok(responsive.includes('body:has(.mobile-accounts-page) header') && responsive.includes('display: none !important'), 'desktop topbar remains hidden');
ok(responsive.includes('body:has(.mobile-accounts-page) .sidebar') && responsive.includes('max-width: 100%'), 'mobile sidebar/layout behavior remains intact');
ok(index.includes('Easy Ticket ERP') && index.includes('Back to ERP'), 'compact mobile header remains present');
ok(!controller.includes('mobile back to ERP') && !controller.includes('dashboard href'), 'controller is unchanged');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C90.1.2.1'"), 'C90.1.2.1 asset identity is active');
ok(release.includes("'corrective_build' => 'C90.1.2.1'"), 'C90.1.2.1 corrective build is active');
ok(release.includes("'corrective_name' => 'Mobile Accounts Back to ERP Navigation Correction'"), 'C90.1.2.1 corrective name is active');
ok(!responsive.includes('service-worker') && !responsive.includes('bottom-nav'), 'no unrelated mobile navigation was added');

console.log(`C90.1.2.1 Mobile Back to ERP Navigation regression: PASS (${assertions} assertions)`);
