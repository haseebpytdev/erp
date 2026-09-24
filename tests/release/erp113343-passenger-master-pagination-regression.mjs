import assert from 'node:assert/strict';
import fs from 'node:fs';

const controller = fs.readFileSync(new URL('../../app/Http/Controllers/Operations/PassengerWorkspaceController.php', import.meta.url), 'utf8');
const source = fs.readFileSync(new URL('../../app/Services/Operations/UnifiedGroupPackageDataSource.php', import.meta.url), 'utf8');
const writer = fs.readFileSync(new URL('../../app/Services/Operations/AdaptivePassengerMasterWriter.php', import.meta.url), 'utf8');
const view = fs.readFileSync(new URL('../../resources/views/operations/passengers/index.blade.php', import.meta.url), 'utf8');
let pass = 0;
const ok = (value, message) => { assert.ok(value, message); pass++; };

ok(controller.includes('LengthAwarePaginator') && controller.includes('$perPage = 25'), 'Passenger Master uses 25-row server paginator');
ok(controller.includes('$filtered->forPage($page, $perPage)') && controller.includes('new LengthAwarePaginator'), 'pagination is applied after the complete filtered result set');
ok(controller.includes("'query' => $request->query()") && controller.includes('withQueryString'), 'search query is retained across pagination links');
ok(controller.includes('normalizePassport($needle)') && source.includes('normalizePassport'), 'search uses canonical logical passport normalization');
ok(controller.includes("UPPER(REPLACE(`$c`, ' ', ''))") && writer.includes("UPPER(REPLACE(`$passportColumn`, ' ', ''))"), 'update and create duplicate checks use the same normalized passport identity');
ok(view.includes('Showing {{ $passengers->firstItem()') && view.includes('->onEachSide(2)->links()'), 'Passenger Master shows compact result counter and page links');
ok(view.includes('.pm262-pagination') && view.includes('.pm262-pagination svg{width:16px!important;height:16px!important'), 'pagination styling is scoped and compact like report pagination');
ok(view.includes('.pm262-table{overflow:auto}'), 'passenger table remains responsive without page-level overflow');
ok(controller.includes('$this->source->passengers()') && writer.includes('resolveStandalone'), 'booking reuse and standalone master write paths remain present');

console.log(`erp113343-passenger-master-pagination-regression: ${pass} assertions passed`);
