import type { ReceiptWidth } from '@/types';
import { Button } from '@/components/ui/button';
import { WIDTH_OPTIONS } from './print';

/** The three paper widths the receipt renderer knows, as a button row. */
export function PrintButtons({
  printing,
  onPrint,
  disabled = false,
}: {
  printing: ReceiptWidth | null;
  onPrint: (width: ReceiptWidth) => void;
  disabled?: boolean;
}) {
  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {WIDTH_OPTIONS.map((option) => (
        <Button
          key={option.value}
          variant="secondary"
          size="sm"
          icon="print-outline"
          title={option.hint}
          disabled={disabled || printing !== null}
          loading={printing === option.value}
          onClick={() => onPrint(option.value)}
        >
          {option.label}
        </Button>
      ))}
    </div>
  );
}
