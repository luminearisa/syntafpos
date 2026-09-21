<?php

namespace App\Services\Import;

/**
 * The business scope one import runs in.
 *
 * Every row of every entity is written inside this company, and the keys that
 * must be unique within an import are claimed here so a duplicate in the file
 * is caught before anything is written rather than by a constraint error.
 */
final class ImportContext
{
    /**
     * Keys already taken by an earlier row, grouped by the scope they are
     * unique within: 'sku', 'code', 'email', a stock cell...
     *
     * @var array<string, list<string>>
     */
    private array $claimed = [];

    public function __construct(
        public readonly int $companyId,
        public readonly ?int $userId = null,
    ) {}

    /**
     * Reserve a key for this import.
     *
     * Returns false when an earlier row already claimed it, which is how a
     * duplicate inside the file is reported instead of a database conflict.
     */
    public function claim(string $scope, string $key): bool
    {
        $claimed = $this->claimed[$scope] ?? [];

        if (in_array($key, $claimed, true)) {
            return false;
        }

        $claimed[] = $key;
        $this->claimed[$scope] = $claimed;

        return true;
    }
}
