import fs from 'node:fs';
import assert from 'node:assert/strict';

const read = (path) => fs.readFileSync(path, 'utf8');
const release = read('config/et_erp_release.php');
const controller = read('app/Http/Controllers/Operations/GeneralBookingHotelProductController.php');
const c85 = read('resources/views/reports/travel/movements/makkah-checkout.blade.php');
const historical = read('tests/release/erp113378-c84-4-1-hotel-set-reconciliation-regression.mjs');
const ok = (value, message) => assert.ok(value, message);

ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version remains ERP-11.3.378');
ok(release.includes("'release' => 'ERP-11.3.378'"), 'release remains ERP-11.3.378');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C85.1'"), 'asset version is promoted to C85.1');
ok(release.includes("'corrective_build' => 'C85.1'"), 'corrective build is promoted to C85.1');
ok(release.includes("'corrective_name' => 'Hotel Stay Set Reconciliation'"), 'corrective name remains Hotel Stay Set Reconciliation');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C84.4.1'") && release.includes("'corrective_build' => 'C84.4.1'"), 'prior C84.4.1 identity is retained as historical alias');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C85'") && release.includes("'corrective_build' => 'C85'"), 'production C85 identity is retained as historical alias');
ok(controller.includes('reconcileOmittedHotelServices') && controller.includes('removeOmittedHotelService'), 'Hotel reconciliation implementation is unchanged');
ok(c85.includes('Makkah Check-out'), 'C85 Makkah check-out implementation remains present');
ok(historical.includes('Hotel Stay Set Reconciliation'), 'Hotel reconciliation regression remains present');

console.log('ERP-11.3.378 C85.1 release identity regression: PASS / 10');
