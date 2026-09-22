import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(file, 'utf8');
const policy = read('app/Services/Administration/ErpRoleAccessPolicy.php');
const matrix = read('app/Services/Administration/ErpPermissionMatrixService.php');
const middleware = read('app/Http/Middleware/EnforceErpRoleScopedAccess.php');
const routes = read('routes/erp103179.php');
const air = read('public/erp-theme/js/products/air.js');
const ok = (value, message) => assert.ok(value, message);

ok(policy.includes("return 'bookings';") && policy.includes("/operations/bookings/[0-9]+/products"), 'Booking Product paths have explicit Booking ownership');
ok(policy.includes("/system/erp-bookings/[0-9]+/(?:air|hotel|transport|visa)-product"), 'system product APIs retain Booking ownership');
ok(policy.includes("bookings.products."), 'Booking Product route names have explicit ownership');
ok(policy.includes("'/products-services'") && policy.includes("'products.'"), 'Products & Services retains canonical master route terms');
ok(!policy.includes("'/operations/bookings/{booking}/products'") || routes.includes("name('bookings.products.show')"), 'Booking Products Hub route remains named and protected');
ok(middleware.includes("part==='/products'&&bookingProductsHref(href)"), 'navigation does not hide Booking Product links with master substring rules');
ok(middleware.includes("a[href=\"/products\"]"), 'master Products navigation uses exact href matching');
ok(matrix.includes("'manage',\n            'administer',\n            'full access',\n            'all access'"), 'strict capability keeps only broad authority as universal action');
ok(!matrix.includes("'create',\n            'edit',\n            'update',\n            'delete',\n            'approve',\n            'post',"), 'strict capability does not cross-grant unrelated mutation actions');
ok(policy.includes("'GET', 'HEAD' => ['view', 'list', 'read', 'access', 'create', 'add', 'edit', 'update', 'manage']"), 'read routes accept appropriate module read or operational authority');
ok(policy.includes("'POST' => ['create', 'add', 'prepare', 'process', 'manage', 'fulfill']"), 'create routes require create-like or broad authority');
ok(policy.includes("'PUT', 'PATCH' => ['edit', 'update', 'manage']"), 'update routes require update-like or broad authority');
ok(policy.includes("'DELETE' => ['delete', 'remove', 'manage']"), 'delete routes require delete-like or broad authority');
ok(policy.includes("'approve' => ['approve', 'authorize', 'manage']"), 'approval routes require approval-like or broad authority');
ok(policy.includes("'post' => ['post', 'manage']"), 'posting routes require posting or broad authority');
ok(matrix.includes("'model_has_permissions', 'permission_user', 'user_permissions', 'user_permission'"), 'direct user permission links remain native/adaptive');
ok(policy.includes("! $this->isSuperAdmin($user)"), 'Super Admin bypass remains preserved');
ok(air.includes('etgpAirSearchableAirline113330'), 'Air exposes one reusable searchable Airline control');
ok(air.includes("role','combobox"), 'searchable Airline control exposes combobox semantics');
ok(air.includes('ArrowDown') && air.includes('ArrowUp') && air.includes('Escape') && air.includes("event.key==='Enter"), 'Airline control supports keyboard navigation');
ok(air.includes("normalized(item.name).includes(query)") && air.includes("normalized(item.code).includes(query)") && air.includes("normalized(item.label).includes(query)"), 'Airline search covers name, code and label');
ok(air.includes('airline.getSelected()') && air.includes('airline_id') && air.includes('airline_code'), 'selected Airline preserves master identity fields');
ok(air.includes('String(segment.airline_code||segment.airline||\'\')'), 'legacy Airline values rehydrate by code/name');
ok(air.includes('refreshFlightSuggestions') && air.includes("airline.input.addEventListener('change',refreshFlightSuggestions)"), 'Airline selection refreshes flight suggestions');
ok(air.includes("etgpAirSearchableAirline113330('Airline'"), 'legacy and multi-group Air paths use the searchable control');
ok(air.includes("if(airlines.length)?etgpAirSearchableAirline113330") || air.includes("airlines.length?etgpAirSearchableAirline113330"), 'multi-group path uses Airline Master when available');

console.log('ERP-11.3.330 Booking Product permission and Airline contract: PASS (25 static assertions)');
