(function (root) {
  'use strict';
  const field = key => document.querySelector(`[data-pm-field="${key}"]`);
  const set = (key, value) => { const node = field(key); if (node && value != null) node.value = value; };
  const mapResult = result => {
    if (!result) return;
    set('title', result.sex === 'M' ? 'Mr' : result.sex === 'F' ? 'Ms' : '');
    set('sex', result.sex === 'M' ? 'Male' : result.sex === 'F' ? 'Female' : 'X');
    set('firstName', result.givenNames); set('lastName', result.surname);
    set('passportNumber', result.passportNumber); set('nationality', result.nationality);
    set('dateOfBirth', result.dateOfBirth); set('passportExpiry', result.passportExpiry); set('issuingCountry', result.issuingCountry);
    const state = document.getElementById('pm262-state'); if (state) state.textContent = result.valid ? 'Ready for review' : 'Passport scan needs review. Please verify the highlighted fields.';
  };
  const preprocessImage = source => {
    if (!source || !source.getContext) return source;
    const width = Math.min(1800, Math.max(800, source.width || 1200)), height = Math.round(width * 0.7);
    const crop = document.createElement('canvas'); crop.width = width; crop.height = height;
    const ctx = crop.getContext('2d'); const sy = Math.round((source.height || height) * 0.58); const sh = Math.max(1, (source.height || height) - sy);
    ctx.drawImage(source, 0, sy, source.width || width, sh, 0, 0, width, height);
    const image = ctx.getImageData(0, 0, width, height); for (let i = 0; i < image.data.length; i += 4) { const gray = Math.max(0, Math.min(255, ((image.data[i] * 0.299 + image.data[i + 1] * 0.587 + image.data[i + 2] * 0.114) - 128) * 1.35 + 128)); image.data[i] = image.data[i + 1] = image.data[i + 2] = gray; } ctx.putImageData(image, 0, 0); return crop;
  };
  const recognizeImage = async source => {
    if (root.ETLocalOCR && typeof root.ETLocalOCR.recognize === 'function') return root.ETLocalOCR.recognize(source, { whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<', localOnly: true });
    if (typeof root.TextDetector === 'function') { const detector = new root.TextDetector(); return (await detector.detect(source)).map(item => item.rawValue || '').join('\n'); }
    throw new Error('Local OCR runtime is unavailable. Paste MRZ text or enter the details manually.');
  };
  const runtimeFailure = error => /importScripts|worker failed|failed to fetch|networkerror|ocr could not start|tesseract runtime unavailable|local ocr runtime is unavailable/i.test(String(error?.message || error || ''));
  const failureMessage = error => runtimeFailure(error)
    ? 'Passport OCR could not start. Please refresh and try again, or enter the passenger manually.'
    : 'Passport could not be read clearly. Try another image or enter the details manually.';
  const process = async source => { const crop = preprocessImage(source); let text = await recognizeImage(crop); let candidate = root.ETPassportMRZ.extractTD3(text); if (!candidate && crop !== source) { text = await recognizeImage(source); candidate = root.ETPassportMRZ.extractTD3(text); } const result = root.ETPassportMRZ.parse(candidate || text); mapResult(result); return result; };
  root.ETPassengerScanner = { mapResult, process, preprocessImage, stopStream: stream => stream && stream.getTracks().forEach(track => track.stop()) };
  if (root.ETPassengerEdit) {
    const form = document.getElementById('pm262-passenger-form');
    if (form) { form.action = `/passengers/${encodeURIComponent(root.ETPassengerEdit.source_table)}/${root.ETPassengerEdit.id}`; const method = document.createElement('input'); method.type = 'hidden'; method.name = '_method'; method.value = 'PATCH'; form.appendChild(method); mapResult({ givenNames: root.ETPassengerEdit.first_name, surname: root.ETPassengerEdit.last_name, passportNumber: root.ETPassengerEdit.passport_no, nationality: root.ETPassengerEdit.nationality, dateOfBirth: root.ETPassengerEdit.date_of_birth, passportExpiry: root.ETPassengerEdit.passport_expiry, issuingCountry: root.ETPassengerEdit.issuing_country, sex: root.ETPassengerEdit.sex }); set('title', root.ETPassengerEdit.title || ''); }
  }
  const state = document.getElementById('pm262-state'), video = document.getElementById('pm262-video'), canvas = document.getElementById('pm262-canvas');
  let stream = null;
  const stop = () => { root.ETPassengerScanner.stopStream(stream); stream = null; if (video) { video.pause(); video.srcObject = null; video.style.display = 'none'; } const capture = document.getElementById('pm262-capture'); if (capture) capture.hidden = true; };
  document.getElementById('pm262-scan')?.addEventListener('click', () => { document.getElementById('pm262-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); document.getElementById('pm262-camera')?.focus(); });
  document.getElementById('pm262-mode-scan')?.addEventListener('click', () => document.getElementById('pm262-scan')?.click());
  document.getElementById('pm262-mode-upload')?.addEventListener('click', () => document.getElementById('pm262-upload')?.click());
  document.getElementById('pm262-camera')?.addEventListener('click', async () => { stop(); state.textContent = 'Opening camera…'; try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } }); video.srcObject = stream; video.style.display = 'block'; await video.play(); document.getElementById('pm262-capture').hidden = false; state.textContent = 'Camera ready. Capture the passport MRZ.'; } catch (_) { state.textContent = 'Camera is not available on this device. Upload an image or enter the passenger manually.'; } });
  document.getElementById('pm262-capture')?.addEventListener('click', async () => { if (!video || !canvas) return; state.textContent = 'Reading passport…'; canvas.width = video.videoWidth; canvas.height = video.videoHeight; canvas.getContext('2d').drawImage(video, 0, 0); stop(); try { await process(canvas); } catch (error) { console.error('[ET Passport OCR]', error); state.textContent = failureMessage(error); } });
  document.getElementById('pm262-upload')?.addEventListener('change', async event => { const file = event.target.files?.[0]; if (!file) return; state.textContent = 'Reading passport…'; try { const image = await createImageBitmap(file); if (canvas) { canvas.width = image.width; canvas.height = image.height; canvas.getContext('2d').drawImage(image, 0, 0); } await process(canvas || image); image.close?.(); } catch (error) { console.error('[ET Passport OCR]', error); state.textContent = failureMessage(error); } finally { event.target.value = ''; } });
  const revealMrz = () => { const input = document.getElementById('pm262-mrz-input'); if (input) { input.style.display = 'block'; input.focus(); } };
  document.getElementById('pm262-mode-mrz')?.addEventListener('click', revealMrz);
  document.getElementById('pm262-mrz')?.addEventListener('click', revealMrz);
  document.getElementById('pm262-mrz-input')?.addEventListener('input', event => { try { mapResult(root.ETPassportMRZ.parse(event.target.value)); } catch (_) {} });
  window.addEventListener('pagehide', stop); window.addEventListener('beforeunload', stop);
})(window);
