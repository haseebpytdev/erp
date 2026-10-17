import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = process.cwd();
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const air = read('public/erp-theme/js/products/air.js');
const controller = read('app/Http/Controllers/Operations/GeneralBookingAirProductController.php');
const historical = read('tests/release/erp113378-c88-1-voucher-iata-master-refresh-regression.mjs');
const authority = read('app/Services/Administration/ErpBranchAccessAuthority.php');
const release = read('config/et_erp_release.php');
const check = (condition, message) => assert.equal(Boolean(condition), true, message);

check(air.includes('var appendGroupCard113324='), 'group additions use a targeted card append helper');
check(air.includes('var reindexGroupEditors113324='), 'group editor indexes are repaired after structural changes');
check(air.includes('pageState.groups.push(copy);appendGroupCard113324'), 'duplicate group appends only the new card');
check(air.includes('pageState.groups.splice(at,1);card.remove();'), 'delete group removes only its card');
check(air.includes('pageState.groups.push(addedGroup);appendGroupCard113324'), 'add group appends only the new card');
check(!air.includes('pageState.groups.push(copy);render();'), 'duplicate group no longer rebuilds the workspace');
check(!air.includes('pageState.groups.splice(at,1);render();'), 'delete group no longer rebuilds the workspace');
check(!air.includes('fare_commercials:{}});render();'), 'add group no longer rebuilds the workspace');
check(air.includes('data-etgp-air-delegated-root'), 'root delegation marker remains present');
check(air.includes('etgpAirDraftDelay113119'), 'draft debounce lifecycle remains active');
check(air.includes('?600:180'), 'authenticated draft debounce remains 600ms');
check(air.includes('client_key'), 'stable group and segment keys remain');
check(air.includes('groupsBlock.appendChild(card)'), 'new cards remain in the native groups container');
check(air.includes('editorHosts.push(editor)'), 'new editors remain in the native editor collection');
check(controller.includes('$existingByPassenger') && controller.includes('DB::transaction(function'), 'C89.4.5 save/preload behavior remains intact');
const baseAuthority = fs.existsSync(path.join(root, '.git'))
  ? execFileSync('git', ['show', 'f0f7142dd851ec5e154d67ff2de0bf66f25ab2af:app/Services/Administration/ErpBranchAccessAuthority.php'], { cwd: root, encoding: 'utf8' }).replace(/\r\n/g, '\n')
  : authority.replace(/\r\n/g, '\n');
check(authority.replace(/\r\n/g, '\n') === baseAuthority, 'central branch authority remains unchanged');
check(historical.includes('pakistani_iata_name') && historical.includes('voucher presentation markup is unchanged'), 'C88.1 voucher semantic assertions remain');
check(!historical.includes('C88 Air client runtime is unchanged'), 'historical Air assertion is not falsely applied to later Air work');
check(release.includes("'asset_version' => 'ERP-11.3.378-C89.4.5.1'"), 'C89.4.5.1 asset identity is active');
check(release.includes("'corrective_build' => 'C89.4.5.1'"), 'C89.4.5.1 corrective build is active');
check(release.includes("'corrective_name' => 'Air Product Performance Completion'"), 'C89.4.5.1 corrective name is active');
check(!fs.existsSync(path.join(root, 'database', 'migrations', 'c89-4-5-1-air-performance-completion.php')), 'no migration was added');
check(!fs.existsSync(path.join(root, 'database', 'schema')), 'no schema directory was introduced');
check(!controller.includes('SalesInvoiceService::create'), 'no accounting or invoice path was added');
check(air.includes('renderTicketGroupEditor113106(editor,groupData,bookingId)'), 'native group editor remains the sole group renderer');

console.log('C89.4.5.1 Air performance completion regression: PASS (25 assertions)');
