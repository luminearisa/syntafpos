<?php

namespace App\Services\Export;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * How an export is serialised and delivered.
 *
 * CSV is implemented today; an Excel or PDF export is a new class implementing
 * this interface and registered in ExportRegistry, with no change to any
 * exporter or to the controller.
 */
interface ExportDriver
{
    /**
     * Machine name matched against the request's format parameter.
     */
    public function format(): string;

    /**
     * Extension of the downloaded filename.
     */
    public function extension(): string;

    /**
     * Content type of the response body.
     */
    public function contentType(): string;

    /**
     * Build the response that streams the export to the client.
     */
    public function response(Exporter $exporter, Request $request, int $companyId): Response;
}
