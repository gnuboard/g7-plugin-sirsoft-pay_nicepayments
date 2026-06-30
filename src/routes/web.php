<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\PayNicepayments\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\PayNicepayments\Http\Middleware\VbankNotifyIpWhitelist;

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
        // IP 화이트리스트 미들웨어로 NicePay 공식 발송 IP 만 허용
        Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
            ->middleware(VbankNotifyIpWhitelist::class)
            ->name('payment.vbank-notify');
    });

// SignData 생성: 비회원 결제도 결제창 진입 직전에 호출하므로 인증 미들웨어를 걸지 않는다.
// 대신 컨트롤러에서 주문번호, 결제 전 상태, 나이스페이 결제 레코드, 청구 금액을 모두 검증한다.
Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware(['throttle:30,1'])
    ->group(function () {
        Route::post('/payment/sign-data', [PaymentCallbackController::class, 'signData'])
            ->name('payment.sign-data');
    });
