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
  const recognizeImage = async source => {
    if (root.ETLocalOCR && typeof root.ETLocalOCR.recognize === 'function') return root.ETLocalOCR.recognize(source, { whitelist: 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<', localOnly: true });
    if (typeof root.TextDetector === 'function') { const detector = new root.TextDetector(); return (await detector.detect(source)).map(item => item.rawValue || '').join('\n'); }
    throw new Error('Local OCR runtime is unavailable. Paste MRZ text or enter the details manually.');
  };
  const process = async source => { const text = await recognizeImage(source); const result = root.ETPassportMRZ.parse(text); mapResult(result); return result; };
  root.ETPassengerScanner = { mapResult, process, stopStream: stream => stream && stream.getTracks().forEach(track => track.stop()) };
  const state = document.getElementById('pm262-state'), video = document.getElementById('pm262-video'), canvas = document.getElementById('pm262-canvas');
  let stream = null;
  const stop = () => { root.ETPassengerScanner.stopStream(stream); stream = null; if (video) { video.pause(); video.srcObject = null; video.style.display = 'none'; } const capture = document.getElementById('pm262-capture'); if (capture) capture.hidden = true; };
  document.getElementById('pm262-scan')?.addEventListener('click', () => { document.getElementById('pm262-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' }); document.getElementById('pm262-camera')?.focus(); });
  document.getElementById('pm262-camera')?.addEventListener('click', async () => { stop(); state.textContent = 'Opening camera…'; try { stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } }); video.srcObject = stream; video.style.display = 'block'; await video.play(); document.getElementById('pm262-capture').hidden = false; state.textContent = 'Camera ready. Capture the passport MRZ.'; } catch (_) { state.textContent = 'Camera is not available on this device. Upload an image or enter the passenger manually.'; } });
  document.getElementById('pm262-capture')?.addEventListener('click', async () => { if (!video || !canvas) return; state.textContent = 'Reading passport…'; canvas.width = video.videoWidth; canvas.height = video.videoHeight; canvas.getContext('2d').drawImage(video, 0, 0); stop(); try { await process(canvas); } catch (_) { state.textContent = 'Passport could not be read clearly. Try another image or enter the details manually.'; } });
  document.getElementById('pm262-upload')?.addEventListener('change', async event => { const file = event.target.files?.[0]; if (!file) return; state.textContent = 'Reading passport…'; try { const image = await createImageBitmap(file); if (canvas) { canvas.width = image.width; canvas.height = image.height; canvas.getContext('2d').drawImage(image, 0, 0); } await process(canvas || image); image.close?.(); } catch (_) { state.textContent = 'Passport could not be read clearly. Try another image or enter the details manually.'; } finally { event.target.value = ''; } });
  document.getElementById('pm262-mrz-input')?.addEventListener('input', event => { try { mapResult(root.ETPassportMRZ.parse(event.target.value)); } catch (_) {} });
  window.addEventListener('pagehide', stop); window.addEventListener('beforeunload', stop);
})(window);
