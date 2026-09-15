(function () {
  'use strict';
  const value = c => c === '<' ? 0 : /[0-9]/.test(c) ? Number(c) : c.charCodeAt(0) - 55;
  const check = (text, digit) => { let total = 0; for (let i = 0; i < text.length; i++) total += value(text[i]) * [7, 3, 1][i % 3]; return total % 10 === Number(digit); };
  const dateValue = (raw, kind) => {
    if (!/^\d{6}$/.test(raw)) return null;
    const yy = Number(raw.slice(0, 2)), mm = Number(raw.slice(2, 4)), dd = Number(raw.slice(4, 6));
    const now = new Date();
    const candidates = [1900 + yy, 2000 + yy, 2100 + yy].map(year => new Date(Date.UTC(year, mm - 1, dd)))
      .filter(date => date.getUTCFullYear() % 100 === yy && date.getUTCMonth() === mm - 1 && date.getUTCDate() === dd);
    const plausible = candidates.filter(date => kind === 'dob'
      ? date <= now && (now.getUTCFullYear() - date.getUTCFullYear()) <= 120
      : date >= new Date(Date.UTC(now.getUTCFullYear() - 25, now.getUTCMonth(), now.getUTCDate()))
        && date <= new Date(Date.UTC(now.getUTCFullYear() + 20, now.getUTCMonth(), now.getUTCDate())));
    const chosen = plausible.sort((a, b) => Math.abs(a - now) - Math.abs(b - now))[0];
    return chosen ? chosen.toISOString().slice(0, 10) : null;
  };
  const normalizeOcr = text => String(text || '').toUpperCase().replace(/[^A-Z0-9<\r\n]/g, '').split(/\r?\n/).map(line => line.trim()).filter(Boolean);
  const extractTD3 = text => {
    const lines = normalizeOcr(text).map(line => line.replace(/\s+/g, '')).filter(line => line.length >= 20);
    for (let i = 0; i < lines.length - 1; i++) {
      if (lines[i].startsWith('P<') && lines[i].length < 44 && lines[i + 1].length > 44) {
        const joined = lines[i] + lines[i + 1]; lines.splice(i, 2, joined.slice(0, 44), joined.slice(44));
      }
    }
    for (let i = 0; i < lines.length - 1; i++) {
      const first = lines[i], second = lines[i + 1];
      if (first[0] !== 'P' || first.length < 40 || second.length < 40) continue;
      const a = first.length === 44 ? first : first.slice(0, 44);
      const b = second.length === 44 ? second : second.slice(0, 44);
      if (a.length === 44 && b.length === 44) return `${a}\n${b}`;
    }
    return null;
  };
  const repairNumeric = (line, positions) => {
    const chars = line.split(''), sites = positions.filter(i => /[OIL]/.test(chars[i] || ''));
    if (sites.length > 6) return [];
    const out = [];
    const walk = (k) => { if (out.length > 64) return; if (k === sites.length) { out.push(chars.join('')); return; } const i = sites[k], old = chars[i]; chars[i] = old === 'O' ? '0' : '1'; walk(k + 1); chars[i] = old; };
    walk(0); return out;
  };
  const correctionCandidates = (a, b) => repairNumeric(b, [0,1,2,3,4,5,6,7,8,9,13,14,15,16,17,18,19,21,22,23,24,25,26,27,42,43]).map(x => `${a}\n${x}`);
  window.ETPassportMRZ = {
    normalizeOcr,
    extractTD3,
    parse(input, internal = false) {
      const lines = String(input || '').toUpperCase().replace(/\r/g, '').split(/\n+/).map(s => s.replace(/\s+/g, '')) .filter(Boolean);
      if (lines.length !== 2 || lines.some(line => line.length !== 44)) return { valid: false, review: true, error: 'Passport scan needs review. Please verify the highlighted fields.' };
      const a = lines[0], b = lines[1];
      const passport = b.slice(0, 9), dob = b.slice(13, 19), expiry = b.slice(21, 27);
      const composite = b.slice(0, 10) + b.slice(13, 20) + b.slice(21, 43);
      const checks = { passportNumber: check(passport, b[9]), dateOfBirth: check(dob, b[19]), expiry: check(expiry, b[27]), optional: check(b.slice(28, 42), b[42]), composite: check(composite, b[43]) };
      checks.overall = checks.passportNumber && checks.dateOfBirth && checks.expiry && checks.composite;
      if (!checks.overall && !internal) { const valid = correctionCandidates(a,b).map(x => { const q=x.split('\n'); return window.ETPassportMRZ.parse(q.join('\n'), true); }).filter(x => x.valid); if (valid.length === 1) return {...valid[0], correctionsApplied:['numeric-confusable'], correctionCount:1, correctionState:'corrected'}; if (valid.length > 1) return {valid:false,review:true,correctionState:'ambiguous',error:'Passport scan needs review. Please verify the highlighted fields.'}; }
      const names = a.slice(5).split('<<');
      const dateOfBirth = dateValue(dob, 'dob'), passportExpiry = dateValue(expiry, 'expiry');
      return { valid: checks.overall && !!dateOfBirth && !!passportExpiry, review: !(checks.overall && dateOfBirth && passportExpiry), documentCode: a.slice(0, 2), issuingCountry: a.slice(2, 5), surname: names[0].replace(/</g, ' ').trim(), givenNames: (names[1] || '').replace(/</g, ' ').trim(), passportNumber: passport.replace(/</g, ''), nationality: b.slice(10, 13), dateOfBirth, sex: b[20], passportExpiry, checkDigits: checks };
    }
  };
  window.ETPassportMRZ.resolveDate = dateValue;
})();
