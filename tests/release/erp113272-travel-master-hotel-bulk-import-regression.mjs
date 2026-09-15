import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const read = p => fs.readFileSync(path.join(root, p), 'utf8');

let n = 0;

const ok = (value, message) => {
    if (!value) {
        throw new Error(message);
    }

    n++;
};

const method = (source, needle) => {
    const first = source.indexOf(needle);

    if (first < 0) {
        throw new Error(`method not found: ${needle}`);
    }

    if (source.indexOf(needle, first + needle.length) >= 0) {
        throw new Error(`method not unique: ${needle}`);
    }

    const open = source.indexOf('{', first);

    if (open < 0) {
        throw new Error(`method opening brace not found: ${needle}`);
    }

    let depth = 0;

    for (let i = open; i < source.length; i++) {
        if (source[i] === '{') {
            depth++;
        } else if (source[i] === '}') {
            depth--;

            if (depth === 0) {
                return source.slice(first, i + 1);
            }
        }
    }

    throw new Error(`method closing brace not found: ${needle}`);
};

const compact = value => value.replace(/\s+/g, ' ').trim();

const routes = read('routes/erp103179.php');
const authority = read(
    'app/Services/Operations/NativeHotelMasterAuthority.php'
);
const middleware = read(
    'app/Http/Middleware/PresentTravelMasterHotelBulkImport.php'
);
const controller = read(
    'app/Http/Controllers/Operations/HotelMasterBulkImportController.php'
);
const hierarchy = read('app/Http/Middleware/PresentTravelMasterHierarchy.php');
const uiPolish = read('app/Http/Middleware/PresentTravelMasterUiPolish.php');

const previewMethod = method(
    authority,
    'public function preview('
);

const importMethod = method(
    authority,
    'public function import('
);

const hotelRowsMethod = method(
    authority,
    'private function hotelRows('
);

const textKeyMethod = method(
    authority,
    'private function textKey('
);

const requiredMethod = method(
    authority,
    'function requiredUnresolvedColumns('
);

const previewCompact = compact(previewMethod);
const importCompact = compact(importMethod);
const hotelRowsCompact = compact(hotelRowsMethod);
const textKeyCompact = compact(textKeyMethod);
const requiredCompact = compact(requiredMethod);

/* Existing route/controller/security contract. */
ok(
    routes.includes('travel-masters.hotels.bulk-template'),
    'template route'
);

ok(
    routes.includes('travel-masters.hotels.bulk-preview'),
    'preview route'
);

ok(
    routes.includes('travel-masters.hotels.bulk-import'),
    'import route'
);

ok(
    routes.includes('PresentTravelMasterHotelBulkImport'),
    'middleware'
);

ok(
    routes.includes("Route::middleware(['auth'])"),
    'auth scope'
);

ok(
    controller.includes('preview')
        && controller.includes('import'),
    'actions'
);

ok(
    controller.includes('isSuperAdmin')
        && controller.includes('hasPermissionLike'),
    'write auth'
);

/* Runtime native authority. */
ok(
    authority.includes('Schema::getColumnListing')
        && authority.includes('discover'),
    'runtime table discovery'
);

ok(
    authority.includes('booking_') === false,
    'booking tables excluded'
);

ok(
    authority.includes('city_id')
        && authority.includes('travel_city_id'),
    'FK city support'
);

ok(
    /\[\s*['"]city['"]\s*,\s*['"]city_name['"]\s*,\s*['"]location['"]\s*,?\s*\]/
        .test(authority),
    'text city support'
);

ok(
    authority.includes('hasRequiredStorage'),
    'required-column fail closed'
);

ok(
    authority.includes('DB::transaction'),
    'transaction'
);

ok(
    authority.includes('nextUniqueCode'),
    'import recompute'
);

ok(
    authority.includes('canonicalHotelName'),
    'canonical aliases'
);

ok(
    authority.includes('1000 data rows'),
    '1000 row cap'
);

ok(
    authority.includes('preg_replace'),
    'BOM'
);

/* UI safety contract. */
ok(
    middleware.includes('input type="file"')
        && middleware.includes('data-csv-text'),
    'file and paste UI'
);

ok(
    middleware.includes('data-preview')
        && middleware.includes('data-import'),
    'explicit actions'
);

ok(
    middleware.includes("Accept':'application/json"),
    'JSON accept'
);

ok(
    middleware.includes('const esc='),
    'HTML escaping'
);

ok(
    middleware.includes('Rows Submitted:')
        && middleware.includes('Import Complete'),
    'result summary'
);

ok(
    middleware.includes('!r.ok'),
    'non-2xx handling'
);

ok(
    !middleware.includes("<td>'+r.city+'</td>"),
    'raw CSV HTML absent'
);

/* Existing import safety. */
ok(
    authority.includes('count($lines) > 1000'),
    '1001 rejected'
);

ok(
    previewMethod.includes("['generated_code']")
        && previewMethod.includes('incrementCode'),
    'preview sequence'
);

ok(
    /\[\s*['"]city['"]\s*,\s*['"]city_name['"]\s*,\s*['"]location['"]\s*,?\s*\]/
        .test(importMethod),
    'text city write'
);

ok(
    !authority.includes('Schema::create')
        && !authority.includes('booking_services'),
    'no schema/booking writes'
);

ok(
    !fs.existsSync(
        path.join(
            root,
            'database/migrations/2026_09_15_120000_hotel_bulk_import.php'
        )
    ),
    'no migration'
);

ok(
    read('VERSION.txt').trim()
        === 'v1.1.33.287-ERP11.3.287',
    'version unchanged'
);

ok(
    authority.includes('Schema::getTables'),
    'runtime Schema table scan'
);

ok(
    authority.includes('private function identity'),
    'shared identity helper'
);

ok(
    authority.includes('requiredUnresolvedColumns')
        && authority.includes('Schema::getColumns'),
    'column metadata introspection'
);

ok(
    authority.includes('str_starts_with'),
    'booking table exclusion'
);

/* .272 closure: Hotel-table storage mode is authoritative. */
ok(
    authority.includes('hotelUsesCityFk')
        && authority.includes('hotelIdentity'),
    'hotel storage-mode identity helpers'
);

ok(
    hotelRowsMethod.includes('identityFromValues'),
    'hotelRows shared identity'
);

ok(
    !hotelRowsMethod.includes('$this->key(')
        && !hotelRowsMethod.includes('$this->textKey('),
    'hotelRows direct key paths removed'
);

/* Preview closure. */
ok(
    previewMethod.includes('hotelUsesCityFk'),
    'preview storage-mode detection'
);

ok(
    previewMethod.includes('hotelIdentity'),
    'preview shared identity'
);

ok(
    previewMethod.includes('$aliasIdentity')
        && previewMethod.includes('$aliasName'),
    'preview alias identity'
);

ok(
    previewMethod.includes("$row['city_id'] <= 0"),
    'preview FK city requires id'
);

ok(
    !previewMethod.includes('$this->key('),
    'preview direct key path removed'
);

/* Import closure. */
ok(
    importMethod.includes('hotelUsesCityFk'),
    'import storage-mode detection'
);

ok(
    importMethod.includes('hotelIdentity'),
    'import shared identity'
);

ok(
    importMethod.includes("$resolvedCity['id'] <= 0"),
    'import FK city requires id'
);

ok(
    importMethod.includes('isset($hotels[$identity])'),
    'import duplicate recheck shared identity'
);

ok(
    importMethod.includes('$hotels[$identity]'),
    'import inserted map shared identity'
);

ok(
    !importMethod.includes('$this->key('),
    'import direct key path removed'
);

/* Deterministic field writes. */
ok(
    importCompact.includes(
        "'city_iata', 'iata', 'iata_code', 'city_code'"
    ),
    'city IATA write'
);

ok(
    importCompact.includes(
        "'country', 'country_code', 'country_iso'"
    )
        && importMethod.includes("'SA'"),
    'country write'
);

ok(
    importMethod.includes("$row['created_at'] = $now;"),
    'created_at actual write'
);

ok(
    importMethod.includes("$row['updated_at'] = $now;"),
    'updated_at actual write'
);

ok(
    importMethod.indexOf("$row['created_at'] = $now;")
        < importMethod.indexOf(')->insert($row)'),
    'created_at before insert'
);

ok(
    importMethod.indexOf("$row['updated_at'] = $now;")
        < importMethod.indexOf(')->insert($row)'),
    'updated_at before insert'
);

/* Single TEXT identity authority. */
ok(
    textKeyMethod.includes('identityFromValues'),
    'textKey delegates'
);

ok(
    /identityFromValues\s*\(\s*0\s*,\s*\$city\s*,\s*\$name\s*\)/
        .test(textKeyMethod),
    'textKey zero-id delegation'
);

ok(
    !textKeyMethod.includes("'TEXT:'")
        && !textKeyMethod.includes('canonicalCityFamily'),
    'textKey duplicate normalization absent'
);

/* Required-column fail-closed contract. */
for (const field of [
    'created_at',
    'updated_at',
    'name',
    'hotel_name',
    'title',
    'property_name',
    'city_id',
    'travel_city_id',
    'city',
    'city_name',
    'location',
    'code',
    'hotel_code',
    'property_code',
    'city_iata',
    'iata',
    'iata_code',
    'city_code',
    'country',
    'country_code',
    'country_iso',
    'is_active',
    'active',
]) {
    ok(
        requiredMethod.includes(`'${field}'`)
            || requiredMethod.includes(`"${field}"`),
        `required safe field ${field}`
    );
}

ok(
    !requiredCompact.includes("'id',")
        && !requiredCompact.includes('"id",'),
    'non-auto id not safe-whitelisted'
);

for (const field of [
    'status',
    'vendor_id',
    'supplier_id',
    'default_vendor_id',
    'default_supplier_id',
    'star_rating',
    'stars',
    'rating',
    'phone',
    'phone_no',
    'contact',
    'address',
    'hotel_address',
    'notes',
    'remarks',
    'description',
]) {
    ok(
        !requiredMethod.includes(`'${field}'`)
            && !requiredMethod.includes(`"${field}"`),
        `unknown required field fails closed ${field}`
    );
}

ok(
    requiredMethod.includes('__METADATA_UNAVAILABLE__'),
    'metadata failure fail closed'
);

ok(
    middleware.includes("replace(/[^a-z0-9]+/gi,' ')")
    && middleware.includes("n(x.textContent)==='add hotel'"),
    'native + Add Hotel anchor normalization'
);

/* Native company-context contract: dynamic, authenticated, fail-closed. */
ok(controller.includes('private function companyId(Request $request)'), 'controller company resolver');
ok(controller.includes("['company_id', 'current_company_id', 'active_company_id']"), 'explicit company authority');
ok(!controller.includes("['primary_branch_id', 'branch_id', 'home_branch_id']"), 'branch authority is adaptive service-owned');
ok(controller.includes("Schema::getColumnListing($schema['branches_table'])") && controller.includes("in_array('company_id', $branchColumns, true)"), 'adaptive branch company relationship');
ok(previewMethod.includes('preview($rows, $this->companyId($request))') === false, 'preview authority remains service-bound');
ok(controller.includes('$this->authority->preview($rows, $this->companyId($request))'), 'preview receives company context');
ok(controller.includes('$this->authority->import($rows, $this->companyId($request))'), 'import receives company context');
ok(authority.includes('public function preview(array $rows, ?int $companyId = null)'), 'preview company contract');
ok(authority.includes('public function import(array $rows, ?int $companyId = null)'), 'import company contract');
ok(authority.includes('deterministicFields($hotelColumns, $companyId)'), 'preview deterministic company safety');
ok(authority.includes('deterministicFields($columns, $companyId)'), 'import recomputes company safety');
ok(authority.includes("$row['company_id'] = (int) $companyId;") && authority.includes("in_array('company_id', $columns, true)"), 'company_id actual insert mapping');
ok(authority.includes('Required company context could not be resolved safely.'), 'missing company context fails closed');
ok(!requiredCompact.includes("'company_id'") && !requiredCompact.includes('"company_id"'), 'company_id not unconditional safe field');
ok(!authority.includes("company_id' => 1") && !authority.includes('company_id = 1'), 'company id not hardcoded');
ok(authority.indexOf("$row['company_id'] = (int) $companyId;") < authority.indexOf(')->insert($row)'), 'company_id before insert');

/* Adaptive ERP User Management branch authority. */
ok(controller.includes('ErpUserManagementService'), 'adaptive user service used');
ok(controller.includes('$this->users->user($userId)'), 'controller calls adaptive user snapshot');
ok(controller.includes('$this->users->schema()'), 'controller calls adaptive schema');
ok(controller.includes("$snapshot['primary_branch_id']"), 'primary branch from snapshot');
ok(controller.includes("$snapshot['branch_ids']"), 'assigned branches from snapshot');
ok(controller.includes("$schema['branches_table']") && controller.includes("$schema['branch_id_column']"), 'adaptive branch schema columns');
ok(controller.includes("value('company_id')"), 'company id from native branch row');
ok(controller.includes('array_values(array_unique(array_filter(array_map'), 'assigned branch normalization');
ok(controller.includes('count($companyIds) === 1'), 'single-company multi-branch safe');
ok(controller.includes('return count($companyIds) === 1 ?'), 'multi-company ambiguity fails closed');
ok(!controller.includes('->first()->company_id') && !controller.includes('branchIds[0]'), 'no first-branch arbitrary fallback');
ok(controller.includes('companyIdFromBranch'), 'primary branch helper');
ok(controller.includes('$this->authority->preview($rows, $this->companyId($request))'), 'preview same adaptive context');
ok(controller.includes('$this->authority->import($rows, $this->companyId($request))'), 'import same adaptive context');
ok(authority.includes('deterministicFields') && authority.includes("$row['company_id'] = (int) $companyId;"), 'dynamic company contract retained');
ok(middleware.includes("n(x.textContent)==='add hotel'"), 'native anchor regression retained');

/* Import completion UX contract. */
ok(middleware.includes("ib.disabled=true"), 'import button disabled before request');
ok(middleware.includes("ib.textContent='Importing...'"), 'import progress text');
ok(middleware.includes('Import Complete'), 'success completion feedback');
ok(middleware.includes('Rows Created:'), 'success created summary');
ok(middleware.includes('Refreshing hotel list...'), 'success refresh feedback');
ok(middleware.includes('result.scrollIntoView({behavior:\'smooth\',block:\'start\'})'), 'success result scroll');
ok(middleware.includes('window.location.reload()'), 'success page reload');
ok(middleware.includes('ib.disabled=false'), 'error restores button enabled');
ok(middleware.includes("ib.textContent='Import New Hotels'"), 'error restores import label');
ok(!middleware.includes("catch(e=>{window.location.reload()"), 'error path does not reload');
ok(middleware.includes('esc(r.rows_submitted)') && middleware.includes('esc(r.rows_invalid)'), 'success values escaped');

/* Hotel native form safety and hierarchy preservation. */
ok(!hierarchy.includes('finalizeHotel'), 'hierarchy has no Hotel form finalizer');
ok(!hierarchy.includes("field(form,'Address')") && !hierarchy.includes("field(form,'Notes')") && !hierarchy.includes("field(form,'Active')"), 'hierarchy does not resolve Hotel fields');
ok(!hierarchy.includes('row.append(address,notes,active)'), 'hierarchy does not reparent Hotel fields');
ok(!hierarchy.includes('et-tm-hotel-final-row-113286') && !hierarchy.includes('et-tm-hotel-actions-113286'), 'obsolete Hotel final-row hooks removed');
ok(hierarchy.includes('et-tm-child-nav-113287'), 'child navigation presentation exists');
const primaryInsertion = hierarchy.indexOf("nativeStrip.insertAdjacentElement('afterend',primary)");
ok(primaryInsertion >= 0 && primaryInsertion < hierarchy.indexOf("make('transport'") && primaryInsertion < hierarchy.indexOf("make('airlines'"), 'primary nav inserted before child navigation');
ok(hierarchy.includes("str_replace('</head>'"), 'hierarchy runtime is injected in document head');
ok(hierarchy.includes('PREPAINT') || hierarchy.includes('MutationObserver'), 'bounded prepaint observer exists');
ok(hierarchy.includes('observer.disconnect()'), 'prepaint observer disconnects');
ok(hierarchy.includes('native.style.removeProperty'), 'native strip restores on prepaint failure');
ok(hierarchy.includes('ERP-10\\.25\\.7') && hierarchy.includes('TRAVEL MASTER MANAGEMENT'), 'stale Travel Masters kicker is normalized');
ok(!hierarchy.includes("querySelectorAll('h1,h2,h3,h4,p,div,span')"), 'no generic container text scan');
ok(!hierarchy.includes("node.textContent='TRAVEL MASTER MANAGEMENT'"), 'no generic container text replacement');
ok(!hierarchy.includes('normalizeKicker'), 'no client-side kicker mutation');
ok(hierarchy.includes("preg_replace('/ERP-10\\.25\\.7") && hierarchy.includes("$request->path()!=='master-data/travel-masters'"), 'kicker replacement is server-side and page scoped');
const embedded = hierarchy.match(/<script data-et-travel-master-hierarchy="113287">([\s\S]*?)<\/script>/)?.[1] || '';
let embeddedSyntax = true;
try { new Function(embedded); } catch (error) { embeddedSyntax = false; }
ok(embeddedSyntax, 'embedded hierarchy JavaScript parses');
ok(middleware.includes('Bulk Import CSV'), 'Bulk Import same-row action remains');
ok(middleware.includes('Download Template'), 'Download Template action remains');
ok(middleware.includes("textContent='Bulk Import CSV'"), 'native Add Hotel anchor remains authoritative');
ok(middleware.includes('Import Complete') && middleware.includes('window.location.reload'), 'Import completion UX preserved');

/* .288 UI polish safety and pagination contracts. */
ok(uiPolish.includes("$request->path()!=='master-data/travel-masters'"), 'UI polish exact path guard');
ok(uiPolish.includes('data-et-travel-master-ui="113288"') && uiPolish.includes('et-tm-ui-113288'), 'UI polish marker');
ok(!uiPolish.includes('DB::') && !uiPolish.includes('->save(') && !uiPolish.includes('->update('), 'UI polish has no data mutation');
ok(uiPolish.includes('MutationObserver') && uiPolish.includes('2200'), 'presentation observer is bounded');
ok(uiPolish.includes("getComputedStyle(r).display!=='none'"), 'native hidden rows remain excluded');
ok(uiPolish.includes('et-tm-page-hidden-113288'), 'pagination uses scoped hidden class');
ok(uiPolish.includes('[25,50,100]') && uiPolish.includes('per=25'), 'pagination sizes and default');
ok(uiPolish.includes("kind==='hotel'") && uiPolish.includes("'airline'"), 'semantic Hotel and Airline table discovery');
ok(!uiPolish.includes("document.querySelector('table')"), 'no generic first-table authority');
ok(!uiPolish.includes('cloneNode') && !uiPolish.includes('appendChild') && !uiPolish.includes('replaceChildren'), 'native controls are not cloned or moved');
ok(uiPolish.includes("closest('form')") && uiPolish.includes('safeWrapper'), 'form resolution is scoped and fail-closed');
ok(uiPolish.includes('safeWrapper') && uiPolish.includes('label.htmlFor') && uiPolish.includes("querySelectorAll('label').length>1"), 'safe field wrapper resolution');
ok(uiPolish.includes('et-tm-ui-grid-113288') && uiPolish.includes('et-tm-ui-field-'), 'field classes are applied with safe grid');
ok(uiPolish.includes("find('add hotel')") && uiPolish.includes("find('add record')") && uiPolish.includes("find('add airline')"), 'Hotel Transport Airline action rows targeted');
ok(uiPolish.includes('[3,3,3,3,3,3,3,3,4,3,1]') && uiPolish.includes('[3,3,3,3,3,3,3,3,2,4,3,1]') && uiPolish.includes('[3,3,3,3,2,2,3,2,1]'), 'mapped compact form proportions');
ok(uiPolish.includes('sizeWrap.append(sizeLabel,size)'), 'page-size select is attached to new pager container');
ok(!uiPolish.includes("label.insertAdjacentElement('afterend',size)"), 'detached page-size insertion removed');
ok(uiPolish.includes('info.textContent=') && uiPolish.includes("nav.textContent=''"), 'pager info and navigation are stable');
ok(uiPolish.includes("setAttribute('aria-current','page')") && uiPolish.includes("textContent='…'"), 'active page aria and ellipsis');
ok(uiPolish.includes('const body=table.tBodies[0];if(!body)return false') && uiPolish.includes("table.dataset.etTmPager='113288'"), 'pager marker follows valid tbody');
ok(uiPolish.includes('size.onchange=') && uiPolish.includes('page=1;render()'), 'page-size changes reset pagination');
ok(uiPolish.includes("tab!=='hotels'&&tab!=='airlines'"), 'pagination is exact-tab gated');
ok(uiPolish.includes('table.dataset.etTmPager=\'113288\';return true'), 'pager marker assigned after successful render');
ok(uiPolish.includes("addEventListener('input'") && uiPolish.includes("closest('.et-tm-ui-pager')") && uiPolish.includes('setTimeout'), 'filter changes repaginate after native handlers');
let uiSyntax=true;try{new Function(uiPolish.match(/<script data-et-travel-master-ui="113288">([\s\S]*?)<\/script>/)?.[1]||'')}catch(e){uiSyntax=false}ok(uiSyntax,'UI polish embedded JavaScript parses');

console.log(
    `erp113272-travel-master-hotel-bulk-import-regression: ${n} assertions passed`
);
