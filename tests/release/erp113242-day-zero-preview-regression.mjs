import assert from 'node:assert/strict';
import fs from 'node:fs';

const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const controller = read('app/Http/Controllers/System/ProductionDataResetController.php');
const service = read('app/Services/System/DayZeroDataResetService.php');
const view = read('resources/views/system/day-zero-data-reset-v113242.blade.php');
const version = read('VERSION.txt').trim();

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };

ok(controller.includes('DayZeroDataResetService'), 'production reset route controller now uses Day-Zero planner');
ok(controller.includes("system.day-zero-data-reset-v113242"), 'Day-Zero preview view is rendered');
ok(controller.includes('day_zero_reset_backup_path'), 'Day-Zero backup session namespace is independent from legacy reset');

ok(service.includes("public const CONFIRMATION = 'RESET ERP TO DAY ZERO'"), 'exact Day-Zero confirmation phrase is fixed');
ok(service.includes('public const EXECUTION_ENABLED = false'), 'destructive execution is disabled in preview release');
ok(service.includes("'users'"), 'user accounts are preserved');
ok(service.includes("'model_has_roles'") && service.includes("'role_has_permissions'"), 'authorization foundation is preserved');
ok(service.includes("'customers'") && service.includes("'suppliers'") && service.includes("'passengers'"), 'known UAT identity tables are marked for fresh start');
ok(service.includes("'sessions'") && service.includes("'jobs'") && service.includes("'cache'"), 'runtime residue is included in Day-Zero cleanup planning');
ok(service.includes("'action' => 'review'"), 'unknown tables fail closed to REVIEW');
ok(service.includes('Unclassified table. Must be reviewed explicitly'), 'unknown-table reason is explicit');
ok(service.includes("'ready_to_execute' => self::EXECUTION_ENABLED && $reviewTables === 0"), 'execution requires both release enablement and zero REVIEW tables');
ok(service.includes('Full pre-Day-Zero database backup'), 'backup is full-database rather than only touched tables');
ok(service.includes("storage_path('app/day-zero-reset-backups')"), 'Day-Zero backup storage is separate from legacy reset');
ok(service.includes("storage_path('app/system/day-zero-reset-completed.json')"), 'Day-Zero completion lock is separate from legacy reset');
ok(service.includes('Day-Zero execution is intentionally LOCKED'), 'execute path refuses mutation in preview release');
ok(!service.includes('->delete(') && !service.includes('truncate(') && !service.includes('DROP TABLE'), 'preview service contains no destructive database operation');

ok(view.includes('No delete can run from this preview build.'), 'UI clearly states preview-only safety boundary');
ok(view.includes('Day-Zero Reset Locked'), 'UI disables destructive action');
ok(view.includes("route('system.production-data-reset.backup')"), 'existing authenticated route remains the backup entry point');
ok(version === 'v1.1.33.249-ERP11.3.249', 'ERP-11.3.246 controlled execution release metadata is promoted for packaging');

console.log(`TESTS_PASS=${pass}`);
console.log('TESTS_FAIL=0');
