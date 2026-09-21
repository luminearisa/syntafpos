import { useCallback, useState } from 'react';
import { saleApi } from '@/api/services';
import type { ReceiptWidth } from '@/types';
import { apiErrorMessage } from '@/utils/api-error';
import { useToast } from '@/components/ui/toast';

/**
 * Printing a sale's document (Phase 3.2).
 *
 * The server renders the receipt as a complete HTML page with its own `@page`
 * size — 58mm, 80mm or A4 — so the browser's print dialogue is handed a document
 * that already knows how wide its paper is. That is deliberate: a client-side
 * re-layout would be a second renderer that can disagree with the archive copy.
 *
 * The window is opened synchronously from the click and filled when the HTML
 * arrives. Browsers only allow `window.open` inside the gesture, so a popup held
 * open empty is the price of fetching first; the alternative is a print view the
 * cashier has to press again.
 */
export const WIDTH_OPTIONS: Array<{ value: ReceiptWidth; label: string; hint: string }> = [
  { value: '58', label: '58mm', hint: 'Narrow till roll' },
  { value: '80', label: '80mm', hint: 'Standard till roll' },
  { value: 'a4', label: 'A4', hint: 'Invoice for filing' },
];

export function openPrintWindow(): Window | null {
  return window.open('', '_blank', 'width=420,height=640,resizable,scrollbars');
}

/**
 * Write a receipt into a window and print it.
 *
 * The stylesheet is inline, so nothing here depends on the app's own CSS or on
 * the print window still being able to reach the dev server.
 */
export function printDocument(target: Window, html: string, title: string): void {
  target.document.open();
  target.document.write(html);
  target.document.close();
  target.document.title = title;

  // A receipt is a small paper: focus it and let Ctrl+P work if the automatic
  // dialogue is blocked by a popup setting.
  target.focus();

  const go = () => {
    target.print();
  };

  if (target.document.readyState === 'complete') {
    go();
  } else {
    target.addEventListener('load', go, { once: true });
  }
}

/**
 * Print a sale's document (Phase 3.2).
 *
 * The window has to be opened inside the click — a browser blocks one opened
 * after an await — so the order here is fixed: claim the window, fetch the
 * server-rendered HTML for the chosen paper width, write it in and print. The
 * fetch is what keeps the figures honest: the receipt is rendered from the
 * stored sale snapshot on the server, so a reprint years later reads the same
 * as the original, and the app never re-lays-out an invoice from an API object.
 */
export function useReceiptPrinter(saleId: number) {
  const { toast } = useToast();
  const [printing, setPrinting] = useState<ReceiptWidth | null>(null);

  const print = useCallback(
    async (width: ReceiptWidth) => {
      const target = openPrintWindow();

      if (!target) {
        toast({
          variant: 'warning',
          title: 'The print window was blocked',
          message: 'Allow popups for this site, or print from the sale page.',
        });

        return;
      }

      setPrinting(width);

      try {
        const { data } = await saleApi.receipt(saleId, width);

        printDocument(target, data.html, `Sale ${data.sale.number}`);
      } catch (error) {
        // The window is already open, so say so in it rather than leaving the
        // cashier with an empty page and a toast elsewhere.
        const message = apiErrorMessage(error, 'The document could not be rendered.');

        target.document.open();
        target.document.write(
          `<pre style="font:14px/1.5 system-ui;padding:16px;white-space:pre-wrap">${message.replace(/[<>&]/g, '')}</pre>`
        );
        target.document.close();
        target.close();

        toast({ variant: 'error', title: 'Cannot print', message });
      } finally {
        setPrinting(null);
      }
    },
    [saleId, toast]
  );

  return { print, printing };
}
