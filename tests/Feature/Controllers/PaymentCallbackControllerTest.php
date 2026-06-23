<?php

namespace Plugins\Sirsoft\PayNicepayments\Tests\Feature\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Plugins\Sirsoft\PayNicepayments\Tests\PluginTestCase;

class PaymentCallbackControllerTest extends PluginTestCase
{
    private const TEST_MID = 'nicepay00m';

    private const TEST_MERCHANT_KEY = 'EYzu8jGGMfqaDEp76gSckuvnaHHu+bC4opsSN6lHv3b2lurNYkVXrZ7Z1AoqQnXI3eLuaUFyoRNC6FkrzVjceg==';

    private function makeAuthorizeResponse(string $tid, string $moid, int $amount, string $resultCode = '3001'): array
    {
        return [
            'ResultCode' => $resultCode,
            'ResultMsg' => '정상처리',
            'TID' => $tid,
            'Moid' => $moid,
            'Amt' => (string) $amount,
            'PayMethod' => 'CARD',
            'AppNo' => 'APP12345',
            'CardNum' => '4330-****-****-1234',
            'IssuCardName' => '신한카드',
            'CardQuota' => '00',
            'AuthDate' => now()->format('YmdHis'),
        ];
    }

    private function createTestOrder(
        int $totalAmount = 50000,
        PaymentMethodEnum $paymentMethod = PaymentMethodEnum::CARD,
    ): Order {
        $user = User::factory()->create();

        $order = OrderFactory::new()->create([
            'user_id' => $user->id,
            'order_number' => 'ORD-TEST-' . random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => $totalAmount,
            'total_discount_amount' => 0,
            'total_coupon_discount_amount' => 0,
            'total_product_coupon_discount_amount' => 0,
            'total_order_coupon_discount_amount' => 0,
            'total_code_discount_amount' => 0,
            'base_shipping_amount' => 0,
            'extra_shipping_amount' => 0,
            'shipping_discount_amount' => 0,
            'total_shipping_amount' => 0,
            'total_amount' => $totalAmount,
            'total_due_amount' => $totalAmount,
            'total_points_used_amount' => 0,
            'total_deposit_used_amount' => 0,
            'total_paid_amount' => 0,
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => $paymentMethod,
            'pg_provider' => 'nicepayments',
            'paid_amount_local' => 0,
            'paid_at' => null,
            'transaction_id' => null,
            'card_approval_number' => null,
        ]);

        return $order;
    }

    private function mockPluginSettings(array $overrides = []): void
    {
        $defaults = [
            'is_test_mode' => true,
            'test_mid' => self::TEST_MID,
            'test_merchant_key' => self::TEST_MERCHANT_KEY,
            'live_mid' => '',
            'live_merchant_key' => '',
            'redirect_success_url' => '/shop/orders/{orderId}/complete',
            'redirect_fail_url' => '/shop/checkout',
        ];

        $settingsMock = $this->createMock(\App\Services\PluginSettingsService::class);
        $settingsMock->method('get')
            ->willReturn(array_merge($defaults, $overrides));

        $this->app->instance(\App\Services\PluginSettingsService::class, $settingsMock);
    }

    private function makeSignature(string $authToken, string $mid, int $amt, string $merchantKey): string
    {
        return bin2hex(hash('sha256', $authToken . $mid . (string) $amt . $merchantKey, true));
    }

    private function makeCallbackParams(string $moid, int $amt, array $overrides = []): array
    {
        $authToken = 'AUTH_TOKEN_' . uniqid();
        $signature = $this->makeSignature($authToken, self::TEST_MID, $amt, self::TEST_MERCHANT_KEY);

        return array_merge([
            'AuthResultCode' => '0000',
            'AuthResultMsg' => '성공',
            'NextAppURL' => 'https://pay.nicepay.co.kr/v1/authorize',
            'TxTid' => 'TX_TID_' . uniqid(),
            'AuthToken' => $authToken,
            'PayMethod' => 'CARD',
            'MID' => self::TEST_MID,
            'Moid' => $moid,
            'Amt' => $amt,
            'NetCancelURL' => 'https://pay.nicepay.co.kr/v1/netcancel',
            'Signature' => $signature,
        ], $overrides);
    }

    private function makeVbankNotifyPayload(string $moid, int $amt, array $overrides = []): array
    {
        return array_merge([
            'PG' => 'nicepay',
            'PayMethod' => 'VBANK',
            'MID' => self::TEST_MID,
            'MOID' => $moid,
            'TID' => 'VBANK_TID_' . uniqid(),
            'Amt' => $amt,
            'ResultCode' => '4110',
            'ResultMsg' => '입금완료',
            'AuthDate' => now()->format('YmdHis'),
            'AuthCode' => 'AUTH12345',
            'VbankNum' => '1234567890',
            'VbankName' => '국민은행',
            'VbankInputName' => '홍길동',
            'FnCd' => '004',
            'FnName' => '국민은행',
        ], $overrides);
    }

    // ===== SignData 생성 테스트 =====

    public function test_sign_data_allows_guest_pending_nicepayments_order(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'ediDate',
                'signData',
                'mid',
            ])
            ->assertJsonPath('mid', self::TEST_MID);

        $this->assertSame(64, strlen((string) $response->json('signData')));
    }

    public function test_sign_data_rejects_amount_mismatch(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 49999,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_amount'));
    }

    public function test_sign_data_rejects_non_payable_order(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update(['order_status' => OrderStatusEnum::CANCELLED]);
        $order->payment()->update(['payment_status' => PaymentStatusEnum::FAILED]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_restores_retryable_failed_nicepayments_order(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => [
                'payment_failure_code' => 'USER_CANCEL',
                'payment_failure_message' => '사용자가 결제창을 닫았습니다.',
                'payment_failed_at' => now()->toIso8601String(),
            ],
        ]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::FAILED,
            'payment_meta' => [
                'failure_source' => 'nicepayments',
                'failure_code' => 'USER_CANCEL',
                'failure_message' => '사용자가 결제창을 닫았습니다.',
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['ediDate', 'signData', 'mid']);

        $order->refresh();
        $payment = $order->payment->fresh();

        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::READY, $payment->payment_status);
        $this->assertArrayNotHasKey('payment_failure_code', $order->order_meta);
        $this->assertEquals(1, $order->order_meta['nicepayments_retry_count'] ?? null);
        $this->assertArrayNotHasKey('failure_code', $payment->payment_meta);
        $this->assertEquals(1, $payment->payment_meta['retry_count'] ?? null);
    }

    public function test_sign_data_does_not_restore_amount_mismatch_failure(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update([
            'order_status' => OrderStatusEnum::CANCELLED,
            'order_meta' => [
                'payment_failure_code' => 'AMOUNT_MISMATCH',
                'payment_failure_message' => 'amount mismatch',
                'payment_failed_at' => now()->toIso8601String(),
            ],
        ]);
        $order->payment()->update([
            'payment_status' => PaymentStatusEnum::FAILED,
            'payment_meta' => [
                'failure_source' => 'nicepayments',
                'failure_code' => 'AMOUNT_MISMATCH',
                'failure_message' => 'amount mismatch',
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));

        $order->refresh();
        $payment = $order->payment->fresh();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
    }

    public function test_sign_data_rejects_non_nicepayments_payment_record(): void
    {
        $order = $this->createTestOrder(50000);
        $order->payment()->update(['pg_provider' => 'kginicis']);
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_rejects_missing_payment_record(): void
    {
        $order = $this->createTestOrder(50000);
        $order->payment()->delete();
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => $order->order_number,
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.invalid_request'));
    }

    public function test_sign_data_rejects_unknown_order(): void
    {
        $this->mockPluginSettings();

        $response = $this->postJson('/plugins/sirsoft-pay_nicepayments/payment/sign-data', [
            'amt' => 50000,
            'moid' => 'ORD-NOT-FOUND',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('error', __('sirsoft-pay_nicepayments::messages.errors.order_not_found'));
    }

    // ===== 성공 콜백 테스트 =====

    public function test_auth_callback_redirects_to_complete_page_on_valid_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_' . uniqid();
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals($tid, $payment->transaction_id);
        $this->assertEquals('APP12345', $payment->card_approval_number);
    }

    public function test_auth_callback_stores_only_whitelisted_pg_response_fields(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $tid = 'TID_SANITIZED';
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(array_merge(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                [
                    'BuyerName' => '홍길동',
                    'BuyerEmail' => 'buyer@example.test',
                    'BuyerTel' => '01012345678',
                    'NewSensitiveField' => 'store-me-not',
                ]
            ), 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame($tid, $meta['pg_raw_response']['TID'] ?? null);
        $this->assertSame('3001', $meta['pg_raw_response']['ResultCode'] ?? null);
        $this->assertArrayNotHasKey('CardNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerEmail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerTel', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('NewSensitiveField', $meta['pg_raw_response']);
    }

    public function test_vbank_issue_stores_only_whitelisted_pg_response_fields(): void
    {
        $order = $this->createTestOrder(30000, PaymentMethodEnum::VBANK);
        $this->mockPluginSettings();

        $tid = 'VBANK_ISSUE_SANITIZED';
        $params = $this->makeCallbackParams($order->order_number, 30000, [
            'PayMethod' => 'VBANK',
        ]);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(array_merge(
                $this->makeAuthorizeResponse($tid, $order->order_number, 30000, '4100'),
                [
                    'PayMethod' => 'VBANK',
                    'VbankBankCode' => '004',
                    'VbankBankName' => '국민은행',
                    'VbankNum' => '1234567890',
                    'VbankExpDate' => '20260521',
                    'VbankExpTime' => '235959',
                    'VbankInputName' => '홍길동',
                    'BuyerName' => '구매자',
                    'BuyerEmail' => 'buyer@example.test',
                    'BuyerTel' => '01012345678',
                    'NewSensitiveField' => 'store-me-not',
                ]
            ), 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame($tid, $meta['pg_raw_response']['TID'] ?? null);
        $this->assertSame('국민은행', $meta['pg_raw_response']['VbankBankName'] ?? null);
        $this->assertArrayNotHasKey('VbankNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('VbankInputName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('CardNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerEmail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerTel', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('NewSensitiveField', $meta['pg_raw_response']);
    }

    /**
     * 인증 단계(AuthResultCode != '0000') 실패는 사용자가 아직 결제를 끝내지 않은 상태로
     * 어떤 코드/메시지가 오든 generic 에러 toast 없이 체크아웃으로 깨끗하게 복귀해야 한다.
     *
     * 모바일에서 사용자가 취소버튼을 누르거나, PG 가 인증을 거부하거나, 타임아웃이 발생하더라도
     * 이 시점엔 결제 승인 (NextAppURL 호출) 이 일어나기 전이므로 사용자 입장에서는 "다시 시도"
     * 외에 할 수 있는 것이 없다. "결제 처리 중 오류" 같은 강한 메시지는 부적절.
     */
    public function test_auth_callback_softens_pre_authorize_failures_to_silent_redirect(): void
    {
        $this->mockPluginSettings();

        $cases = [
            ['code' => '9999', 'msg' => '사용자 취소'],                  // 표준 사용자 취소
            ['code' => '2001', 'msg' => '사용자 취소'],                  // 한국어 사용자 메시지
            ['code' => '0021', 'msg' => '결제창을 종료하셨습니다'],       // 결제창 종료 (취소 키워드 없음)
            ['code' => '8001', 'msg' => 'User cancelled'],               // 영문 cancel
            ['code' => 'F004', 'msg' => '지불 거절'],                    // 사용자/취소 키워드 없는 PG 거절
            ['code' => '5500', 'msg' => '인증 시간 초과'],                // 타임아웃
            ['code' => '0033', 'msg' => ''],                              // 메시지 없는 실패
        ];

        foreach ($cases as $case) {
            $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
                'AuthResultCode' => $case['code'],
                'AuthResultMsg' => $case['msg'],
            ]);

            $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

            $response->assertRedirect();
            $location = $response->headers->get('Location');

            $this->assertStringNotContainsString(
                'error=',
                $location,
                "AuthResultCode={$case['code']} ({$case['msg']}) 은 인증단계 실패이므로 error query 없이 체크아웃으로 복귀해야 한다. 실제 redirect: {$location}",
            );
            $this->assertStringContainsString('/shop/checkout', $location);
        }
    }

    public function test_auth_callback_records_pre_authorize_failure_when_amount_matches_order(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', [
            'AuthResultCode' => '0021',
            'AuthResultMsg' => '결제창을 종료하셨습니다',
            'Moid' => $order->order_number,
            'Amt' => 50000,
        ]);

        $response->assertRedirect('/shop/checkout');
        $this->assertStringNotContainsString('error=', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals('0021', $order->order_meta['payment_failure_code'] ?? null);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);
        $this->assertNull($order->payment->cancelled_at);
    }

    public function test_auth_callback_redirects_to_fail_on_mid_mismatch(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000, [
            'MID' => 'WRONG_MID',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=mid_mismatch', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_signature_mismatch(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000, [
            'Signature' => 'INVALID_SIGNATURE',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=signature_mismatch', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_on_order_not_found(): void
    {
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('NON_EXISTENT_ORDER', 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse('TID_NONE', 'NON_EXISTENT_ORDER', 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_found', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_fail_and_sends_net_cancel_on_authorize_failure(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response([
                'ResultCode' => '9999',
                'ResultMsg' => '승인 실패',
            ], 200),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);
        $this->assertEquals('9999', $order->payment->payment_meta['failure_code'] ?? null);
    }

    public function test_auth_callback_records_authorize_exception_as_failed_payment(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response([], 500),
            'pay.nicepay.co.kr/v1/netcancel' => Http::response('OK', 200),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=authorize_failed', $response->headers->get('Location'));

        $order->refresh();
        $payment = $order->payment->fresh();

        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals('AUTHORIZE_EXCEPTION', $order->order_meta['payment_failure_code'] ?? null);
        $this->assertEquals(PaymentStatusEnum::FAILED, $payment->payment_status);
        $this->assertEquals('AUTHORIZE_EXCEPTION', $payment->payment_meta['failure_code'] ?? null);
    }

    public function test_auth_callback_redirects_to_fail_url_on_missing_params(): void
    {
        $this->mockPluginSettings();

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', [
            'AuthResultCode' => '0000',
        ]);

        $response->assertRedirect();
        $this->assertStringContainsString('error=invalid_params', $response->headers->get('Location'));
    }

    public function test_auth_callback_redirects_to_custom_success_url(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings(['redirect_success_url' => '/custom/payment/{orderId}/done']);

        $tid = 'TID_CUSTOM';
        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse($tid, $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect("/custom/payment/{$order->order_number}/done");
    }

    public function test_auth_callback_detects_mobile_device(): void
    {
        $order = $this->createTestOrder(50000);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            'pay.nicepay.co.kr/v1/authorize' => Http::response(
                $this->makeAuthorizeResponse('TID_MOBILE', $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/callback',
            $params,
            ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)']
        );

        $response->assertRedirect("/shop/orders/{$order->order_number}/complete");

        $payment = $order->payment;
        $payment->refresh();
        $this->assertEquals('mobile', $payment->payment_device);
    }

    public function test_auth_callback_ignores_non_payable_order_without_authorize_request(): void
    {
        $order = $this->createTestOrder(50000);
        $order->update(['order_status' => OrderStatusEnum::CANCELLED]);
        $order->payment()->update(['payment_status' => PaymentStatusEnum::FAILED]);
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams($order->order_number, 50000);

        Http::fake([
            '*' => Http::response(
                $this->makeAuthorizeResponse('TID_SHOULD_NOT_BE_SENT', $order->order_number, 50000),
                200
            ),
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=order_not_payable', $response->headers->get('Location'));

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(PaymentStatusEnum::FAILED, $order->payment->payment_status);
        $this->assertNull($order->payment->transaction_id);
        Http::assertNothingSent();
    }

    // ===== 가상계좌 입금 통보 테스트 =====

    public function test_vbank_notify_returns_ok_on_successful_deposit(): void
    {
        $order = $this->createTestOrder(30000);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, ['TID' => 'VBANK_TID_001'])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_vbank_notify_stores_only_whitelisted_pg_response_fields(): void
    {
        $order = $this->createTestOrder(30000);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, [
                'TID' => 'VBANK_TID_SANITIZED',
                'name' => '구매자명',
                'BuyerEmail' => 'buyer@example.test',
                'MallUserID' => 'member-001',
                'Signature' => 'signature-value',
            ])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $payment = $order->payment;
        $payment->refresh();

        $meta = $payment->payment_meta;
        $this->assertTrue($meta['pg_response_sanitized'] ?? false);
        $this->assertSame('VBANK_TID_SANITIZED', $meta['pg_raw_response']['TID'] ?? null);
        $this->assertSame('4110', $meta['pg_raw_response']['ResultCode'] ?? null);
        $this->assertArrayNotHasKey('VbankNum', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('VbankInputName', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('name', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('BuyerEmail', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('MallUserID', $meta['pg_raw_response']);
        $this->assertArrayNotHasKey('Signature', $meta['pg_raw_response']);
        $this->assertTrue($meta['vbank_notifications'][0]['raw_sanitized'] ?? false);
        $this->assertArrayNotHasKey('VbankNum', $meta['vbank_notifications'][0]['raw']);
        $this->assertArrayNotHasKey('VbankInputName', $meta['vbank_notifications'][0]['raw']);
    }

    public function test_vbank_notify_returns_ok_on_cancelled_deposit(): void
    {
        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload('ORD-TEST-CANCEL', 30000, [
                'TID' => 'VBANK_TID_002',
                'ResultCode' => '4100',
                'ResultMsg' => '계좌발급',
            ])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload('NON_EXISTENT_ORDER', 30000, ['TID' => 'VBANK_TID_003'])
        );

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }

    public function test_vbank_notify_is_idempotent_for_same_tid(): void
    {
        $tid = 'VBANK_TID_DUPLICATE';
        $order = $this->createTestOrder(30000);
        $order->payment()->update(['transaction_id' => $tid]);

        $response = $this->post(
            '/plugins/sirsoft-pay_nicepayments/payment/vbank-notify',
            $this->makeVbankNotifyPayload($order->order_number, 30000, ['TID' => $tid])
        );

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }
}
