<?php

namespace App\Services;

use App\Enums\SequenceResetPeriod;
use App\Models\DocumentSequence;
use App\Support\BusinessContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Document numbering engine.
 *
 * Sequence rows are incremented atomically inside a transaction using an
 * exclusive write, so concurrent requests cannot collide on the same number.
 * SQLite serialises writes, and the row is selected FOR UPDATE on drivers
 * that support it.
 */
class NumberingService
{
    private const DEFAULTS = [
        'invoice' => ['prefix' => 'INV', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'sales_order' => ['prefix' => 'SO', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'purchase_order' => ['prefix' => 'PO', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'payment' => ['prefix' => 'PAY', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'return' => ['prefix' => 'RET', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'expense' => ['prefix' => 'EXP', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'journal' => ['prefix' => 'JE', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'receipt' => ['prefix' => 'RCP', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],

        // Phase 2 documents.
        'purchase_request' => ['prefix' => 'PR', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'goods_receipt' => ['prefix' => 'GR', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'purchase_return' => ['prefix' => 'PRET', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'stock_adjustment' => ['prefix' => 'ADJ', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'stock_opname' => ['prefix' => 'OPN', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],
        'warehouse_transfer' => ['prefix' => 'TRF', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value],

        // Phase 3 — a held cart's recall code. Numbered only on hold, so an
        // abandoned till session never burns a number.
        'pos_cart' => ['prefix' => 'PARK', 'padding' => 4, 'reset' => SequenceResetPeriod::Daily->value],

        // A shift is a document a manager signs and files, so it is numbered on
        // open rather than derived from its register and time.
        'shift' => ['prefix' => 'SHIFT', 'padding' => 4, 'reset' => SequenceResetPeriod::Yearly->value],
    ];

    public function __construct(private BusinessContext $context) {}

    /**
     * Generate the next formatted document number.
     *
     * @param  array{prefix?: string, padding?: int, reset_period?: string}  $config
     */
    public function next(string $documentType, ?int $companyId = null, ?int $branchId = null, array $config = []): string
    {
        $companyId ??= $this->context->companyId();
        $branchId ??= null;

        $defaults = self::DEFAULTS[$documentType] ?? ['prefix' => 'DOC', 'padding' => 6, 'reset' => SequenceResetPeriod::Yearly->value];

        $prefix = $config['prefix'] ?? $defaults['prefix'];
        $padding = $config['padding'] ?? $defaults['padding'];
        $resetPeriod = $config['reset_period'] ?? $defaults['reset'];

        $number = $this->nextSequence($documentType, $companyId, $branchId, $prefix, $padding, $resetPeriod);

        return sprintf(
            '%s-%s-%s',
            $prefix,
            $this->periodSegment($resetPeriod),
            str_pad((string) $number, $padding, '0', STR_PAD_LEFT)
        );
    }

    /**
     * Peek at the number that would be issued next, without consuming it.
     */
    public function preview(string $documentType, ?int $companyId = null, ?int $branchId = null, array $config = []): string
    {
        $sequence = $this->resolveSequence(
            $documentType,
            $companyId ?? $this->context->companyId(),
            $branchId,
            $config['prefix'] ?? self::DEFAULTS[$documentType]['prefix'] ?? 'DOC',
            $config['padding'] ?? self::DEFAULTS[$documentType]['padding'] ?? 6,
            $config['reset_period'] ?? self::DEFAULTS[$documentType]['reset'] ?? SequenceResetPeriod::Yearly->value
        );

        return sprintf(
            '%s-%s-%s',
            $sequence->prefix,
            $this->periodSegment($sequence->reset_period->value),
            str_pad((string) $sequence->next_sequence, $sequence->padding, '0', STR_PAD_LEFT)
        );
    }

    private function nextSequence(string $documentType, int $companyId, ?int $branchId, string $prefix, int $padding, string $resetPeriod): int
    {
        return DB::transaction(function () use ($documentType, $companyId, $branchId, $prefix, $padding, $resetPeriod) {
            $sequence = $this->resolveSequence($documentType, $companyId, $branchId, $prefix, $padding, $resetPeriod);

            if ($this->shouldReset($sequence)) {
                $sequence->next_sequence = 1;
                $sequence->period_start = $this->periodStart($resetPeriod);
            }

            $number = $sequence->next_sequence;
            $sequence->next_sequence = $number + 1;
            $sequence->save();

            return $number;
        });
    }

    private function resolveSequence(string $documentType, int $companyId, ?int $branchId, string $prefix, int $padding, string $resetPeriod): DocumentSequence
    {
        $query = DocumentSequence::query()
            ->where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when(! $branchId, fn ($q) => $q->whereNull('branch_id'));

        if (DB::getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }

        $sequence = $query->first();

        if (! $sequence) {
            try {
                return DocumentSequence::create([
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'document_type' => $documentType,
                    'prefix' => $prefix,
                    'padding' => $padding,
                    'next_sequence' => 1,
                    'reset_period' => $resetPeriod,
                    'period_start' => $this->periodStart($resetPeriod),
                ])->fresh();
            } catch (\Throwable $e) {
                // Lost a create race; refetch the winning row.
                Log::warning('Document sequence create race, refetching', [
                    'document_type' => $documentType,
                    'company_id' => $companyId,
                ]);

                return $query->first() ?? throw new RuntimeException('Unable to resolve document sequence.');
            }
        }

        return $sequence;
    }

    private function shouldReset(DocumentSequence $sequence): bool
    {
        if ($sequence->period_start === null) {
            return false;
        }

        $start = $this->periodStart($sequence->reset_period->value);

        return $start->greaterThan($sequence->period_start);
    }

    private function periodStart(string $resetPeriod): Carbon
    {
        $now = now();

        return match (SequenceResetPeriod::from($resetPeriod)) {
            SequenceResetPeriod::Daily => $now->startOfDay(),
            SequenceResetPeriod::Monthly => $now->startOfMonth(),
            SequenceResetPeriod::Yearly => $now->startOfYear(),
            SequenceResetPeriod::Never => $now,
        };
    }

    private function periodSegment(string $resetPeriod): string
    {
        $now = now();

        return match (SequenceResetPeriod::from($resetPeriod)) {
            SequenceResetPeriod::Daily => $now->format('Ymd'),
            SequenceResetPeriod::Monthly => $now->format('Ym'),
            SequenceResetPeriod::Yearly, SequenceResetPeriod::Never => (string) $now->year,
        };
    }
}
