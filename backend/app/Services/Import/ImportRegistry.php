<?php

namespace App\Services\Import;

use App\Services\Import\Importers\BrandImporter;
use App\Services\Import\Importers\CategoryImporter;
use App\Services\Import\Importers\CustomerImporter;
use App\Services\Import\Importers\OpeningStockImporter;
use App\Services\Import\Importers\ProductImporter;
use App\Services\Import\Importers\SupplierImporter;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The import entities the application accepts, keyed by the request parameter.
 *
 * Importers are stateless services resolved from the container, so the map is
 * built once per request and the entity list is derived from it rather than
 * maintained alongside it.
 */
final class ImportRegistry
{
    /**
     * @var array<string, Importer>
     */
    private array $importers;

    public function __construct()
    {
        $this->importers = collect([
            ProductImporter::class,
            CategoryImporter::class,
            BrandImporter::class,
            CustomerImporter::class,
            SupplierImporter::class,
            OpeningStockImporter::class,
        ])
            ->mapWithKeys(fn (string $class): array => [
                app($class)->entity() => app($class),
            ])
            ->all();
    }

    public function importer(string $entity): Importer
    {
        $importer = Arr::get($this->importers, strtolower($entity));

        if (! $importer) {
            throw new NotFoundHttpException(
                'Unknown import entity: '.$entity.'. Supported entities are '.$this->supported().'.'
            );
        }

        return $importer;
    }

    /**
     * The entities a client may offer, for validation messages and docs.
     *
     * @return list<string>
     */
    public function entities(): array
    {
        return array_keys($this->importers);
    }

    private function supported(): string
    {
        return implode(', ', $this->entities());
    }
}
