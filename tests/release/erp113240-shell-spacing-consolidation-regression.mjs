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

ok(presenter.includes('ERP-11.3.240'), 'presenter retains the .240 shell/topbar correction');
ok(presenter.includes('utilityTopbarHtml($nativeMain)'), 'native utility topbar is extracted before register canvas replacement');
ok(presenter.includes('topbar') && presenter.includes('top-bar') && presenter.includes('app-header') && presenter.includes('main-header') && presenter.includes('navbar-horizontal'), 'topbar extractor supports all native shell aliases');
ok(presenter.includes('class="et-shell-content-frame et-register-content-frame"'), 'register content keeps one shared shell frame');
ok(presenter.includes("str_contains($text, 'sign out')"), 'header fallback recognizes the native authenticated utility header');

ok(shellCss.includes('--et-shell-gutter-x:24px'), 'desktop horizontal gutter remains 24px');
ok(shellCss.includes('--et-shell-gutter-y:20px'), 'desktop vertical gutter remains 20px');
ok(shellCss.includes('padding-left:0!important') && shellCss.includes('padding-right:0!important'), 'outer wrappers still drop duplicate horizontal padding');
ok(shellCss.includes('width:100%!important') && shellCss.includes('max-width:none!important'), 'main canvas keeps one width authority');
ok(shellCss.includes('main>.topbar') && shellCss.includes('width:calc(100% + (var(--et-shell-gutter-x) * 2))!important'), 'utility topbar spans the full shell width despite page gutter');
ok(shellCss.includes('main>.container') && shellCss.includes('padding-left:0!important'), 'direct Bootstrap/native containers do not add a second gutter');
ok(shellCss.includes('.et-fin') && shellCss.includes('.et-reg-shell'), 'Accounting and Operations share the same outer width authority');
ok(shellCss.includes('.et-fin-modes{margin:0 0 12px!important}'), 'Accounting tab row keeps normalized rhythm');
ok(shellCss.includes('.et-fin-actions{margin:0 0 14px!important}'), 'Accounting action rhythm remains normalized');
ok(shellCss.includes('.et-fin-filter{margin:0 0 14px!important}'), 'Accounting filter rhythm remains normalized');

ok(shellCss.includes('min-height:52px!important') && shellCss.includes('padding:6px 10px!important'), 'sidebar brand/header geometry is compacted by .241 without regressing shell authority');
ok(shellCss.includes('padding:5px var(--et-sidebar-inner-x) 10px!important'), 'root sidebar navigation keeps one inner gutter');
ok(shellCss.includes('margin:14px 8px 6px!important'), 'sidebar section rhythm remains CSS-owned');
ok(!baseJs.includes("heading.style.setProperty('margin'"), 'base JS does not own sidebar heading margin');
ok(!finalizer.includes("heading.style.setProperty('margin'"), 'finalizer does not own sidebar heading margin');
ok(!finalizer.includes("row.style.setProperty('margin-top'"), 'finalizer does not own first-row margin');

ok(controller.includes("base_path('public/erp-ui/erp-shell-spacing.css')"), 'shell spacing CSS remains served through Laravel');
const registerAssetPosition = controller.indexOf('file_get_contents($registerWorkspaceUi)');
const shellAssetPosition = controller.indexOf('file_get_contents($shellSpacingUi)');
ok(registerAssetPosition >= 0 && shellAssetPosition > registerAssetPosition, 'shell spacing CSS still loads last after module/register styles');
ok(controller.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned immutable UI asset cache remains intact');

ok(accountingCss.includes('.cvf27-card') && accountingCss.includes('.cvs27-card') && accountingCss.includes('.aa-wrap'), 'Accounting business workspaces retain their existing visual implementation');
ok(!registerJs.includes('accounting/'), 'register interaction JS remains isolated from Accounting');
ok(!/fetch\s*\(|XMLHttpRequest|FormData\(|requestSubmit\(/.test(registerJs), 'register interaction remains read-only and local');
ok(version === 'v1.1.33.247-ERP11.3.247', 'functional .241 checkpoint intentionally retains deployed .240 release metadata');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
