import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..', '..');
const read = relative => fs.readFileSync(path.join(root, relative), 'utf8');
const view = read('resources/views/operations/bookings/general-client-voucher-v113142.blade.php');
const controller = read('app/Http/Controllers/Operations/GeneralBookingVoucherPreviewController.php');
const visa = read('app/Http/Controllers/Operations/GeneralBookingVisaProductController.php');
const repository = read('app/Services/Operations/LegacyVisaTravelMasterRepository.php');
const profile = read('app/Services/Organization/CompanyProfileSnapshotService.php');
let checks = 0;
const has = (text, value, message) => { assert.ok(text.includes(value), message); checks++; };
const lacks = (text, value, message) => { assert.ok(!text.includes(value), message); checks++; };

assert.match(read('VERSION.txt').trim(), /^v1\.1\.33\.(?:15[5-9]|1[6-9]\d|[2-9]\d\d)-ERP11\.3\.(?:15[5-9]|1[6-9]\d|[2-9]\d\d)$/); checks++;
has(view, '<th>Visa No.</th>', 'main passenger table contains Visa No.');
has(view, '$visaByPassenger[(int)($p[\'id\'] ?? 0)]', 'Visa lookup uses stable booking passenger ID');
has(view, "$passengerVisa['visa_number'] ?? $passengerVisa['visa_no'] ?? ''", 'missing Visa number renders an empty cell');
lacks(view, '<div class="section-title blue">Visa</div>', 'standalone Visa section is absent');
lacks(view, 'class="visa-table"', 'standalone Visa table is absent');
has(view, 'class="passenger-table"', 'compact passenger table width rules are applied');
has(view, 'thead{display:table-header-group}', 'print repeats table headers');
has(view, 'tr{break-inside:avoid}', 'passenger rows do not split');

const lower = view.indexOf('<div class="voucher-lower">');
const instructions = view.indexOf('<div class="instructions">', lower);
const footer = view.indexOf('class="voucher-footer-text"', lower);
const qr = view.indexOf('<div class="qr-box">', lower);
assert.ok(lower >= 0 && instructions > lower && footer > instructions && qr > footer, 'lower layout places instructions/footer left before right-side QR'); checks++;
has(view, "@if($resolvedVoucherFooter !== '')", 'resolved footer renders only when non-empty');
assert.equal((view.match(/resolvedVoucherFooter/g) ?? []).length, 2, 'resolved footer has one condition and one rendering'); checks++;
lacks(view, "$company['footer']", 'Company Profile footer is not rendered separately');
lacks(view, 'Saudi Footer:', 'no Saudi footer label');
lacks(view, 'Company Footer:', 'no Company footer label');
has(view, "@if($voucherQrImage !== '')", 'saved QR image is used when available');
has(view, 'Public voucher QR unavailable', 'controlled placeholder remains when public QR is unavailable');
lacks(view, '{{ $publicVoucherUrl }}<', 'raw public URL is never printed');
has(controller, 'safeVoucherUrl(', 'saved QR/public URLs are sanitized');
has(controller, "$passengerVisaMap->build($visaRows)", 'controller delegates stable Visa mapping');
has(controller, "$footerResolver->resolve($visaRows", 'controller delegates exclusive footer priority');
has(repository, "['voucher_footer_html']", 'exact native Saudi Company footer field is reused');
has(visa, "'saudi_company_footer' =>", 'saved Saudi master relationship exposes footer to voucher');

for (const forbidden of ['vendor_cost_pkr', 'cost_currency', 'cost_rate', 'exchange_rate', 'margin_pkr', 'vendor_id', 'supplier accounting']) lacks(view, forbidden, `client voucher excludes ${forbidden}`);
has(view, "$company['logo']", 'Company Profile logo remains authoritative');
has(view, "$company['name']", 'Company Profile name remains authoritative');
lacks(profile, "'name' => 'Easy Ticket'", 'Company Profile resolver has no hard-coded Easy Ticket name');
has(view, '<strong>Saudi Company:</strong>', 'saved Saudi name remains top-right');
has(view, '<strong>Pakistani IATA:</strong>', 'saved Pakistani IATA remains top-right');
has(view, '@page{size:A4 portrait', 'A4 portrait is preserved');
has(view, 'object-fit:contain', 'logo/QR preserve aspect ratio');

console.log(`ERP-11.3.155 voucher view regression checks passed: ${checks}`);
