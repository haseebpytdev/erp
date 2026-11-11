import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/Operations/GeneralBookingAirProductController.php'), 'utf8');
const air = fs.readFileSync(path.join(root, 'public/erp-theme/js/products/air.js'), 'utf8');
const ok = (condition, message) => assert.ok(condition, message);

const methodStart = controller.indexOf('private function storeTicketGroups(');
const methodEnd = controller.indexOf('\n    private function ', methodStart + 10);
const methodBody = controller.slice(methodStart, methodEnd < 0 ? controller.length : methodEnd);
const guard = methodBody.indexOf("$request->exists('deleted_group_service_ids')");
const validation = methodBody.indexOf('$request->validate([');
const deleteLoop = methodBody.indexOf('foreach ($deletedGroupServiceIds as $deleteId)');

ok(methodStart >= 0, 'group-save method remains the ordinary Air Save boundary');
ok(guard >= 0 && guard < validation, 'deletion instruction is rejected before validation and writes');
ok(methodBody.includes('! is_array($deletionInstruction) || $deletionInstruction !== []'), 'malformed and non-empty deletion instructions are rejected');
ok(methodBody.includes('ordinary Air Save cannot remove them'), 'rejection explains the dedicated cancellation workflow');
ok(deleteLoop < 0, 'legacy persisted-group delete loop is removed from ordinary Save');
ok(!methodBody.includes('deletedGroupServiceIds'), 'ordinary Save no longer carries persisted deletion IDs into its transaction');
ok(!methodBody.includes("DB::table('booking_services')->where('id', $deleteId)->delete()"), 'ordinary Save has no booking-service hard-delete branch');
ok(!methodBody.includes("DB::table('air_ticket_details')->where('booking_service_id', $deleteId)->delete()"), 'ordinary Save has no ticket-detail hard-delete branch');
ok(!methodBody.includes("booking_service_passengers')->where('booking_service_id', $deleteId)->delete()"), 'ordinary Save has no passenger-link hard-delete branch');
ok(!methodBody.includes('assertGroupDeletionSafe($deleteId)'), 'obsolete deletion safety branch is removed rather than reused');
ok(methodBody.includes('syncGroupedItinerary($booking, $segments, $claimed, $resolvedGroupContexts)'), 'ordinary itinerary synchronization remains intact');
ok(methodBody.includes('syncTickets($serviceId, $booking'), 'ordinary ticket editing remains intact');
ok(air.includes('Persisted Air Group removal is unavailable until the authorized cancellation workflow is activated'), 'persisted UI removal remains unavailable');
ok(air.includes('event.stopImmediatePropagation()'), 'persisted UI removal cannot invoke the legacy handler');
ok(air.includes('No Air Ticket Groups remain. Use the authorized cancellation workflow'), 'final-group UI does not submit ordinary Save');
ok(air.includes('pageState.groups.splice(at,1)'), 'unsaved groups still remove locally');
ok(!air.includes("fetch('/system/erp-bookings/'+bookingId+'/air-product/delete"), 'no legacy delete endpoint is called');
ok(controller.includes("whereNull('status')->orWhereNotIn('status'"), 'active Air reader filtering remains intact');
ok(controller.includes('deleted_group_service_ids'), 'legacy deletion input is explicitly handled server-side');
ok(methodBody.includes("$deletionInstruction !== []"), 'empty compatibility arrays are distinguished from removal instructions');

console.log('ERP-11.3.378 C92.5G Air Save deletion-bypass regression: PASS (21 assertions)');
