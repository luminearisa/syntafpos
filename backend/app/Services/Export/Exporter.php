<?php

namespace App\Services\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * One exportable entity: the columns of its file, and the company-scoped query
 * that produces the rows.
 *
 * An exporter never decides how the rows are serialised — that is the driver's
 * job — so the same definition serves a CSV download today and an Excel or PDF
 * one later without this class changing.
 */
abstract class Exporter
{
    /**
     * Machine name matched against the {entity} route parameter.
     */
    abstract public function entity(): string;

    /**
     * Permission that governs reading this entity, from PermissionCatalogue.
     */
    abstract public function permission(): string;

    /**
     * Header labels of the file, in column order.
     *
     * @return list<string>
     */
    abstract public function columns(): array;

    /**
     * The rows to export: already scoped to the companies the user may see and
     * to the company the request resolved, and carrying the filters the client
     * asked for.
     *
     * @return Builder<Model>
     */
    abstract public function query(Request $request, int $companyId): Builder;

    /**
     * One record as field values, in the order columns() declares.
     *
     * Money and quantities are returned as decimal strings: a float here would
     * print the truncated binary representation into the file (§46).
     *
     * @return list<mixed>
     */
    abstract public function map(Model $record, Request $request): array;

    /**
     * The filters this export accepts, for a client's filter UI.
     *
     * @return list<string>
     */
    public function filters(): array
    {
        return [];
    }
}
