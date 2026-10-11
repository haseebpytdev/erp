import fs from 'node:fs';
import assert from 'node:assert/strict';

const tabs=fs.readFileSync('resources/views/reports/travel/partials/movement-tabs.blade.php','utf8');
const middleware=fs.readFileSync('app/Http/Middleware/ApplyErpReleaseMetadata.php','utf8');
const service=fs.readFileSync('app/Services/Reports/TravelReportService.php','utf8');
const config=fs.readFileSync('config/et_erp_release.php','utf8');
const ok=(v,m)=>assert.ok(v,m);
ok(tabs.includes('.et-arrival-tabs a:not(.active):hover'),'authoritative inactive selector remains exact');
ok((tabs.includes('background:#dbeafe')||tabs.includes('background:#f8fafc')||tabs.includes('background:#f5f7fa'))&&tabs.includes('border-color:#cbd5e1')&&tabs.includes('box-shadow:'),'hover remains visibly distinct');
ok((tabs.includes('a.active{background:#1769d2;border-color:#1769d2;color:#fff}')||tabs.includes('a.active{background:#2563eb;border-color:#2563eb;color:#fff}')),'active state remains unchanged');
ok(tabs.includes('gap:7px')&&tabs.includes('overflow-x:auto')&&tabs.includes('flex-wrap:nowrap'),'gap and mobile overflow remain');
ok(tabs.includes('width:16px!important')&&tabs.includes('height:16px!important'),'paginator SVG remains 16px');
ok(middleware.includes('$dom->createTextNode($build')&&middleware.includes('$dom->createTextNode($line)'),'C84.3.1 DOM-safe text handling remains');
ok(service.includes('class TravelReportService'),'TravelReportService remains unchanged');
ok(/'asset_version' => 'ERP-11.3.378-C84.3(?:\.2|\.3|\.4)'/.test(config)&&/'corrective_build' => 'C84.3(?:\.2|\.3|\.4)'/.test(config)&&/Visible Movement Tab Hover|Movement Tab Hover Color Refinement|Movement Tab Button Style Alignment/.test(config),'C84.3 lineage identity remains correct');
console.log('ERP-11.3.378 C84.3.2 visible movement hover regression: PASS / 8');
