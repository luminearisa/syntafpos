<?php

namespace App\Models;

use App\Enums\LocationType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseLocation extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'warehouse_id',
        'parent_id',
        'code',
        'name',
        'type',
        'level',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'level' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(WarehouseLocation::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(WarehouseLocation::class, 'parent_id');
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class, 'location_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'location_id');
    }

    /**
     * Is this location nested somewhere beneath the given candidate?
     *
     * Walks the parent chain to guard re-parenting: a location can never be
     * moved under one of its own descendants, which would create a cycle.
     */
    public function isDescendantOf(self $candidate): bool
    {
        $candidateId = (int) $candidate->id;
        $parentId = $this->parent_id;

        while ($parentId !== null) {
            if ((int) $parentId === $candidateId) {
                return true;
            }

            // Trashed ancestors still anchor the hierarchy, so they count.
            $parentId = static::query()
                ->withTrashed()
                ->where('id', $parentId)
                ->value('parent_id');
        }

        return false;
    }
}
