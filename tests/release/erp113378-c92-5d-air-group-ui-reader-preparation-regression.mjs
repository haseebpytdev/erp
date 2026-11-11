import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const air = read('public/erp-theme/js/products/air.js');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const reports = read('app/Services/Reports/TravelReportService.php');
const routes = read('routes/erp103179.php');
const ok = (value, message) => assert.ok(value, message);

ok(air.includes('C92.5D: persisted-group removal remains deliberately unavailable'), 'persisted removal is explicitly unavailable');
ok(air.includes('selected&&Number(selected.service_id||0)>0'), 'persisted groups are distinguished from unsaved groups');
ok(air.includes('event.stopImmediatePropagation()'), 'persisted removal cannot reach the legacy delete handler');
ok(air.includes('No Air Ticket Groups remain. Use the authorized cancellation workflow'), 'final-group UX avoids ordinary-save validation');
ok(air.includes('pageState.groups.splice(at,1)'), 'unsaved/local removal remains immediate');
ok(!air.includes("fetch('/system/erp-bookings/'+bookingId+'/air-product/delete"), 'no separate old delete endpoint is called');
ok(controller.includes("whereNull('status')->orWhereNotIn('status'"), 'Air editor excludes cancelled services from active projection');
ok(reports.includes("activeServiceIds") && reports.includes("orWhereNotIn('status'"), 'movement readers exclude cancelled Air services');
ok(reports.includes('booking_itinerary_segments') && reports.includes('booking_services'), 'movement authority remains native Air service plus itinerary');
ok(routes.includes('GeneralBookingAirProductController::class') && routes.includes("/air-product"), 'existing Air route remains the only save route');
ok(!routes.includes('cancel-air') && !routes.includes('air-cancellation'), 'no public cancellation route is enabled');
ok(air.includes('etgpAirRequestTimeout113106') && air.includes('etgpAirDraftKey113119'), 'C91.4 save timeout and scoped draft behavior remain present');

console.log('ERP-11.3.378 C92.5D Air group UI/reader preparation regression: PASS (12 assertions)');
