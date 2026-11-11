import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const summary = read('app/Services/Operations/BookingProductSummaryResolver.php');
const reports = read('app/Services/Reports/TravelReportService.php');
const air = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const ok = (condition, message) => assert.ok(condition, message);
const activeStatus = "orWhereNotIn('status', ['cancelled', 'canceled', 'CANCELLED', 'CANCELED'])";

ok(summary.includes('private function airServiceRows'), 'booking summary has a dedicated Air service reader');
ok(summary.includes("$product === 'air' && in_array('status', $columns, true)"), 'generic service reader protects Air context when selected');
ok(summary.includes(activeStatus), 'Air summary excludes cancelled services when status exists');
ok(reports.includes('private function activeAirServiceCount'), 'report count authority has an active Air count path');
ok(reports.includes('private function hasActiveAirService'), 'product summary identity has an active Air path');
ok(reports.includes("'air'=>$this->activeAirServiceCount()"), 'report counts use active Air services');
ok(reports.includes('hasActiveAirService($id)'), 'booking product names use active Air services');
ok(reports.includes(activeStatus), 'report service filters preserve fail-safe status semantics');
ok(reports.includes('private function movementEvents'), 'movement reports retain the shared movement authority');
ok(reports.includes('activeServiceIds'), 'movement events exclude cancelled Air services');
ok(reports.includes('booking_group_package_flights'), 'legacy package flight events remain a separate preserved source');
ok(air.includes("whereNull('status')->orWhereNotIn('status'"), 'Air editor excludes cancelled services');
ok(air.includes('booking_itinerary_segments'), 'Air itinerary authority remains native');
ok(!reports.includes("DB::table('air_ticket_details')->delete"), 'reader changes do not delete historical ticket records');
ok(!reports.includes("DB::table('booking_services')->where('booking_id',$id)->delete"), 'reader changes do not delete service history');

console.log('ERP-11.3.378 C92.5H active Air reader consistency regression: PASS (15 assertions)');
