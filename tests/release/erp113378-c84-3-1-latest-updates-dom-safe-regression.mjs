import fs from 'node:fs';
import assert from 'node:assert/strict';

const middleware=fs.readFileSync('app/Http/Middleware/ApplyErpReleaseMetadata.php','utf8');
const tabs=fs.readFileSync('resources/views/reports/travel/partials/movement-tabs.blade.php','utf8');
const service=fs.readFileSync('app/Services/Reports/TravelReportService.php','utf8');
const config=fs.readFileSync('config/et_erp_release.php','utf8');
const ok=(v,m)=>assert.ok(v,m);
ok(middleware.includes('Movement Tab Hover & Latest Updates')&&middleware.includes('System Health & Updates'),'intended ampersand text remains');
ok(!middleware.includes("createElement('h3', $build.' · '.$title)")&&!middleware.includes("createElement('li', $line)"),'raw ampersand display text is not passed to createElement');
ok(middleware.includes("$dom->createTextNode($build.' · '.$title)")&&middleware.includes('$dom->createTextNode($line)'),'DOM-safe text nodes are used');
ok(middleware.includes('Latest Updates')&&middleware.includes('data-et-latest-updates'),'Latest Updates remains present');
ok(tabs.includes('a:not(.active):hover'),'C84.3 movement hover remains');
ok(tabs.includes('et-arrival-card')&&tabs.includes('et-arrival-tabs')&&!tabs.includes('et-movement-tabs-card'),'Arrival authority remains unchanged');
ok(/'asset_version' => 'ERP-11.3.378-C84.3(?:\.1|\.2|\.3)'/.test(config)&&/'corrective_build' => 'C84.3(?:\.1|\.2|\.3)'/.test(config)&&/Latest Updates DOM Runtime Hotfix|Visible Movement Tab Hover|Movement Tab Hover Color Refinement/.test(config),'C84.3 lineage release identity remains correct');
ok(service.includes('class TravelReportService'),'TravelReportService is unchanged in scope');
console.log('ERP-11.3.378 C84.3.1 Latest Updates DOM-safe regression: PASS / 8');
