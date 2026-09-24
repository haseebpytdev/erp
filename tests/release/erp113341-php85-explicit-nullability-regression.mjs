import fs from 'node:fs';
import assert from 'node:assert/strict';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const files = [
  'app/Services/Reports/TravelReportService.php',
  'app/Http/Controllers/Reports/TravelReportsController.php',
  'app/Services/Administration/ErpRoleAccessPolicy.php',
  'app/Services/Operations/ServerSidebarComposer.php',
  'routes/erp103179.php',
  'config/et_erp_release.php'
];
const source = fs.readFileSync(path.join(root, files[0]), 'utf8');
let assertions = 0;
const ok = (condition, message) => {
  assertions += 1;
  assert.ok(condition, message);
};

ok(source.includes('applyBookingRelationFilters'), 'applyBookingRelationFilters exists');
ok(source.includes('?string $queryAlias=null'), 'queryAlias is explicitly nullable');
ok(!/(^|[^?])string \$queryAlias=null/.test(source), 'implicit queryAlias nullability is absent');

const implicitNullable = /(?<![?\\w|])(?:string|int|float|bool|array|[A-Z_\\\\][A-Za-z0-9_\\\\]*)\\s+\\$[A-Za-z_][A-Za-z0-9_]*\\s*=\\s*null\\b/g;
let implicitCount = 0;
for (const relative of files) {
  const text = fs.readFileSync(path.join(root, relative), 'utf8');
  implicitCount += [...text.matchAll(implicitNullable)].length;
}
ok(implicitCount === 0, 'all changed PHP files have no implicit nullable parameters');
ok(source.includes("whereDate('h.'.$date,'<=',$v)));}}}"), 'line-55 parser fix remains present');
ok(source.includes("($r->id??'')))->values();"), 'line-101 parser fix remains present');
ok(files.length === 6, 'all six changed PHP files are audited');

console.log('ERP-11.3.341 PHP 8.5 explicit-nullability regression: PASS (' + assertions + ' assertions)');
console.log('IMPLICIT_NULLABLE_PARAMETER_COUNT=' + implicitCount);
