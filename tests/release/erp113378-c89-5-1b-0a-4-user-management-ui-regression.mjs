import fs from 'node:fs';

const ok = (label, value) => {
  if (!value) throw new Error(`FAIL: ${label}`);
  console.log(`PASS: ${label}`);
};

const blade = fs.readFileSync('resources/views/administration/erp-user-management.blade.php', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/Administration/ErpUserManagementController.php', 'utf8');
const service = fs.readFileSync('app/Services/Administration/ErpUserManagementService.php', 'utf8');
const matrix = fs.readFileSync('app/Services/Administration/ErpPermissionMatrixService.php', 'utf8');
const release = fs.readFileSync('config/et_erp_release.php', 'utf8');

ok('Inherited state is rendered', blade.includes('value="INHERIT"') && blade.includes('>Inherited</option>'));
ok('Allow state is rendered', blade.includes('value="ALLOW"') && blade.includes('>Allow</option>'));
ok('Deny state is rendered', blade.includes('value="DENY"') && blade.includes('>Deny</option>'));
ok('Tri-state values post through the existing field contract', blade.includes('name="permission_overrides[') && controller.includes("permission_overrides"));
ok('Legacy permission id fields are absent', !blade.includes('permission_ids[') && !blade.includes('direct_permission_ids'));
ok('Existing override synchronization remains authoritative', service.includes('syncOverrides') && !service.includes('syncPermissions($id'));
ok('Permission sections remain grouped', blade.includes('data-permission-section="{{ $sectionName }}"') && matrix.includes("'ADMINISTRATION'") && matrix.includes("'TRAVEL REPORTS'") && matrix.includes("'SYSTEM / SETTINGS'"));
ok('Permission search remains available', blade.includes('data-et-permission-search') && blade.includes('data-search') && blade.includes("addEventListener('input'"));
ok('Visible-only bulk actions are explicit', blade.includes('et-visible-inherit-103336') && blade.includes('et-visible-allow-103336') && blade.includes('et-visible-deny-103336') && blade.includes('!row?.hidden'));
ok('Collapse and expand actions are wired', blade.includes('et-collapse-all-103336') && blade.includes('et-expand-all-103336') && blade.includes('section.open=false') && blade.includes('section.open=true'));
ok('Module summaries expose override counts', blade.includes('permissions · <span class="et-section-count-103336">0</span> overrides'));
ok('Effective access remains presentation-only', blade.includes('Effective Access') && !blade.includes('name="effective_permissions'));
ok('C89.3 precedence remains matrix-owned', matrix.includes('applyPermissionOverrides') && matrix.includes("explicit_deny") && matrix.includes("explicit_allow"));
ok('Branch authority remains service-owned', service.includes('ErpBranchAccessAuthority') || !service.includes('branch_access_override'));
ok('Page layout is bounded without horizontal permission-grid overflow', blade.includes('max-width:1280px') && blade.includes('overflow-x:hidden') && blade.includes('.um-perm-grid{display:flex;flex-direction:column'));
ok('Tri-state controls expose state styling', blade.includes('[data-state="ALLOW"]') && blade.includes('[data-state="DENY"]') && blade.includes('[data-state="INHERIT"]'));
ok('Release identity is C89.5.1B.0A.4 or later polish', /ERP-11\.3\.378-C89\.5\.1B\.(?:0A\.(?:4|5)|0B)/.test(release));
ok('Protected historical regressions remain present', fs.existsSync('tests/release/erp113378-c89-4-6-5-administration-spacing-copy-regression.mjs') && fs.existsSync('tests/release/erp113378-c89-5-1b-0a-3-user-management-c89-overrides-regression.mjs'));

console.log('ASSERTIONS=18');
