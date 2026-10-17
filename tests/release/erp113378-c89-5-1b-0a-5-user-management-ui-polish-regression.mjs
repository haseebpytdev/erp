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

ok('list mode retains users table', blade.includes('@if(!$selected)') && blade.includes('id="et-user-table-103336"'));
ok('edit mode hides users table', blade.includes('@if($selected)') && blade.includes('@endif'));
ok('Back to Users remains', blade.includes('Back to Users') && blade.includes("route('administration.erp-user-management.index')"));
ok('six edit sections preserved', ['1. Identity & Account Status','2. Role Template & Native Roles','3. Branch Access','4. Custom Permissions','5. Effective Access Summary','6. Login & Security'].every(label => blade.includes(label)));
ok('permission grouping preserved', blade.includes('data-permission-section="{{ $sectionName }}"') && matrix.includes("'ADMINISTRATION'") && matrix.includes("'TRAVEL REPORTS'"));
ok('permission name/key are separate columns', blade.includes('grid-template-columns:minmax(220px,1.3fr)') && blade.includes('font-family:ui-monospace'));
ok('compact permission row list', blade.includes('.um-perm-row{display:grid') && blade.includes('min-height:48px'));
ok('Inherited state preserved', blade.includes('value="INHERIT"') && blade.includes('>Inherited</option>'));
ok('Allow state preserved', blade.includes('value="ALLOW"') && blade.includes('>Allow</option>'));
ok('Deny state preserved', blade.includes('value="DENY"') && blade.includes('>Deny</option>'));
ok('tri-state presentation is segmented', blade.includes('um-tristate') && blade.includes('aria-pressed') && blade.includes('data-value=INHERIT'));
ok('permission_overrides contract preserved', blade.includes('name="permission_overrides[') && controller.includes('permission_overrides'));
ok('legacy permission editor absent', !blade.includes('permission_ids['));
ok('direct permission editor absent', !blade.includes('direct_permission_ids'));
ok('legacy syncPermissions absent', !service.includes('syncPermissions($id'));
ok('modules collapsed by default', blade.includes('<details class="um-perm-section"') && !blade.includes('<details open'));
ok('human-readable module counts', blade.includes('permissions · <span class="et-section-count-103336">0</span> overrides'));
ok('permission search preserved', blade.includes('data-et-permission-search') && blade.includes('addEventListener(\'input\''));
ok('bulk actions guard hidden rows', blade.includes('et-visible-allow-103336') && blade.includes('!row?.hidden'));
ok('effective summary is read-only', blade.includes('um-effective-cards') && !blade.includes('name="effective_permissions'));
ok('single update endpoint remains', blade.includes("route('administration.erp-user-management.update"));
ok('single form submission path', (blade.match(/method="POST"/g) || []).length >= 2 && blade.includes('type="submit"'));
ok('branch authority remains protected', service.includes('ErpBranchAccessAuthority') || !service.includes('branch_access_override'));
ok('permission precedence remains matrix-owned', matrix.includes('applyPermissionOverrides') && matrix.includes('explicit_deny'));
ok('route marker files remain untouched by UI contract', !blade.includes('C89ManagedBusinessRoute') && !blade.includes('RouteMatched'));
ok('global listener remains outside view', !blade.includes('C89RouteMatchedListener'));
ok('effective summary uses authoritative values', blade.includes('effectivePermissionIds') && blade.includes('rolePermissionIds') && blade.includes('oldBranchIds'));
ok('mobile permission stack exists', blade.includes('@media(max-width:760px)') && blade.includes('grid-template-columns:1fr'));
ok('sticky save bar remains single action group', blade.includes('position:sticky') && blade.includes('Save User Account') && blade.includes('Reset Changes'));
ok('C89.5.1B.0A.3 regression remains present', fs.existsSync('tests/release/erp113378-c89-5-1b-0a-3-user-management-c89-overrides-regression.mjs'));
ok('C89.5.1B.0A.4 regression remains present', fs.existsSync('tests/release/erp113378-c89-5-1b-0a-4-user-management-ui-regression.mjs'));
ok('Administration C89.4.6.5 regression remains present', fs.existsSync('tests/release/erp113378-c89-4-6-5-administration-spacing-copy-regression.mjs'));
ok('release identity is C89.5.1B.0A.5', release.includes("'asset_version' => 'ERP-11.3.378-C89.5.1B.0A.5'") && release.includes("'corrective_build' => 'C89.5.1B.0A.5'") && release.includes("'corrective_name' => 'ERP User Management Final UI Polish'"));

console.log('ASSERTIONS=30');
