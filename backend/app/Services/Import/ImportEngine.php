<?php

namespace App\Services\Import;

use App\Support\Csv;
use App\Support\CsvFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * The import engine: parse a file once, validate every row, and either report
 * the outcome or commit it.
 *
 * Preview and commit both run through analyze(), so a commit is validated on
 * the server against the file the client actually sent — never against a
 * preview payload the client could have edited. The controller opens the
 * transaction before commit; this class never writes.
 */
final class ImportEngine
{
    public function __construct(private ImportRegistry $registry) {}

    /**
     * Parse and validate a whole file without writing anything.
     */
    public function analyze(string $entity, UploadedFile $file, ImportContext $context): ImportResult
    {
        $importer = $this->registry->importer($entity);

        $csv = Csv::read($file->getRealPath());

        if ($csv->errors) {
            return $this->fail($csv);
        }

        $missing = array_diff($importer->requiredColumns(), $csv->headers);

        if ($missing !== []) {
            return new ImportResult([], [
                'file' => ['The file is missing required columns: '.implode(', ', $missing).'.'],
            ]);
        }

        $rows = [];
        $knownKeys = array_flip(array_merge($importer->requiredColumns(), $importer->optionalColumns()));

        foreach ($csv->rows as $index => $record) {
            // Unknown columns are ignored; a short row's missing trailing
            // fields are read as blank rather than shifting under a neighbour.
            $known = array_intersect_key($csv->combine($index), $knownKeys);

            [$values, $errors] = $importer->validateRow($known, $context);

            $rows[] = [
                'row_number' => $csv->lineOf($index),
                'values' => $values,
                'valid' => $errors === [],
                'errors' => $errors,
            ];
        }

        return new ImportResult($rows);
    }

    /**
     * Persist every validated row of a file, inside the caller's transaction.
     *
     * Rows that failed validation are never written: the controller refuses to
     * open the transaction unless analysis was clean, and this method refuses
     * to continue past a row it cannot write (§44).
     *
     * @return list<Model>
     */
    public function commit(string $entity, UploadedFile $file, ImportContext $context): array
    {
        $importer = $this->registry->importer($entity);
        $result = $this->analyze($entity, $file, $context);

        $invalid = array_filter($result->rows, fn (array $row): bool => ! $row['valid']);

        if ($invalid !== [] || $result->fileErrors !== []) {
            // Nothing has been written yet and nothing below will be.
            throw $this->unacceptable($result);
        }

        $written = [];

        foreach ($result->validRows() as $row) {
            $written[] = $importer->commitRow($row['values'], $context);
        }

        return $written;
    }

    /**
     * Report a rejected file as a validation failure, so the client renders it
     * with the same shape it renders any other field error.
     */
    private function unacceptable(ImportResult $result): ValidationException
    {
        $messages = [];

        foreach ($result->fileErrors as $field => $fieldErrors) {
            foreach ($fieldErrors as $fieldError) {
                $messages[$field][] = $fieldError;
            }
        }

        foreach ($result->errorMap() as $rowNumber => $rowErrors) {
            foreach ($rowErrors as $field => $fieldErrors) {
                foreach ($fieldErrors as $fieldError) {
                    $messages["rows.{$rowNumber}.{$field}"][] = $fieldError;
                }
            }
        }

        return ValidationException::withMessages($messages);
    }

    private function fail(CsvFile $csv): ImportResult
    {
        return new ImportResult([], ['file' => $csv->errors]);
    }
}
