import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const voucher = read('app/Http/Controllers/Operations/GeneralBookingVoucherPreviewController.php');
const visa = read('app/Http/Controllers/Operations/GeneralBookingVisaProductController.php');
const blade = read('resources/views/operations/bookings/general-client-voucher-v113142.blade.php');
const routes = read('routes/erp103179.php');
const release = read('config/et_erp_release.php');
const base = '93381cd43498fd0df90057feafd379e406eedb3d';

let assertions = 0;
const ok = (condition, message) => { assertions += 1; assert.ok(condition, message); };
const unchangedFromBase = (file) => {
  try {
    execFileSync('git', ['diff', '--quiet', `${base}..HEAD`, '--', file], { cwd: root, stdio: 'ignore' });
    return true;
  } catch {
    return false;
  }
};

ok(routes.includes("'/operations/bookings/{booking}/client-voucher-preview'"), 'General Client Voucher route remains authoritative');
ok(routes.includes('GeneralBookingVoucherPreviewController::class'), 'voucher route uses the server controller');
ok(voucher.includes('public function show('), 'voucher rendering remains server-side');
ok(voucher.includes('$visaRows = (array) ($visa[\'visa_rows\'] ?? []);'), 'voucher reads the existing Visa snapshot projection');
ok(voucher.includes('$visaRelationships = $this->voucherVisaRelationships($visaRows);'), 'relationship display values are resolved before Blade rendering');
ok(voucher.includes('private function voucherVisaRelationships(array $visaRows): array'), 'voucher resolver has a focused relationship helper');
ok(voucher.includes("DB::table('travel_voucher_partners')"), 'current names come from the Travel Voucher Partner master');
ok(voucher.includes("->whereIn('id', array_keys($partnerIds))"), 'master lookup is batched by stable IDs');
ok((voucher.match(/DB::table\('travel_voucher_partners'\)/g) || []).length === 1, 'master lookup is not per passenger or per relationship');
ok(voucher.includes("$row[$prefix.'_master_table']") && voucher.includes("$row[$prefix.'_master_id']"), 'explicit master table and ID fields are honored');
ok(voucher.includes("$table === 'travel_voucher_partners' && $masterId > 0"), 'only the proven native master table is treated as current authority');
ok(voucher.includes("$table === '' && (int) ($row[$legacyField] ?? 0) > 0"), 'legacy IDs remain supported as a compatibility fallback');
ok(voucher.includes("$currentNames[$id] !== ''") && voucher.includes(': $fallback;'), 'stored snapshot remains fallback when current master is unavailable');
ok(voucher.includes("$key = strtolower($snapshotSaudi).'|'.strtolower($snapshotIata);"), 'relationship dedupe key preserves prior cardinality and order');
ok(voucher.includes("'saudi_company_name' => $saudi") && voucher.includes("'pakistani_iata_name' => $iata"), 'resolved names use the existing Blade variable contract');
ok(visa.includes("$row['saudi_company_name_snapshot']") && visa.includes("$row['pakistani_iata_name_snapshot']"), 'snapshot fallback source remains booking Visa data');
ok(visa.includes('private function providerSnapshots(array $row): array'), 'existing Visa snapshot provider path remains intact');
ok(blade.includes("$relationship['saudi_company_name']") && blade.includes("$relationship['pakistani_iata_name']"), 'voucher Blade keeps the existing value expressions');
ok(unchangedFromBase('resources/views/operations/bookings/general-client-voucher-v113142.blade.php'), 'voucher presentation markup is unchanged');
ok(unchangedFromBase('public/erp-theme/js/products/air.js'), 'C88 Air client runtime is unchanged');
ok(unchangedFromBase('app/Http/Controllers/Operations/GeneralBookingAirProductController.php'), 'C88 Air server runtime is unchanged');
const refreshHelper = voucher.slice(voucher.indexOf('private function voucherVisaRelationships'), voucher.indexOf('private function voucherCurrentPartnerName'));
ok(!refreshHelper.includes('->insert(') && !refreshHelper.includes('->update(') && !refreshHelper.includes('->delete('), 'voucher refresh performs no database writes');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version remains stable');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C88.1'"), 'C88.1 asset version is active');
ok(release.includes("'corrective_build' => 'C88.1'"), 'C88.1 corrective build is active');
ok(release.includes("'corrective_name' => 'Travel Voucher IATA Master Refresh'"), 'C88.1 corrective name is active');
ok(!fs.existsSync(path.join(root, 'database', 'migrations', 'c88-1-voucher-iata-master-refresh.php')), 'C88.1 adds no migration');

console.log(`C88.1 regression PASS (${assertions} assertions)`);
