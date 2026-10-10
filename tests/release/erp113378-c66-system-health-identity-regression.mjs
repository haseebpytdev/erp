import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (value, label) => { assert.ok(value, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

const middleware = read('app/Http/Middleware/ApplyErpReleaseMetadata.php');
const release = read('config/et_erp_release.php');
const header = read('resources/views/operations/bookings/partials/booking-workspace-header-v11370.blade.php');
const product = read('resources/views/operations/bookings/partials/product-workspace-v113305.blade.php');
const presenter = read('app/Services/Operations/BookingWorkspaceShellPresenter.php');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAdditionalServiceProductController.php');

ok(/'corrective_build'\s*=>\s*'C(?:66|67)'/.test(release), 'current corrective metadata is explicit');
ok(/'corrective_name'\s*=>\s*'(?:System Health Identity|System Health Identity Dedup)'/.test(release), 'current corrective name is explicit');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version is unchanged');
ok(release.includes("'release' => 'ERP-11.3.378'"), 'release is unchanged');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C47'"), 'asset revision remains C47');
ok(middleware.includes('metric-card'), 'Application target uses metric-card');
ok(middleware.includes('metric-label'), 'Application label authority uses metric-label');
ok(middleware.includes('metric-value'), 'Application version authority uses metric-value');
ok(!middleware.includes('$headingNodes') && !middleware.includes('card-title'), 'Health target no longer depends on heading/card-title authority');
ok(middleware.includes('count($applicationCards) !== 1'), 'zero or multiple Application cards fail closed');
ok(middleware.includes("metric-note") && middleware.includes("$note->appendChild($dom->createTextNode($releaseName))"), 'native metric-note contains configured release');
ok(middleware.includes("createElement('strong', 'Build '"), 'Application identity contains configured build');
ok(middleware.includes('$correctiveName'), 'Application identity contains configured corrective name');
ok(middleware.includes("createElement('div', 'Asset '"), 'Application identity contains configured asset revision');
ok(middleware.includes('.//*[@data-et-corrective-build]'), 'Application identity replacement is bounded and idempotent');
ok(middleware.includes('aside\\b') && middleware.includes('sidebar-foot'), 'sidebar transformation is structurally scoped');
ok(middleware.includes("//*[contains(concat(\" \", normalize-space(@class), \" \"), \" sidebar-foot \")]"), 'sidebar-foot is the target boundary');
ok(!middleware.includes("preg_match_callback") || middleware.includes('sidebar-foot'), 'sidebar does not depend on Live text insertion');
ok(middleware.includes('count($versions) > 2'), 'duplicate sidebar versions are normalized');
ok(middleware.includes("setAttribute('data-et-sidebar-corrective-build'"), 'sidebar build marker is structural');
ok(middleware.includes("createTextNode('Build '.$correctiveBuild)"), 'sidebar displays Build identity');
ok(!middleware.includes('Supplementary Presentation Polish') && !middleware.includes('Asset C47'), 'full corrective name and asset are not hardcoded in sidebar');
ok(middleware.includes('while ($versions[0]->firstChild)'), 'sidebar transformation is idempotent');
ok(header.includes('$headerReadOnly = $headerReadOnly ?? (!empty($lock[\'locked\']))'), 'C65 header fallback preserved');
ok(product.includes("'headerReadOnly' => $context->isSupplementary() ? ! $supplementaryWritable : null"), 'C65 supplementary header override preserved');
ok(presenter.includes('additional-services/\\d+/products/(?:air|hotel|transport|visa)'), 'C64 focused-shell classifier preserved');
ok(product.includes('data-billing-writable') && product.includes('$supplementaryWritable'), 'C63 writable markers preserved');
ok(!controller.split('public function apiStore')[0].includes('catch (Throwable') && controller.includes('productKey(string $product)'), 'Transport error visibility and product allowlist preserved');
ok(!middleware.includes('GeneralBookingTransport') && !presenter.includes('GeneralBookingTransport'), 'no business logic was added');
ok(!middleware.includes('database/migrations') && !middleware.includes('shell_exec') && !middleware.includes('git show'), 'no migration or runtime Git dependency added');
ok(!middleware.includes("str_replace($version") && !middleware.includes("str_replace($releaseName"), 'no unrestricted global release/version replacement was added');

console.log('PASS ' + pass + ' ERP-11.3.378 C66 System Health identity assertions');
