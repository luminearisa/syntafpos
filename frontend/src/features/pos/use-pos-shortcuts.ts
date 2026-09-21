import { type RefObject, useEffect, useRef } from 'react';

export type ShortcutMap = Record<string, (() => void) | undefined>;

/**
 * Till keyboard shortcuts.
 *
 * A cashier works with one hand on the scanner, so everything the footer offers
 * is also a key: F2 search, F4 customer, F6 discount, F8 hold, F9 recall,
 * F10 checkout, Esc closes the topmost dialog.
 *
 * Two adaptations, both of them the browser's fault rather than ours:
 * F5 reloads and F12 opens devtools, so neither is claimed here even though
 * they would be natural picks; and while a modal is open only Esc is handled,
 * because the dialog owns the keyboard at that point. Every binding also has a
 * visible button in the footer, which is what a touch-only till falls back to.
 *
 * Keys fire even while the search box has focus — that is where a cashier's
 * cursor lives all day — so nothing here filters by event target.
 */
export function usePosShortcuts(map: ShortcutMap, enabled = true): void {
  const latest = useRef(map);

  // Committed after render, never during it; a key can only be pressed once
  // the browser is back outside React anyway.
  useEffect(() => {
    latest.current = map;
  });

  useEffect(() => {
    if (!enabled) {
      return;
    }

    const handler = (event: KeyboardEvent) => {
      if (event.altKey || event.ctrlKey || event.metaKey) {
        return;
      }

      const action = latest.current[event.key];

      if (action) {
        event.preventDefault();
        action();
      }
    };

    window.addEventListener('keydown', handler);

    return () => window.removeEventListener('keydown', handler);
  }, [enabled]);
}

/**
 * Focus an input on a shortcut, and return the ref to attach to it.
 */
export function useAutoFocus<T extends HTMLElement>(signal: unknown): RefObject<T | null> {
  const ref = useRef<T | null>(null);

  useEffect(() => {
    ref.current?.focus();
    if (ref.current instanceof HTMLInputElement) {
      ref.current.select();
    }
  }, [signal]);

  return ref;
}
