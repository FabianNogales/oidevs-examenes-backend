<?php

use App\Http\Controllers\Api\V1\Portal\PortalCategoryController;
use App\Http\Middleware\EnsureActiveAdministrator;
use Illuminate\Support\Facades\Route;

foreach (['event', 'news'] as $catalog) {
    // Existing event selector remains unchanged in v1.php.
    if ($catalog === 'news') {
        Route::get('portal/news-categories', [PortalCategoryController::class, 'selection'])->defaults('catalog', 'news');
        Route::get('admin/portal/news-categories', [PortalCategoryController::class, 'selection'])->defaults('catalog', 'news')
            ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureActiveAdministrator::class]);
    }
    Route::prefix('admin/portal/'.$catalog.'-categories/manage')
        ->middleware(['auth:sanctum', 'session.current', 'password.changed', EnsureActiveAdministrator::class])
        ->group(function () use ($catalog) {
            Route::get('/', [PortalCategoryController::class, 'index'])->defaults('catalog', $catalog);
            Route::post('/', [PortalCategoryController::class, 'store'])->defaults('catalog', $catalog);
            Route::patch('/{category}', [PortalCategoryController::class, 'update'])->whereNumber('category')->defaults('catalog', $catalog);
            Route::patch('/{category}/status', [PortalCategoryController::class, 'status'])->whereNumber('category')->defaults('catalog', $catalog);
            Route::get('/{category}/history', [PortalCategoryController::class, 'history'])->whereNumber('category')->defaults('catalog', $catalog);
        });
}
