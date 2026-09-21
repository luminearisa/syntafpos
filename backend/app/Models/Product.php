<?php

namespace App\Models;

use App\Enums\ProductType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id', 'category_id', 'brand_id', 'default_unit_id', 'tax_id',
        'sku', 'barcode', 'name', 'description', 'image', 'product_type',
        'track_inventory', 'allow_negative_stock', 'is_sellable', 'is_purchasable',
        'is_active', 'cost_price', 'selling_price', 'minimum_selling_price',
        'weight', 'length', 'width', 'height',
        'minimum_stock', 'maximum_stock', 'reorder_point', 'reorder_quantity',
    ];

    protected function casts(): array
    {
        return [
            'product_type' => ProductType::class,
            'track_inventory' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'is_sellable' => 'boolean',
            'is_purchasable' => 'boolean',
            'is_active' => 'boolean',
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:4',
            'minimum_selling_price' => 'decimal:4',
            'weight' => 'decimal:4',
            'length' => 'decimal:4',
            'width' => 'decimal:4',
            'height' => 'decimal:4',
            'minimum_stock' => 'decimal:6',
            'maximum_stock' => 'decimal:6',
            'reorder_point' => 'decimal:6',
            'reorder_quantity' => 'decimal:6',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function defaultUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'default_unit_id');
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function attributeValues(): BelongsToMany
    {
        // Attributes are attached at the variant level; this convenience
        // relation exists for eager-loading a product's full option set.
        return $this->belongsToMany(
            AttributeValue::class,
            'product_variant_attribute_values',
            'product_variant_id',
            'attribute_value_id'
        );
    }
}
