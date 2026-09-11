import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const baseJs = read('public/erp-ui/erp-professional.js');
const finalizer = read('public/erp-ui/erp-professional-finalize.js');
const prepaint = read('public/erp-ui/erp-sidebar-prepaint.css');
const ready = read('public/erp-ui/erp-sidebar-ready.js');
const shellCss = read('public/erp-ui/erp-shell-spacing.css');
const controller = read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(baseJs.includes('ERP-11.3.241'), 'base UI documents the .241 single-pass sidebar contract');
ok(!baseJs.includes('sidebarNav.appendChild') && !baseJs.includes("link.classList.add('et-ui-current')"), 'base UI cannot reorder or path-highlight sidebar links');
ok(finalizer.includes('one hidden prepaint pass only'), 'sidebar normalization is explicitly single-pass');
ok(finalizer.includes("nav.insertBefore(heading, sectionRows[0])"), 'section heading insertion preserves native row order');
ok(!finalizer.includes('canonicalNav.appendChild') && !finalizer.includes('appendRow(row)'), 'finalizer does not rebuild root navigation order');
ok(finalizer.includes("row.dataset.etSidebarLevel = 'root'"), 'root rows are classified');
ok(finalizer.includes("nav.dataset.etSidebarLevel = 'nested'"), 'nested navs are classified separately');
ok(finalizer.includes("row.dataset.etSidebarLevel = 'nested-row'"), 'nested rows receive their own geometry hook');
ok(finalizer.includes("link.dataset.etNativeActive = 'true'"), 'native active state is annotated without path-only rewriting');
ok(finalizer.includes('if (!nativeActiveFound)'), 'exact location fallback is used only when native state is absent');
ok(finalizer.includes('normalizeSearch(linkUrl.search) === currentSearch'), 'active fallback is query-aware');
ok(!finalizer.includes("link.classList.remove('et-ui-current')") && !finalizer.includes("link.style.setProperty('background'"), 'finalizer does not repaint current-state styles inline');

ok(prepaint.includes('visibility: hidden') && prepaint.includes('2s forwards'), 'prepaint guard hides normalization with a finite failsafe');
ok(ready.includes("body.dataset.etSidebarReady = 'true'"), 'sidebar is revealed only after finalization');

ok(shellCss.includes('min-height:52px!important') && shellCss.includes('width:34px!important'), 'brand/logo area is compact');
ok(shellCss.includes('min-height:34px!important') && shellCss.includes('padding:7px 8px!important'), 'root links use deterministic compact geometry');
ok(shellCss.includes('margin:14px 8px 6px!important'), 'section headings have safe vertical separation');
ok(shellCss.includes('margin:2px 0 6px 22px!important'), 'nested nav indentation is independent from root rows');
ok(shellCss.includes('min-height:30px!important') && shellCss.includes('font-size:12px!important'), 'nested rows are compact and readable');
ok(shellCss.includes(':has(.nav [data-et-native-active="true"])'), 'active nested child cannot make parent look like a second selected destination');
ok(shellCss.includes('--et-page-title-size:26px') && shellCss.includes('body.et-ui-professional main h1'), 'global page-title token is applied across modules');
ok(shellCss.includes('--et-control-height:36px') && shellCss.includes('main input:not([type="checkbox"])'), 'global form control height is normalized without affecting checkboxes/radios');
ok(shellCss.includes('main .nav-tabs') && shellCss.includes('gap:6px!important'), 'module tab groups receive consistent spacing');

const registerAssetPosition = controller.indexOf('file_get_contents($registerWorkspaceUi)');
const shellAssetPosition = controller.indexOf('file_get_contents($shellSpacingUi)');
ok(registerAssetPosition >= 0 && shellAssetPosition > registerAssetPosition, 'stabilized shell CSS remains final CSS authority');
ok(controller.includes("'Cache-Control' => 'private, max-age=31536000, immutable'"), 'versioned UI asset cache remains immutable');
ok(version === 'v1.1.33.241-ERP11.3.241', 'ERP-11.3.241 release metadata is promoted for packaging');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
