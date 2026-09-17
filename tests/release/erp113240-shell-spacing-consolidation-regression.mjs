import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path =>
  fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const presenter =
  read('app/Http/Middleware/PresentUnifiedRegisterWorkspace.php');

const controller =
  read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');

const shellCss =
  read('public/erp-theme/et-shell.css');

const baseJs =
  read('public/erp-ui/erp-professional.js');

const finalizer =
  read('public/erp-ui/erp-professional-finalize.js');

const registerJs =
  read('public/erp-ui/erp-register-workspace.js');

const accountingCss =
  read('public/erp-theme/modules/accounting.css');

const version =
  read('VERSION.txt').trim();

let pass = 0;

const ok = (condition, label) => {
  assert.ok(condition, label);
  pass++;
};

ok(
  presenter.includes('utilityTopbarHtml($nativeMain)'),
  'native utility topbar extraction remains available'
);

ok(
  presenter.includes(
    'class="et-shell-content-frame et-register-content-frame"'
  ),
  'register content retains shared shell frame'
);

ok(
  shellCss.includes('--et-shell-gutter-x:24px'),
  'desktop application gutter is 24px'
);

ok(
  shellCss.includes('--et-shell-topbar-height:56px'),
  'utility header height is 56px'
);

ok(
  shellCss.includes('--et-shell-sidebar-width:208px'),
  'desktop sidebar width remains 208px'
);

ok(
  shellCss.includes('--et-sidebar-brand-height:64px'),
  'brand row is exactly 64px'
);

ok(
  shellCss.includes('--et-sidebar-logo-size:36px'),
  'brand logo footprint is 36px'
);

ok(
  shellCss.includes('--et-sidebar-row-height:32px'),
  'sidebar row density is controlled centrally'
);

ok(shellCss.includes(':where(.topbar,.top-bar,.app-header,.main-header,.navbar-horizontal){width:100%'), 'topbar uses natural full-width layout');
ok(!shellCss.includes('100% + (var(--et-shell-gutter-x) * 2)') && !shellCss.includes('margin-left:calc('), 'topbar introduces no negative-margin overflow hack');

ok(
  shellCss.includes(
    'body.et-ui-professional main>div>.container'
  ),
  'nested native/bootstrap outer container gutter is neutralized'
);

ok(
  !shellCss.includes(':not(.gp-focus-mode)'),
  'progressive booking uses global shell geometry'
);

ok(
  !shellCss.includes(':not(.et-air-focus-mode-103172)'),
  'air booking uses global shell geometry'
);

ok(
  !baseJs.includes("heading.style.setProperty"),
  'base JS does not style generated headings inline'
);

ok(
  !baseJs.includes("link.style.setProperty"),
  'base JS does not style active links inline'
);

ok(
  !finalizer.includes("heading.style.setProperty"),
  'finalizer does not style generated headings inline'
);

ok(
  !finalizer.includes("nav.style.display"),
  'finalizer does not hide duplicate nav using inline style'
);

ok(
  finalizer.includes(
    "classList.add('et-ui-nav-root-empty')"
  ),
  'finalizer uses semantic duplicate-nav class'
);

ok(
  shellCss.includes('.et-ui-nav-root-empty'),
  'shell stylesheet owns duplicate-nav visibility'
);

ok(
  shellCss.includes('--et-shell-sidebar-width:208px') &&
  !controller.includes("base_path('public/erp-ui/erp-shell-spacing.css')"),
  'fresh shell stylesheet is authoritative'
);

ok(
  shellCss.includes('--et-shell-gutter-x:24px') &&
  shellCss.includes('--et-shell-gutter-y:18px'),
  'fresh shell owns approved gutter tokens'
);

ok(
  controller.includes(
    "'Cache-Control' => 'private, max-age=31536000, immutable'"
  ),
  'versioned UI asset cache remains immutable'
);

ok(
  accountingCss.includes('.cvf27-card') &&
  accountingCss.includes('.cvs27-card') &&
  accountingCss.includes('.aa-wrap'),
  'Accounting workspace implementation remains intact'
);

ok(
  !registerJs.includes('accounting/'),
  'register interaction remains isolated from Accounting'
);

ok(
  !/fetch\s*\(|XMLHttpRequest|FormData\(|requestSubmit\(/.test(registerJs),
  'register interaction remains read-only/local'
);

ok(
  version === 'v1.1.33.303-ERP11.3.303',
  'ERP-11.3.259 release metadata is current'
);

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
