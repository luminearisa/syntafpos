<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => $this->avatar,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            'companies' => CompanyResource::collection($this->whenLoaded('companies')),
            'branches' => BranchResource::collection($this->whenLoaded('branches')),
            'warehouses' => WarehouseResource::collection($this->whenLoaded('warehouses')),
            'registers' => RegisterResource::collection($this->whenLoaded('registers')),
            'roles' => RoleResource::collection($this->whenLoaded('roles')),

            'permissions' => $this->when(
                ! is_null($this->resource->permissions ?? null),
                fn () => $this->resource->permissions
            ),
        ];
    }
}
