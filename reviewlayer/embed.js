(function bootstrapReviewLayer() {
  'use strict';

  if (window.__reviewLayerLoaded) {
    return;
  }

  const script = document.currentScript;
  if (!script || !script.src) {
    console.error('[ReviewLayer] Unable to determine the installation URL.');
    return;
  }

  window.__reviewLayerLoaded = true;
  const baseUrl = new URL('./', script.src).href;
  const options = {
    baseUrl,
    projectKey: script.dataset.project || 'default',
    language: script.dataset.lang || 'en'
  };

  const start = () => {
    import(`${baseUrl}assets/app.js?v=1.4.1`)
      .then(({ startReviewLayer }) => startReviewLayer(options))
      .catch((error) => {
        window.__reviewLayerLoaded = false;
        console.error('[ReviewLayer] Application startup failed.', error);
      });
  };

  if (document.body) {
    start();
  } else {
    window.addEventListener('DOMContentLoaded', start, { once: true });
  }
}());
