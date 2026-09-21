<?php

namespace App\Services;

use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Support\DecimalMath;
use App\Support\MoneyFormat;

/**
 * Renders a sale as a printable document in three paper widths.
 *
 * 58mm and 80mm are the two till rolls a counter actually owns; A4 is the
 * invoice a customer asks for when the ticket goes to their accounts department.
 * All three are built from the sale's own snapshot columns and the same
 * pre-formatted figures, so the only thing a width changes is how much fits on
 * the page — never what the numbers say.
 *
 * Two decisions worth stating:
 *  - The output is HTML rather than a PDF. A browser's print dialog reaches every
 *    printer in the shop without a server-side PDF library, and a thermal roll
 *    wants character layout, not a page description language.
 *  - Money is formatted through MoneyFormat, from the exact decimals the sale
 *    stores — the same helper that writes the figure in a till's error message,
 *    so paper and screen cannot disagree. Nothing goes near a float, so a printed
 *    total cannot drift from the one the till showed.
 */
class SaleReceiptService
{
    /**
     * @param  string  $width  58 | 80 | a4
     */
    public function render(Sale $sale, string $width = '80'): string
    {
        $sale->loadMissing(['items', 'payments', 'company', 'cashier']);

        return match ($width) {
            '58' => $this->roll($sale, 58),
            'a4' => $this->invoice($sale),
            default => $this->roll($sale, 80),
        };
    }

    /**
     * A thermal roll: narrow, monospaced, one column pair per line, cut at the
     * bottom — so the totals and the change belong there, not the header.
     */
    private function roll(Sale $sale, int $mm): string
    {
        $columns = $mm === 58 ? 32 : 42;
        $lines = [];

        $lines[] = $this->line($this->companyName($sale), 'center', true);
        $lines[] = $this->line($this->companyTaxLine($sale), 'center');
        $lines[] = $this->line($this->outletLine($sale), 'center');
        $lines[] = $this->rule($columns);

        $lines[] = $this->pair('No', (string) $sale->number, $columns);
        $lines[] = $this->pair('Date', $this->dateTime($sale), $columns);
        $lines[] = $this->pair('Register', (string) $sale->register_code, $columns);
        $lines[] = $this->pair('Cashier', (string) $sale->cashier?->name, $columns);
        $lines[] = $this->pair('Customer', $sale->customer_name ?: 'Walk-in', $columns);

        if ($sale->customer_code) {
            $lines[] = $this->pair('Code', (string) $sale->customer_code, $columns);
        }

        $lines[] = $this->rule($columns);

        foreach ($sale->items as $item) {
            // The name gets a line to itself: a roll this narrow cannot fit a
            // name and its figures, and truncating a name is how a customer
            // disputes an item they cannot read.
            $lines[] = $this->line($this->itemName($item), 'left');
            $lines[] = $this->line(sprintf(
                '%s x %s = %s',
                $this->quantity($item),
                MoneyFormat::format($item->unit_price, $sale->currency),
                MoneyFormat::format($item->line_total, $sale->currency)
            ), 'right');
        }

        $lines[] = $this->rule($columns);

        foreach ($this->summaryRows($sale) as [$label, $value, $negative]) {
            $lines[] = $this->line(
                $label.': '.($negative ? '-' : '').$value,
                'right'
            );
        }

        $lines[] = $this->rule($columns);
        $lines[] = $this->line(
            'TOTAL '.MoneyFormat::format($sale->grand_total, $sale->currency),
            'center',
            true
        );

        // Amount, method, paid, change, reference — the five things the spec asks
        // a receipt to prove. One block per tender rather than one blended figure,
        // because a split payment of cash and QRIS is settled in two different
        // places and a customer's own copy has to name both. A tender that has
        // been cancelled or failed is not printed at all: paper showing money the
        // till is not holding is worse than paper saying nothing about it.
        $counted = $sale->payments->filter(
            fn (SalePayment $payment) => $payment->status?->countsTowardPaid() === true
        );

        foreach ($counted as $payment) {
            $lines[] = $this->pair(
                (string) $payment->method_name,
                MoneyFormat::format($payment->amount, $sale->currency),
                $columns
            );

            if ($payment->paid_at) {
                $lines[] = $this->pair('Paid', $payment->paid_at->format('d M Y H:i'), $columns);
            }

            if (bccomp((string) $payment->change, '0', 4) > 0) {
                $lines[] = $this->pair('Change', MoneyFormat::format($payment->change, $sale->currency), $columns);
            }

            if (filled($payment->reference)) {
                $lines[] = $this->pair('Reference', (string) $payment->reference, $columns);
            }
        }

        // Only a split tender needs the roll-up; on a single payment the lines
        // above already say it, and repeating the figure twice invites the reader
        // to add them up.
        if ($counted->count() > 1) {
            $lines[] = $this->pair('Paid', MoneyFormat::format($sale->paid_total, $sale->currency), $columns);
        }

        $balance = $this->balance($sale);

        if (bccomp($balance, '0', 4) > 0) {
            $lines[] = $this->pair('Balance', MoneyFormat::format($balance, $sale->currency), $columns);
        }

        if ($counted->count() > 1 && bccomp((string) $sale->change_due, '0', 4) > 0) {
            $lines[] = $this->pair('Change', MoneyFormat::format($sale->change_due, $sale->currency), $columns);
        }

        if ($sale->notes) {
            $lines[] = $this->rule($columns);
            $lines[] = $this->line((string) $sale->notes, 'left');
        }

        $lines[] = $this->line($this->footer($sale), 'center');

        return $this->document(
            'Receipt '.$sale->number,
            implode("\n", $lines),
            $mm === 58 ? '58' : '80'
        );
    }

    /**
     * The A4 invoice: both parties named in full, tax itemised per line, and the
     * payment state stamped on it — the three things an accounts department
     * checks before accepting a document.
     */
    private function invoice(Sale $sale): string
    {
        $rows = '';

        foreach ($sale->items as $item) {
            $rows .= '<tr>'
                .'<td>'.e($this->itemName($item))
                    .'<div class="sub">'.e($this->lineDetail($item)).'</div></td>'
                .'<td class="n">'.e($this->quantity($item)).'</td>'
                .'<td class="n">'.e(MoneyFormat::format($item->unit_price, $sale->currency)).'</td>'
                .'<td class="n">'.e($this->moneyOrDash($item->discount_amount, $sale->currency)).'</td>'
                .'<td class="n">'.e($this->moneyOrDash($item->tax_amount, $sale->currency)).'</td>'
                .'<td class="n">'.e(MoneyFormat::format($item->line_total, $sale->currency)).'</td>'
                .'</tr>';
        }

        $summary = '';
        foreach ($this->summaryRows($sale) as [$label, $value, $negative]) {
            $grand = $label === 'Total';
            $summary .= '<tr class="'.($grand ? 'grand' : '').'">'
                .'<td></td><td colspan="4" class="lbl">'.e($label).'</td>'
                .'<td class="n">'.($negative ? '−' : '').e($value).'</td>'
                .'</tr>';
        }

        // Method, amount, paid, reference — one row per tender, in the order they
        // were taken. A tender that did not settle keeps its row with the status
        // spelled out: an invoice that silently drops a failed card payment reads
        // as if the money had been tried and never mentioned, and that is exactly
        // the gap an accounts department later asks about.
        $payments = '';
        foreach ($sale->payments as $payment) {
            $method = e((string) $payment->method_name);
            $counted = $payment->status?->countsTowardPaid() === true;

            $payments .= '<tr'.($counted ? '' : ' class="void"').'>'
                .'<td>'.$method.($counted ? '' : ' <span class="ref">('.e((string) $payment->status?->label()).')</span>').'</td>'
                .'<td class="ref">'.e((string) ($payment->reference ?: $payment->number)).'</td>'
                .'<td class="ref">'.e($payment->paid_at?->format('d M Y H:i') ?? '—').'</td>'
                .'<td class="n">'.e(MoneyFormat::format($payment->amount, $sale->currency)).'</td>'
                .'</tr>';

            if (bccomp((string) $payment->change, '0', 4) > 0) {
                $payments .= '<tr><td></td><td colspan="2" class="ref">Change from tender</td>'
                    .'<td class="n">'.e(MoneyFormat::format($payment->change, $sale->currency)).'</td></tr>';
            }
        }

        $balance = $this->balance($sale);
        $customer = $sale->customer_name ?: 'Walk-in customer';
        $soldBy = trim((string) $sale->register_code) === ''
            ? (string) $sale->cashier?->name
            : $sale->register_code.' · '.(string) $sale->cashier?->name;

        $body = '<header>'
            .'<div class="who">'
                .'<h1>'.e($this->companyName($sale)).'</h1>'
                .'<p>'.e($this->companyTaxLine($sale)).'</p>'
                .'<p>'.e($this->companyAddress($sale)).'</p>'
                .'<p class="outlet">'.e($this->outletLine($sale)).'</p>'
            .'</div>'
            .'<div class="doc"><h2>INVOICE</h2><table class="meta">'
                .'<tr><th>Invoice no.</th><td>'.e((string) $sale->number).'</td></tr>'
                .'<tr><th>Date</th><td>'.e($this->dateTime($sale)).'</td></tr>'
                .'<tr><th>Status</th><td class="status">'.e($this->statusLabel($sale)).'</td></tr>'
            .'</table></div>'
            .'</header>'

            .'<section class="parties">'
                .'<div><h3>Billed to</h3><p class="strong">'.e($customer).'</p>'
                    .'<p>'.nl2br(e($this->customerLines($sale))).'</p></div>'
                .'<div><h3>Sold by</h3><p class="strong">'.e($soldBy).'</p>'
                    .'<p>'.e($this->outletLine($sale)).'</p></div>'
            .'</section>'

            .'<table class="items"><thead><tr>'
                .'<th>Item</th><th class="n">Qty</th><th class="n">Unit price</th>'
                .'<th class="n">Discount</th><th class="n">Tax</th><th class="n">Amount</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody><tfoot>'.$summary.'</tfoot></table>'

            .'<section class="settle"><div><h3>Payments</h3>'
                .'<table class="payments"><thead><tr>'
                    .'<th>Method</th><th>Reference</th><th>Paid</th><th class="n">Amount</th>'
                .'</tr></thead><tbody>'.$payments
                    .'<tr class="tot"><td colspan="3">Received</td><td class="n">'.e(MoneyFormat::format($sale->paid_total, $sale->currency)).'</td></tr>'
                    .'<tr class="tot"><td colspan="3">Balance</td><td class="n">'.e(MoneyFormat::format($balance, $sale->currency)).'</td></tr>'
                    .'<tr class="tot"><td colspan="3">Change</td><td class="n">'.e(MoneyFormat::format($sale->change_due, $sale->currency)).'</td></tr>'
                .'</tbody></table></div>'
                .'<div class="stamp"><span>'.e($this->stampLabel($sale, $balance)).'</span></div>'
            .'</section>'

            .($sale->notes
                ? '<section class="notes"><h3>Notes</h3><p>'.nl2br(e((string) $sale->notes)).'</p></section>'
                : '')

            .'<footer>'.e($this->footer($sale)).'</footer>';

        return $this->document('Invoice '.$sale->number, $body, 'a4');
    }

    /**
     * Header summary rows in the order an invoice reads them: gross, line
     * discounts, the sale-level discount, tax, other charges, rounding, total.
     *
     * A row is dropped when it is zero rather than printed as "Rp 0": a roll is
     * short, and a zero row teaches a cashier to stop reading them.
     *
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    private function summaryRows(Sale $sale): array
    {
        $currency = (string) $sale->currency;
        $zero = '0.0000';

        $rows = [['Subtotal', MoneyFormat::format($sale->subtotal, $currency), false]];

        if (bccomp((string) $sale->item_discount_total, $zero, 4) > 0) {
            $rows[] = ['Item discount', MoneyFormat::format($sale->item_discount_total, $currency), true];
        }

        if (bccomp((string) $sale->discount_total, $zero, 4) > 0) {
            $rows[] = ['Discount', MoneyFormat::format($sale->discount_total, $currency), true];
        }

        if (bccomp((string) $sale->tax_total, $zero, 4) > 0) {
            $rows[] = ['Tax', MoneyFormat::format($sale->tax_total, $currency), false];
        }

        if (bccomp((string) $sale->other_charges, $zero, 4) > 0) {
            $rows[] = ['Other charges', MoneyFormat::format($sale->other_charges, $currency), false];
        }

        if (bccomp((string) $sale->rounding, $zero, 4) !== 0) {
            $negative = bccomp((string) $sale->rounding, $zero, 4) < 0;
            // Stored signed; shown as the correction it is, so the sign in front
            // of the amount and the sign of the rounding always agree.
            $rows[] = [
                'Rounding',
                MoneyFormat::format(DecimalMath::sub($zero, (string) $sale->rounding), $currency),
                $negative,
            ];
        }

        $rows[] = ['Total', MoneyFormat::format($sale->grand_total, $currency), false];

        return $rows;
    }

    private function balance(Sale $sale): string
    {
        return DecimalMath::sub((string) $sale->grand_total, (string) $sale->paid_total);
    }

    private function moneyOrDash(?string $amount, ?string $currency): string
    {
        return bccomp((string) ($amount ?? '0'), '0', 4) === 0 ? '—' : MoneyFormat::format($amount, $currency);
    }

    private function quantity(SaleItem $item): string
    {
        $value = rtrim(rtrim(bcadd((string) $item->quantity, '0', 6), '0'), '.');

        return $value === '' ? '0' : $value;
    }

    private function itemName(SaleItem $item): string
    {
        return $item->variant_name
            ? $item->product_name.' — '.$item->variant_name
            : $item->product_name;
    }

    /**
     * The small print under an invoice line: how much of the amount is tax, and
     * whether it was already inside the price.
     */
    private function lineDetail(SaleItem $item): string
    {
        $parts = ['SKU '.$item->product_sku];

        if (bccomp((string) $item->tax_rate, '0', 4) > 0) {
            $rate = rtrim(rtrim((string) $item->tax_rate, '0'), '.');
            $parts[] = $item->tax_mode === 'inclusive'
                ? 'incl. '.$rate.'% tax'
                : 'plus '.$rate.'% tax';
        }

        if (bccomp((string) $item->discount_amount, '0', 4) > 0) {
            $parts[] = 'discount off';
        }

        return implode(' · ', $parts);
    }

    private function dateTime(Sale $sale): string
    {
        $date = $sale->date?->format('d M Y') ?? '';
        $time = $sale->created_at?->format('H:i') ?? '';

        return trim($date.' '.$time);
    }

    private function statusLabel(Sale $sale): string
    {
        return (string) $sale->status?->label();
    }

    private function stampLabel(Sale $sale, string $balance): string
    {
        if ($sale->status === SaleStatus::Cancelled) {
            return 'CANCELLED';
        }

        return bccomp($balance, '0', 4) > 0 ? 'BALANCE DUE' : 'PAID';
    }

    private function footer(Sale $sale): string
    {
        return $sale->status === SaleStatus::Cancelled
            ? 'This transaction was cancelled.'
            : 'Thank you for your purchase. Please keep this receipt for exchange.';
    }

    private function companyName(Sale $sale): string
    {
        $company = $sale->company;

        if (! $company) {
            return (string) $sale->branch_name;
        }

        return $company->legal_name ?: $company->name;
    }

    private function companyTaxLine(Sale $sale): string
    {
        $taxNumber = $sale->company?->tax_number;

        return $taxNumber ? 'NPWP '.$taxNumber : '';
    }

    private function companyAddress(Sale $sale): string
    {
        $company = $sale->company;

        if (! $company) {
            return '';
        }

        return implode(', ', array_filter([$company->address, $company->city, $company->phone]));
    }

    private function outletLine(Sale $sale): string
    {
        return implode(' · ', array_filter([
            $sale->branch_name,
            $sale->branch_phone,
        ]));
    }

    private function customerLines(Sale $sale): string
    {
        return implode("\n", array_filter([
            $sale->customer_code ? 'Code '.$sale->customer_code : null,
            $sale->customer_phone,
            $sale->customer_email,
            $sale->customer_address,
        ]));
    }

    /**
     * One line of a roll: aligned, escaped, and wrapping rather than truncating,
     * because a clipped product name is a dispute waiting to happen.
     */
    private function line(string $text, string $align, bool $bold = false): string
    {
        return sprintf(
            '<div class="%s%s">%s</div>',
            $align,
            $bold ? ' b' : '',
            e(trim($text) === '' ? ' ' : $text)
        );
    }

    /**
     * A label/value pair with the value right-aligned on the same column every
     * time, which is what a cashier points at when a customer asks a question.
     */
    /**
     * One label and one figure on a line, the figure against the right edge.
     *
     * The label budget follows the paper: a 58mm roll has 32 characters to spend
     * and must keep most of them for the amount, while an 80mm roll has room to
     * name a method in full. Truncating a payment method to "Bank transfe" is the
     * kind of thing that makes a customer phone the shop about their own receipt.
     */
    private function pair(string $label, string $value, int $columns): string
    {
        $budget = $columns >= 40 ? 16 : 11;
        $label = substr($label, 0, $budget);
        $room = $columns - $budget - 1;
        $value = strlen($value) > $room ? substr($value, -$room) : $value;

        return $this->line(str_pad($label, $budget + 1, ' ').str_pad($value, $room, ' ', STR_PAD_LEFT), 'left');
    }

    private function rule(int $columns): string
    {
        return '<div class="rule">'.e(str_repeat('-', $columns)).'</div>';
    }

    /**
     * The document shell.
     *
     * Styles are inline in a <style> block rather than in the app's stylesheet so
     * a receipt survives being emailed, opened from a blob, or printed by a
     * browser that never loaded the SPA. @page carries the paper size: a 58mm
     * roll is not a scaled-down A4, and a printer told the wrong width cuts
     * through the totals.
     *
     * @param  string  $width  58 | 80 | a4
     */
    private function document(string $title, string $body, string $width): string
    {
        $roll = $width !== 'a4';
        $paper = $roll ? $width.'mm' : 'a4';
        $margin = $roll ? '2mm' : '14mm';
        $font = $roll
            ? "width: {$paper}; padding: 2mm; font: 11px/1.35 ui-monospace, 'DejaVu Sans Mono', Menlo, Consolas, monospace;"
            : 'width: 182mm; font: 12px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: #16181d;';
        $class = $roll ? 'roll '.$width : 'a4';

        $style = <<<'CSS'
          * { box-sizing: border-box; }
          body { margin: 0; color: #16181d; }
          .b { font-weight: 700; }
          .center { text-align: center; }
          .left { text-align: left; }
          .right { text-align: right; }
          .rule { white-space: pre; overflow: hidden; letter-spacing: -1px; }
          .roll div { white-space: pre-wrap; word-break: break-word; }
          h1 { font-size: 15px; margin: 0 0 2px; }
          h2 { font-size: 17px; letter-spacing: .12em; margin: 0 0 6px; text-align: right; }
          h3 { font-size: 10px; text-transform: uppercase; letter-spacing: .08em; color: #5b6270; margin: 0 0 4px; }
          p { margin: 0 0 2px; }
          header { display: flex; justify-content: space-between; gap: 10mm; align-items: flex-start; }
          .doc { text-align: right; }
          .meta { border-collapse: collapse; margin-left: auto; }
          .meta th { text-align: left; font-weight: 500; color: #5b6270; padding: 1px 8px 1px 0; white-space: nowrap; }
          .meta td { text-align: right; font-variant-numeric: tabular-nums; }
          .meta .status { font-weight: 700; }
          .parties { display: flex; gap: 12mm; margin: 8mm 0 6mm; }
          .parties > div { flex: 1; }
          .strong { font-weight: 700; }
          table.items { width: 100%; border-collapse: collapse; }
          table.items th { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #5b6270; text-align: left; border-bottom: 1px solid #d7dae0; padding: 4px 6px; }
          table.items td { border-bottom: 1px solid #eceef2; padding: 6px; vertical-align: top; }
          table.items .sub { color: #5b6270; font-size: 10.5px; }
          table.items .lbl { text-align: right; }
          .n, table td.n { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
          table.items tfoot td { border: 0; padding: 4px 6px; }
          table.items tfoot tr:first-child td { border-top: 1px solid #d7dae0; }
          table.items tfoot tr.grand td { font-weight: 700; font-size: 14px; border-top: 2px solid #16181d; padding-top: 8px; }
          .settle { display: flex; gap: 12mm; margin-top: 8mm; align-items: flex-start; }
          .settle > div { flex: 1; }
          table.payments { width: 100%; border-collapse: collapse; }
          table.payments th { font-size: 10px; text-transform: uppercase; letter-spacing: .06em; color: #5b6270; text-align: left; border-bottom: 1px solid #d7dae0; padding: 4px 6px; }
          table.payments td { border-bottom: 1px solid #eceef2; padding: 4px 6px; }
          table.payments .ref { color: #5b6270; }
          table.payments tr.void td { color: #9aa1ad; }
          table.payments tr.tot td { border-bottom: none; font-weight: 600; }
          .stamp { border: 2px solid #16181d; border-radius: 3px; padding: 6mm 4mm; text-align: center; font-weight: 700; letter-spacing: .1em; }
          .notes { margin-top: 6mm; }
          footer { margin-top: 8mm; padding-top: 3mm; border-top: 1px solid #d7dae0; color: #5b6270; font-size: 10.5px; text-align: center; }
          @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
        CSS;

        return '<!doctype html>'."\n"
            .'<html lang="en">'."\n"
            .'<head>'."\n"
            .'<meta charset="utf-8">'."\n"
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'."\n"
            .'<title>'.e($title).'</title>'."\n"
            .'<style>'."\n"
            ."@page { size: {$paper} auto; margin: {$margin}; }\n"
            ."body.{$class} { {$font} }\n"
            .$style."\n"
            .'</style>'."\n"
            .'</head>'."\n"
            .'<body class="'.e($class).'">'."\n"
            .$body."\n"
            .'</body>'."\n"
            .'</html>';
    }
}
