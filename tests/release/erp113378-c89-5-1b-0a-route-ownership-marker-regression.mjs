import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const ok = (condition, message) => assert.ok(condition, message);

const business = read('app/Http/Middleware/C89ManagedBusinessRoute.php');
const support = read('app/Http/Middleware/C89AuthenticatedSupportAssetRoute.php');
const routes = read('routes/erp103179.php');
const bookingRoutes = read('routes/erp10286.php');
const release = read('config/et_erp_release.php');

ok(business.includes('final class C89ManagedBusinessRoute'), 'business marker exists');
ok(support.includes('final class C89AuthenticatedSupportAssetRoute'), 'support marker exists');
ok(business.includes('return $next($request);'), 'business marker is pass-through');
ok(support.includes('return $next($request);'), 'support marker is pass-through');
const handleBody = (source) => source.slice(source.indexOf('handle('));
ok(!handleBody(business).match(/auth|authorize|DB::|redirect|permission/i), 'business marker has no policy behavior');
ok(!handleBody(support).match(/auth|authorize|DB::|redirect|permission/i), 'support marker has no policy behavior');
ok(routes.includes('Event::listen(RouteMatched::class'), 'global RouteMatched listener remains');
ok(routes.includes('EnforceErpRoleScopedAccess::class'), 'global listener still appends role middleware');
const listener = routes.match(/Event::listen\(RouteMatched::class[\s\S]*?\n\}\);/)?.[0] ?? '';
ok(!listener.includes('C89ManagedBusinessRoute') && !listener.includes('C89AuthenticatedSupportAssetRoute'), 'listener has no ownership condition');
for (const protectedFile of [
  'app/Services/Administration/ErpRoleAccessPolicy.php',
  'app/Http/Middleware/EnforceErpRoleScopedAccess.php',
]) {
  let unchanged = true;
  try { execFileSync('git', ['diff', '--quiet', 'HEAD', '--', protectedFile], { cwd: root, stdio: 'ignore' }); }
  catch { unchanged = false; }
  ok(unchanged, `${protectedFile} remains untouched`);
}
ok(routes.includes("C89ManagedBusinessRoute::class"), 'business marker is registered');
ok(routes.includes("C89AuthenticatedSupportAssetRoute::class"), 'support marker is registered');
ok(bookingRoutes.includes("C89ManagedBusinessRoute::class"), 'booking overlay group is marked');

const supportEndpoints = [
  'erp-professional.css', 'erp-professional.js', 'reports-filter.js', 'booking-focus.js',
  'general-progressive-step1.js', 'general-progressive-step1.css', 'dedicated-product-core.js',
  'products-air.js', 'products-visa.js', 'products-visa-core.js',
  'dedicated-product-navigation.js', 'dedicated-visa-navigation.js',
];
for (const endpoint of supportEndpoints) ok(routes.includes(`/system/erp-assets/${endpoint}`), `${endpoint} remains declared`);
ok(supportEndpoints.length === 12, 'support asset count is 12');
ok(routes.includes("['auth', C89AuthenticatedSupportAssetRoute::class]"), 'support assets remain authenticated');
ok(routes.includes("['auth', C89ManagedBusinessRoute::class]"), 'business routes remain authenticated');
ok(!routes.includes('C89ManagedBusinessRoute::class, C89AuthenticatedSupportAssetRoute::class'), 'no double marker declaration');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C89.5.1B.0A'"), 'release asset identity');
ok(release.includes("'corrective_build' => 'C89.5.1B.0A'"), 'corrective build identity');
ok(release.includes("'corrective_name' => 'C89 Route Ownership Markers'"), 'corrective name identity');
ok(!routes.includes('supplementary.*'), 'no new permission key');
ok(!execFileSync('git', ['diff', '--name-only', 'HEAD'], { cwd: root, encoding: 'utf8' }).split(/\r?\n/).filter(Boolean).some((file) => file.startsWith('database/') || file.startsWith('migrations/')), 'no migration scope added');

console.log('ERP-11.3.378 C89.5.1B.0A route ownership marker regression: PASS (25 assertions)');
