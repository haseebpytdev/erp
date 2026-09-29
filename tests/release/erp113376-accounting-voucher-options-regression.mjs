import fs from 'node:fs';
import process from 'node:process';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../..', import.meta.url));
const read = file => fs.readFileSync(`${root}/${file}`, 'utf8');
let pass = 0; let fail = 0;
const ok = (condition, label) => { if (condition) { pass++; console.log(`PASS ${label}`); } else { fail++; console.log(`FAIL ${label}`); } };

const resolver = read('app/Services/Accounting/AccountingPartyRoleResolver.php');
const voucher = read('app/Services/Accounting/CashVoucherService.php');
const adjustment = read('app/Http/Controllers/Accounting/AdvanceAdjustmentController.php');
const form = read('resources/views/accounting/cash-vouchers/form.blade.php');
const index = read('resources/views/accounting/cash-vouchers/index.blade.php');
const release = read('config/et_erp_release.php');

ok(resolver.includes("join('party_roles as pr'"), 'party_roles is the shared role authority');
ok(resolver.includes("'CUSTOMER'") && resolver.includes("'VENDOR'"), 'customer and supplier roles map to native role values');
ok(resolver.includes('pr.is_active') && resolver.includes('p.is_active'), 'inactive parties and roles are rejected');
ok(resolver.includes('starts_on') && resolver.includes('ends_on'), 'effective role dates are enforced');
ok(!resolver.includes('is_customer') && !resolver.includes('is_supplier') && !resolver.includes('entity_type'), 'profile and entity identity fields do not grant roles');
ok(voucher.includes("return $this->partyRoles->options($partyType)"), 'voucher selectors use shared party authority');
ok(voucher.includes('return [];') && !voucher.includes("fallback_cash_bank_accounts"), 'cash and bank selectors fail closed without synthetic fallback');
ok(voucher.includes('cashBankAccounts()') && voucher.includes('control_flag'), 'cash and bank accounts exclude control accounts');
ok(adjustment.includes('assertPartyRole') && adjustment.includes('documentSnapshot($targetType'), 'advance adjustment is server-side party scoped');
ok(adjustment.includes('currencies') || adjustment.includes('currency_code'), 'advance adjustment preserves currency authority');
ok(form.includes("'Customer'"), 'customer voucher label is not Customer / Agent');
ok(index.includes('grid-template-columns:repeat(3') && index.includes('et-fin-filter-fields'), 'desktop filter grid is three columns');
ok(index.includes('grid-template-columns:repeat(2') && index.includes('grid-template-columns:1fr'), 'tablet and mobile filter grids are responsive');
const searchAt = index.indexOf('<label>Search</label>');
const typeAt = index.indexOf('<label>Type</label>');
const statusAt = index.indexOf('<label>Status</label>');
ok(searchAt >= 0 && searchAt < typeAt && typeAt < statusAt, 'filter order starts Search, Type, Status');
ok(index.includes('From') && index.includes('To') && index.includes('Bank / Cash'), 'date and account filters are present');
ok(index.includes('et-fin-filter-head') && index.includes('↻ Reset'), 'reset remains in filter card header');
ok(release.includes("v1.1.33.376-ERP11.3.376") && release.includes("ERP-11.3.376"), 'release identity is .376');
ok(read('VERSION.txt').trim() === 'v1.1.33.376-ERP11.3.376', 'VERSION.txt is .376');

console.log(`ERP376_REGRESSION=${fail ? 'FAIL' : 'PASS'} (${pass} assertions)`);
if (fail) process.exitCode = 1;
