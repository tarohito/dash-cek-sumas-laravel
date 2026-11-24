<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BatchController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QrVerificationController;
use App\Http\Controllers\RbacController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TagLayoutController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:api-global')->group(function () {
    // Public Auth & QR Verification Endpoints
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::get('/auth/demo-accounts', [AuthController::class, 'demoAccounts']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::post('/password-resets', [PasswordResetController::class, 'store'])->middleware('throttle:auth-reset');

    Route::post('/scans/record', [ScanController::class, 'record'])->middleware('throttle:public-scan');
    Route::post('/q/{tagNumber}/verify', [QrVerificationController::class, 'verify'])->middleware('throttle:public-scan');

    // Authenticated & RBAC-Protected Endpoints
    Route::middleware('sumas.auth')->group(function () {
        Route::get('/auth/check-username', [AuthController::class, 'checkUsername']);
        Route::put('/auth/profile', [AuthController::class, 'updateProfile']);

        Route::get('/rbac/permissions', [RbacController::class, 'index']);
        Route::put('/rbac/permissions', [RbacController::class, 'update']);

        Route::get('/tag-layouts', [TagLayoutController::class, 'index']);
        Route::post('/tag-layouts', [TagLayoutController::class, 'upsert']);
        Route::put('/tag-layouts/active', [TagLayoutController::class, 'setActive']);
        Route::delete('/tag-layouts/{id}', [TagLayoutController::class, 'destroy']);

        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

        Route::get('/scans/locations', [ScanController::class, 'locations']);
        Route::get('/scans/stats', [ScanController::class, 'stats']);
        Route::get('/scans/activity', [ScanController::class, 'activity']);

        Route::get('/batches', [BatchController::class, 'index']);
        Route::post('/batches', [BatchController::class, 'store'])->middleware('throttle:heavy-ops');
        Route::get('/batches/{id}/tags', [BatchController::class, 'tags']);
        Route::get('/batches/{id}/all-tags', [BatchController::class, 'allTags'])->middleware('throttle:heavy-ops');
        Route::get('/batches/{id}/summary', [BatchController::class, 'summary']);
        Route::patch('/batches/{id}/redirect-url', [BatchController::class, 'updateRedirectUrl']);
        Route::post('/batches/{id}/allocate', [BatchController::class, 'allocate']);
        Route::post('/batches/{id}/deallocate', [BatchController::class, 'deallocate']);
        Route::put('/batches/{id}/projects', [BatchController::class, 'updateProjects']);
        Route::put('/batches/{id}', [BatchController::class, 'update']);
        Route::delete('/batches/{id}', [BatchController::class, 'destroy']);

        Route::get('/audit-logs', [AuditLogController::class, 'index']);
        Route::post('/audit-logs', [AuditLogController::class, 'store']);

        Route::get('/brands', [BrandController::class, 'index']);
        Route::post('/brands', [BrandController::class, 'store']);
        Route::put('/brands/{id}', [BrandController::class, 'update']);
        Route::delete('/brands/{id}', [BrandController::class, 'destroy']);

        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}/specs', [CategoryController::class, 'updateSpecs']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);

        Route::get('/products', [ProductController::class, 'index']);
        Route::get('/products/{id}/relational', [ProductController::class, 'relational']);
        Route::post('/products', [ProductController::class, 'store']);
        Route::put('/products/{id}', [ProductController::class, 'update']);
        Route::delete('/products/{id}', [ProductController::class, 'destroy']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);

        Route::get('/password-resets', [PasswordResetController::class, 'index']);
        Route::post('/password-resets/{id}/respond', [PasswordResetController::class, 'respond']);

        Route::get('/notifications/stream', [NotificationController::class, 'stream']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/mark-read', [NotificationController::class, 'markRead']);

        Route::get('/settings', [SettingsController::class, 'index']);
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::post('/settings/test-alert', [SettingsController::class, 'testAlert'])->middleware('throttle:heavy-ops');
        Route::get('/settings/backup', [SettingsController::class, 'backup'])->middleware('throttle:heavy-ops');
        Route::post('/settings/restore', [SettingsController::class, 'restore'])->middleware('throttle:heavy-ops');

        Route::get('/trash', [TrashController::class, 'index']);
        Route::get('/trash/count', [TrashController::class, 'count']);
        Route::post('/trash/bulk-restore', [TrashController::class, 'bulkRestore']);
        Route::post('/trash/bulk-delete', [TrashController::class, 'bulkDestroy']);
        Route::post('/trash/{id}/restore', [TrashController::class, 'restore']);
        Route::delete('/trash/{id}', [TrashController::class, 'destroy']);
        Route::delete('/trash', [TrashController::class, 'emptyTrash']);
    });

    Route::fallback(function () {
        return response()->json([
            'success' => false,
            'error' => 'Endpoint API tidak ditemukan.',
        ], 404);
    });
});
