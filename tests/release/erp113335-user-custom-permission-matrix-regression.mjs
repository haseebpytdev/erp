import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(file, 'utf8');
const service = read('app/Services/Administration/ErpUserManagementService.php');
const matrix = read('app/Services/Administration/ErpPermissionMatrixService.php');
const controller = read('app/Http/Controllers/Administration/ErpUserManagementController.php');
const view = read('resources/views/administration/erp-user-management.blade.php');
const policy = read('app/Services/Administration/ErpRoleAccessPolicy.php');
const routes = read('routes/erp103179.php');
const ok = (value, message) => assert.ok(value, message);

const templates = [
  'Administrator','Operations Staff','Ticketing Staff','Ticketing Manager',
  'Cashier','Accountant','Sales Executive','Sales Manager','Umrah Staff',
  'Umrah Manager','Visa Staff','Finance Manager','Auditor / Read Only','Custom Access',
];

ok(view.includes('et-role-template-103335'), 'Role Template selector exists');
for (const name of templates) ok(controller.includes(`'${name}'`), `template exists: ${name}`);
ok(view.includes('Custom Permissions') && view.includes('data-et-custom-permissions'), 'Custom Permissions always visible');
ok(view.includes('Apply Template') && view.includes('client-side') && view.includes('add access'), 'template is preset-only and customizable');
ok(view.includes('name="permission_ids[]"') && view.includes('Save User Account'), 'custom permission add/remove submits only on save');
ok(view.includes('Reset to Template') && view.includes('et-reset-template-103335'), 'Reset to Template exists');
ok(view.includes('Customized') && view.includes('et-template-state-103335'), 'customized state is detectable');
ok(service.includes("'model_has_permissions', 'permission_user', 'user_permissions', 'user_permission'"), 'direct permission pivot detection exists');
ok(service.includes('assignedPermissionIds') && service.includes('rolePermissionIds'), 'direct and role IDs are separately exposed');
ok(service.includes('effectivePermissionIds') && service.includes('array_merge('), 'effective permission union is exposed');
ok(service.includes('whereIn($idColumn, $ids)') && service.includes('syncPermissions'), 'invalid permission IDs are validated before persistence');
ok(!controller.match(/permission_ids[^\n]*\b(?:1|2|3)\b/), 'no hard-coded permission IDs');
ok(!controller.match(/role_ids[^\n]*\b(?:1|2|3)\b/), 'no hard-coded role IDs');
for (const section of ['ADMINISTRATION','MASTER DATA','OPERATIONS','ACCOUNTING','TRAVEL REPORTS','SYSTEM / SETTINGS','OTHER / UNMAPPED']) {
  ok(view.includes(section) || matrix.includes(`'${section}'`), `section exists: ${section}`);
}
ok(view.includes('et-section-select-all-103335'), 'section Select All exists');
ok(view.includes('indeterminate'), 'section indeterminate state exists');
ok(view.includes('et-permission-search-103335') && view.includes('data-search'), 'permission search preserves selected values');
ok(view.includes('ROLE') && view.includes('DIRECT') && view.includes('ROLE + DIRECT'), 'inherited/direct badges are supported');
ok(view.includes('Inherited from Native Role'), 'inherited access remains effective when direct checkbox is cleared');
ok(controller.includes("'Cashier'") && controller.includes("'receipt'") && controller.includes("'payment'"), 'Cashier maps transaction entry');
ok(controller.includes("'Accountant'") && controller.includes("'journal'"), 'Accountant maps accounting authority');
ok(controller.includes("'Ticketing Staff'") && controller.includes("'accounting'"), 'Ticketing Staff excludes general accounting');
ok(controller.includes("'Operations Staff'") && controller.includes("'user administration'"), 'Operations Staff excludes accounting/admin by default');
ok(controller.includes("'Umrah Staff'") && controller.includes("'Umrah Manager'"), 'Umrah templates exist');
ok(controller.includes("'sales invoice post'") && controller.includes("'payment post'"), 'Umrah Manager excludes posting/journals/admin');
ok(controller.includes("'Auditor / Read Only'") && controller.includes("'manage'"), 'Auditor maps read-like authority');
ok(view.includes("template.value==='Custom Access'") && controller.includes("'Custom Access' => ['include' => [], 'exclude' => []]"), 'Custom Access leaves selections unchanged');
ok(policy.includes("return 'bookings';") && policy.includes('bookings.products.'), 'Booking product URLs remain Booking Operations');
ok(routes.includes('EnforceErpRoleScopedAccess'), 'direct-route middleware remains');
ok(view.includes('branch_ids[]') && view.includes('primary_branch_id'), 'branch access remains independent');
ok(view.includes('Your own roles are shown but locked') && service.includes('cannot deactivate your own'), 'Super Admin self-protection remains');
ok(view.includes('No dedicated Travel Report permissions exist yet'), 'Travel Reports zero-state exists');
ok(!controller.match(/Travel Report[^\n]*(?:permission|row).*\b(?:create|insert|save)/i), 'no Travel Report permission rows are invented');
ok(!fs.existsSync('database/migrations') || !read('README_DEPLOY.txt').includes('erp113335'), 'no migration introduced');
ok(!read('VERSION.txt').includes('335'), 'release metadata unchanged');

console.log(`ERP-11.3.335 custom permission matrix regression: PASS (${templates.length + 35} assertions)`);
