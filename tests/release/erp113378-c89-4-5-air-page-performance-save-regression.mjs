import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const air = fs.readFileSync(path.join(root, 'public/erp-theme/js/products/air.js'), 'utf8');
const controller = fs.readFileSync(path.join(root, 'app/Http/Controllers/Operations/GeneralBookingAirProductController.php'), 'utf8');
const workspace = fs.readFileSync(path.join(root, 'resources/views/operations/bookings/partials/product-workspace-v113305.blade.php'), 'utf8');
const release = fs.readFileSync(path.join(root, 'config/et_erp_release.php'), 'utf8');
const authority = fs.readFileSync(path.join(root, 'app/Services/Administration/ErpBranchAccessAuthority.php'), 'utf8');

const baseAuthority = execFileSync('git', ['show', 'f0f7142dd851ec5e154d67ff2de0bf66f25ab2af:app/Services/Administration/ErpBranchAccessAuthority.php'], { cwd: root, encoding: 'utf8' }).replace(/\r\n/g, '\n');
const check = (condition, message) => assert.equal(Boolean(condition), true, message);

check(air.includes("data-etgp-air-delegated-root"), 'Air workspace has a stable delegated root marker');
check(air.includes("addEventListener('input'"), 'Air input handling remains active');
check(air.includes("addEventListener('change'"), 'Air change handling remains active');
check(!air.includes("},180);"), 'No 180ms draft churn remains');
check(air.includes("?600:180"), 'Authenticated draft persistence is conservatively debounced');
check(air.includes("etgp-air-product-draft-v113119:") && air.includes("user:"), 'Draft key is booking and user scoped');
check(workspace.includes('data-etgp-user-id="{{ (int) auth()->id() }}"'), 'Authenticated actor scope is server rendered');
check(air.includes("!root.getAttribute('data-etgp-user-id')"), 'Legacy draft compatibility is restricted to unauthenticated fixtures');
check(air.includes('Unsaved local Air draft recovered'), 'Recovery state is explicitly identified');
check(!air.includes('Unsaved Air data was restored from this browser'), 'Recovery is not presented as ordinary saved data');
check(air.includes("etgpAirRequest113106(bookingId,'PUT'"), 'Save continues through PUT');
check(air.includes('etgpAirData113314.refresh(bookingId)'), 'Successful save performs server read-back');
check(air.includes('etgpAirDraft113314.clear(bookingId)'), 'Only the matching scoped draft is cleared');
check(air.includes('save.disabled=true'), 'Double submit protection remains');
check(air.includes("save.textContent='Saving…'"), 'Saving state remains visible');
check(air.includes('client_key'), 'Stable segment/group client keys remain');
check(air.includes('segment_keys'), 'Stable segment mappings remain');
check(air.includes('r.remove()'), 'Segment removal is targeted');
check(air.includes('row(segment)'), 'Segment insertion is targeted');
check(air.includes('_etgpRecalc113126'), 'Group-local recalculation hook exists');
check(controller.includes('DB::transaction(function'), 'Air save remains atomic');
check(controller.includes('$existingByPassenger'), 'Existing ticket rows are preloaded');
check(!controller.includes('where($passengerColumn, $passengerValue)\n                ->orderByDesc'), 'Per-passenger existing-row query was removed');
check(controller.includes('$physicalColumnsCache'), 'Schema discovery is request-cached');
check(controller.includes('$prototypeCache'), 'Prototype discovery is request-cached');
check(controller.includes('$supplierOptionsCache'), 'Supplier master discovery is request-cached');
check(authority.replace(/\r\n/g, '\n') === baseAuthority, 'C89.4.4 branch authority is unchanged');
check(!controller.includes('SalesInvoiceService::create'), 'No invoice creation path was added');
check(release.includes("'asset_version' => 'ERP-11.3.378-C89.4.5.1'"), 'C89.4.5.1 asset identity is present');
check(release.includes("'corrective_build' => 'C89.4.5.1'"), 'C89.4.5.1 corrective build is present');
check(release.includes("'corrective_name' => 'Air Product Performance Completion'"), 'C89.4.5.1 name is present');

console.log('C89.4.5 Air performance/save regression: PASS (30 assertions)');
