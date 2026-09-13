import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const service = read('app/Services/System/DayZeroDataResetService.php');
const controller = read('app/Http/Controllers/System/ProductionDataResetController.php');
const view = read('resources/views/system/day-zero-data-reset-v113242.blade.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(service.includes('ERP-11.3.244 functional checkpoint'), 'service identifies the execution-safety checkpoint');
ok(service.includes('public const EXECUTION_ENABLED = false'), 'destructive execution remains disabled');
ok(service.includes("'ready_to_execute' => self::EXECUTION_ENABLED && $safetyPreviewReady"), 'execution remains gated by explicit release enablement and all safety checks');
ok(service.includes("'action' => 'review'"), 'future unknown tables continue to fail closed');
ok(service.includes('Unclassified table. Must be reviewed explicitly'), 'future-schema REVIEW reason remains explicit');

ok(service.includes('private function dependencyAudit'), 'live FK dependency audit exists');
ok(service.includes('private function foreignKeyRelationships'), 'foreign-key discovery authority exists');
ok(service.includes('information_schema.KEY_COLUMN_USAGE'), 'MySQL/MariaDB FK discovery is present');
ok(service.includes('information_schema.table_constraints'), 'PostgreSQL FK discovery is present');
ok(service.includes('sys.foreign_key_columns'), 'SQL Server FK discovery is present');
ok(service.includes('PRAGMA foreign_key_list'), 'SQLite FK discovery is present');
ok(service.includes("$parentAction === 'clear' && $childAction !== 'clear'"), 'preserved/counter/review child pointing at CLEAR parent is a blocker');
ok(service.includes("$childAction === 'clear' && $parentAction === 'clear'"), 'CLEAR-to-CLEAR foreign keys feed delete ordering');
ok(service.includes('private function topologicalDeleteOrder'), 'child-before-parent delete ordering is computed');
ok(service.includes("'dependency_plan_ready' => $dependency['ready']"), 'dependency plan readiness is surfaced');
ok(service.includes("'dependency_cycles' => count($dependency['cycle_tables'])"), 'dependency cycles are surfaced and block readiness');

ok(service.includes('private function counterResetPreview'), 'counter reset preview exists');
ok(service.includes('Schema::getColumnListing($table)'), 'counter preview inspects live columns');
ok(service.includes("'current_number'") && service.includes("'next_number'"), 'common current/next numbering columns are recognized');
ok(service.includes("'counter_reset_ready' => $counterPreview['ready']"), 'counter reset readiness is surfaced');
ok(service.includes("'ERP-11.3.244-EXECUTION-SAFETY-PREVIEW'"), 'backup metadata identifies the safety preview');
ok(service.includes('Day-Zero execution is intentionally LOCKED in ERP-11.3.244 execution safety preview'), 'execute path remains hard locked');
ok(!service.includes('->delete(') && !service.includes('truncate(') && !service.includes('TRUNCATE TABLE') && !service.includes('DELETE FROM') && !service.includes('DROP TABLE') && !service.includes('DB::statement('), 'safety preview contains no destructive database operation');

ok(controller.includes('$this->backupReady($request, $service)'), 'controller validates backup status through Day-Zero service authority');
ok(controller.includes('$service->validateBackup($path, $at)'), 'backupReady uses the same service validation contract as execute');

ok(view.includes("config('et_erp_release.release', 'ERP')"), 'maintenance heading uses current release metadata');
ok(!view.includes('ERP-11.3.242 Preview'), 'stale hard-coded .242 heading is removed');
ok(view.includes('Execution safety audit'), 'view presents FK/dependency safety audit');
ok(view.includes('FK blockers'), 'view exposes FK blocker count');
ok(view.includes('Safe child-before-parent delete order preview'), 'view exposes calculated delete order');
ok(view.includes('Counter reset preview'), 'view exposes exact counter reset preview');
ok(view.includes('Fresh backup validation'), 'view exposes fresh backup validation state');
ok(view.includes('Day-Zero Reset Locked'), 'destructive control remains disabled');

ok(version === 'v1.1.33.251-ERP11.3.251', 'ERP-11.3.251 release metadata is promoted for packaging');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
