import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = file => fs.readFileSync(new URL(file, import.meta.url), 'utf8');
const routes = read('../../routes/erp103179.php');
const controller = read('../../app/Http/Controllers/Reports/TravelReportsController.php');
const sidebar = read('../../app/Services/Operations/ServerSidebarComposer.php');
const resolver = read('../../app/Services/Operations/NativeErpLayoutResolver.php');
const index = read('../../resources/views/reports/travel/index.blade.php');
const report = read('../../resources/views/reports/travel/report.blade.php');
const service = read('../../app/Services/Reports/TravelReportService.php');
const middleware = read('../../app/Http/Middleware/ApplyErpReleaseMetadata.php');
const shellCss = read('../../public/erp-theme/et-shell.css');
const passengerSource = read('../../app/Services/Operations/UnifiedGroupPackageDataSource.php');
const passengerController = read('../../app/Http/Controllers/Operations/PassengerWorkspaceController.php');
let assertions = 0;
const ok = (value, message) => { assertions += 1; assert.ok(value, message); };

ok(routes.includes("prefix('travel-reports')"), 'Report Center route prefix');
ok(routes.includes("name('index')"), 'Report Center route name');
for (const key of ['bookings','passengers','air','hotels','visas','transport','group-umrah','customers','suppliers','branches','agents','airlines','sectors']) {
  ok(service.includes(`'${key}'=>`), `known REPORTS key ${key}`);
}
ok((routes.match(/defaults\('report', \$report\)/g) ?? []).length === 2, 'page/export routes bind report defaults');
ok(routes.includes("->defaults('movement', $movement)"), 'movement routes bind movement default');
ok(controller.includes('show(Request $request,string $report)'), 'show controller contract');
ok(controller.includes('export(Request $request,string $report)'), 'export controller contract');
ok(controller.includes('movement(Request $request,string $movement)'), 'movement controller contract');
ok(controller.includes('isset(TravelReportService::REPORTS[$report])'), 'unknown reports fail closed');
ok(controller.includes('isset(TravelReportService::MOVEMENTS[$movement])'), 'unknown movements fail closed');
ok(!routes.includes("Route::get('/travel-reports/{report}'"), 'no generic wildcard report route');
ok(routes.includes("name($report.'.export')"), 'export route names preserved');
ok(routes.includes("name($report)"), 'page route names preserved');
ok(routes.includes("name('group-umrah.'.$movement)"), 'movement route names preserved');
ok(controller.includes("route('travel-reports.'.$report" ) === false, 'controller does not invent route names');
ok(sidebar.includes("'TRAVEL REPORTS' => ['travel reports', 'movement reports']"), 'compact Travel Reports section');
ok(sidebar.includes("['Movement Reports','/travel-reports/group-umrah/arrival']"), 'Movement Reports sidebar entry');
ok(!sidebar.includes("['Report Center','/travel-reports']"), 'Report Center is internal-only');
for (const label of ['Booking Report','Passenger Report','Air / Ticketing Report','Hotel Report','Visa Report','Transport Report','Group Umrah Report','Customer-wise Report','Supplier / Vendor-wise Report','Branch-wise Report','Agent / Salesperson Report','Airline-wise Report','Sector / Destination Report']) ok(!sidebar.includes(`['${label}'`), `deferred sidebar link not synthesized: ${label}`);
ok(sidebar.includes(".//ul[li]"), 'LI-owning UL selected before NAV wrapper');
ok(sidebar.includes('classMatches') && sidebar.includes('count($classMatches[0]) !== 1'), 'class-only ambiguity remains fail closed');
ok(sidebar.includes('data-et-server-sidebar'), 'sidebar composition marker preserved');
ok(sidebar.includes('known[$key] = $row'), 'sidebar insertion is deduplicated');
ok(index.includes("@extends($layoutMeta['layout'])") && report.includes("@extends($layoutMeta['layout'])"), 'views use native layout contract');
ok(index.includes("$layoutMeta['content_section'] ?? 'content'") && report.includes("$layoutMeta['content_section'] ?? 'content'"), 'views use resolved content section');
ok(controller.includes("'layoutMeta'=>$layoutMeta"), 'controller passes layoutMeta data shape');
ok(resolver.includes('fromExistingBookingViews') && resolver.includes('detectPrimarySection'), 'native resolver contract preserved');
ok(!index.match(/<html|<body|class=["']sidebar/iu) && !report.match(/<html|<body|class=["']sidebar/iu), 'views do not create standalone shell');
ok(index.includes('width:100%') && index.includes('max-width:none') && index.includes('min-width:0'), 'index width contract');
ok(report.includes('width:100%') && report.includes('max-width:none') && report.includes('min-width:0'), 'report width contract');
ok(report.includes('overflow-x:auto'), 'table scroll is container-local');
ok(!index.match(/position\s*:\s*(absolute|fixed)|left\s*:\s*-\d+/i) && !report.match(/position\s*:\s*(absolute|fixed)|left\s*:\s*-\d+/i), 'no overlay positioning workaround');
ok(!service.match(/sale_price|cost_price|gross_margin|supplier_cost|profitability|commission/i), 'no financial fields introduced');
ok(middleware.includes('normalizeTravelReportHostTitle'), 'travel report host title normalizer is in final response path');
ok(middleware.includes("'Travel Reports'"), 'travel report host title is explicit');
for (const route of ['travel-reports','travel-reports/sectors','travel-reports/group-umrah/arrival','travel-reports/group-umrah/departure-intimation']) {
  ok(middleware.includes("str_starts_with(strtolower(trim($request->path(), '/')), 'travel-reports')"), `host title route guard covers ${route}`);
}
ok(shellCss.includes('display:flex!important;') && shellCss.includes('flex-direction:column!important;'), 'sidebar owns vertical flex layout');
ok(shellCss.includes('.sidebar .nav') && shellCss.includes('flex:1 1 auto!important'), 'sidebar navigation owns remaining height');
ok(shellCss.includes('.sidebar footer') && shellCss.includes('flex:0 0 auto!important'), 'sidebar release footer remains fixed sibling');
ok(passengerSource.includes('passportValue($a, $columns)'), 'passenger sources use canonical passport resolver');
ok(passengerSource.includes("'passport', 'passport_id', 'document_number'"), 'passport aliases are supported');
ok(passengerController.includes("normalizePassport((string) ($row['passport_no'] ?? ''))"), 'search uses canonical displayed passport');
ok(passengerController.includes('forPage($page, $perPage)'), 'passenger search paginates after filtering');
console.log(`PASS erp113342 travel reports runtime presentation regression (${assertions} assertions)`);
