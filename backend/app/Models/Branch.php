<?php

namespace App\Models;

use App\Enums\BranchType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'type',
        'phone',
        'email',
        'address',
        'city',
        'province',
        'country',
        'postal_code',
        'timezone',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'type' => BranchType::class,
        ];
    }

    /**
     * Restrict to branches reachable by the given user.
     *
     * Falls back to company-level access when no direct branch grant exists,
     * which is how company-wide roles such as Owner behave.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $query) use ($user) {
            $query->whereIn('id', $user->branches()->select('branches.id'))
                ->orWhereIn('company_id', $user->companies()->select('companies.id'));
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    public function registers(): HasMany
    {
        return $this->hasMany(Register::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'branch_user');
    }
}
