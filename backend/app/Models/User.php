<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'status',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'last_login_at' => 'datetime',
        ];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_user');
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_user');
    }

    public function registers(): BelongsToMany
    {
        return $this->belongsToMany(Register::class, 'register_user');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }

    /**
     * Resolve the effective role set for the given company scope.
     * Company-scoped roles take priority, then fall back to global roles.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function rolesFor(?int $companyId): BelongsToMany
    {
        return $this->roles()
            ->where(fn ($q) => $q->where('company_id', $companyId)->orWhereNull('company_id'));
    }

    /**
     * Permission names granted to this user within the given company scope.
     *
     * @return list<string>
     */
    public function permissionNames(?int $companyId = null): array
    {
        return cache()->remember(
            $this->permissionCacheKey($companyId),
            now()->addMinutes(5),
            fn () => $this->rolesFor($companyId)
                ->with('permissions:name')
                ->get()
                ->pluck('permissions')
                ->flatten()
                ->pluck('name')
                ->unique()
                ->values()
                ->all()
        );
    }

    public function hasPermission(string $permission, ?int $companyId = null): bool
    {
        return in_array($permission, $this->permissionNames($companyId), true);
    }

    public function clearPermissionCache(?int $companyId = null): void
    {
        cache()->forget($this->permissionCacheKey($companyId));
        cache()->forget($this->permissionCacheKey(null));
    }

    private function permissionCacheKey(?int $companyId): string
    {
        return 'user.permissions.'.$this->id.'.'.($companyId ?? 'global');
    }
}
