<?php

namespace App\Http\Requests\Import;

use App\Services\Import\ImportRegistry;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The payload both phases of the import flow accept: the entity to import into
 * and the file to read.
 *
 * The file is never stored: it is read from the request's temp path and PHP
 * discards it when the request ends, so an import leaves nothing on disk.
 */
class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(ImportRegistry $registry): array
    {
        return [
            'entity' => ['required', 'string', Rule::in($registry->entities())],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'file' => [
                'required',
                'file',
                'max:5120',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $this->guardCsv($value, $fail);
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'entity.in' => 'Unsupported import entity.',
            'file.max' => 'The import file may not be larger than 5 MB.',
        ];
    }

    /**
     * Reject anything that is not CSV by extension or by content type: an
     * upload of another shape has no rows to validate and no business being
     * inside the import path.
     */
    private function guardCsv(mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        if (strtolower($value->getClientOriginalExtension()) !== 'csv') {
            $fail('The file must have a .csv extension.');

            return;
        }

        $mimeType = strtolower((string) $value->getMimeType());

        // 'text/plain' is what a plain-text CSV is detected as; the remaining
        // entries are the labels browsers and spreadsheet applications attach
        // to the same content.
        $acceptable = [
            'text/csv',
            'text/plain',
            'application/csv',
            'text/comma-separated-values',
            'application/vnd.ms-excel',
        ];

        if (! in_array($mimeType, $acceptable, true)) {
            $fail('The file must be CSV content; a '.$mimeType.' upload cannot be imported.');
        }
    }
}
