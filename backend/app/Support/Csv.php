<?php

namespace App\Support;

/**
 * RFC-4180 CSV reading and writing, shared by the import and export modules.
 *
 * The only place in the application that speaks fgetcsv/fputcsv: quoting, the
 * byte-order-mark and escape handling live here so a future Excel or PDF
 * driver composes the same primitives instead of re-deriving them.
 */
final class Csv
{
    /**
     * UTF-8 byte order mark.
     *
     * Written at the head of an export so spreadsheet applications detect the
     * encoding and render Indonesian text correctly instead of mojibake.
     */
    public const BOM = "\xEF\xBB\xBF";

    public const SEPARATOR = ',';

    public const ENCLOSURE = '"';

    /**
     * Empty escape: RFC 4180 doubles the enclosure inside a field rather than
     * backslash-escaping it, and an empty string selects that behaviour.
     */
    public const ESCAPE = '';

    public const EOL = "\r\n";

    /**
     * Read a CSV file into its header row and one associative array per line.
     *
     * Values are trimmed and blank lines are skipped, but the original line
     * number of every surviving row is kept so import errors point at the file
     * the user is looking at.
     */
    public static function read(string $path): CsvFile
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return new CsvFile([], [], ['The uploaded file could not be read.']);
        }

        try {
            $headers = null;
            $rows = [];
            $errors = [];
            $line = 0;

            while (($record = fgetcsv($handle, 0, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE)) !== false) {
                $line++;

                if ($headers === null) {
                    $headers = array_map(
                        static fn ($column): string => trim(self::stripBom((string) $column)),
                        $record
                    );

                    continue;
                }

                // fgetcsv returns a single empty field for a line that holds
                // nothing but the separator-terminator: not data, so it is not
                // reported as a row.
                if (count($record) === 1 && ($record[0] === '' || $record[0] === null)) {
                    continue;
                }

                $rows[] = ['line' => $line, 'data' => array_map(static fn ($value) => trim((string) $value), $record)];
            }

            if ($headers === null) {
                $errors[] = 'The file is empty: it has no header row.';
            }

            return new CsvFile($headers ?? [], $rows, $errors);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Write the byte order mark onto an open export stream.
     *
     * @param  resource  $stream
     */
    public static function writeBom($stream): void
    {
        fwrite($stream, self::BOM);
    }

    /**
     * Write a header row onto an open export stream.
     *
     * @param  resource  $stream
     * @param  list<string>  $headers
     */
    public static function writeHeader($stream, array $headers): void
    {
        self::write($stream, $headers);
    }

    /**
     * Write one data row onto an open export stream.
     *
     * Values are coerced to string once, here, rather than at the call site:
     * a null reaches the file as an empty field and a decimal string keeps its
     * exact digits, never the truncated float representation PHP would print.
     *
     * @param  resource  $stream
     * @param  list<mixed>  $values
     */
    public static function writeRow($stream, array $values): void
    {
        self::write($stream, array_map(
            static fn (mixed $value): string => match (true) {
                $value === null => '',
                is_bool($value) => $value ? '1' : '0',
                default => (string) $value,
            },
            $values
        ));
    }

    /**
     * @param  resource  $stream
     * @param  list<string>  $fields
     */
    private static function write($stream, array $fields): void
    {
        fputcsv($stream, $fields, self::SEPARATOR, self::ENCLOSURE, self::ESCAPE, self::EOL);
    }

    private static function stripBom(string $value): string
    {
        if (str_starts_with($value, self::BOM)) {
            return substr($value, strlen(self::BOM));
        }

        return $value;
    }
}
