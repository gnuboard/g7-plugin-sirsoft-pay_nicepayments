<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController;

/*
|--------------------------------------------------------------------------
| NicePayments Plugin Web Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /plugins/sirsoft-pay_nicepayments (PluginRouteServiceProvider 자동 적용)
| 미들웨어: web (PluginRouteServiceProvider 자동 적용)
|
| 나이스페이먼츠는 브라우저 POST 콜백 방식이므로 CSRF 미들웨어를 제외합니다.
|
*/

Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->group(function () {
        // 결제 승인 콜백 (나이스페이먼츠 → 브라우저 POST)
        Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
            ->name('payment.callback');

        // 가상계좌 입금 통보 (나이스페이먼츠 서버 → 우리 서버 POST)
        Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
            ->name('payment.vbank-notify');
    });

// SignData 생성: CSRF 제외 + 인증 필수 (로그인 사용자만 결제 가능)
//
// 인증 가드: 'auth:sanctum,web' — Sanctum Bearer 토큰(SPA/PAT) 우선,
// 없으면 web 세션 쿠키로 fallback. 'auth' 단독(=web guard 만 체크)은
// localStorage 의 Sanctum 토큰만 들고 있는 SPA 클라이언트를 막아 401 발생함.
Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware(['auth:sanctum,web', 'throttle:30,1'])
    ->group(function () {
        Route::post('/payment/sign-data', [PaymentCallbackController::class, 'signData'])
            ->name('payment.sign-data');
    });
