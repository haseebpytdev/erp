import fs from 'node:fs';
import { execFileSync } from 'node:child_process';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const sourcePath = path.join(root, 'app/Services/Reports/TravelReportService.php');
const source = fs.readFileSync(sourcePath, 'utf8');
const parent = execFileSync('git', [
  'show',
  'f25353b43ed4320b0593e0a6a98b886305678d2d:app/Services/Reports/TravelReportService.php'
], { cwd: root, encoding: 'utf8' });

let assertions = 0;
const ok = (condition, message) => {
  assertions += 1;
  assert.ok(condition, message);
};

function scanDelimiters(text) {
  const pairs = { ')': '(', '}': '{', ']': '[' };
  const stack = [];
  let mismatches = 0;
  let state = 'normal';
  for (let i = 0; i < text.length; i += 1) {
    const c = text[i];
    const n = text[i + 1];
    if (state === 'line') {
      if (c === '\n') state = 'normal';
      continue;
    }
    if (state === 'block') {
      if (c === '*' && n === '/') { state = 'normal'; i += 1; }
      continue;
    }
    if (state === 'single' || state === 'double') {
      if (c === '\\') { i += 1; continue; }
      if ((state === 'single' && c === "'") || (state === 'double' && c === '"')) state = 'normal';
      continue;
    }
    if (c === '/' && n === '/') { state = 'line'; i += 1; continue; }
    if (c === '/' && n === '*') { state = 'block'; i += 1; continue; }
    if (c === "'") { state = 'single'; continue; }
    if (c === '"') { state = 'double'; continue; }
    if ('({['.includes(c)) stack.push(c);
    else if (')}]'.includes(c)) {
      if (stack.at(-1) !== pairs[c]) mismatches += 1;
      else stack.pop();
    }
  }
  return {
    unmatchedParentheses: stack.filter((c) => c === '(').length,
    unmatchedBraces: stack.filter((c) => c === '{').length,
    unmatchedBrackets: stack.filter((c) => c === '[').length,
    mismatches
  };
}

ok(source.includes("whereDate('h.'.$date,'<=',$v)));}}}"), 'Group Umrah closure must remain intact');
ok(source.includes('private function orderedChildRows'), 'orderedChildRows must exist');
ok(!parent.includes("($r->id??'')))->values();"), 'parent must lack the repaired closure');
ok(source.includes("($r->id??'')))->values();"), 'orderedChildRows sortBy closure must be repaired');
const scan = scanDelimiters(source);
ok(scan.mismatches === 0, 'source delimiter mismatches must be zero');
ok(scan.unmatchedParentheses === 0, 'source unmatched parentheses must be zero');
ok(scan.unmatchedBraces === 0, 'source unmatched braces must be zero');
ok(scan.unmatchedBrackets === 0, 'source unmatched brackets must be zero');

console.log(`ERP-11.3.340 Travel Report PHP structure regression: PASS (${assertions} assertions)`);
console.log(`structural-mismatches=${scan.mismatches} unmatched-parentheses=${scan.unmatchedParentheses} unmatched-braces=${scan.unmatchedBraces} unmatched-brackets=${scan.unmatchedBrackets}`);
