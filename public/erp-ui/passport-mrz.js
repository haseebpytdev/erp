(function () {
  'use strict';
  const value = c => c === '<' ? 0 : /[0-9]/.test(c) ? Number(c) : c.charCodeAt(0) - 55;
  const check = (text, digit) => { let total = 0; for (let i = 0; i < text.length; i++) total += value(text[i]) * [7, 3, 1][i % 3]; return total % 10 === Number(digit); };
  const dateValue = (raw, kind) => {
    const yy = Number(raw.slice(0, 2)), mm = raw.slice(2, 4), dd = raw.slice(4, 6);
    const now = new Date(), year = kind === 'dob'
      ? (yy + 2000 <= now.getFullYear() - 16 ? yy + 2000 : yy + 1900)
      : (yy + 2000 >= now.getFullYear() - 20 ? yy + 2000 : yy + 2100);
    return `${year}-${mm}-${dd}`;
  };
  window.ETPassportMRZ = {
    parse(input) {
      const lines = String(input || '').toUpperCase().replace(/\r/g, '').split(/\n+/).map(s => s.replace(/\s+/g, '')) .filter(Boolean);
      if (lines.length !== 2 || lines.some(line => line.length !== 44)) return { valid: false, review: true, error: 'Passport scan needs review. Please verify the highlighted fields.' };
      const a = lines[0], b = lines[1];
      const passport = b.slice(0, 9), dob = b.slice(13, 19), expiry = b.slice(21, 27);
      const composite = b.slice(0, 10) + b.slice(13, 20) + b.slice(21, 43);
      const checks = { passportNumber: check(passport, b[9]), dateOfBirth: check(dob, b[19]), expiry: check(expiry, b[27]), optional: check(b.slice(28, 42), b[42]), composite: check(composite, b[43]) };
      checks.overall = checks.passportNumber && checks.dateOfBirth && checks.expiry && checks.composite;
      const names = a.slice(5).split('<<');
      return { valid: checks.overall, review: !checks.overall, documentCode: a.slice(0, 2), issuingCountry: a.slice(2, 5), surname: names[0].replace(/</g, ' ').trim(), givenNames: (names[1] || '').replace(/</g, ' ').trim(), passportNumber: passport.replace(/</g, ''), nationality: b.slice(10, 13), dateOfBirth: dateValue(dob, 'dob'), sex: b[20], passportExpiry: dateValue(expiry, 'expiry'), checkDigits: checks };
    }
  };
  window.ETPassportMRZ.resolveDate = dateValue;
  if (typeof document === 'undefined') return;
  const byId = id => document.getElementById(id);
  const state = byId('pm262-state'), input = byId('pm262-mrz-input');
  byId('pm262-mrz')?.addEventListener('click', () => { input.style.display = 'block'; input.focus(); state.textContent = 'Paste two TD3 MRZ lines, then review the fields.'; });
  input?.addEventListener('input', () => { const parsed = window.ETPassportMRZ.parse(input.value); state.textContent = parsed.valid ? 'Ready for review' : parsed.error || 'Passport scan needs review.'; });
  byId('pm262-camera')?.addEventListener('click', async () => { state.textContent = 'Opening camera…'; try { const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } }); stream.getTracks().forEach(track => track.stop()); state.textContent = 'Camera ready. Capture or upload an image, then review.'; } catch (_) { state.textContent = 'Camera is not available on this device. Upload an image or enter the passenger manually.'; } });
  byId('pm262-upload')?.addEventListener('change', () => { state.textContent = 'Reading passport… Review the extracted fields before saving.'; });
})();
