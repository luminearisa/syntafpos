<?php

namespace App\Services\Export\Drivers;

use App\Services\Export\ExportDriver;
use App\Services\Export\Exporter;
use App\Support\Csv;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The CSV export driver.
 *
 * Rows are written to the output stream as the query yields them rather than
 * assembled into one string in memory, so a full stock movement history is a
 * constant-size stream instead of a payload the size of the ledger (§43).
 * A UTF-8 byte order mark heads the file so spreadsheet applications render
 * Indonesian text correctly, and quoting is left to fputcsv, which follows
 * RFC 4180.
 */
final class CsvExportDriver implements ExportDriver
{
    /**
     * Rows fetched per query page: large enough to amortise the round trip,
     * small enough that the page is never a meaningful share of memory.
     */
    public const PAGE_SIZE = 500;

    public function format(): string
    {
        return 'csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function response(Exporter $exporter, Request $request, int $companyId): Response
    {
        $query = $exporter->query($request, $companyId);

        $filename = sprintf(
            '%s-%s.%s',
            $exporter->entity(),
            now()->format('Y-m-d'),
            $this->extension()
        );

        return response()->streamDownload(
            function () use ($exporter, $request, $query): void {
                $stream = fopen('php://output', 'wb');

                Csv::writeBom($stream);
                Csv::writeHeader($stream, $exporter->columns());

                // Keyset pagination on the primary key: the page is fetched
                // with a where on the last id seen, so the stream walks the
                // whole table without an offset scan.
                $query->chunkById(self::PAGE_SIZE, function ($records) use ($exporter, $request, $stream): void {
                    foreach ($records as $record) {
                        Csv::writeRow($stream, $exporter->map($record, $request));
                    }
                });

                fclose($stream);
            },
            $filename,
            ['Content-Type' => $this->contentType()]
        );
    }
}
