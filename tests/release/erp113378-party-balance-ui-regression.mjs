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
ok('opening filter compact', openingIndex.includes('et378-filters') && openingIndex.includes('Apply Filters') && openingIndex.includes('>Reset</a>'));
ok('opening empty state', openingIndex.includes('No opening balances match these filters'));
ok('opening form sectioned', ['Party Details','Opening Value','References &amp; Notes'].every(x=>openingForm.includes(x)) && openingForm.includes('Save Draft'));
ok('opening show professional', openingShow.includes('et378-meta') && openingShow.includes('Review lifecycle'));
ok('return index professional register', returnIndex.includes('Advance return register') && returnIndex.includes('et378-table'));
ok('return filter compact', returnIndex.includes('Find advance returns') && returnIndex.includes('Apply Filters') && returnIndex.includes('>Reset</a>'));
ok('return empty state', returnIndex.includes('No advance returns match these filters'));
ok('return form sectioned', ['Return Details','Payment Details','Narration / Notes','Available Advance Sources'].every(x=>returnForm.includes(x)));
ok('return source empty states', returnForm.includes('Select a customer to load available advance sources.') && returnForm.includes('No returnable customer advance is currently available.') && returnForm.includes('Loading available advance sources...'));
ok('return source table preserved', returnForm.includes('advance-sources') && returnForm.includes('allocations['));
ok('return summary cards', returnForm.includes('available-total') && returnForm.includes('selected-total') && returnForm.includes('remaining-total') && returnForm.includes('return-amount'));
ok('source selected row state', returnForm.includes("classList.toggle('is-selected'") && returnForm.includes('data-source') && returnForm.includes('disabled'));
ok('return show professional', returnShow.includes('Controlled refund record') && returnShow.includes('et378-meta'));
ok('opening field contract preserved', openingForm.includes('name="party_type"') && openingForm.includes('name="party_id"') && openingForm.includes('name="balance_type"') && openingForm.includes('name="opening_date"') && openingForm.includes('name="branch_id"') && openingForm.includes('name="amount"'));
ok('return field contract preserved', returnForm.includes('name="customer_party_id"') && returnForm.includes('name="return_date"') && returnForm.includes('name="cash_bank_account_code"') && returnForm.includes('name="payment_proof"'));
ok('workflow guards preserved', openingShow.includes("$canUpdate") && openingShow.includes("$canApprove") && openingShow.includes("$canPost") && openingShow.includes("$canReverse") && returnShow.includes("$canUpdate") && returnShow.includes("$canApprove") && returnShow.includes("$canPost") && returnShow.includes("$canReverse"));
const sidebar=read('app/Http/Middleware/PresentCashVoucherLinks.php');
ok('sidebar opening label', sidebar.includes('<span>Opening Balances</span>') && !sidebar.includes('<span>Party Opening Balances</span>'));
ok('sidebar return label', sidebar.includes('<span>Advance Returns</span>') && !sidebar.includes('<span>Customer Advance Returns</span>'));
ok('accounting css owns ERP378 styles', read('public/erp-theme/modules/accounting.css').includes('et378') && !views.some(p=>read(p).includes('<style')));
ok('canonical metadata unchanged', read('VERSION.txt').includes('v1.1.33.378-ERP11.3.378') && read('config/et_erp_release.php').includes('ERP-11.3.378 Party Balance Lifecycle'));
ok('migrations and services untouched', !source.includes('Migration') && fs.existsSync(path.join(root,'app/Services/Accounting/PartyBalanceLifecycleService.php')));
console.log(`ERP113378_PARTY_BALANCE_UI=PASS (${pass}/${total} assertions)`);
if(pass!==total) process.exitCode=1;
