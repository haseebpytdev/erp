import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path =>
  fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const shell =
  read('public/erp-ui/erp-shell-spacing.css');

const base =
  read('public/erp-ui/erp-professional.css');

const baseJs =
  read('public/erp-ui/erp-professional.js');

const finalizer =
  read('public/erp-ui/erp-professional-finalize.js');

const controller =
  read('app/Http/Controllers/System/ErpProfessionalUiAssetController.php');

const version =
  read('VERSION.txt').trim();

let pass = 0;

const ok = (condition, label) => {
  assert.ok(condition, label);
  pass++;
};

ok(
  version === 'v1.1.33.248-ERP11.3.248',
  'ERP-11.3.248 release metadata is current'
);

for (const token of [
  '--et-shell-sidebar-width:224px',
  '--et-shell-gutter-x:24px',
  '--et-shell-gutter-y:18px',
  '--et-shell-topbar-height:56px',
  '--et-sidebar-brand-height:64px',
  '--et-sidebar-logo-size:36px',
  '--et-sidebar-nav-x:10px',
  '--et-sidebar-row-height:36px',
  '--et-sidebar-icon-size:20px',
]) {
  ok(
    shell.includes(token),
    `shell contains ${token}`
  );
}

ok(
  !shell.includes(':not(.gp-focus-mode)'),
  'progressive booking is not excluded from shell authority'
);

ok(
  !shell.includes(':not(.et-air-focus-mode-103172)'),
  'air booking is not excluded from shell authority'
);

ok(
  shell.includes(
    'body.et-ui-professional main>div>.container'
  ),
  'nested content container receives outer-gutter cleanup'
);

ok(
  shell.includes('padding-left:0!important') &&
  shell.includes('padding-right:0!important'),
  'duplicate horizontal padding is removed'
);

ok(
  shell.includes(
    'height:var(--et-sidebar-brand-height)!important'
  ),
  'brand row receives exact height'
);

ok(
  shell.includes(
    'width:var(--et-sidebar-logo-size)!important'
  ),
  'logo receives compact footprint'
);

ok(
  !baseJs.includes('heading.style.setProperty'),
  'base JS contains no heading geometry setProperty'
);

ok(
  !baseJs.includes('link.style.setProperty'),
  'base JS contains no active-link style setProperty'
);

ok(
  !finalizer.includes('heading.style.setProperty'),
  'finalizer contains no heading geometry setProperty'
);

ok(
  !finalizer.includes('nav.style.display'),
  'finalizer contains no inline duplicate-nav display mutation'
);

const basePos =
  controller.indexOf('file_get_contents($base)');

const accountingPos =
  controller.indexOf('file_get_contents($accountingUi)');

const registerPos =
  controller.indexOf('file_get_contents($registerWorkspaceUi)');

const shellPos =
  controller.indexOf('file_get_contents($shellSpacingUi)');

ok(
  basePos >= 0 &&
  accountingPos > basePos &&
  registerPos > accountingPos &&
  shellPos > registerPos,
  'CSS load order is base -> accounting -> register -> shell'
);

ok(
  base.includes('Easy Ticket ERP professional UI primitives'),
  'professional primitives remain separate from final shell geometry'
);

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
