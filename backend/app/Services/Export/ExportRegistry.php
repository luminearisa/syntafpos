<?php

namespace App\Services\Export;

use App\Services\Export\Drivers\CsvExportDriver;
use App\Services\Export\Exporters\CustomerExporter;
use App\Services\Export\Exporters\GoodsReceiptExporter;
use App\Services\Export\Exporters\ProductExporter;
use App\Services\Export\Exporters\PurchaseOrderExporter;
use App\Services\Export\Exporters\StockExporter;
use App\Services\Export\Exporters\StockMovementExporter;
use App\Services\Export\Exporters\SupplierExporter;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The export entities and the formats they can be delivered in.
 *
 * Both maps are keyed by the name a client asks for and built from the classes
 * themselves, so an added exporter or driver cannot fall out of sync with the
 * list the registry reports.
 */
final class ExportRegistry
{
    /**
     * @var array<string, Exporter>
     */
    private array $exporters;

    /**
     * @var array<string, ExportDriver>
     */
    private array $drivers;

    public function __construct()
    {
        $this->exporters = collect([
            ProductExporter::class,
            StockExporter::class,
            CustomerExporter::class,
            SupplierExporter::class,
            PurchaseOrderExporter::class,
            GoodsReceiptExporter::class,
            StockMovementExporter::class,
        ])
            ->mapWithKeys(fn (string $class): array => [
                app($class)->entity() => app($class),
            ])
            ->all();

        $this->drivers = collect([
            CsvExportDriver::class,
        ])
            ->mapWithKeys(fn (string $class): array => [
                app($class)->format() => app($class),
            ])
            ->all();
    }

    public function exporter(string $entity): Exporter
    {
        $exporter = Arr::get($this->exporters, strtolower($entity));

        if (! $exporter) {
            throw new NotFoundHttpException(
                'Unknown export entity: '.$entity.'. Supported entities are '.$this->supportedEntities().'.'
            );
        }

        return $exporter;
    }

    public function driver(?string $format): ExportDriver
    {
        $format = strtolower((string) $format);

        $driver = Arr::get($this->drivers, $format !== '' ? $format : 'csv');

        if (! $driver) {
            throw new NotFoundHttpException(
                'Unsupported export format: '.$format.'. Supported formats are '.$this->supportedFormats().'.'
            );
        }

        return $driver;
    }

    /**
     * @return list<string>
     */
    public function entities(): array
    {
        return array_keys($this->exporters);
    }

    private function supportedEntities(): string
    {
        return implode(', ', $this->entities());
    }

    private function supportedFormats(): string
    {
        return implode(', ', array_keys($this->drivers));
    }
}
