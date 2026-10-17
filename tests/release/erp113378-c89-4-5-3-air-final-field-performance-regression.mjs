import fs from 'node:fs';
import path from 'node:path';

const root = process.cwd();
const air = fs.readFileSync(path.join(root, 'public/erp-theme/js/products/air.js'), 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');
const c451 = fs.readFileSync(path.join(root, 'tests/release/erp113378-c89-4-5-1-air-performance-completion-regression.mjs'), 'utf8');
const c452 = fs.readFileSync(path.join(root, 'tests/release/erp113378-c89-4-5-2-air-flight-field-entry-performance-regression.mjs'), 'utf8');
const c8951b0b = fs.readFileSync(path.join(root, 'tests/release/erp113378-c89-5-1b-0b-scoped-fail-closed-regression.mjs'), 'utf8');
const c8951b0a5 = fs.readFileSync(path.join(root, 'tests/release/erp113378-c89-5-1b-0a-5-user-management-ui-polish-regression.mjs'), 'utf8');
const c89465 = fs.readFileSync(path.join(root, 'tests/release/erp113378-c89-4-6-5-administration-spacing-copy-regression.mjs'), 'utf8');

let assertions = 0;
const check = (condition, message) => {
  assertions += 1;
  if (!condition) throw new Error(`FAIL: ${message}`);
};

check((air.match(/page\.addEventListener\('input',recalcFromEvent113119\)/g) || []).length === 1, 'one delegated fare input path remains');
check((air.match(/page\.addEventListener\('change',recalcFromEvent113119\)/g) || []).length === 1, 'one delegated fare change path remains');
check(!air.includes("input.addEventListener('input',recalc)"), 'direct fare input recalc path is absent');
check(!air.includes("input.addEventListener('change',recalc)"), 'direct fare change recalc path is absent');
check(air.includes("data-etgp-air-commercial"), 'commercial controls are explicitly marked');
check(air.includes('markNonCommercialAirEvent113119'), 'non-commercial event gate is present');
check(air.includes("target.getAttribute('data-etgp-air-commercial')==='1'"), 'delegated recalculation is commercial-only');
check(air.includes("ticketInputs.forEach(function(input){input.addEventListener('input',syncTicketStatuses)"), 'ticket number status sync remains');
check(air.includes("ticket.addEventListener('change',function(){fillFollowingTickets(index);})"), 'ticket number continuation remains');
check(air.includes("ticket.addEventListener('blur',function(){fillFollowingTickets(index);})"), 'ticket blur continuation remains');
check(air.includes("ticketStatus.select.addEventListener('change',syncIssueDateRequirement)"), 'issue-date requirement sync remains');
check(air.includes("ticketStatus.select.addEventListener('change',syncTicketStatuses)"), 'ticket status sync remains');
check(air.includes("etgpAirDraftDelay113119"), 'draft debounce remains');
check(air.includes("scheduleAirMultiDraft113119"), 'draft scheduler remains');
check(air.includes("requestIdleCallback"), 'idle draft scheduling remains');
check(air.includes("pagehide") && air.includes("visibilitychange"), 'lifecycle draft flush remains');
check(air.includes("lastDraftPayload===payloadSerialized"), 'identical draft writes remain skipped');
check(air.includes("JSON.stringify(payload)"), 'draft serialization remains');
check(air.includes("localStorage.setItem"), 'draft localStorage recovery remains');
check(air.includes("etgpAirRequest113106(bookingId,'PUT',payload)"), 'save PUT remains');
check(air.includes('syncEditors();var invalid=validate()'), 'explicit save validation remains');
check(air.includes('result.ticket_groups||pageState.groups'), 'save response state remains authoritative');
check(!air.includes("pageState.groups.push(copy);render();"), 'duplicate group targeted rendering remains');
check(!air.includes("pageState.groups.splice(at,1);render();"), 'delete group targeted rendering remains');
check(air.includes('indexedAirlines=airlines.map'), 'airline index remains precomputed');
check(!air.includes('airlines.filter(function(item){return !query||normalized(item.name)'), 'per-key airline normalization remains absent');
check(air.includes('lastResultSignature'), 'unchanged autocomplete result rebuilds remain skipped');
check(release.includes("'asset_version' => 'ERP-11.3.378-C89.4.5.3'"), 'C89.4.5.3 asset identity is active');
check(release.includes("'corrective_build' => 'C89.4.5.3'"), 'C89.4.5.3 build identity is active');
check(release.includes("'corrective_name' => 'Air Final Field Entry Performance'"), 'C89.4.5.3 name is active');
check(c451.includes('C89.4.5.1 Air performance completion regression'), 'C89.4.5.1 regression remains available');
check(c452.includes('C89.4.5.2 Air flight field-entry performance regression'), 'C89.4.5.2 regression remains available');
check(c8951b0b.includes('C89.5.1B.0B'), 'C89.5.1B.0B regression remains available');
check(c8951b0a5.includes('C89.5.1B.0A.5'), 'ERP User Management regression remains available');
check(c89465.includes('C89.4.6.5'), 'Administration regression remains available');
check(!fs.existsSync(path.join(root, 'database/migrations/c89-4-5-3-air-final-field-performance.php')), 'no migration was added');

console.log(`C89.4.5.3 Air final field performance regression: PASS (${assertions} assertions)`);
