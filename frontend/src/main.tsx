import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { setAssetPath } from 'ionicons';
import { defineCustomElements } from 'ionicons/loader';
import './index.css';
import App from './App.tsx';

// <ion-icon> resolves its SVGs relative to the document base by default, which
// the Vite bundle never serves. Point it at the catalogue the
// ionicons-assets plugin exposes under BASE_URL.
setAssetPath(`${import.meta.env.BASE_URL}ionicons/svg/`);

// <ion-icon> is a custom element; register it before React mounts so the
// icons resolve on first paint instead of flashing as unknown elements.
void defineCustomElements(window);

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>
);
