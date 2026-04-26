<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\YooMoneyWallet\YooMoneyWallet;

Route::post('/extensions/yoomoney_wallet/notification', [YooMoneyWallet::class, 'notification'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.yoomoney_wallet.notification');
