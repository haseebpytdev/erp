import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
const root=path.resolve(import.meta.dirname,'..','..'); const view=fs.readFileSync(path.join(root,'resources/views/mobile/accounts/form.blade.php'),'utf8'); const release=fs.readFileSync(path.join(root,'config/et_erp_release.php'),'utf8');
const checks=[
 [view.includes('let reviewState = null') && view.includes('reviewState = { suggestions }'),'successful result stored in frontend state'],
 [view.includes('<button type="button" class="secondary" data-ai-review>Review Details</button>'),'review button is non-submit'],
 [view.includes("event.currentTarget") && view.includes('detailMarkup(reviewState)'),'dynamic handler toggles local details'],
 [view.includes('d.hidden=false') && view.includes("button.textContent='Hide Details'") && view.includes("button.textContent='Review Details'"),'details open and close'],
 [view.includes('f.name||') && view.includes('t.name||') && view.includes('s.receipt_time?.value||'),'nullable fields render safely'],
 [view.includes('Array.isArray(s.warnings)'),'empty warnings handled'],
 [view.includes('party_match') && view.includes('our_bank_match'),'party and bank matches handled'],
 [view.includes('Apply Safe Fields') && view.includes('Save Draft remains a separate action'),'safe apply remains separate'],
 [!view.includes('JSON.stringify(suggestions') && !view.includes('<pre>'),'raw JSON is not displayed'],
 [view.includes("fetch(endpoint") && view.includes("[data-ai-review]") && view.indexOf("fetch(endpoint") < view.indexOf("[data-ai-review]"),'review details makes no provider call'],
 [release.includes('ERP-11.3.378-C90.6B.1') && release.includes("'corrective_build' => 'C90.6B.1'"),'C90.6B.1 identity'],
]; for(const [ok,label] of checks) assert.ok(ok,label); console.log(`C90.6B.1 regression PASS (${checks.length} assertions)`);
