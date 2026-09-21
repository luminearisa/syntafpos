<?php

namespace App\Support;

/**
 * One parsed CSV file: its header row, its data rows and any structural error
 * that stopped it from being read at all.
 *
 * Rows keep the line they came from. Import errors are reported against that
 * number, so a user correcting a file lands on the right row.
 *
 * @see Csv::read()
 */
final class CsvFile
{
    /**
     * @param  list<string>  $headers  Header labels, BOM stripped, in file order.
     * @param  list<array{line: int, data: list<string>}>  $rows  Positional field values per data row.
     * @param  list<string>  $errors  Structural problems that prevent importing.
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly array $errors = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->headers === [] || $this->rows === [];
    }

    /**
     * Turn one positional row into an associative array keyed by header.
     *
     * Fields beyond the header are dropped and missing trailing fields become
     * empty strings rather than raising a size mismatch, so a ragged row is a
     * validation problem instead of a crash.
     */
    public function combine(int $index): array
    {
        $row = $this->rows[$index];

        return array_combine(
            $this->headers,
            array_pad(
                array_slice($row['data'], 0, count($this->headers)),
                count($this->headers),
                ''
            )
        );
    }

    /**
     * The line number a data row came from.
     */
    public function lineOf(int $index): int
    {
        return $this->rows[$index]['line'];
    }
}
