<?php

use App\Http\Controllers\Api\V1;
use App\Http\Middleware\ApiLocale;
use Illuminate\Support\Facades\Route;

// REST API v1 (docs/03-api.md). Served at /api/v1. Errors are application/problem+json.
Route::prefix('v1')->middleware(['throttle:api', ApiLocale::class])->name('api.')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [V1\AuthController::class, 'register']);
        Route::post('auth/login', [V1\AuthController::class, 'login']);
    });

    Route::get('home', [V1\CatalogController::class, 'home']);
    Route::get('games', [V1\CatalogController::class, 'games']);
    Route::get('games/{game:slug}', [V1\CatalogController::class, 'game']);
    Route::get('games/{game:slug}/packages', [V1\CatalogController::class, 'gamePackages']);
    Route::get('packages/{package:slug}', [V1\CatalogController::class, 'package']);
    Route::get('reference', [V1\CatalogController::class, 'reference']);
    Route::get('packages/{package:slug}/reviews', [V1\CommunityController::class, 'reviews']);
    Route::get('trust', [V1\DeviceController::class, 'trust']);
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/device-code', [V1\DeviceController::class, 'startDeviceCode']);
    });
    Route::post('auth/device-token', [V1\DeviceController::class, 'pollDeviceCode'])->middleware('throttle:30,1');
    // Signed, short-lived; no bearer token so download managers and CDNs can fetch it.
    Route::get('downloads/{download}/file', [V1\DeviceController::class, 'file'])->middleware('signed')->name('downloads.file');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [V1\AuthController::class, 'logout']);
        Route::post('auth/email/resend', [V1\AuthController::class, 'resendVerification'])->middleware('throttle:6,1');
        Route::get('me', [V1\AuthController::class, 'me']);
        Route::patch('me', [V1\AuthController::class, 'update']);
        Route::get('library', [V1\CommerceController::class, 'library'])->middleware('ability:library:read');

        Route::get('devices', [V1\DeviceController::class, 'index']);
        Route::post('devices', [V1\DeviceController::class, 'store'])->middleware('throttle:10,1');
        Route::delete('devices/{device:public_id}', [V1\DeviceController::class, 'destroy']);
        Route::post('devices/{device:public_id}/heartbeat', [V1\DeviceController::class, 'heartbeat'])->middleware('throttle:60,1');
        Route::post('devices/{device:public_id}/licenses', [V1\DeviceController::class, 'refreshLicenses'])->middleware('throttle:30,1');
        Route::post('downloads', [V1\DeviceController::class, 'authorizeDownload'])->middleware('throttle:30,60');
        Route::post('downloads/{download}/complete', [V1\DeviceController::class, 'completeDownload']);

        Route::post('translation-reports', [V1\CommunityController::class, 'report'])->middleware('throttle:30,1');
        Route::get('me/feedback', [V1\CommunityController::class, 'myFeedback']);
        Route::post('packages/{package:slug}/reviews', [V1\CommunityController::class, 'saveReview'])->middleware('throttle:10,1');
        Route::delete('packages/{package:slug}/reviews', [V1\CommunityController::class, 'deleteReview']);

        Route::middleware(['ability:commerce', 'verified'])->group(function () {
            Route::get('cart', [V1\CommerceController::class, 'cart']);
            Route::post('cart/items', [V1\CommerceController::class, 'addItem']);
            Route::delete('cart/items/{package}', [V1\CommerceController::class, 'removeItem']);
            Route::post('cart/coupon', [V1\CommerceController::class, 'checkCoupon']);
            Route::post('orders', [V1\CommerceController::class, 'createOrder'])->middleware('throttle:10,1');
            Route::get('orders', [V1\CommerceController::class, 'orders']);
            Route::get('orders/{order:public_id}', [V1\CommerceController::class, 'order']);
            Route::post('orders/{order:public_id}/pay', [V1\CommerceController::class, 'pay'])->middleware('throttle:10,1');
            Route::post('orders/{order:public_id}/refund-requests', [V1\CommerceController::class, 'requestRefund'])->middleware('throttle:5,1');
            Route::post('gift-codes/redeem', [V1\CommerceController::class, 'redeemGift'])->middleware('throttle:10,1');
        });
    });
});
