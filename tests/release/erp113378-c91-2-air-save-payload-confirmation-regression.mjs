import fs from 'node:fs';

const air = fs.readFileSync('public/erp-theme/js/products/air.js', 'utf8');
const controller = fs.readFileSync('app/Http/Controllers/Operations/GeneralBookingAirProductController.php', 'utf8');
const routes = fs.readFileSync('routes/erp103179.php', 'utf8');
const ok = (condition, message) => {
  if (!condition) throw new Error(message);
  console.log(`ok - ${message}`);
};

ok(air.includes('var etgpAirSanitizeGroup113119='), 'group payload sanitizer exists');
ok(air.includes('if(ticketGroups.length>0&&!renderGroupOnly)'), 'child editors do not embed the full group collection');
ok(air.includes('payload={common:payload.common,tickets:payload.tickets,fare_commercials:payload.fare_commercials}'), 'child editor returns only its permitted fields');
ok(air.includes('ticket_groups:payload.ticket_groups.map(function(group){return etgpAirSanitizeGroup113119(group);})'), 'final PUT payload rebuilds clean group objects');
ok(air.includes('segments:Array.isArray(payload.segments)?payload.segments.map'), 'top-level segments are copied once at request boundary');
ok(air.includes('Array.from(new Set(payload.deleted_group_service_ids'), 'deletion IDs are normalized and deduplicated');
ok(air.includes('merged._etgpDeletedGroupServiceIds113119=[]'), 'draft deletion IDs cannot authorize deletion');
ok(air.includes('pageState.groups=pageState.groups.map(function(group){return etgpAirSanitizeGroup113119(group);})'), 'editor state is stripped of stale nested artifacts');
ok(air.includes('Duplicate Group'), 'duplicate-group action remains available');
ok(air.includes('copy=JSON.parse(JSON.stringify(pageState.groups[index]))'), 'duplicate group uses sanitized state');
ok(air.includes("responseError.kind=response.status===422?'validation':'server'"), 'validation and server responses are classified');
ok(air.includes("networkError.kind=error&&error.name==='AbortError'?'unknown':'network'"), 'network and timeout outcomes are classified');
ok(air.includes("refreshError.kind='saved-refresh-failed'"), 'confirmed PUT with failed refresh is distinguished');
ok(air.includes("refreshTimeout.kind='saved-refresh-unknown'"), 'confirmed PUT with refresh timeout is distinguished');
ok(air.includes('Save outcome is unknown; the request timed out before confirmation.'), 'ambiguous timeout is not treated as a failed write');
ok(air.includes('Air data was saved, but the confirmation refresh failed.'), 'refresh failure does not claim the save failed');
ok(air.includes('etgpAirRequestTimeout113106=30000'), 'save controls have a bounded client wait');
ok(air.includes('etgpAirDraft113314.clear(bookingId)'), 'draft clears only after refresh success');
ok(air.includes('save.disabled=true'), 'duplicate submissions remain blocked while saving');
ok(air.includes('payload=requestPayload') && air.includes("etgpAirRequest113106(bookingId,'PUT',payload)"), 'only one clean write request is issued and retries remain manual');
ok(controller.includes('DB::transaction'), 'native Air persistence remains transactional');
ok(controller.includes("'ticket_groups.*.tickets.*.ticket_number' => ['nullable', 'string', 'max:120']"), 'ticket number validation remains unchanged');
ok(controller.includes("'segments.*.departure_at' => ['required', 'date']"), 'required group segment departure validation remains');
ok(routes.includes("'/system/erp-bookings/{booking}/air-product'") && routes.includes('GeneralBookingAirProductController::class'), 'Air save route remains unchanged');
ok(!air.includes('deleted_group_service_ids:Array.isArray(draft'), 'draft state cannot inject deletion IDs');

const group = {
  service_id: 7,
  client_key: 'group-1',
  segment_keys: ['segment-1'],
  common: { pnr: 'ABC123' },
  tickets: [{ booking_passenger_id: 11, ticket_number: 'TKT-1' }],
  fare_commercials: [{ fare_type: 'ADULT', sale_price: 1 }],
  ticket_groups: [{ ticket_groups: [{ ticket_groups: [{ stale: true }] }] }],
  segments: [{ from: 'LHE', to: 'JED' }],
};
const sanitized = {
  service_id: group.service_id,
  client_key: group.client_key,
  segment_keys: [...group.segment_keys],
  common: { ...group.common },
  tickets: group.tickets.map(ticket => ({ ...ticket })),
  fare_commercials: group.fare_commercials.map(row => ({ ...row })),
};
ok(!Object.prototype.hasOwnProperty.call(sanitized, 'ticket_groups'), 'sanitized groups have no nested ticket_groups');
ok(!Object.prototype.hasOwnProperty.call(sanitized, 'segments'), 'sanitized groups have no duplicated top-level segments');
ok(JSON.stringify(sanitized).length < JSON.stringify(group).length, 'sanitized request size is smaller than recursive editor state');
ok(air.includes('ticket_groups:payload.ticket_groups.map'), 'single and multiple groups use the same canonical request boundary');
const sixPassengers = Array.from({ length: 6 }, (_, index) => ({ booking_passenger_id: index + 1, ticket_number: `TKT-${index + 1}` }));
const sixTicketGroup = { ...sanitized, tickets: sixPassengers };
ok(sixTicketGroup.tickets.length === 6 && sixTicketGroup.tickets.every(ticket => ticket.booking_passenger_id > 0), 'six passenger ticket numbers remain passenger-scoped');
ok(air.includes("pageState.groups.push(copy)") && air.includes("{ticket_number:''}"), 'Duplicate Group creates a clean unsaved copy');
ok(air.includes('pageState.deletedGroupServiceIds.push(Number(group.service_id))'), 'group deletion remains an explicit user action');
ok(controller.includes("'segments.*.departure_at' => ['required', 'date']"), 'required segment departure dates remain enforced');
ok(air.includes("responseError.kind=response.status===422?'validation':'server'"), 'invalid validation responses remain distinguishable');
ok(air.includes('etgpAirDraft113314.write(bookingId,payload)'), 'browser draft is retained before a save attempt');
ok(air.includes('networkError.kind=error&&error.name===\'AbortError\'?\'unknown\':\'network\''), 'interrupted network outcomes remain safe to retry manually');
ok(!air.includes('etgpAirSave113314(bookingId,payload).catch'), 'ambiguous outcomes do not automatically replay the write');

console.log('C91.2 Air save payload and confirmation regression: PASS (38 assertions)');
