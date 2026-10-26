<?php

use App\Http\Controllers\AccountSecurityController;
use App\Http\Controllers\Auth\StaffAuthController;
use App\Http\Controllers\CatalogAdminController;
use App\Http\Controllers\DiscountApprovalController;
use App\Http\Controllers\LeaveApprovalController;
use App\Http\Controllers\OvertimeApprovalController;
use App\Http\Controllers\OwnLeaveController;
use App\Http\Controllers\OwnOvertimeController;
use App\Http\Controllers\OwnProfileController;
use App\Http\Controllers\OwnScheduleController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductDiscountController;
use App\Http\Controllers\ProductInformationController;
use App\Http\Controllers\ProductLabelController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\PublicProductCodeController;
use App\Http\Controllers\WorkspaceController;
use App\Support\IsolatedDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Reference\OriginalComparisonReference;
use Tests\Reference\OriginalLandingReference;
use Tests\Reference\OriginalLoginReference;
use Tests\Reference\OriginalMfaReference;
use Tests\Reference\OriginalProductReference;

Route::pattern('id', '[1-9][0-9]{0,9}');

if (app()->environment('testing')) {
    Route::get('/__qa/original-landing', [OriginalLandingReference::class, 'render']);
    Route::get('/__qa/original-login', [OriginalLoginReference::class, 'render']);
    Route::get('/__qa/original-product/{id}', [OriginalProductReference::class, 'render']);
    Route::get('/__qa/original-comparison', [OriginalComparisonReference::class, 'render']);
    Route::get('/__qa/original-mfa', [OriginalMfaReference::class, 'render']);
    Route::get('/__qa/target', function () {
        IsolatedDatabase::verify();
        $db = DB::connection('pos');

        return ['isolated' => true, 'mail_isolated' => config('mail.default') === 'array', 'sales' => $db->table('sales')->count(), 'stock_logs' => $db->table('stock_logs')->count(), 'demo_stock' => (int) $db->table('products')->where('barcode', 'SYNTHETIC-DEMO-001')->value('stock')];
    });
}

Route::get('/', [PublicController::class, 'index'])->middleware('throttle:120,1')->name('home');
Route::get('/index', fn () => redirect()->route('home'));
Route::get('/catalog', [PublicController::class, 'catalog'])->middleware('throttle:120,1')->name('catalog');
Route::get('/catalog/{id}', [PublicController::class, 'product'])->middleware('throttle:120,1')->name('product');
Route::get('/product-image/{id}', [PublicController::class, 'image'])->name('product.image');
Route::get('/compare', [PublicController::class, 'compare'])->middleware('throttle:120,1')->name('compare');
Route::get('/catalog/scan', PublicProductCodeController::class)->middleware('throttle:60,1')->name('catalog.scan');
Route::get('/login', [StaffAuthController::class, 'login'])->name('login');
Route::post('/login', [StaffAuthController::class, 'authenticate'])->name('login.submit');
Route::get('/two-factor', [StaffAuthController::class, 'challenge'])->name('mfa');
Route::post('/two-factor/send', [StaffAuthController::class, 'send'])->middleware('throttle:10,1')->name('mfa.send');
Route::post('/two-factor', [StaffAuthController::class, 'verify'])->name('mfa.verify');
Route::post('/logout', [StaffAuthController::class, 'logout'])->name('logout');
Route::middleware('staff')->group(function (): void {
    Route::get('/overtime-approvals', [OvertimeApprovalController::class, 'index'])->middleware('role:admin,supervisor')->name('overtime-approvals.index');
    Route::post('/overtime-approvals/{id}/decision', [OvertimeApprovalController::class, 'decide'])->middleware('role:admin,supervisor')->name('overtime-approvals.decide');
    Route::get('/account/overtime', [OwnOvertimeController::class, 'index'])->name('overtime.index');
    Route::post('/account/overtime', [OwnOvertimeController::class, 'store'])->name('overtime.store');
    Route::get('/leave-approvals', [LeaveApprovalController::class, 'index'])->middleware('role:admin,hr,hr_manager,supervisor')->name('leave-approvals.index');
    Route::post('/leave-approvals/{id}/decision', [LeaveApprovalController::class, 'decide'])->middleware('role:admin,hr,hr_manager,supervisor')->name('leave-approvals.decide');
    Route::get('/account/leave', [OwnLeaveController::class, 'index'])->name('leave.index');
    Route::post('/account/leave', [OwnLeaveController::class, 'store'])->name('leave.store');
    Route::get('/account/schedule', OwnScheduleController::class)->name('schedule.index');
    Route::get('/account/profile', [OwnProfileController::class, 'edit'])->name('profile.edit');
    Route::post('/account/profile', [OwnProfileController::class, 'update'])->name('profile.update');
    Route::get('/manage/products/{id}/information', [ProductInformationController::class, 'edit'])->middleware('role:admin,inventory_staff')->name('product-information.edit');
    Route::post('/manage/products/{id}/information', [ProductInformationController::class, 'update'])->middleware('role:admin,inventory_staff')->name('product-information.update');
    Route::get('/product-discounts', [ProductDiscountController::class, 'index'])->middleware('role:admin,inventory_staff,finance')->name('product-discounts.index');
    Route::post('/product-discounts', [ProductDiscountController::class, 'store'])->middleware('role:admin,inventory_staff')->name('product-discounts.store');
    Route::post('/product-discounts/{id}/decision', [ProductDiscountController::class, 'decide'])->middleware('role:admin,finance')->name('product-discounts.decide');
    Route::post('/product-discounts/{id}/cancel', [ProductDiscountController::class, 'cancel'])->middleware('role:admin,inventory_staff')->name('product-discounts.cancel');
    Route::get('/account/security', [AccountSecurityController::class, 'index'])->name('account.security');
    Route::post('/account/password', [AccountSecurityController::class, 'update'])->name('account.password');
    Route::get('/discount-requests', [DiscountApprovalController::class, 'index'])->middleware('role:cashier,finance,admin')->name('discounts.index');
    Route::post('/discount-requests/{id}/decision', [DiscountApprovalController::class, 'decide'])->middleware('role:finance,admin')->name('discounts.decide');
    Route::get('/account/recovery-codes', [StaffAuthController::class, 'recovery'])->name('mfa.recovery');
    Route::get('/dashboard', [WorkspaceController::class, 'dashboard'])->name('dashboard');
    Route::get('/admin', [WorkspaceController::class, 'dashboard'])->middleware('role:admin')->name('admin');
    Route::middleware('role:admin,inventory_staff')->group(function (): void {
        Route::get('/labels', [ProductLabelController::class, 'index'])->name('labels.index');
        Route::post('/labels/print', [ProductLabelController::class, 'print'])->name('labels.print');
        Route::get('/manage/{entity}', [CatalogAdminController::class, 'index'])->whereIn('entity', ['products', 'categories', 'suppliers'])->name('manage.index');
        Route::get('/manage/{entity}/create', [CatalogAdminController::class, 'form'])->whereIn('entity', ['products', 'categories', 'suppliers'])->name('manage.create');
        Route::get('/manage/{entity}/{id}/edit', [CatalogAdminController::class, 'form'])->whereIn('entity', ['products', 'categories', 'suppliers'])->name('manage.edit');
        Route::post('/manage/{entity}', [CatalogAdminController::class, 'save'])->whereIn('entity', ['products', 'categories', 'suppliers'])->name('manage.store');
        Route::put('/manage/{entity}/{id}', [CatalogAdminController::class, 'save'])->whereIn('entity', ['products', 'categories', 'suppliers'])->name('manage.update');
        Route::delete('/manage/{entity}/{id}', [CatalogAdminController::class, 'deactivate'])->whereIn('entity', ['products', 'categories', 'suppliers'])->name('manage.deactivate');
        Route::get('/inventory', [WorkspaceController::class, 'inventory'])->name('inventory');
    });
    Route::post('/inventory/restock', [WorkspaceController::class, 'restock'])->middleware('role:admin')->name('inventory.restock');
    Route::get('/reports', [WorkspaceController::class, 'reports'])->middleware('role:admin,finance,cashier')->name('reports');
    Route::get('/receipts/{id}', [PosController::class, 'receipt'])->middleware('role:admin,finance,cashier')->name('receipt');
    Route::middleware('role:cashier')->group(function (): void {
        Route::get('/api/discount-requests', [DiscountApprovalController::class, 'available'])->name('discounts.available');
        Route::post('/api/discount-requests', [DiscountApprovalController::class, 'store'])->name('discounts.store');
        Route::post('/discount-requests/{id}/cancel', [DiscountApprovalController::class, 'cancel'])->name('discounts.cancel');
        Route::get('/pos', [PosController::class, 'index'])->name('pos');
        Route::get('/api/pos/lookup', [PosController::class, 'lookup'])->name('pos.lookup');
        Route::get('/api/pos/requests/{key}', [PosController::class, 'resolve'])->whereUuid('key')->name('pos.resolve');
        Route::post('/api/pos/checkout', [PosController::class, 'checkout'])->name('pos.checkout');
        Route::post('/sales/{id}/refund', [PosController::class, 'refund'])->name('pos.refund');
        Route::post('/sales/{id}/void', [PosController::class, 'void'])->name('pos.void');
    });
});
