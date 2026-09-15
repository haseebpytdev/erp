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
  const fieldConfusions = { digits: { O:'0', I:'1', L:'1', B:'8', S:'5', Z:'2', G:'6' }, letters: { '0':'O', '8':'B', '5':'S', '2':'Z', '6':'G' } };
  const numericPositions = [9,13,14,15,16,17,18,19,21,22,23,24,25,26,27,42,43];
  const buildCorrectionCandidates = (a, b) => {
    const sites = [], alphaNumeric = new Set([...Array(9).keys(), ...Array.from({length:14}, (_,i) => i + 28)]);
    const push = (line, index, choices) => choices.length && sites.push({ line, index, choices });
    numericPositions.forEach(index => fieldConfusions.digits[b[index]] && push('b', index, [fieldConfusions.digits[b[index]]]));
    for (let index = 2; index <= 43; index++) { const c=a[index]; if (fieldConfusions.letters[c]) push('a', index, [fieldConfusions.letters[c]]); else if (c === '1') push('a', index, ['I','L']); }
    for (let index = 10; index <= 12; index++) { const c=b[index]; if (fieldConfusions.letters[c]) push('b', index, [fieldConfusions.letters[c]]); else if (c === '1') push('b', index, ['I','L']); }
    if (!check(b.slice(0, 9), b[9])) [...Array(9).keys()].forEach(index => { const c=b[index], choices = c === '1' ? [c, 'I', 'L'] : fieldConfusions.digits[c] || fieldConfusions.letters[c] ? [c, fieldConfusions.digits[c] || fieldConfusions.letters[c]] : []; if (choices.length) push('b', index, choices); });
    if (sites.length > 6) return new Set();
    const out = new Set(), walk = (k, x, y) => { if (out.size >= 64) return; if (k === sites.length) { out.add(`${x}\n${y}`); return; } const s=sites[k]; for (const value of s.choices) { if (s.line === 'a') { const z=x.split(''); z[s.index]=value; walk(k+1,z.join(''),y); } else { const z=y.split(''); z[s.index]=value; walk(k+1,x,z.join('')); } } };
    walk(0, a, b); return out;
  };
  const td3Syntax = (a, b) => a.startsWith('P<') && /^[A-Z<]{3}$/.test(a.slice(2, 5)) && /^[A-Z<]{39}$/.test(a.slice(5))
    && /^[A-Z0-9<]{9}$/.test(b.slice(0, 9)) && /^[A-Z<]{3}$/.test(b.slice(10, 13)) && /^[0-9]{6}$/.test(b.slice(13, 19))
    && /^[0-9]$/.test(b[9]) && /^[0-9]$/.test(b[19]) && /^[MFX<]$/.test(b[20]) && /^[0-9]{6}$/.test(b.slice(21, 27)) && /^[0-9]$/.test(b[27]) && /^[A-Z0-9<]{14}$/.test(b.slice(28, 42)) && /^[0-9]$/.test(b[42]) && /^[0-9]$/.test(b[43]);
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
      const names = a.slice(5).split('<<');
      const dateOfBirth = dateValue(dob, 'dob'), passportExpiry = dateValue(expiry, 'expiry');
      const cleanValid = td3Syntax(a, b) && checks.overall && !!dateOfBirth && !!passportExpiry;
      if (cleanValid) return { valid:true, review:false, correctionState:'clean', documentCode:a.slice(0,2), issuingCountry:a.slice(2,5), surname:names[0].replace(/</g,' ').trim(), givenNames:(names[1] || '').replace(/</g,' ').trim(), passportNumber:passport.replace(/</g,''), nationality:b.slice(10,13), dateOfBirth, sex:b[20], passportExpiry, checkDigits: checks };
      if (!internal) {
        const unique = buildCorrectionCandidates(a, b);
        const valid = [...unique].map(candidate => window.ETPassportMRZ.parse(candidate, true)).filter(result => result.valid);
        if (valid.length === 1) return {...valid[0], correctionsApplied:['position-aware-confusable'], correctionCount:1, correctionState:'corrected'};
        if (valid.length > 1) return {valid:false,review:true,correctionState:'ambiguous',error:'Passport scan needs review. Please verify the highlighted fields.'};
      }
      return { valid:false, review:true, correctionState:'review', documentCode:a.slice(0,2), issuingCountry:a.slice(2,5), surname:names[0].replace(/</g,' ').trim(), givenNames:(names[1] || '').replace(/</g,' ').trim(), passportNumber:passport.replace(/</g,''), nationality:b.slice(10,13), dateOfBirth, sex:b[20], passportExpiry, checkDigits:checks, error:'Passport scan needs review. Please verify the highlighted fields.' };
    }
  };
  window.ETPassportMRZ.resolveDate = dateValue;
})();
