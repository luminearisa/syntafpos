/// <reference types="vite/client" />

import type { DetailedHTMLProps, HTMLAttributes } from 'react';

type IonIconProps = DetailedHTMLProps<HTMLAttributes<HTMLElement>, HTMLElement>;

/**
 * Ionicons are registered as a custom element (`<ion-icon>`). React 19 removed
 * the global JSX namespace that Stencil's bundled typings augment, so the tag
 * is declared against `react`'s own JSX namespace instead.
 */
declare module 'react' {
  namespace JSX {
    interface IntrinsicElements {
      'ion-icon': IonIconProps & {
        class?: string;
        name?: string;
        src?: string;
        size?: 'small' | 'large';
        lazy?: boolean;
        flipRtl?: boolean;
        sanitize?: boolean;
      };
    }
  }
}
