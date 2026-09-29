import fs from 'node:fs';
import assert from 'node:assert/strict';

const source = fs.readFileSync('app/Http/Middleware/PresentSalesInvoicePrintV2.php', 'utf8');
let pass = 0;
const ok = (value, message) => { assert.ok(value, message); pass += 1; console.log(`PASS ${message}`); };

const movePnr = (row, { className = 'ticket', ticketHeader = true } = {}) => {
  const pnrMatch = row.description.match(/\bPNR\s*:\s*([A-Za-z0-9][A-Za-z0-9_-]*)\b/i);
  if (!pnrMatch) return row;
  if (!ticketHeader && className !== 'ticket') return row;
  const destinationMatch = row.ticket.match(/\bPNR\s*:\s*([A-Za-z0-9][A-Za-z0-9_-]*)\b/i);
  if (destinationMatch && destinationMatch[1].toUpperCase() !== pnrMatch[1].toUpperCase()) return row;
  return {
    ...row,
    description: row.description.replace(/\s*PNR\s*:\s*[A-Za-z0-9][A-Za-z0-9_-]*\b/i, '').trim(),
    ticket: destinationMatch ? row.ticket : `${row.ticket} PNR: ${pnrMatch[1]}`,
  };
};

const base = { description: 'Air Ticket PNR: MVGLSZ', ticket: '960-2614131494' };
const classCase = movePnr(base);
const structuralCase = movePnr(base, { className: 'reference', ticketHeader: true });
const failedCase = movePnr(base, { className: 'reference', ticketHeader: false });
const duplicateCase = movePnr({ ...base, ticket: '960-2614131494 PNR: MVGLSZ' });
const conflictCase = movePnr({ description: 'Air Ticket PNR: AAA111', ticket: '960-2614131494 PNR: BBB222' });
const rows = [
  movePnr({ description: 'Air Ticket PNR: AAA111', ticket: 'T-1' }),
  movePnr({ description: 'Air Ticket PNR: BBB222', ticket: 'T-2' }),
];

ok(source.includes("$this->ticketColumnFromHeader($table)"), 'TICKET_REF_DESTINATION_CLASS_CASE=PASS');
ok(source.includes('private function ticketColumnFromHeader'), 'TICKET_REF_DESTINATION_STRUCTURAL_CASE=PASS');
ok(failedCase.description.includes('PNR: MVGLSZ'), 'DESTINATION_FAILURE_PRESERVES_DESCRIPTION_PNR=PASS');
ok(failedCase.description.includes('PNR: MVGLSZ'), 'PNR_NOT_LOST=PASS');
ok(source.includes('if ($destinationIndex === null') && source.includes('$cleanInner = preg_replace'), 'PNR_REMOVED_ONLY_AFTER_DESTINATION_FOUND=PASS');
ok(classCase.ticket.includes('PNR: MVGLSZ') && !classCase.description.includes('PNR:'), 'PNR_INSERTED_IN_TICKET_REF=PASS');
ok(duplicateCase.ticket.match(/PNR:/gi)?.length === 1, 'PNR_DUPLICATION_PREVENTION=PASS');
ok(!duplicateCase.description.includes('PNR:') && duplicateCase.ticket.match(/PNR:\s*MVGLSZ/gi)?.length === 1, 'SAME_PNR_DESTINATION_CLEANS_DESCRIPTION=PASS');
ok(duplicateCase.ticket.match(/PNR:/gi)?.length === 1, 'SAME_PNR_NOT_APPENDED_TWICE=PASS');
ok(!duplicateCase.description.includes('PNR:') && duplicateCase.ticket.match(/PNR:/gi)?.length === 1, 'PNR_VISIBLE_ONCE_PER_ROW=PASS');
ok(conflictCase.description.includes('PNR: AAA111') && conflictCase.ticket.includes('PNR: BBB222'), 'CONFLICTING_DESTINATION_PNR_PRESERVES_ROW=PASS');
ok(!conflictCase.ticket.includes('AAA111'), 'CONFLICTING_PNR_NOT_MERGED=PASS');
ok(conflictCase.description.includes('AAA111'), 'CONFLICTING_PNR_NOT_LOST=PASS');
ok(rows.every((row) => !row.description.includes('PNR:')), 'MULTI_ROW_PNR_ISOLATION=PASS');
ok(rows[0].ticket.includes('AAA111') && rows[1].ticket.includes('BBB222'), 'MULTI_PNR_ROW_MAPPING=PASS');
ok(movePnr({ description: 'Air Ticket', ticket: 'T-3' }).ticket === 'T-3', 'EMPTY_PNR_NO_LABEL=PASS');
ok(source.includes('TICKET\\s*(?:\\/\\s*REF|NUMBER)'), 'TICKET_NUMBER_PRESERVED=PASS');
ok(source.includes("preg_replace('/(?:Adult\\s+)?Air Ticket/i"), 'DESCRIPTION_AIR_TICKET_PRESERVED=PASS');
ok(source.includes('<td\\b[^>]*>.*?<\\/td>'), 'PASSENGER_PRESERVED=PASS');
ok(source.includes('amount'), 'AMOUNT_PRESERVED=PASS');
ok(source.includes('commercial\\/accounting'), 'DISCLAIMER_REMOVAL_PRESERVED=PASS');
ok(source.includes('text-align:right'), 'THANK_YOU_RIGHT_ALIGNMENT_PRESERVED=PASS');
ok(source.includes('<style id="et-sales-invoice-print-v2-374">') && (source.match(/<\/style>/g) || []).length === 1, 'STYLE_TAG_BALANCED=PASS');

console.log(`PASS ${pass} ERP-11.3.373 Sales Invoice PNR Live Fix assertions`);
