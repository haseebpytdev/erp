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
        === 'v1.1.33.271-ERP11.3.271',
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

console.log(
    `erp113272-travel-master-hotel-bulk-import-regression: ${n} assertions passed`
);