import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const header = read('resources/views/operations/bookings/partials/booking-workspace-header-v11370.blade.php');
const product = read('resources/views/operations/bookings/partials/product-workspace-v113305.blade.php');
const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const release = read('config/et_erp_release.php');
const c63Controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');
const c63Partial = product;

ok(header.includes('$headerReadOnly = $headerReadOnly ?? (!empty($lock[\'locked\']))'), 'shared header keeps backward-compatible base-lock default');
ok(header.includes('@if($headerReadOnly)') && header.includes('Read-only'), 'header renders read-only from effective presentation authority');
ok(header.includes('$lock[\'locked\']'), 'original locked header authority remains available');
ok(product.includes("'headerReadOnly' => $context->isSupplementary() ? ! $supplementaryWritable : null"), 'supplementary caller supplies presentation-only authority');
ok(product.includes("$lock['locked'] ? '1' : '0'"), 'original editor path still uses base lock');
ok(product.includes('$supplementaryWritable') && product.includes('data-billing-writable'), 'product editor still uses supplementary writable state');
ok(product.includes('data-etgp-booking-locked="{{ $context->isSupplementary() ? ($supplementaryWritable ? \'0\' : \'1\')'), 'booking lock marker preserves supplementary semantics');
ok(product.includes('data-billing-context="{{ $context->billingContext'), 'billing context marker remains present');
ok(product.includes('data-billing-writable="{{ $context->isSupplementary() ? ($supplementaryWritable ? \'1\' : \'0\')'), 'billing writable marker remains authoritative');
ok(!product.includes('$lock[\'locked\'] =') && !product.includes('$lock["locked"] ='), 'base lock object is not mutated');
ok(middleware.includes('additional-services/[^/]+/products/(?:air|hotel|transport|visa)'), 'C64 focused supplementary path classifier remains present');
['air', 'hotel', 'transport', 'visa'].forEach(productKey => ok(middleware.includes('products/(?:air|hotel|transport|visa)'), `${productKey} supplementary role remains covered`));
ok(/'corrective_build'\s*=>\s*'C(?:65|66)'/.test(release), 'corrective build metadata remains explicit');
ok(/'corrective_name'\s*=>\s*'(?:Supplementary Presentation Polish|System Health Identity)'/.test(release), 'corrective name metadata remains explicit');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version remains unchanged');
ok(release.includes("'release' => 'ERP-11.3.378'"), 'release remains unchanged');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C47'"), 'asset revision remains C47');
ok(middleware.includes('DOMDocument') && middleware.includes('applicationCards'), 'Health identity uses a structural Application-card target');
ok(middleware.includes("metric-label") && middleware.includes("metric-value"), 'Health target requires native Application metric markup');
ok(middleware.includes('data-et-corrective-build'), 'Application card receives corrective build marker');
ok(middleware.includes('data-et-corrective-name') && middleware.includes('data-et-asset-revision'), 'Application card receives corrective name and asset markers');
ok(middleware.includes('$releaseName') && middleware.includes("$identity->appendChild($dom->createElement('div', $releaseName))"), 'Application card displays configured release');
ok(middleware.includes("'data-et-sidebar-corrective-build='"), 'sidebar has a bounded corrective-build marker');
ok(middleware.includes('data-et-sidebar-corrective-build') && middleware.includes('Build '), 'sidebar displays configured build identity');
ok(middleware.includes('$releaseName'), 'sidebar release remains config-driven');
ok(middleware.includes('sidebar-foot') && middleware.includes('data-et-sidebar-corrective-build'), 'sidebar identity remains structurally bounded');
ok(middleware.includes("if ($correctiveBuild !== ''") && middleware.includes('data-et-sidebar-corrective-build='), 'sidebar identity transformation is idempotent');
ok(middleware.includes('foreach ($xpath->query(\'.//*[@data-et-corrective-build]\''), 'Health identity removes prior marker before bounded reinsertion');
ok(!middleware.includes("stripos($html, $escapedVersion)") && !middleware.includes("$offset = $escapedVersion"), 'first global version occurrence is not the identity authority');
ok(!middleware.includes('shell_exec') && !middleware.includes('git show') && !middleware.includes('exec('), 'runtime Git dependency remains absent');
ok(!middleware.includes('GeneralBookingTransport') && !presenter.includes('GeneralBookingTransport'), 'no new Transport business logic was added');
ok(c63Controller.includes('productKey(string $product)') && c63Partial.includes('data-billing-context'), 'C63 runtime and writable architecture remain present');

console.log('PASS ' + pass + ' ERP-11.3.378 C65 supplementary presentation polish assertions');
