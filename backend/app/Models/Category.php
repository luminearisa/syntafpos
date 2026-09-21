<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'parent_id',
        'code',
        'name',
        'description',
        'image',
        'level',
        'sort_order',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'sort_order' => 'integer',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /**
     * Is this category a descendant of the given category, i.e. does the
     * given category sit somewhere above it in the parent chain?
     *
     * Used to reject reparenting that would create a cycle: before moving
     * a category under a new parent, ensure the new parent is not one of
     * its own descendants (`$newParent->isDescendantOf($category)`).
     */
    public function isDescendantOf(self $candidate): bool
    {
        if ($this->is($candidate) || ! $this->parent_id) {
            return false;
        }

        $seen = [$this->id];
        $parent = $this->parent;

        while ($parent) {
            if ($parent->is($candidate)) {
                return true;
            }

            // Guards against a pre-existing cycle looping forever.
            if (in_array($parent->id, $seen, true)) {
                return false;
            }

            $seen[] = $parent->id;
            $parent = $parent->parent;
        }

        return false;
    }
}
