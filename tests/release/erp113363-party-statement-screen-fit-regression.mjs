import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '../..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const screen = read('resources/views/accounting/party-statement/index.blade.php');
const print = read('resources/views/accounting/party-statement/print.blade.php');
const service = read('app/Services/Accounting/PartyStatementService.php');
const releaseConfig = read('config/et_erp_release.php');
const currentRelease = read('CURRENT_RELEASE.md');
const controller = read('app/Http/Controllers/Accounting/PartyStatementController.php');
const links = read('app/Services/Accounting/PartyStatementSourceLinkResolver.php');
const header = read('resources/views/accounting/party-statement/_document-header.blade.php');
const widths = (source) => {
  const inline = [...source.matchAll(/<col[^>]*style="width:([0-9]+)%"/g)].map((m) => Number(m[1]));
  if (inline.length) return inline;
  return [...source.matchAll(/col:nth-child\((\d+)\)\{width:([0-9]+)%\}/g)]
    .sort((a, b) => Number(a[1]) - Number(b[1]))
    .map((m) => Number(m[2]));
};
const screenWidths = widths(screen);
const printWidths = widths(print);

const checks = [
  ['ERP workspace full width', screen.includes('.et-party-statement{width:100%;max-width:none')],
  ['preview full width', screen.includes('.et-ps-preview{width:100%;box-sizing:border-box')],
  ['document full width', screen.includes('.et-ps-document{width:100%;max-width:none;min-height:0;aspect-ratio:auto')],
  ['no physical screen paper width', !screen.includes('210mm') && !screen.includes('297mm') && !screen.includes('aspect-ratio:210')],
  ['mobile paper constraint removed', !screen.includes('.et-ps-document{width:210mm') && !screen.includes('min-height:297mm')],
  ['preview and document same workspace', screen.includes('.et-ps-preview{width:100%') && screen.includes('.et-ps-document{width:100%')],
  ['responsive filters', screen.includes('repeat(4,minmax(0,1fr))') && screen.includes('repeat(2,minmax(0,1fr))') && screen.includes('grid-template-columns:1fr')],
  ['actions separate row', screen.includes('.et-ps-actions{grid-column:1/-1')],
  ['screen table full width', screen.includes('.et-ps-ledger-table{width:100%;max-width:100%')],
  ['nine screen columns', screenWidths.length === 9 && screenWidths.join('/') === '8/4/9/11/13/26/9/9/11'],
  ['nine headers', (screen.match(/<th>/g) || []).length === 9],
  ['screen width total', screenWidths.reduce((sum, width) => sum + width, 0) === 100],
  ['screen readable font', screen.includes('border-collapse:collapse;font-size:10px')],
  ['screen cell separation', screen.includes('border:1px solid #dbe4ef') && screen.includes('vertical-align:middle')],
  ['screen header wrapping', screen.includes('.et-ps-ledger-table th{background:#f2f5f8;white-space:normal}')],
  ['screen numeric alignment', screen.includes('th:nth-last-child(-n+3)') && screen.includes('text-align:right')],
  ['screen identity nowrap', screen.includes('td:nth-child(-n+3)') && screen.includes('white-space:nowrap')],
  ['description can wrap', screen.includes('overflow-wrap:anywhere')],
  ['no cell clipping', !screen.includes('overflow:hidden') && !screen.includes('text-overflow:ellipsis')],
  ['controlled table scroll only', screen.includes('.et-ps-table-wrap{width:100%;max-width:100%;overflow-x:auto') && !screen.includes('et-ps-document{width:210mm')],
  ['body horizontal scroll prevented', screen.includes('html,body{max-width:100%;overflow-x:hidden}')],
  ['screen source links preserved', screen.includes('source_url') && screen.includes('target="_blank"') && screen.includes('rel="noopener"')],
  ['print unchanged geometry', print.includes('width:210mm') && print.includes('min-height:297mm') && print.includes('@page{size:A4 portrait')],
  ['print nine columns', printWidths.length === 9 && printWidths.join('/') === '8/4/9/11/13/26/9/9/11'],
  ['print table remains 8px', print.includes('font-size:8px')],
  ['print black borders', print.includes('border:.7pt solid #000')],
  ['print no source links', !print.includes('source_url') && !print.includes('et-ps-source-link')],
  ['print file remains separate', print.includes('et-ps-print-page') && !print.includes('et-ps-preview')],
  ['period controls preserved', screen.includes('name="from"') && screen.includes('name="to"')],
  ['type controls preserved', screen.includes('name="party_type"')],
  ['all branches presentation preserved', header.includes('Branch: All Branches')],
  ['financial freeze preserved', service.includes("where('je.status', 'posted')")],
  ['no report writes', !service.includes('insert(') && !service.includes('update(') && !controller.includes('DB::table')],
  ['363 migration contract remains scoped', !releaseConfig.includes('CURRENT_DEVELOPMENT_RELEASE=ERP-11.3.363') || (releaseConfig.includes('NEW_MIGRATION_REQUIRED=NO') && currentRelease.includes('NEW_MIGRATION_REQUIRED=NO'))],
  ['screen source reference unchanged', screen.includes("{{ $row['reference'] ?: '—' }}")],
  ['print source reference plain', print.includes("{{ $row['reference'] ?: '—' }}")],
  ['source resolver unchanged', links.includes('source_type') && links.includes('source_id')],
];

for (const [name, value] of checks) assert.ok(value, name);
console.log(`363_REGRESSION=PASS (${checks.length} assertions)`);
