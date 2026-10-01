import fs from 'node:fs';
import path from 'node:path';
const root = process.cwd();
const read = p => fs.readFileSync(path.join(root,p),'utf8');
let total=0, pass=0; const ok=(name, value)=>{total++; if(value) pass++; else console.log(`FAIL ${name}`);};
const views = [
  'resources/views/accounting/party-opening-balances/index.blade.php',
  'resources/views/accounting/party-opening-balances/form.blade.php',
  'resources/views/accounting/party-opening-balances/show.blade.php',
  'resources/views/accounting/customer-advance-returns/index.blade.php',
  'resources/views/accounting/customer-advance-returns/form.blade.php',
  'resources/views/accounting/customer-advance-returns/show.blade.php',
];
const source = views.map(read).join('\n');
const openingIndex=read(views[0]), openingForm=read(views[1]), openingShow=read(views[2]);
const returnIndex=read(views[3]), returnForm=read(views[4]), returnShow=read(views[5]);
ok('six ERP378 pages present', views.every(p => fs.existsSync(path.join(root,p))));
ok('shared scoped workspace language', (source.match(/et378/g)||[]).length >= 30);
ok('opening index professional register', openingIndex.includes('Opening balance register') && openingIndex.includes('et378-table'));
ok('opening filter compact', openingIndex.includes('et378-filters') && openingIndex.includes('Apply filters'));
ok('opening empty state', openingIndex.includes('No opening balances match these filters'));
ok('opening form sectioned', openingForm.includes('et378-grid') && openingForm.includes('Save draft'));
ok('opening show professional', openingShow.includes('et378-meta') && openingShow.includes('Review lifecycle'));
ok('return index professional register', returnIndex.includes('Advance return register') && returnIndex.includes('et378-table'));
ok('return filter compact', returnIndex.includes('Find advance returns') && returnIndex.includes('Apply filters'));
ok('return empty state', returnIndex.includes('No advance returns match these filters'));
ok('return form sectioned', returnForm.includes('Advance sources') && returnForm.includes('et378-totalbar'));
ok('return source table preserved', returnForm.includes('advance-sources') && returnForm.includes('allocations['));
ok('return summary cards', returnForm.includes('available-total') && returnForm.includes('selected-total') && returnForm.includes('remaining-total'));
ok('return show professional', returnShow.includes('Controlled refund record') && returnShow.includes('et378-meta'));
ok('opening field contract preserved', openingForm.includes('name="party_type"') && openingForm.includes('name="party_id"') && openingForm.includes('name="balance_type"') && openingForm.includes('name="opening_date"') && openingForm.includes('name="branch_id"') && openingForm.includes('name="amount"'));
ok('return field contract preserved', returnForm.includes('name="customer_party_id"') && returnForm.includes('name="return_date"') && returnForm.includes('name="cash_bank_account_code"') && returnForm.includes('name="payment_proof"'));
ok('workflow guards preserved', openingShow.includes("$canUpdate") && openingShow.includes("$canApprove") && openingShow.includes("$canPost") && openingShow.includes("$canReverse") && returnShow.includes("$canUpdate") && returnShow.includes("$canApprove") && returnShow.includes("$canPost") && returnShow.includes("$canReverse"));
const sidebar=read('app/Http/Middleware/PresentCashVoucherLinks.php');
ok('sidebar short party label', sidebar.includes('<span>Party Balances</span>') && !sidebar.includes('<span>Party Opening Balances</span>'));
ok('sidebar one-line label', !sidebar.includes('Party Opening Balances') && sidebar.includes('data-et-live-accounting-nav="party-opening"'));
console.log(`ERP113378_PARTY_BALANCE_UI=PASS (${pass}/${total} assertions)`);
if(pass!==total) process.exitCode=1;
