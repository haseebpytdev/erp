import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const presenter = read('app/Http/Middleware/PresentUnifiedRegisterWorkspace.php');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const shellCss = read('public/erp-ui/erp-shell-spacing.css');
const baseJs = read('public/erp-ui/erp-professional.js');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const registerJs = read('public/erp-ui/erp-register-workspace.js');
const accountingCss = read('public/erp-ui/erp-accounting-vouchers.css');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(presenter.includes('ERP-11.3.240'), 'presenter documents the .240 shell/topbar correction');
ok(presenter.includes('utilityTopbarHtml($nativeMain)'), 'native utility topbar is extracted before register canvas replacement');
ok(presenter.includes('topbar') && presenter.includes('top-bar') && presenter.includes('app-header') && presenter.includes('main-header') && presenter.includes('navbar-horizontal'), 'topbar extractor supports all native shell aliases');
ok(presenter.includes('class="et-shell-content-frame et-register-content-frame"'), 'register content gets one shared shell frame');
ok(presenter.includes("str_contains($text, 'sign out')"), 'header fallback recognizes the native authenticated utility header');

ok(shellCss.includes('--et-shell-gutter-x:24px'), 'desktop horizontal gutter is 24px');
ok(shellCss.includes('--et-shell-gutter-y:20px'), 'desktop vertical gutter is 20px');
ok(shellCss.includes('padding-left:0!important') && shellCss.includes('padding-right:0!important'), 'outer wrappers drop duplicate horizontal padding');
ok(shellCss.includes('width:100%!important') && shellCss.includes('max-width:none!important'), 'main canvas no longer uses a second centered max-width authority');
ok(shellCss.includes('main>.topbar') && shellCss.includes('width:calc(100% + (var(--et-shell-gutter-x) * 2))!important'), 'utility topbar spans the full shell width despite page gutter');
ok(shellCss.includes('main>.container') && shellCss.includes('padding-left:0!important'), 'direct Bootstrap/native containers do not add a second gutter');
ok(shellCss.includes('.et-fin') && shellCss.includes('.et-reg-shell'), 'Accounting and Operations share the same outer width authority');
ok(shellCss.includes('.et-fin-modes{margin:0 0 12px!important}'), 'Accounting tab row no longer adds a second top gap');
ok(shellCss.includes('.et-fin-actions{margin:0 0 14px!important}'), 'Accounting action rhythm is normalized');
ok(shellCss.includes('.et-fin-filter{margin:0 0 14px!important}'), 'Accounting filter rhythm is normalized');

ok(shellCss.includes('min-height:64px!important') && shellCss.includes('padding:10px 12px!important'), 'sidebar brand/header geometry is compact and deterministic');
ok(shellCss.includes('padding:8px var(--et-sidebar-inner-x) 10px!important'), 'sidebar navigation uses one inner gutter');
ok(shellCss.includes('margin:16px 8px 8px!important'), 'sidebar section rhythm is owned by CSS');
ok(!baseJs.includes("heading.style.setProperty('margin'"), 'base sidebar JS does not own heading margin');
ok(!baseJs.includes("heading.style.setProperty('padding'"), 'base sidebar JS does not own heading padding');
ok(!finalizer.includes("heading.style.setProperty('margin'"), 'finalizer does not own heading margin');
ok(!finalizer.includes("row.style.setProperty('margin-top'"), 'finalizer does not own first-row margin');

ok(controller.includes("base_path('public/erp-ui/erp-shell-spacing.css')"), 'shell spacing CSS is served through Laravel');
const registerAssetPosition = controller.indexOf('file_get_contents($registerWorkspaceUi)');
const shellAssetPosition = controller.indexOf('file_get_contents($shellSpacingUi)');
ok(registerAssetPosition >= 0 && shellAssetPosition > registerAssetPosition, 'shell spacing CSS loads last after module/register styles');
ok(controller.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned immutable UI asset cache remains intact');

ok(accountingCss.includes('.cvf27-card') && accountingCss.includes('.cvs27-card') && accountingCss.includes('.aa-wrap'), 'Accounting business workspaces retain their existing visual implementation');
ok(!registerJs.includes('accounting/'), 'register interaction JS remains isolated from Accounting');
ok(!/fetch\s*\(|XMLHttpRequest|FormData\(|requestSubmit\(/.test(registerJs), 'register interaction remains read-only and local');
ok(version === 'v1.1.33.240-ERP11.3.240', 'ERP-11.3.240 release metadata is promoted for packaging');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
