<?php

namespace App\Services\Import;

/**
 * The outcome of validating a whole file: one entry per row plus the summary a
 * client needs to decide whether to commit, and the error map it needs to fix
 * what it was shown.
 *
 * Carries no state beyond the analysis, so the same object describes a preview
 * and a rejected commit.
 */
final class ImportResult
{
    /**
     * @param  list<array{row_number: int, values: array<string, mixed>, valid: bool, errors: array<string, list<string>>}>  $rows
     * @param  array<string, list<string>>  $fileErrors  Problems with the file itself, keyed like row errors.
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $fileErrors = [],
    ) {}

    public function hasErrors(): bool
    {
        if ($this->fileErrors !== []) {
            return true;
        }

        return array_any($this->rows, fn (array $row) => ! $row['valid']);
    }

    /**
     * @return array{total: int, valid: int, invalid: int}
     */
    public function summary(): array
    {
        return [
            'total' => count($this->rows),
            'valid' => count(array_filter($this->rows, fn (array $row) => $row['valid'])),
            'invalid' => count(array_filter($this->rows, fn (array $row) => ! $row['valid'])),
        ];
    }

    /**
     * Row number to field to messages: the shape a client renders inline next
     * to the offending cell.
     *
     * @return array<int, array<string, list<string>>>
     */
    public function errorMap(): array
    {
        return collect($this->rows)
            ->filter(fn (array $row) => ! $row['valid'])
            ->mapWithKeys(fn (array $row) => [$row['row_number'] => $row['errors']])
            ->all();
    }

    /**
     * Rows that passed validation, in file order.
     *
     * @return list<array{row_number: int, values: array<string, mixed>}>
     */
    public function validRows(): array
    {
        return array_values(array_filter(
            $this->rows,
            fn (array $row) => $row['valid']
        ));
    }
}
