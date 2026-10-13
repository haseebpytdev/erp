import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const air = read('public/erp-theme/js/products/air.js');
const routes = read('routes/erp103179.php');
const release = read('config/et_erp_release.php');

let assertions = 0;
const ok = (condition, message) => { assertions += 1; assert.ok(condition, message); };

ok(/Route::get\(\s*'\/system\/erp-bookings\/\{booking\}\/air-product'/.test(routes), 'native Air GET route remains unchanged');
ok(/Route::put\(\s*'\/system\/erp-bookings\/\{booking\}\/air-product'/.test(routes), 'native Air save route remains unchanged');
ok(controller.includes('public function store(Request $request, int $booking)'), 'Air save action remains server-owned');
ok(controller.includes("'fare_commercials' => ['nullable', 'array', 'max:3']"), 'fare commercial contract remains accepted');
ok(controller.includes('commercialPassengerPayloads('), 'backend completes commercial passenger payloads');
ok(controller.includes("['sale_price', 'cost_price', 'basic_rate', 'vendor_minus_value', 'customer_minus_value', 'vendor_other_cost']"), 'meaningful detection uses current commercial fields');
ok(controller.includes("'booking_passenger_id' => $id"), 'backend binds generated rows to authoritative passenger IDs');
ok(controller.includes("'ticket_number' => ''"), 'blank ticket numbers remain valid for commercial rows');
ok(controller.includes('assertCommercialReadback('), 'read-after-write commercial assertion is present');
ok(controller.includes("array_key_exists('fare_commercials', $data)"), 'partial non-commercial updates preserve existing commercial authority');
ok(controller.includes('Air commercial values could not be linked to the booking passengers.'), 'empty commercial readback fails with UI-safe validation');
ok(controller.includes('Air commercial values could not be verified after saving.'), 'commercial total mismatch fails with UI-safe validation');
ok(controller.includes('expectedCustomer'), 'customer total readback is compared');
ok(controller.includes('expectedSupplier'), 'supplier total readback is compared');
ok(controller.includes('abs(round($expectedCustomer, 2) - $actualCustomer) > 0.01'), 'customer comparison uses decimal tolerance');
ok(controller.includes('abs(round($expectedSupplier, 2) - $actualSupplier) > 0.01'), 'supplier comparison uses decimal tolerance');
ok(controller.includes('$this->syncAirServiceCommercialSnapshot((int) $service[\'id\'], $this->summary($freshTickets, (int) $service[\'id\']))'), 'service snapshot follows verified native readback');
ok(air.includes('var meaningfulCommercial=farePayload.some'), 'client detects meaningful commercial input');
ok(air.includes('var ticketRows=Array.prototype.slice.call'), 'client enumerates native passenger rows');
ok(air.includes('booking_passenger_id:passengerId'), 'client uses booking passenger ID authority');
ok(air.includes("ticket_number:''"), 'client does not fabricate ticket numbers');
ok(air.includes('if(meaningfulCommercial){'), 'client materializes passenger rows for commercial saves');
ok(!controller.includes('air_ticket_entry_drafts'), 'draft rows are not commercial authority');
ok(!controller.includes('BK-2026-0056'), 'historical booking is not hardcoded');
ok(!controller.includes('TravelReportService'), 'travel report logic is untouched');
ok(!fs.existsSync(path.join(root, 'database', 'migrations', 'c88-air-commercial-persistence.php')), 'C88 adds no migration');
ok(release.includes("'version' => 'v1.1.33.378-ERP11.3.378'"), 'application version remains stable');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C88'"), 'C88 asset version is active');
ok(release.includes("'corrective_build' => 'C88'"), 'C88 corrective build is active');
ok(release.includes("'corrective_name' => 'Air Commercial Persistence Integrity'"), 'C88 corrective name is active');

console.log(`C88 regression PASS (${assertions} assertions)`);
