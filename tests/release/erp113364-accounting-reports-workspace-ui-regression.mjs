import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';

const root = path.resolve(import.meta.dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const middleware = read('app/Http/Middleware/PresentAccountingReportsWorkspace.php');
const navPartial = read('resources/views/accounting/management-reporting/_navigation.blade.php');
const release = read('config/et_erp_release.php');
const current = read('CURRENT_RELEASE.md');
const base = '35f47ac17a9ebe635ff839e8e21666eaf6606d5d';
const changed = execFileSync('git', ['diff', '--name-only', `${base}..HEAD`], { cwd: root, encoding: 'utf8' }).trim().split(/\r?\n/).filter(Boolean);
const inject = middleware.slice(middleware.indexOf('private function injectManagementReportingNavigation'), middleware.indexOf('private function controlAfterLabel'));
const resolveBoundary = (html) => {
  const workspaces = [...html.matchAll(/<section\b[^>]*data-et-accounting-reports-workspace(?:="[^"]*")?[^>]*>/gi)];
  if (workspaces.length !== 1) return null;
  const start = workspaces[0].index;
  const rest = html.slice(start);
  const end = rest.indexOf('</section>');
  if (end < 0) return null;
  const workspace = rest.slice(0, end + '</section>'.length);
  const filters = [...workspace.matchAll(/<form\b[^>]*data-et-report-filter(?:="[^"]*")?[^>]*>/gi)];
  return filters.length === 1 ? workspace : null;
};
const validFixture = '<section data-et-accounting-reports-workspace="1"><h1>Accounting Reports</h1><form data-et-report-filter="1"></form></section>';

const checks = [
  ['global layout untouched', changed.every((file) => !file.startsWith('resources/views/layouts/'))],
  ['global header untouched', changed.every((file) => !file.includes('header') && !file.includes('topbar'))],
  ['no first global form injection', !inject.includes("preg_match('/<form\\b/i'") && !inject.includes('return $navigation.$html')],
  ['semantic report marker', middleware.includes('data-et-accounting-reports-workspace="1"')],
  ['fail closed boundary', inject.includes('return $html;') && inject.includes('data-et-accounting-reports-workspace="1"')],
  ['single body heading', middleware.includes('<h1 class="et-accounting-reports-title">Accounting Reports</h1>')],
  ['legacy heading removed', middleware.includes('ERP-09\\.3') && middleware.includes('removeLegacyReportsHeading')],
  ['legacy subtitle removed', middleware.includes('Report headers use') && middleware.includes('original source document')],
  ['navigation below heading', inject.includes('data-et-accounting-reports-workspace="1"') && inject.includes('Accounting Reports')],
  ['six report destinations', ['Management Overview', 'Profit & Loss', 'Trial Balance', 'Balance Sheet', 'Ledger Reports', 'Party Statement'].every((label) => middleware.includes(label))],
  ['ledger active', middleware.includes("route('accounting.reports.index')") && middleware.includes('class="active" href=')],
  ['navigation no duplicate', middleware.includes('data-et-management-report-nav=') && middleware.includes('return $html;')],
  ['navigation internal scroll', middleware.includes('flex-wrap:nowrap') && middleware.includes('overflow-x:auto')],
  ['navigation full width', middleware.includes('width:100%')],
  ['filter marker preserved', middleware.includes('data-et-report-filter="ERP-11.3.34"')],
  ['report filters title context', middleware.includes('Report Type') && middleware.includes('Date From') && middleware.includes('Date To / As Of')],
  ['filter note removed', !middleware.includes('Choose the report, then the matching Vendor') && !middleware.includes('et-rf-note')],
  ['primary filter action', middleware.includes('Apply &amp; Preview')],
  ['reset filter action', middleware.includes('>Reset</a>')],
  ['empty preview compact', middleware.includes('data-et-report-empty-preview="1"') && middleware.includes('Report Preview')],
  ['empty preview message', middleware.includes('Choose a report type and filters, then click Apply &amp; Preview.')],
  ['no fake export text', !middleware.includes('Excel') && !middleware.includes('PDF')],
  ['dynamic subject preserved', middleware.includes('data-et-report-subject-wrap') && middleware.includes('datasetSelect')],
  ['native form authority preserved', middleware.includes("$action = $this->attr($attrs, 'action')") && middleware.includes("$method = strtolower($this->attr($attrs, 'method')")],
  ['hidden fields preserved', middleware.includes('$hidden .= $input') && middleware.includes('type=')],
  ['native controller remains authority', middleware.includes('Native ReportController stays authoritative')],
  ['party statement unchanged', !changed.includes('resources/views/accounting/party-statement/index.blade.php')],
  ['party print unchanged', !changed.includes('resources/views/accounting/party-statement/print.blade.php')],
  ['accounting services unchanged', !changed.some((file) => file.startsWith('app/Services/Accounting/'))],
  ['database routes unchanged', !changed.some((file) => file.startsWith('database/') || file.startsWith('routes/'))],
  ['management partial preserved', navPartial.includes('Management Overview') && navPartial.includes('Party Statement')],
  ['no accounting writes', !middleware.includes('DB::insert') && !middleware.includes('DB::update')],
  ['no migration', release.includes('NEW_MIGRATION_REQUIRED=NO') && current.includes('NEW_MIGRATION_REQUIRED=NO')],
  ['target metadata', release.includes('ERP-11.3.364') && current.includes('CURRENT_DEVELOPMENT_RELEASE=ERP-11.3.364')],
  ['strict workspace count', middleware.includes('preg_match_all($markerPattern') && middleware.includes('!== 1')],
  ['strict filter count', middleware.includes('preg_match_all($filterPattern') && middleware.includes('!== 1')],
  ['scoped filter boundary', middleware.includes('$workspaceHtml') && middleware.includes('sectionRange')],
  ['zero workspace fail closed', resolveBoundary('<main><form data-et-report-filter="1"></form></main>') === null],
  ['single workspace proceeds', resolveBoundary(validFixture) !== null],
  ['multiple workspace fail closed', resolveBoundary(`${validFixture}${validFixture}`) === null],
  ['zero filter fail closed', resolveBoundary('<section data-et-accounting-reports-workspace="1"><h1>Accounting Reports</h1></section>') === null],
  ['multiple filter fail closed', resolveBoundary('<section data-et-accounting-reports-workspace="1"><form data-et-report-filter="1"></form><form data-et-report-filter="2"></form></section>') === null],
  ['unrelated filter cannot satisfy scope', resolveBoundary('<form data-et-report-filter="outside"></form><section data-et-accounting-reports-workspace="1"><h1>Accounting Reports</h1></section>') === null],
  ['repeat invocation idempotent', middleware.includes('data-et-management-report-nav=') && middleware.includes('return $html;')],
];

for (const [name, value] of checks) assert.ok(value, name);
console.log(`364_REGRESSION=PASS (${checks.length} assertions)`);
