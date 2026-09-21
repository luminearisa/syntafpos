<?php

namespace App\Providers;

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\GoodsReceipt;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductPrice;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\PurchaseReturn;
use App\Models\Register;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockOpname;
use App\Models\Supplier;
use App\Models\Tax;
use App\Models\Unit;
use App\Models\UnitConversion;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseTransfer;
use App\Policies\AttributePolicy;
use App\Policies\AttributeValuePolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\BranchPolicy;
use App\Policies\BrandPolicy;
use App\Policies\CategoryPolicy;
use App\Policies\CompanyPolicy;
use App\Policies\CustomerGroupPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\GoodsReceiptPolicy;
use App\Policies\PriceListPolicy;
use App\Policies\ProductBarcodePolicy;
use App\Policies\ProductPolicy;
use App\Policies\ProductPricePolicy;
use App\Policies\ProductVariantPolicy;
use App\Policies\PurchaseOrderPolicy;
use App\Policies\PurchaseRequestPolicy;
use App\Policies\PurchaseReturnPolicy;
use App\Policies\RegisterPolicy;
use App\Policies\RolePolicy;
use App\Policies\StockAdjustmentPolicy;
use App\Policies\StockOpnamePolicy;
use App\Policies\SupplierPolicy;
use App\Policies\TaxPolicy;
use App\Policies\UnitConversionPolicy;
use App\Policies\UnitPolicy;
use App\Policies\UserPolicy;
use App\Policies\WarehousePolicy;
use App\Policies\WarehouseTransferPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Company::class => CompanyPolicy::class,
        Branch::class => BranchPolicy::class,
        Warehouse::class => WarehousePolicy::class,
        Register::class => RegisterPolicy::class,
        User::class => UserPolicy::class,
        Role::class => RolePolicy::class,
        AuditLog::class => AuditLogPolicy::class,
        Category::class => CategoryPolicy::class,
        Brand::class => BrandPolicy::class,
        Unit::class => UnitPolicy::class,
        UnitConversion::class => UnitConversionPolicy::class,
        Tax::class => TaxPolicy::class,
        Attribute::class => AttributePolicy::class,
        AttributeValue::class => AttributeValuePolicy::class,
        Product::class => ProductPolicy::class,
        ProductVariant::class => ProductVariantPolicy::class,
        ProductBarcode::class => ProductBarcodePolicy::class,
        PriceList::class => PriceListPolicy::class,
        ProductPrice::class => ProductPricePolicy::class,
        Customer::class => CustomerPolicy::class,
        CustomerGroup::class => CustomerGroupPolicy::class,
        Supplier::class => SupplierPolicy::class,
        StockAdjustment::class => StockAdjustmentPolicy::class,
        StockOpname::class => StockOpnamePolicy::class,
        WarehouseTransfer::class => WarehouseTransferPolicy::class,
        PurchaseRequest::class => PurchaseRequestPolicy::class,
        PurchaseOrder::class => PurchaseOrderPolicy::class,
        GoodsReceipt::class => GoodsReceiptPolicy::class,
        PurchaseReturn::class => PurchaseReturnPolicy::class,
    ];

    public function boot(): void
    {
        $this->registerPolicies();
    }
}
