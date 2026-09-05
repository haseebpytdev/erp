import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
let checks = 0;
const contains = (text, needle, label) => { assert.ok(text.includes(needle), label); checks += 1; };
const excludes = (text, needle, label) => { assert.ok(!text.includes(needle), label); checks += 1; };

const routes = read('routes/erp103179.php');
const bookingVisa = read('app/Http/Controllers/Operations/GeneralBookingVisaProductController.php');
const masterController = read('app/Http/Controllers/Operations/VisaMasterController.php');
const repository = read('app/Services/Operations/LegacyVisaTravelMasterRepository.php');
const resolver = read('app/Services/Operations/VisaMasterRelationshipResolver.php');
const view = read('resources/views/operations/bookings/visa-masters-v113147.blade.php');
const workspace = read('public/erp11390/general-progressive-step1.js');
const workspaceCss = read('public/erp11390/general-progressive-step1.css');
const voucher = read('resources/views/operations/bookings/general-client-voucher-v113142.blade.php');

assert.match(read('VERSION.txt').trim(), /^v1\.1\.33\.(?:15[2-9]|1[6-9]\d|[2-9]\d\d)-ERP11\.3\.(?:15[2-9]|1[6-9]\d|[2-9]\d\d)$/); checks += 1;
contains(routes, "Route::get('/master-data/travel-masters/visa-management'", 'canonical Visa Management GET route');
contains(routes, "->name('travel-masters.visa-management')", 'canonical Visa Management route is named');
contains(routes, "return redirect()->route('travel-masters.visa-management'", 'obsolete GET path only redirects to the canonical route');

contains(repository, "str_starts_with($table, 'visa_')", 'parallel Visa master tables are excluded');
contains(repository, "preg_match('/^PKI[-_]/'", 'Pakistani IATA fallback requires explicit identity');
excludes(repository, "preg_match('/^TRN", 'Transport prefixes are never accepted as IATA identity');
for (const alias of ['default_supplier_vendor_id', 'default_vendor_account_id', 'default_supplier_account_id', 'vendor_account', 'supplier_account']) {
  contains(repository, `'${alias}'`, `native vendor representation supported: ${alias}`);
}
contains(repository, "'default_vendor_party_id'", 'live-confirmed native Pakistani IATA Vendor field is supported');
contains(repository, "'linked_pakistan_iata_operator_id'", 'live-confirmed native Saudi-to-IATA field is supported');
contains(repository, '$this->relationshipResolver->resolve(', 'all Visa consumers share one relationship resolver');
contains(resolver, "'VENDOR LINK REQUIRED'", 'exact missing-vendor status is emitted');
contains(resolver, "'IATA LINK REQUIRED'", 'exact missing-IATA status is emitted');
contains(resolver, "count($matches) === 1", 'ambiguous fallback relationships are rejected');

contains(view, 'data-iata="{{ $s[\'pakistani_iata_name\'] }}"', 'Saudi option exposes resolved IATA safely');
contains(view, 'data-vendor="{{ $s[\'vendor_name\'] }}"', 'Saudi option exposes resolved Vendor safely');
contains(view, 'readonly aria-readonly="true"', 'resolved rate fields are read-only');
contains(view, "option.dataset.vendor", 'rate form refreshes Vendor from selected Saudi chain');
excludes(view, 'innerHTML', 'Visa Management performs no dynamic HTML interpolation');
contains(masterController, "'is_active' => ['required', 'boolean']", 'Visa Rate status is validated');
contains(masterController, "($saudi['link_complete'] ?? false)", 'Visa Rate save rejects an incomplete chain');
contains(masterController, "DB::table('visa_rate_cards')->insert($row)", 'Visa Rate creation appends a new effective-dated row');
excludes(masterController, "DB::table('visa_rate_cards')->update(", 'Visa Rate creation does not overwrite historical rows');

contains(bookingVisa, "->where('effective_from', '<=', $today)", 'future Visa rates are excluded');
contains(bookingVisa, "orWhere('effective_to', '>=', $today)", 'expired Visa rates are excluded');
contains(bookingVisa, "findSaudiByKey($saudiMasterTable.':'.$saudiMasterId)", 'booking save re-resolves native Saudi authority');
contains(bookingVisa, "($saudi['vendor_id'] ?? 0)", 'booking snapshots the downstream Vendor');
contains(workspace, 'var modalPageSize=10', 'Visa passenger popup remains paginated');
contains(workspace, "available.forEach(function(p){selection[String(p.id)]=true;})", 'Select All spans all eligible passengers');
excludes(workspace, "copy.innerHTML='<strong>'+String(p.name||'Passenger')", 'unsafe passenger interpolation remains absent');
contains(workspace, "['','Passenger Name','Passport No.','Fare Type']", 'approved compact passenger table columns are rendered');
contains(workspace, "q.placeholder='Search passenger name or passport'", 'approved passenger search copy is rendered');
contains(workspace, "String(p.passport_number||'No passport')", 'missing passport has approved fallback');
contains(workspace, "etgp-visa-fare-badge-113152", 'fare type renders as compact badge');
contains(workspace, "Showing '+(filteredPassengers.length?start+1:0)+'–'", 'modal footer shows the visible passenger range');
contains(workspace, "confirm.disabled=count===0", 'Add Selected is disabled when selection is empty');
contains(workspace, "var available=passengers.filter(function(p){return !already[String(p.id)];});", 'already-added Visa passengers remain excluded');
contains(workspaceCss, 'grid-template-columns:34px minmax(0,1.55fr) minmax(0,1fr) 88px', 'approved desktop table uses four compact columns');
contains(workspaceCss, 'overflow-x:hidden!important', 'passenger table prevents desktop horizontal scrolling');
contains(workspaceCss, '@media(max-width:700px)', 'approved modal has a small-screen layout');

for (const forbidden of ['vendor_cost_pkr', 'cost_currency', 'exchange_rate', 'margin_pkr', 'vendor_id']) {
  excludes(voucher, forbidden, `client voucher excludes ${forbidden}`);
}

const importedAppClasses = new Map();
for (const match of routes.matchAll(/^use (App\\[^;]+);$/gm)) {
  const className = match[1].split('\\').at(-1);
  const relative = `${match[1].replaceAll('\\', '/')}.php`.replace(/^App\//, 'app/');
  assert.ok(fs.existsSync(path.join(root, relative)), `route import exists: ${relative}`); checks += 1;
  importedAppClasses.set(className, relative);
}
for (const match of routes.matchAll(/\[([A-Za-z][A-Za-z0-9_]*)::class,\s*'([A-Za-z][A-Za-z0-9_]*)'\]/g)) {
  const [, className, method] = match;
  const relative = importedAppClasses.get(className);
  assert.ok(relative, `route controller ${className} is imported`); checks += 1;
  assert.match(read(relative), new RegExp(`function\\s+${method}\\s*\\(`), `route action exists: ${className}::${method}`); checks += 1;
}

console.log(`ERP-11.3.152 static regression checks passed: ${checks}`);
