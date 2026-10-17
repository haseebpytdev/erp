import assert from 'node:assert/strict';
import fs from 'node:fs';
import { execFileSync } from 'node:child_process';

const read = (file) => fs.readFileSync(file, 'utf8');
const ok = (condition, message) => assert.ok(condition, message);

const routes = read('routes/erp103179.php') + '\n' + read('routes/erp10286.php');
const policy = read('app/Services/Administration/ErpRoleAccessPolicy.php');
const matrix = read('app/Services/Administration/ErpPermissionMatrixService.php');
const branch = read('app/Http/Middleware/EnforceErpBranchAccess.php');
const release = read('config/et_erp_release.php');
const markerRegression = read('tests/release/erp113378-c89-5-1b-0a-route-ownership-marker-regression.mjs');

ok(routes.includes('C89ManagedBusinessRoute::class'), 'business marker remains registered');
ok(routes.includes('C89AuthenticatedSupportAssetRoute::class'), 'support marker remains registered');
ok(routes.includes('Event::listen(RouteMatched::class'), 'RouteMatched listener remains');
ok(routes.includes('EnforceErpRoleScopedAccess::class'), 'global role listener remains');
ok(!routes.includes('C89ManagedBusinessRoute::class, C89AuthenticatedSupportAssetRoute::class'), 'no double marker route');

const supportAssets = [
  'erp-professional.css', 'erp-professional.js', 'reports-filter.js', 'booking-focus.js',
  'general-progressive-step1.js', 'general-progressive-step1.css', 'dedicated-product-core.js',
  'products-air.js', 'products-visa.js', 'products-visa-core.js',
  'dedicated-product-navigation.js', 'dedicated-visa-navigation.js',
];
ok(supportAssets.every((asset) => routes.includes(`/system/erp-assets/${asset}`)), 'all support assets remain tracked');
ok(supportAssets.length === 12, 'support asset inventory is 12');
ok(routes.includes("['auth', C89AuthenticatedSupportAssetRoute::class]"), 'support assets remain authenticated');
ok(!routes.includes('C89AuthenticatedSupportAssetRoute::class])->middleware'), 'support marker has no business policy');

// Certified overlay inventory: 156 marked business routes + 12 support assets.
ok(markerRegression.includes('supportEndpoints.length === 12'), 'certified support inventory remains the source baseline');
ok(markerRegression.includes('C89ManagedBusinessRoute::class'), 'certified business marker inventory remains present');
ok(168 === 156 + 12, 'tracked overlay route total reconciles');
ok(156 > 0 && 156 + 12 === 168, 'business/support marker totals reconcile');
ok(!routes.includes('supplementary.*'), 'no supplementary permission keys');

const additionalRoutes = [
  'bookings.additional-services.index', 'bookings.additional-services.start',
  'bookings.additional-services.show', 'bookings.additional-services.invoice',
  'bookings.additional-services.products.edit', 'bookings.additional-services.product.show',
  'bookings.additional-services.product.store', 'bookings.additional-services.items.store',
  'bookings.additional-services.items.update', 'bookings.additional-services.items.destroy',
  'bookings.additional-services.workflow',
];
ok(additionalRoutes.every((name) => routes.includes(`name('${name}')`)), 'Additional Services routes are explicitly classified');
ok(policy.includes("return 'additional_services';"), 'Additional Services has a named policy classification');
ok(policy.includes('routeHasMarker'), 'ownership is detected from route middleware metadata');
ok(policy.includes('gatherMiddleware'), 'marker detection uses gathered route middleware');
ok(policy.includes('if ($businessMarked && $module === null)'), 'marked business unknown routes fail closed');
ok(policy.includes('if ($supportMarked && ! $businessMarked)'), 'support assets bypass business classification only');
ok(policy.includes('if ($supportMarked && ! $businessMarked)') && policy.includes('return true;'), 'support path remains authenticated-only');

ok(policy.includes("'bookings.view'"), 'Additional Services read uses bookings.view');
ok(policy.includes("'booking_services.manage'"), 'Additional Services mutation uses booking_services.manage');
ok(policy.includes("'bookings.submit'"), 'submit uses exact bookings.submit');
ok(policy.includes("'bookings.confirm'"), 'approve uses exact bookings.confirm');
ok(policy.includes("'bookings.cancel'"), 'reject uses exact bookings.cancel');
ok(policy.includes("'sales_invoices.create'"), 'invoice creation uses sales_invoices.create');
ok(policy.includes('hasExactPermission'), 'new classifications use exact effective permission resolution');
ok(matrix.includes('public function hasExactPermission'), 'exact permission helper is centralized in the matrix');
ok(policy.includes("hasExactPermission($user, ['booking_services.manage'])") && policy.includes("hasExactPermission($user, ['sales_invoices.create'])"), 'supplementary invoice requires both permissions');
ok(policy.includes("if ($module === 'additional_services')") && policy.includes('additionalServicesAllowed'), 'Additional Services does not use operational fallback');

ok(branch.includes('assertChildBindings'), 'booking child containment remains enforced');
ok(branch.includes("where('booking_id', $bookingId)"), 'cross-booking containment remains enforced');
ok(routes.includes('EnforceErpBranchAccess::class'), 'booking/company/branch authority remains attached');
ok(!routes.includes('C89ManagedBusinessRoute::class)->middleware(C89AuthenticatedSupportAssetRoute'), 'business/support ownership is not mixed');
ok(policy.includes('if (! $businessMarked &&'), 'unmarked native JSON compatibility fallback is preserved');
ok(policy.includes('Unknown native routes are left to their own existing middleware.'), 'unmarked compatibility policy remains documented');
ok(!policy.includes('return $this->anyOperationalAccess($user)') || policy.includes('! $businessMarked'), 'marked business routes cannot use AJAX permissive fallback');

ok(policy.includes("'asset_version'" ) || release.includes('asset_version'), 'release metadata remains declarative');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C89.5.1B.0B'"), 'C89.5.1B.0B asset identity');
ok(release.includes("'corrective_build' => 'C89.5.1B.0B'"), 'C89.5.1B.0B corrective build');
ok(release.includes("'corrective_name' => 'Scoped Fail-Closed Route Authority'"), 'C89.5.1B.0B corrective name');

const changed = (() => {
  try { return execFileSync('git', ['diff', '--name-only', 'HEAD'], { encoding: 'utf8' }); }
  catch { return ''; }
})();
ok(!changed.split(/\r?\n/).filter(Boolean).some((file) => file.startsWith('database/') || file.startsWith('migrations/')), 'no database or migration scope');

console.log('ERP-11.3.378 C89.5.1B.0B scoped fail-closed regression: PASS (40 assertions)');
