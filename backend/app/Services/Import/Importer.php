<?php

namespace App\Services\Import;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One entity in the two-phase import flow.
 *
 * An implementation declares the columns a file must carry, then validates and
 * normalises each row and persists the rows that survived. The engine calls
 * those methods in the same order for preview and for commit, so nothing is
 * written on the strength of a client's copy of the preview.
 */
abstract class Importer
{
    /**
     * Cache of code-to-id resolution, so a repeated code is one query, not one
     * per row that mentions it.
     *
     * @var array<string, array<string, ?int>>
     */
    protected array $codeCache = [];

    /**
     * Machine name matched against the request's entity parameter.
     */
    abstract public function entity(): string;

    /**
     * Permission that governs creating this entity, from PermissionCatalogue.
     */
    abstract public function permission(): string;

    /**
     * Columns without which a row cannot be validated at all.
     *
     * @return list<string>
     */
    abstract public function requiredColumns(): array;

    /**
     * Columns a file may carry but does not have to.
     *
     * @return list<string>
     */
    public function optionalColumns(): array
    {
        return [];
    }

    /**
     * Validate one row and normalise it into the values commitRow consumes.
     *
     * @param  array<string, string>  $row  Known columns only, trimmed.
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} Normalised values, then field to messages.
     */
    abstract public function validateRow(array $row, ImportContext $context): array;

    /**
     * Persist one validated row. Runs inside the engine's transaction.
     */
    abstract public function commitRow(array $row, ImportContext $context): Model;

    /*
     * Shared building blocks for the per-entity implementations.
     */

    /**
     * Read a column as null when it is absent or blank.
     */
    protected function value(array $row, string $column): ?string
    {
        $value = trim((string) ($row[$column] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    protected function addError(array &$errors, string $column, string $message): void
    {
        $errors[$column][] = $message;
    }

    /**
     * Required text value, failing in place when it is missing.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function requireValue(array $row, string $column, array &$errors): ?string
    {
        $value = $this->value($row, $column);

        if ($value === null) {
            $this->addError($errors, $column, "The {$column} field is required.");

            return null;
        }

        return $value;
    }

    /**
     * Enforce a value's uniqueness inside the file and inside the company.
     *
     * Either failure records an error; the value is returned only when it is
     * genuinely free, so a duplicate cannot reach the insert. Pass required
     * false for a key the numbering engine supplies when the column is blank.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function uniqueValue(array $row, string $column, ImportContext $context, string $scope, string $table, string $keyColumn, array &$errors, bool $required = true): ?string
    {
        $value = $this->value($row, $column);

        if ($value === null) {
            if ($required) {
                $this->addError($errors, $column, "The {$column} field is required.");
            }

            return null;
        }

        if (! $context->claim($scope, $value)) {
            $this->addError($errors, $column, "The {$scope} '{$value}' is repeated in this file.");

            return null;
        }

        $exists = DB::table($table)
            ->where('company_id', $context->companyId)
            ->where($keyColumn, $value)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            $this->addError($errors, $column, "The {$scope} '{$value}' is already used in this company.");

            return null;
        }

        return $value;
    }

    /**
     * Resolve a code to an id within the importing company, or fail.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function resolveByCode(array $row, string $column, ImportContext $context, string $table, string $label, array &$errors): ?int
    {
        $code = $this->value($row, $column);

        if ($code === null) {
            return null;
        }

        if (! array_key_exists($code, $this->codeCache[$table] ?? [])) {
            $this->codeCache[$table][$code] = DB::table($table)
                ->where('company_id', $context->companyId)
                ->where('code', $code)
                ->value('id');
        }

        $id = $this->codeCache[$table][$code];

        if ($id === null) {
            $this->addError($errors, $column, "No {$label} with the code '{$code}' exists in this company.");

            return null;
        }

        return (int) $id;
    }

    /**
     * Confirm a raw id belongs to the importing company.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function resolveId(array $row, string $column, ImportContext $context, string $table, string $label, array &$errors): ?int
    {
        $value = $this->value($row, $column);

        if ($value === null) {
            return null;
        }

        if (! preg_match('/^\d+$/', $value)) {
            $this->addError($errors, $column, "The {$column} must be a numeric id or omitted.");

            return null;
        }

        $id = (int) $value;

        $exists = DB::table($table)
            ->where('company_id', $context->companyId)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->exists();

        if (! $exists) {
            $this->addError($errors, $column, "No {$label} with the id {$id} exists in this company.");

            return null;
        }

        return $id;
    }

    /**
     * Validate a money or quantity field as an exact decimal string.
     *
     * §46: the value is never materialised as a float, so a price that cannot
     * be represented in binary still arrives at the column intact.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function decimal(array $row, string $column, array &$errors, bool $required = false): ?string
    {
        $value = $this->value($row, $column);

        if ($value === null) {
            if ($required) {
                $this->addError($errors, $column, "The {$column} must be a decimal number.");
            }

            return null;
        }

        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            $this->addError($errors, $column, "The {$column} must be a non-negative decimal number.");

            return null;
        }

        return $value;
    }

    /**
     * Validate a quantity that must be strictly greater than zero.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function positiveDecimal(array $row, string $column, array &$errors, bool $required = true): ?string
    {
        $value = $this->decimal($row, $column, $errors, $required);

        if ($value === null) {
            return null;
        }

        if (bccomp($value, '0', 6) <= 0) {
            $this->addError($errors, $column, "The {$column} must be greater than zero.");

            return null;
        }

        return $value;
    }

    /**
     * Validate a non-negative whole number, such as a term or a sort order.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function nonNegativeInteger(array $row, string $column, array &$errors): ?int
    {
        $value = $this->value($row, $column);

        if ($value === null) {
            return null;
        }

        if (! preg_match('/^\d+$/', $value)) {
            $this->addError($errors, $column, "The {$column} must be a non-negative whole number.");

            return null;
        }

        return (int) $value;
    }

    /**
     * Validate an email address and its uniqueness inside the file and company.
     *
     * Compared case-insensitively: "Jane@Example.com" and "jane@example.com"
     * are the same mailbox, and a file that carries both must be rejected.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function uniqueEmail(array $row, string $column, ImportContext $context, string $table, array &$errors): ?string
    {
        $email = $this->value($row, $column);

        if ($email === null) {
            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError($errors, $column, "The {$column} must be a valid email address.");

            return null;
        }

        $normalised = strtolower($email);

        if (! $context->claim($column, $normalised)) {
            $this->addError($errors, $column, "The {$column} is repeated in this file.");

            return null;
        }

        $exists = DB::table($table)
            ->where('company_id', $context->companyId)
            ->whereRaw('lower(email) = ?', [$normalised])
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            $this->addError($errors, $column, "The {$email} is already used in this company.");

            return null;
        }

        return $email;
    }

    /**
     * Read a boolean column as a flag, rejecting anything ambiguous.
     *
     * @param  array<string, list<string>>  $errors
     */
    protected function flag(array $row, string $column, array &$errors, bool $default = true): bool
    {
        $value = $this->value($row, $column);

        if ($value === null) {
            return $default;
        }

        $normalised = strtolower($value);

        if (in_array($normalised, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($normalised, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        $this->addError($errors, $column, "The {$column} must be one of 1, 0, true or false.");

        return $default;
    }

    /**
     * Validate a value against a string-backed enum.
     *
     * @param  array<string, list<string>>  $errors
     * @param  class-string<\BackedEnum>  $enumClass
     */
    protected function enum(array $row, string $column, array &$errors, string $enumClass, ?\BackedEnum $default = null): ?\BackedEnum
    {
        $value = $this->value($row, $column);

        if ($value === null) {
            return $default;
        }

        $case = $enumClass::tryFrom($value);

        if ($case === null) {
            $this->addError($errors, $column, 'The '.$column.' must be one of: '
                .implode(', ', array_map(static fn (\BackedEnum $case): string => (string) $case->value, $enumClass::cases())).'.');

            return $default;
        }

        return $case;
    }

    /**
     * The (values, errors) pair every validateRow returns.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, list<string>>  $errors
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    protected function row(array $values, array $errors = []): array
    {
        return [$values, $errors];
    }
}
