<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'display_name',
        'guard_name',
        'description',
        'is_system',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }

    /**
     * Sync permissions by name, creating permission rows as needed.
     *
     * @param  list<string>  $permissionNames
     */
    public function syncPermissionNames(array $permissionNames): void
    {
        $ids = collect($permissionNames)
            ->filter()
            ->map(fn (string $name) => Permission::firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'group' => explode('.', $name)[0]]
            )->id)
            ->all();

        $this->permissions()->sync($ids);
    }
}
