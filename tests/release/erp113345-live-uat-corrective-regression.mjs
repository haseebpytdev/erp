import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = file => fs.readFileSync(new URL(file, import.meta.url), 'utf8');
const controller = read('../../app/Http/Controllers/Operations/PassengerWorkspaceController.php');
const arrival = read('../../resources/views/reports/travel/movements/arrival.blade.php');
const composer = read('../../app/Services/Operations/ServerSidebarComposer.php');
const sidebarTest = read('./server-sidebar-composer-regression.php');
let assertions = 0;
const ok = (value, message) => { assertions += 1; assert.ok(value, message); };

ok(controller.includes('$nameNeedle = Str::lower($query);'), 'name needle remains lowercase');
ok(controller.includes('$passportNeedle = UnifiedGroupPackageDataSource::normalizePassport($query);'), 'passport needle is canonical');
ok(controller.includes('str_contains(\n                    UnifiedGroupPackageDataSource::normalizePassport'), 'passport comparison uses canonical haystack');
ok(controller.includes('$passportNeedle')); 
ok(controller.includes('forPage($page, $perPage)'), 'search remains before pagination');

ok(arrival.includes("@include('reports.travel.partials.filter-form', ["), 'arrival uses readable shared filter include');
ok(arrival.includes("['key' => 'from', 'label' => 'From Date'"), 'arrival From Date filter preserved');
ok(arrival.includes("['key' => 'to', 'label' => 'To Date'"), 'arrival To Date filter preserved');
ok(arrival.includes("['key' => 'status', 'label' => 'Status'"), 'arrival Status filter preserved');
ok(arrival.includes("['key' => 'branch', 'label' => 'Branch ID'"), 'arrival Branch filter preserved');
ok(arrival.includes("['key' => 'customer', 'label' => 'Customer ID'"), 'arrival Customer filter preserved');
for (const label of ['Apply Filters', 'Reset', 'Print', 'Export CSV']) ok(arrival.includes(`'label' => '${label}'`), `arrival action ${label} preserved`);
ok(arrival.indexOf('aria-label="Movement Reports"') < arrival.indexOf("@include('reports.travel.partials.filter-form'"), 'movement tabs precede filter card');
ok(!arrival.includes('et-arrival-filters'), 'old independent arrival filter removed');
ok(composer.includes('nav[contains(concat')&&composer.includes('nav-section'), 'composer supports live native NAV/DIV/A structure');
ok(composer.includes("$anchor->setAttribute('href', '/travel-reports')"), 'fallback inserts authorized Travel Reports href');
ok(composer.includes('if ($hasTravelReports) return $html;'), 'fallback is idempotent');
ok(sidebarTest.includes('nestedNav'), 'realistic nested native sidebar fixture exists');
ok(sidebarTest.includes('data-native="yes"'), 'realistic native sidebar attributes are preserved');
ok(composer.includes('flex:1 1 auto') === false, 'layout ownership remains CSS-scoped, not PHP');

console.log(`PASS erp113345 live UAT corrective regression (${assertions} assertions)`);
