<?php

namespace App\Models;

use App\Enums\UnitType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'code',
        'type',
        'precision',
        'is_base',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => UnitType::class,
            'precision' => 'integer',
            'is_base' => 'boolean',
        ];
    }

    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('company_id', $user->companies()->select('companies.id'));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function conversionsFrom(): HasMany
    {
        return $this->hasMany(UnitConversion::class, 'from_unit_id');
    }

    public function conversionsTo(): HasMany
    {
        return $this->hasMany(UnitConversion::class, 'to_unit_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'default_unit_id');
    }

    /**
     * The directed conversion row from this unit to the target, if any.
     *
     * Conversions are one-directional by design: converting a carton to pieces
     * uses the carton->pieces row, and pieces->carton is a separate row so the
     * reciprocal factor stays explicit instead of being derived by division.
     */
    public function conversionTo(self $target): ?UnitConversion
    {
        return $this->conversionsFrom()
            ->where('to_unit_id', $target->id)
            ->first();
    }
}
