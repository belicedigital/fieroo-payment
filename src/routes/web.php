<?php
use Illuminate\Support\Facades\Route;
use Fieroo\Payment\Controllers\PaymentController;
use Fieroo\Payment\Controllers\StripePaymentController;

Route::group(['prefix' => 'admin', 'middleware' => ['web','auth']], function() {
    // Route::group(['prefix' => 'paypal'], function() {
    //     Route::post('/', [PaymentController::class, 'pay'])->name('payment');
    //     Route::post('/furnishings', [PaymentController::class, 'payFurnishings'])->name('payment-furnishings');
    //     Route::get('/success', [PaymentController::class, 'success']);
    //     Route::get('/success-furnishings', [PaymentController::class, 'successFurnishings']);
    //     Route::get('/error', [PaymentController::class, 'error']);
    // });

    Route::post('stripe-payment', [StripePaymentController::class, 'payment'])->name('stripe-payment');
    Route::post('/furnishings', [StripePaymentController::class, 'payFurnishings'])->name('payment-furnishings');
});