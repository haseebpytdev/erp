(function (root) {
  'use strict';
  // Browser-local OCR authority. Deploys with the ERP and never sends image bytes
  // over the network. Native TextDetector is used when available; callers may
  // inject a local WASM recognizer through this same adapter contract.
  root.ETLocalOCR = {
    async recognize(source, options = {}) {
      if (typeof root.TextDetector !== 'function') throw new Error('Local OCR runtime is unavailable.');
      const detector = new root.TextDetector();
      const blocks = await detector.detect(source);
      return blocks.map(block => block.rawValue || '').join('\n');
    }
  };
})(window);
