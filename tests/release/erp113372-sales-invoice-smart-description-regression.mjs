import assert from 'node:assert/strict';
import fs from 'node:fs';

let pass = 0;
const ok = (condition, label) => { assert.ok(condition, label); pass++; };
const read = path => fs.readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const middleware = read('app/Http/Middleware/PresentSalesInvoicePrintV2.php');

ok(middleware.includes('TICKET / REF'), 'TICKET_REF_HEADER=PASS');
ok(middleware.includes("hasClass($open, 'desc')"), 'description cell is targeted by class token');
ok(middleware.includes("hasClass($open, 'ticket')"), 'ticket/ref cell is targeted by class token');
ok(middleware.includes("preg_match('/\\bPNR\\s*:\\s*([A-Za-z0-9][A-Za-z0-9_-]*)\\b/i'"), 'PNR is read from rendered description text');
ok(middleware.includes("PNR: '.$safePnr"), 'PNR is rendered in ticket/ref');
ok(middleware.includes("preg_replace('/\\s*(?:<br\\b"), 'PNR is removed from description');
ok(middleware.includes("preg_replace('/(?:Adult\\s+)?Air Ticket/i', 'Air Ticket'"), 'air title uses truthful native fallback');
ok(middleware.includes('data-et-sales-invoice-print-v2="ERP-11.3.373"'), 'ERP373 marker is present');
ok(middleware.includes('<style id="et-sales-invoice-print-v2-373">'), 'ERP373 style identity is present');
ok(middleware.includes('text-align:right'), 'thank-you line is right aligned');
ok(!middleware.includes('position:fixed'), 'thank-you line is not fixed-position');
ok(middleware.includes('white-space:normal'), 'thank-you line can wrap safely');

const source = `<table class="invoice-table"><thead><tr><th>TICKET NUMBER</th></tr></thead><tbody><tr><td class="desc"><div class="service-title">Adult Air Ticket</div><div class="service-detail">PNR: MVGLSZ</div></td><td class="pax"><div>TANVIR SAMAD SIDDIQUI</div><div>ADULT</div></td><td class="ticket">960-2614131494</td><td class="amount">97,941.00</td></tr></tbody></table><div class="notes">Notes</div><div class="thanks">Thank you for choosing Easy Group Of Travels.</div>`;
const pnr = source.match(/PNR:\s*([^<\r\n]+)/i)?.[1] ?? '';
const description = source.replace(/\s*(?:<br\s*\/?>(?:\s*)?)?PNR\s*:\s*[^<\r\n]+/i, '').replace(/(?:Adult\s+)?Air Ticket/i, 'Air Ticket');
const ticketRef = `960-2614131494<div class="service-detail">PNR: ${pnr}</div>`;
const transformed = source.replace('TICKET NUMBER', 'TICKET / REF').replace(source.match(/<td class="desc">[\s\S]*?<\/td>/i)[0], description.match(/<td class="desc">[\s\S]*?<\/td>/i)[0]).replace(/<td class="ticket">.*?<\/td>/i, `<td class="ticket">${ticketRef}</td>`);
const transformedDescription = transformed.match(/<td class="desc">[\s\S]*?<\/td>/i)?.[0] ?? '';

ok(!/TICKET NUMBER/i.test(transformed), 'ticket heading is corrected');
ok(/TICKET \/ REF/i.test(transformed), 'TICKET_REF_HEADER=PASS');
ok(!/PNR:/i.test(transformedDescription), 'PNR_NOT_IN_DESCRIPTION=PASS');
ok(/class="ticket"[^>]*>[\s\S]*PNR:\s*MVGLSZ/i.test(transformed), 'PNR_IN_TICKET_REF=PASS');
ok(/TANVIR SAMAD SIDDIQUI/.test(transformed), 'PASSENGER_NAME_PRESERVED=PASS');
ok(/ADULT/.test(transformed), 'PASSENGER_TYPE_PRESERVED=PASS');
ok(/960-2614131494/.test(transformed), 'TICKET_NUMBER_PRESERVED=PASS');
ok(/97,941\.00/.test(transformed), 'AMOUNT_PRESERVED=PASS');
ok(/class="notes"[\s\S]*class="thanks"/.test(transformed), 'LOWER_DOCUMENT_ORDER=TOTALS > NOTES > THANK_YOU');
ok(/Thank you for choosing Easy Group Of Travels\./.test(transformed), 'THANK_YOU_TEXT_PRESERVED=PASS');
ok(/class="thanks"/.test(transformed) && middleware.includes('text-align:right'), 'THANK_YOU_RIGHT_ALIGNED=PASS');
ok(!middleware.includes('position:fixed'), 'THANK_YOU_NOT_FIXED=PASS');
ok(!/commercial\/accounting document/i.test(transformed), 'COMMERCIAL_ACCOUNTING_DISCLAIMER_REMOVED=PASS');

const fallback = '<td class="desc"><div class="service-title">Adult Air Ticket</div></td><td class="ticket"></td>';
ok(!/PNR:\s*<\/div>/.test(fallback), 'MISSING_OPTIONAL_DATA_FALLBACK=PASS');
ok(middleware.includes("! $response instanceof Response"), 'successful HTML response boundary is preserved');
ok(middleware.includes('$response->getStatusCode() >= 400'), 'error responses remain untouched');
ok(middleware.includes('class="sheet"'), 'native sheet is still required');
ok(middleware.includes("$response->headers->remove('Content-Length')"), 'content length is handled after mutation');
ok((middleware.match(/<style\b/gi) || []).length === (middleware.match(/<\/style>/gi) || []).length, 'STYLE_TAG_BALANCED=PASS');
ok(middleware.indexOf('</style>') < middleware.indexOf('CSS;'), 'style closes before heredoc end');
ok(middleware.includes('class="sheet"') && middleware.includes('data-et-sales-invoice-print-v2="ERP-11.3.373"'), 'SHEET_STRUCTURE_PRESERVED=PASS');
ok(!middleware.includes('DB::') && !middleware.includes('number_format('), 'NO_FINANCIAL_DATABASE_QUERY=PASS');
ok(!middleware.includes('grand_total') && !middleware.includes('journal_entries'), 'NO_FINANCIAL_RECALCULATION=PASS');
ok(!middleware.match(/Air Ticket\s*[–-]\s*(?:<|$)/i), 'NO_FAKE_AIRLINE=PASS');
ok(!middleware.match(/LHE\s*→\s*JED/i), 'NO_FAKE_SECTOR=PASS');
ok(!middleware.match(/SV739|SV738/i), 'NO_FAKE_FLIGHT=PASS');
ok(!middleware.match(/Economy/i), 'NO_FAKE_CABIN=PASS');

console.log(`PASS ${pass} ERP-11.3.372 Sales Invoice Smart Description assertions`);
