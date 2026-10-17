import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const air = fs.readFileSync(path.join(root, 'public/erp-theme/js/products/air.js'), 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');
const check = (condition, message) => assert.equal(Boolean(condition), true, message);

// Itinerary edits stay local to the edited segment and do not recalculate fares.
check(air.includes('data-etgp-air-segment-113106'), 'segment rows remain explicitly scoped');
check(air.includes('segment.segment_type=type.select.value'), 'Type sync remains segment-scoped');
check(air.includes('segment.flight_number=flight.input.value'), 'Flight number sync remains segment-scoped');
check(air.includes('segment.from=from.input.value.toUpperCase()'), 'From sync remains segment-scoped');
check(air.includes('segment.to=to.input.value.toUpperCase()'), 'To sync remains segment-scoped');
check(air.includes('segment.departure_at=departure.input.value||null'), 'Departure sync remains segment-scoped');
check(air.includes('segment.arrival_at=arrival.input.value||null'), 'Arrival sync remains segment-scoped');
check(air.includes('recalcFromEvent113119'), 'commercial recalc has an explicit event gate');
check(air.includes('if(!itinerary)recalc()'), 'itinerary events skip commercial recalculation');
check(air.includes('page.addEventListener(\'input\',recalcFromEvent113119)'), 'commercial input listener remains');
check(air.includes('page.addEventListener(\'change\',recalcFromEvent113119)'), 'commercial change listener remains');

// Labels are targeted and checkbox nodes are preserved.
check(air.includes('updateSegmentLabels113324'), 'segment labels have a targeted update helper');
check(air.includes('data-etgp-air-segment-owner="\'+key+\'"'), 'labels target the owning segment');
check(air.includes('if(span)span.textContent=text'), 'only label text is updated');
check(air.includes('data-etgp-air-segment-owner'), 'segment checkbox ownership marker remains');
check(!air.includes('container.innerHTML='), 'segment edit does not rebuild the chooser DOM');

// One page-level draft scheduler owns debounce and lifecycle cancellation.
check(air.includes('scheduleAirMultiDraft113119'), 'page-level scheduler remains authoritative');
check(air.includes('multiDraftGeneration'), 'stale draft generations are cancelled');
check(air.includes('clearTimeout(airLifecycle.multiDraftTimer)'), 'pending draft timer is cancelled');
check(air.includes('?1200:180'), 'authenticated debounce is 1200ms');
check(air.includes('requestIdleCallback'), 'idle callback is used after debounce');
check(air.includes('setTimeout(run,0)'), 'bounded idle fallback is present');
check(air.includes('{timeout:250}'), 'idle wait has a bounded timeout');
check(air.includes('!(data&&data._etgpAllGroups)'), 'multi-group editors do not attach duplicate local timers');
check(!air.includes('buildMultiDraft113119=function(){syncEditors();'), 'ordinary draft build does not run full syncEditors');
check(air.includes('page._etgpLastDraftEvent113119'), 'draft builder uses page state plus targeted event context');
check(air.includes('payloadSerialized=JSON.stringify(payload)'), 'draft payload is serialized once for comparison');
check(air.includes('lastDraftPayload===payloadSerialized'), 'identical draft writes are skipped');
check(air.includes('etgpAirDraftKey113119(bookingId)'), 'draft remains booking/user scoped');
check(air.includes('pagehide'), 'pagehide flush remains registered');
check(air.includes('visibilityState===\'hidden\''), 'hidden visibility flush remains registered');
check(!air.includes('fetch(') || air.includes('etgpAirSave113314'), 'draft lifecycle adds no network write');

// Airline lookup is indexed once and rebuilt only when result order changes.
check(air.includes('indexedAirlines=airlines.map'), 'airline search index is precomputed');
check(air.includes('indexedAirlines.filter'), 'keystrokes filter the normalized index');
check(!air.includes('airlines.filter(function(item){return !query||normalized(item.name)'), 'per-key master renormalization is removed');
check(air.includes('lastResultSignature'), 'unchanged airline result order skips rebuild');
check(air.includes('document.createDocumentFragment()'), 'airline options use a DocumentFragment');
check(air.includes('list.slice(0,40)') || air.includes('matches().slice(0,40)'), 'airline result maximum remains 40');
check(air.includes('selected=item||null'), 'airline selection state remains authoritative');

// Existing targeted structural operations and save path remain intact.
check(air.includes('pageState.segments.push(segment);row(segment)'), 'Add Segment remains targeted');
check(air.includes('appendGroupCard113324(copy'), 'Duplicate Group remains targeted');
check(air.includes('pageState.groups.push(addedGroup);appendGroupCard113324'), 'Add Group remains targeted');
check(air.includes('pageState.groups.splice(at,1);card.remove();'), 'Delete Group remains targeted');
check(air.includes('editorHosts.splice(at,1)'), 'removed editor references are cleaned');
check(air.includes('save.addEventListener(\'click\''), 'save action remains');
check(air.includes('syncEditors();var invalid=validate()'), 'save still performs the full authoritative sync');
check(air.includes('etgpAirSave113314(bookingId,payload)'), 'save endpoint remains unchanged');
check(air.includes('save.disabled=true'), 'double-submit protection remains');
check(air.includes('result.ticket_groups||pageState.groups'), 'successful save reads server state back');
check(air.includes('catch(function(error)'), 'failed save keeps the draft/error path');
check(release.includes("'asset_version' => 'ERP-11.3.378-C89.4.5.2'"), 'C89.4.5.2 asset identity is active');
check(release.includes("'corrective_build' => 'C89.4.5.2'"), 'C89.4.5.2 corrective build is active');
check(release.includes("'corrective_name' => 'Air Flight Field Entry Performance'"), 'C89.4.5.2 corrective name is active');
check(!fs.existsSync(path.join(root, 'database', 'migrations', 'c89-4-5-2-air-flight-field-entry-performance.php')), 'no migration was added');
check(!fs.existsSync(path.join(root, 'database', 'schema')), 'no schema directory was introduced');

console.log('C89.4.5.2 Air flight field-entry performance regression: PASS (50 assertions)');
