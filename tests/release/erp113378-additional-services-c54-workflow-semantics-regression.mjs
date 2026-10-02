import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';

const root = path.resolve(import.meta.dirname, '../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const workflow = read('app/Services/Operations/GeneralBookingAdditionalServiceWorkflowManager.php');
let n = 0;
const ok = (value, message) => { n++; assert.ok(value, message); };
const index = token => workflow.indexOf(token);

ok(workflow.includes("return $this->idempotent('already_pending', 'pending_approval')") && workflow.includes("'idempotent' => true"), 'double submit is successful and idempotent');
ok(workflow.includes("return $this->idempotent('already_approved', 'approved')"), 'double approve is successful and idempotent');
ok(workflow.includes("return $this->idempotent('already_rejected', 'rejected')"), 'double reject is successful and idempotent');
ok(!workflow.includes("already_pending', 'This batch is already pending approval"), 'double submit does not use failure branch');
ok(!workflow.includes("already_approved', 'This batch is already approved"), 'double approve does not use failure branch');
ok(!workflow.includes("already_rejected', 'This batch is already rejected"), 'double reject does not use failure branch');
ok(index("return $this->idempotent('already_pending'") < index("if ($action === 'submit') return $this->submit"), 'submit idempotent branch precedes writes');
ok(index("return $this->idempotent('already_approved'") < index('$frozen = $this->integrity->build'), 'approve idempotent branch precedes integrity/write');
ok(index("return $this->idempotent('already_rejected'") < index("if ($action === 'reject') {"), 'reject idempotent branch precedes writes');
ok(index("if ($action === 'reject') {") < index('$frozen = $this->integrity->build'), 'reject branch precedes snapshot build');
ok(workflow.includes("$reason = trim((string) $reason); if ($reason === '' || mb_strlen($reason) > 2000)"), 'reject validates trimmed bounded reason');
ok(workflow.includes("'rejection_reason' => $reason") && workflow.includes("'lock_version' => ((int) $batch->lock_version) + 1"), 'first reject writes audit and increments lock');
ok(workflow.includes("$frozen = $this->integrity->build") && workflow.includes("$batch->source_snapshot_hash !== (string) $frozen['hash']"), 'approve requires frozen snapshot hash');
ok(index('$frozen = $this->integrity->build') < index("if ($action === 'approve') {"), 'approve integrity guard precedes approval write');
const approvalBlock = workflow.slice(index("if ($action === 'approve') {"), index("if ($action === 'approve') {") + 500);
ok(!approvalBlock.includes("'source_snapshot_hash'"), 'approve does not rewrite freeze hash');
ok(workflow.includes("status) !== 'pending_approval'") && workflow.includes("status) !== 'draft'"), 'cross-action state conflicts remain blocked');
ok(workflow.includes("! $this->parentAllows($bookingId)"), 'parent booking rule remains enforced');
ok(workflow.includes('canReopen($user)'), 'approver authority remains enforced');
ok(workflow.includes('DB::transaction') && workflow.includes('lockForUpdate'), 'transaction and row lock remain enforced');
ok(!workflow.includes('travel_status') && !workflow.includes('sales_invoices') && !workflow.includes('journal_entries'), 'no native, invoice, or accounting writes added');

console.log(`ERP378 ADDITIONAL SERVICES C54 WORKFLOW SEMANTICS REGRESSION: PASS (${n} assertions)`);
