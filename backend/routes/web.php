<?php

use App\Http\Controllers\Store;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('home', ['locale' => session('locale', config('lugha.default_locale'))]));
// Fortify's post-login "home" is locale-less; send it to the library in the visitor's language.
Route::get('/library', fn () => redirect()->route('library', ['locale' => app()->getLocale()]))->middleware('auth');

Route::prefix('{locale}')->whereIn('locale', ['ar', 'en'])->group(function () {
    Route::get('/', Store\HomeController::class)->name('home');
    Route::get('/games', [Store\GameController::class, 'index'])->name('games.index');
    Route::get('/games/{game:slug}', [Store\GameController::class, 'show'])->name('games.show');
    Route::get('/packages/{package:slug}', [Store\PackageController::class, 'show'])->name('packages.show');
    Route::get('/support', [Store\SupportController::class, 'index'])->name('support.index');
    Route::view('/how-it-works', 'store.pages.how')->name('pages.how');
    Route::view('/refund-policy', 'store.pages.refunds')->name('pages.refunds');
    Route::view('/privacy', 'store.pages.privacy')->name('pages.privacy');

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::get('/cart', [Store\CartController::class, 'show'])->name('cart.show');
        Route::post('/cart/items', [Store\CartController::class, 'add'])->name('cart.add');
        Route::delete('/cart/items/{item}', [Store\CartController::class, 'remove'])->name('cart.remove');
        Route::post('/cart/coupon', [Store\CartController::class, 'applyCoupon'])->name('cart.coupon');
        Route::delete('/cart/coupon', [Store\CartController::class, 'removeCoupon'])->name('cart.coupon.remove');

        Route::get('/checkout', [Store\CheckoutController::class, 'show'])->name('checkout.show');
        Route::post('/checkout', [Store\CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');

        Route::get('/orders', [Store\OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order:public_id}', [Store\OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order:public_id}/pay', [Store\OrderController::class, 'pay'])->middleware('throttle:10,1')->name('orders.pay');
        Route::post('/orders/{order:public_id}/refund-request', [Store\OrderController::class, 'requestRefund'])->middleware('throttle:5,1')->name('orders.refund-request');

        Route::get('/library', [Store\LibraryController::class, 'index'])->name('library');
        Route::get('/downloads', [Store\LibraryController::class, 'downloads'])->name('downloads');
        Route::get('/gift', [Store\GiftController::class, 'show'])->name('gift.show');
        Route::post('/gift', [Store\GiftController::class, 'redeem'])->middleware('throttle:10,1')->name('gift.redeem');

        Route::get('/support/tickets/{ticket:public_id}', [Store\SupportController::class, 'show'])->name('support.show');
        Route::post('/support/tickets', [Store\SupportController::class, 'store'])->middleware('throttle:10,1')->name('support.store');
        Route::post('/support/tickets/{ticket:public_id}/messages', [Store\SupportController::class, 'reply'])->name('support.reply');
    });

    Route::middleware('auth')->group(function () {
        Route::get('/profile', [Store\ProfileController::class, 'show'])->name('profile');
        Route::get('/devices', [Store\DeviceController::class, 'index'])->name('devices');
        Route::delete('/devices/{device:public_id}', [Store\DeviceController::class, 'destroy'])->name('devices.destroy');
        Route::get('/activate', [Store\DeviceController::class, 'activate'])->name('activate');
        Route::post('/activate', [Store\DeviceController::class, 'decide'])->middleware('throttle:10,1')->name('activate.decide');
    });
});

// The gateway sends the buyer back here; we ask the gateway server-to-server before showing anything as paid.
// Public on purpose: app buyers return here without a web session. It only triggers a server-side status check.
Route::get('/checkout/return/{key}', Store\PaymentReturnController::class)->middleware('throttle:30,1')->name('checkout.return');
// For gateways whose card form is an embedded widget (HyperPay COPYandPAY).
Route::get('/checkout/pay/{key}', Store\HostedPaymentController::class)->middleware('auth')->name('checkout.pay');

Route::post('/webhooks/payments/{gateway}', Store\PaymentWebhookController::class)
    ->whereIn('gateway', ['moyasar', 'hyperpay', 'fake'])->middleware('throttle:120,1')->name('webhooks.payments');

if (! app()->environment('production')) {
    Route::get('/dev/fake-pay/{ref}', [Store\FakePayController::class, 'show'])->middleware('auth')->name('dev.fake-pay');
    Route::post('/dev/fake-pay/{ref}', [Store\FakePayController::class, 'complete'])->middleware('auth')->name('dev.fake-pay.complete');
}
