import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { setAssetPath } from 'ionicons/components';
import { defineCustomElement } from 'ionicons/components/ion-icon.js';
import './index.css';
import App from './App.tsx';

// The component resolves `svg/<name>.svg` relative to this copied catalogue.
setAssetPath(
  new URL(`${import.meta.env.BASE_URL}ionicons/`, document.baseURI).href
);
defineCustomElement();

// The service worker caches the application shell and static chunks only.
// API responses are deliberately excluded so transactional data is never stale.
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
  window.addEventListener(
    'load',
    () => {
      void navigator.serviceWorker
        .register(`${import.meta.env.BASE_URL}sw.js`, {
          scope: import.meta.env.BASE_URL,
        })
        .catch((error: unknown) => {
          console.error('SyntafPOS service worker registration failed.', error);
        });
    },
    { once: true }
  );
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>
);
