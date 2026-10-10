import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = file => fs.readFileSync(new URL(`../../${file}`, import.meta.url), 'utf8');
const air = read('public/erp-theme/js/products/air.js');
const release = read('config/et_erp_release.php');
let assertions = 0;
const check = (value, message) => { assert.ok(value, message); assertions += 1; };

// Zero server groups use the production multi-group renderer with a local,
// unsaved presentation model rather than the historical single editor.
check(/var serverGroups=Array\.isArray\(data&&data\.ticket_groups\)\?data\.ticket_groups:\[\];var zeroState=!serverGroups\.length/.test(air), 'zero-state detection is based on the server response');
check(/var groups=zeroState\?\[\{service_id:null,client_key:'group-new-/.test(air), 'zero-state creates one client-only group');
check(/service_id:null,client_key:'group-new-'\+Date\.now\(\),segment_keys:\[String\(segments\[0\]\.client_key\)\]/.test(air), 'zero-state group has no persisted service ID and owns the first segment');
check(!/if\(!groups\.length\)\{renderTicketGroupEditor113106\(host,data,bookingId\);return;\}/.test(air), 'legacy zero-group single editor branch is removed');
check(/etgp-air-multi-group-page-113324/.test(air), 'zero-state shares the main multi-group page class');
check(/etgpAirSubhead113106\('Air Ticket Groups'/.test(air), 'Air Ticket Groups heading remains in the shared layout');
check(/'\+ Add Ticket Group'/.test(air), 'Add Ticket Group remains available');
check(/etgp-air-multi-group-totals-113324/.test(air), 'page totals remain visible');

// Empty itinerary data receives one neutral, unsaved segment for presentation.
check(/if\(zeroState&&!segments\.length\)segments=\[\{id:0,client_key:'segment-new-/.test(air), 'zero-state renders one blank segment');
check(/var segments=sourceSegments\.map\(function\(segment\)\{return Object\.assign\(\{\},segment\);\}\)/.test(air), 'server segments are copied before presentation edits');
check(/neutralPlaceholders/.test(air) && /Enter flight no\./.test(air), 'blank segment uses neutral C78 placeholders');
check(/segment_keys:\[String\(segments\[0\]\.client_key\)\]/.test(air), 'initial blank segment has usable group ownership');

// The response and shared cache remain authoritative and empty on clean mount.
check(/data=Object\.assign\(\{\},etgpAirDraft113314\.apply\(data,bookingId\)\)/.test(air), 'prepared rendering data is detached from the response object');
check(!/data\.ticket_groups\s*=\s*\[/.test(air), 'server ticket_groups are never overwritten with a synthetic group');
check(!/setProductResponse\(['"]air['"],bookingId,\{[^}]*ticket_groups/.test(air), 'synthetic groups are not written to product cache');
check(/var save= create|var save=create/.test(air), 'shared main Save control remains present');
check(/etgpAirSave113314\(bookingId,payload\)/.test(air) && /etgpAirData113314\.refresh\(bookingId\)/.test(air), 'first save uses the existing PUT and fresh scoped GET');
check(/data\.ticket_groups=result\.ticket_groups\|\|pageState\.groups/.test(air), 'server response replaces the ephemeral group after save');

// Existing validation, state integrity, lock behavior, and product scope stay
// owned by the established implementation.
check(/if\(!group\.segment_keys\|\|!group\.segment_keys\.length\)return/.test(air), 'empty visual group cannot save without a segment');
check(/Select Vendor \/ Supplier for every Ticket Group/.test(air), 'supplier validation remains enforced');
check(/Enter a PNR for every Ticket Group/.test(air), 'PNR validation remains enforced');
check(/multiDraftGeneration/.test(air) && /cancelAirMultiDraft113119/.test(air), 'C77 lifecycle timer protections remain');
check(/getProductEndpoint\?core\.getProductEndpoint/.test(air), 'supplementary endpoint remains context-derived');
check(/requestAnimationFrame\(function\(\)\{var lock=integration\.getLockState\(\)/.test(air), 'locked Air still receives server-derived read-only state');
check(/corrective_build' => 'C79'/.test(release) && /asset_version' => 'ERP-11\.3\.378-C79'/.test(release), 'C79 release metadata is active');

console.log(`ERP-11.3.378 C79 Air zero-state/main UI parity regression: PASS (${assertions} assertions)`);
