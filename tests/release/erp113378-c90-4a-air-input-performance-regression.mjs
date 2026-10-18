import fs from 'node:fs';

const read = file => fs.readFileSync(file, 'utf8');
const air = read('public/erp-theme/js/products/air.js');
const core = read('public/erp-theme/js/dedicated-product-core.js');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const routes = read('routes/erp103179.php');
const release = read('config/et_erp_release.php');
const ok = (condition, message) => {
  if (!condition) throw new Error(message);
  console.log(`ok - ${message}`);
};

ok(air.includes("etgp-air-product-draft-v113119:"), 'Air draft key version remains unchanged');
ok(air.includes("var supplementary=core.getBillingContext&&core.getBillingContext()==='SUPPLEMENTARY'"), 'draft context scoping remains');
ok(air.includes("+':user:'+String(userId)"), 'draft remains user scoped');
ok(air.includes("var zeroState=!serverGroups.length"), 'zero-service state remains supported');
ok(air.includes("service_id:null"), 'zero-service transient group remains service_id null');
ok(air.includes("segment-new-") && air.includes("segment_type:'outbound'"), 'zero-service default segment remains');
ok(air.includes('ActiveBookingPassengerResolver') || air.includes('booking_passenger_id'), 'passenger ticket identity remains booking-passenger based');
ok(air.includes('fillFollowingTickets'), 'ticket auto-increment behavior remains');
ok(air.includes("booking_class"), 'class field remains');
ok(air.includes("baggage"), 'baggage field remains');
ok(air.includes("ticketStatus.select"), 'ticket status field remains');
ok(air.includes('airCommercialRecalc113119'), 'existing commercial recalculation authority remains');
ok(air.includes('commercialContribution113119'), 'affected-group commercial contribution is cached');
ok(air.includes('recalcAffectedCommercial113119'), 'commercial input recalculation is group-scoped');
ok(air.includes('commercialCache113119'), 'commercial totals use cached group contributions');
ok(air.includes('markNonCommercialAirEvent113119'), 'noncommercial event classification remains');
ok(air.includes('page._etgpSkipCommercialRecalc113119'), 'noncommercial input skips commercial recalculation');
ok(air.includes('page._etgpBuildMultiDraft113119=buildMultiDraft113119'), 'lifecycle draft builder remains');
ok(air.includes('scheduleAirMultiDraft113119'), 'lifecycle draft scheduler remains');
ok(air.includes('if(ownsDraft && !(data&&data._etgpAllGroups))'), 'editor-local scheduler remains gated outside active multi-group editor');
ok(air.includes('etgpAirDraftWrite113119'), 'draft persistence remains');
ok(air.includes('etgpAirDraftClear113119'), 'draft clear remains');
ok(air.includes("etgpAirRequest113106(bookingId,'PUT',payload)"), 'Air save remains PUT');
ok(air.includes('etgpAirData113314.refresh(bookingId)'), 'authoritative refresh remains after save');
ok(routes.includes("'/system/erp-bookings/{booking}/air-product'") && routes.includes('GeneralBookingAirProductController::class'), 'Air product route remains unchanged');
ok(controller.includes('DB::transaction'), 'Air save transaction remains');
ok(controller.includes('ensureAirService'), 'native Air service authority remains');
ok(controller.includes('syncTickets'), 'native ticket persistence remains');
ok(controller.includes('syncGroupedItinerary'), 'native itinerary persistence remains');
ok(!controller.includes('C90.4A'), 'controller is unchanged by this frontend release');
ok(!fs.readdirSync('database/migrations').some(name => name.toLowerCase().includes('c90_4a')), 'no C90.4A migration exists');
ok(release.includes("'asset_version' => 'ERP-11.3.378-C90.4A'") && release.includes("'corrective_build' => 'C90.4A'"), 'C90.4A release identity is present');
ok(release.includes("'corrective_name' => 'Air Input Performance Correction'"), 'C90.4A corrective name is present');

console.log('C90.4A Air input performance regression: PASS (32 assertions)');
