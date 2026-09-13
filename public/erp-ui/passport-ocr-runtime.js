(function (root) {
  'use strict';
  // Browser-local OCR authority. All runtime paths are same-origin Laravel
  // asset routes; image bytes never leave the browser.
  let workerPromise = null;
  const base = root.ETTesseractAssetBase || '/system/erp-assets/tesseract';
  const workerPath = `${base}/dist/worker.min.js`;
  const corePath = `${base}/core`;
  const langPath = `${base}/lang-data`;
  root.ETLocalOCR = {
    async recognize(source, options = {}) {
      if (!root.Tesseract || typeof root.Tesseract.createWorker !== 'function') throw new Error('Passport OCR could not start. Paste the MRZ or enter the passenger manually.');
      if (!workerPromise) workerPromise = root.Tesseract.createWorker('eng', 1, { workerPath, corePath, langPath });
      const worker = await workerPromise;
      await worker.setParameters({ tessedit_char_whitelist: options.whitelist || 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<' });
      const result = await worker.recognize(source);
      return result?.data?.text || '';
    }
  };
})(window);
