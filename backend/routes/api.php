<?php

use App\Http\Controllers\Api\V1\Attributes\AttributeController;
use App\Http\Controllers\Api\V1\AttributeValues\AttributeValueController;
use App\Http\Controllers\Api\V1\Audit\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\Branches\BranchController;
use App\Http\Controllers\Api\V1\Brands\BrandController;
use App\Http\Controllers\Api\V1\Categories\CategoryController;
use App\Http\Controllers\Api\V1\Companies\CompanyController;
use App\Http\Controllers\Api\V1\CustomerGroups\CustomerGroupController;
use App\Http\Controllers\Api\V1\Customers\CustomerController;
use App\Http\Controllers\Api\V1\Dashboard\DashboardController;
use App\Http\Controllers\Api\V1\GoodsReceipts\GoodsReceiptController;
use App\Http\Controllers\Api\V1\PriceLists\PriceListController;
use App\Http\Controllers\Api\V1\ProductBarcodes\ProductBarcodeController;
use App\Http\Controllers\Api\V1\ProductPrices\ProductPriceController;
use App\Http\Controllers\Api\V1\Products\ProductController;
use App\Http\Controllers\Api\V1\ProductVariants\ProductVariantController;
use App\Http\Controllers\Api\V1\PurchaseOrders\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseRequests\PurchaseRequestController;
use App\Http\Controllers\Api\V1\PurchaseReturns\PurchaseReturnController;
use App\Http\Controllers\Api\V1\Registers\RegisterController;
use App\Http\Controllers\Api\V1\Roles\PermissionController;
use App\Http\Controllers\Api\V1\Roles\RoleController;
use App\Http\Controllers\Api\V1\Settings\SettingsController;
use App\Http\Controllers\Api\V1\StockAdjustments\StockAdjustmentController;
use App\Http\Controllers\Api\V1\StockOpnames\StockOpnameController;
use App\Http\Controllers\Api\V1\Suppliers\SupplierController;
use App\Http\Controllers\Api\V1\Taxes\TaxController;
use App\Http\Controllers\Api\V1\UnitConversions\UnitConversionController;
use App\Http\Controllers\Api\V1\Units\UnitController;
use App\Http\Controllers\Api\V1\Users\UserController;
use App\Http\Controllers\Api\V1\Warehouses\WarehouseController;
use App\Http\Controllers\Api\V1\WarehouseTransfers\WarehouseTransferController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {

    // Authentication (throttled harder than other endpoints).
    Route::prefix('auth')->group(function () {
        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:6,1')->name('auth.login');
        Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])
            ->middleware('throttle:3,1')->name('auth.forgot-password');
        Route::post('reset-password', [ResetPasswordController::class, 'reset'])
            ->middleware('throttle:3,1')->name('auth.reset-password');

        Route::middleware('auth:sanctum')->group(function () {
            Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
            Route::get('me', [AuthController::class, 'me'])->name('auth.me');
            Route::put('password', [AuthController::class, 'changePassword'])->name('auth.password');
        });
    });

    Route::middleware('auth:sanctum')->group(function () {

        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard.index');

        Route::apiResource('companies', CompanyController::class);
        Route::apiResource('branches', BranchController::class);
        Route::apiResource('warehouses', WarehouseController::class);
        Route::apiResource('registers', RegisterController::class);
        Route::apiResource('users', UserController::class);
        Route::apiResource('roles', RoleController::class);
        Route::apiResource('permissions', PermissionController::class)
            ->only(['index', 'show']);

        // Catalog master data.
        Route::apiResource('categories', CategoryController::class);
        Route::apiResource('brands', BrandController::class);
        Route::apiResource('units', UnitController::class);
        // A read-only computation, so it rides the show route of a unit.
        Route::get('units/{unit}/convert', [UnitController::class, 'convert'])->name('units.convert');
        Route::apiResource('unit-conversions', UnitConversionController::class);
        Route::apiResource('taxes', TaxController::class);
        Route::apiResource('attributes', AttributeController::class);
        Route::apiResource('attribute-values', AttributeValueController::class);

        // Products, variants, barcodes and pricing.
        // The lookup route is declared first: inside a resource it would be
        // swallowed by the {product} show parameter.
        Route::get('products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
        Route::apiResource('products', ProductController::class);
        Route::apiResource('product-variants', ProductVariantController::class);
        Route::apiResource('product-barcodes', ProductBarcodeController::class);
        Route::apiResource('price-lists', PriceListController::class);
        Route::apiResource('product-prices', ProductPriceController::class);

        // Parties: customers, their light grouping, and suppliers.
        // The summary is a computed read, so it rides the supplier resource.
        Route::apiResource('customer-groups', CustomerGroupController::class);
        Route::apiResource('customers', CustomerController::class);
        Route::apiResource('suppliers', SupplierController::class);
        Route::get('suppliers/{supplier}/summary', [SupplierController::class, 'summary'])->name('suppliers.summary');

        // Stock operations: adjustments, stock takes and warehouse transfers.
        // Each carries its own state machine, so the transitions are explicit
        // endpoints alongside the resource rather than status fields on a PUT.
        Route::apiResource('stock-adjustments', StockAdjustmentController::class);
        Route::post('stock-adjustments/{stock_adjustment}/submit', [StockAdjustmentController::class, 'submit'])->name('stock-adjustments.submit');
        Route::post('stock-adjustments/{stock_adjustment}/approve', [StockAdjustmentController::class, 'approve'])->name('stock-adjustments.approve');
        Route::post('stock-adjustments/{stock_adjustment}/post', [StockAdjustmentController::class, 'post'])->name('stock-adjustments.post');

        Route::apiResource('stock-opnames', StockOpnameController::class);
        Route::post('stock-opnames/{stock_opname}/count', [StockOpnameController::class, 'startCounting'])->name('stock-opnames.count');
        Route::post('stock-opnames/{stock_opname}/review', [StockOpnameController::class, 'sendToReview'])->name('stock-opnames.review');
        Route::post('stock-opnames/{stock_opname}/approve', [StockOpnameController::class, 'approve'])->name('stock-opnames.approve');
        Route::post('stock-opnames/{stock_opname}/post', [StockOpnameController::class, 'post'])->name('stock-opnames.post');

        Route::apiResource('warehouse-transfers', WarehouseTransferController::class);
        Route::post('warehouse-transfers/{warehouse_transfer}/submit', [WarehouseTransferController::class, 'submit'])->name('warehouse-transfers.submit');
        Route::post('warehouse-transfers/{warehouse_transfer}/approve', [WarehouseTransferController::class, 'approve'])->name('warehouse-transfers.approve');
        Route::post('warehouse-transfers/{warehouse_transfer}/ship', [WarehouseTransferController::class, 'ship'])->name('warehouse-transfers.ship');
        Route::post('warehouse-transfers/{warehouse_transfer}/receive', [WarehouseTransferController::class, 'receive'])->name('warehouse-transfers.receive');
        Route::post('warehouse-transfers/{warehouse_transfer}/complete', [WarehouseTransferController::class, 'complete'])->name('warehouse-transfers.complete');
        Route::post('warehouse-transfers/{warehouse_transfer}/cancel', [WarehouseTransferController::class, 'cancel'])->name('warehouse-transfers.cancel');

        // Purchasing: requests and orders each carry their own state machine,
        // so the transitions are explicit endpoints alongside the resource.
        Route::apiResource('purchase-requests', PurchaseRequestController::class);
        Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestController::class, 'submit'])->name('purchase-requests.submit');
        Route::post('purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])->name('purchase-requests.approve');
        Route::post('purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])->name('purchase-requests.reject');
        Route::post('purchase-requests/{purchase_request}/convert', [PurchaseRequestController::class, 'convert'])->name('purchase-requests.convert');

        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        Route::post('purchase-orders/{purchase_order}/submit', [PurchaseOrderController::class, 'submit'])->name('purchase-orders.submit');
        Route::post('purchase-orders/{purchase_order}/approve', [PurchaseOrderController::class, 'approve'])->name('purchase-orders.approve');
        Route::post('purchase-orders/{purchase_order}/send', [PurchaseOrderController::class, 'send'])->name('purchase-orders.send');
        Route::post('purchase-orders/{purchase_order}/close', [PurchaseOrderController::class, 'close'])->name('purchase-orders.close');
        Route::post('purchase-orders/{purchase_order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');

        // Receiving and returns post stock through the inventory engine, so
        // their state machine is likewise explicit alongside the resource.
        Route::apiResource('goods-receipts', GoodsReceiptController::class);
        Route::post('goods-receipts/{goods_receipt}/post', [GoodsReceiptController::class, 'post'])->name('goods-receipts.post');

        Route::apiResource('purchase-returns', PurchaseReturnController::class);
        Route::post('purchase-returns/{purchase_return}/post', [PurchaseReturnController::class, 'post'])->name('purchase-returns.post');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        // Reports are read-only aggregations over the ledger and purchasing
        // documents, so they live outside the apiResource shape: each is its
        // own endpoint under a dedicated prefix.
        require __DIR__.'/reports-inventory.php';
        require __DIR__.'/reports-purchasing.php';
        require __DIR__.'/reports-suppliers.php';
        require __DIR__.'/reports-products.php';

        // Bulk import is a two-step preview-then-commit flow, so it also sits
        // apart from the resource controllers.
        require __DIR__.'/imports.php';
        require __DIR__.'/exports.php';

        // Phase 3.1 — the point-of-sale till: product search, the working cart
        // and its held drafts.
        require __DIR__.'/pos.php';

        // Phase 3.2 — the transactions that till produces: sales, payments and
        // the invoices printed from them.
        require __DIR__.'/sales.php';

        // Phase 3.3 — the ways a shop agrees to be paid, and the defaults a till
        // falls back on before anyone has configured them.
        require __DIR__.'/payment-methods.php';

        Route::prefix('settings')->group(function () {
            Route::get('/', [SettingsController::class, 'index'])->name('settings.index');
            Route::get('{key}', [SettingsController::class, 'show'])->name('settings.show');
            Route::put('/', [SettingsController::class, 'update'])->name('settings.update');
        });
    });
});
