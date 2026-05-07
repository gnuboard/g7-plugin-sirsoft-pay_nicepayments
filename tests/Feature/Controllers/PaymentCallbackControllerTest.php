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

    private function createTestOrder(int $totalAmount = 50000): Order
    {
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
            'payment_method' => PaymentMethodEnum::CARD,
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

    public function test_auth_callback_redirects_to_fail_on_auth_result_code_not_0000(): void
    {
        $this->mockPluginSettings();

        $params = $this->makeCallbackParams('ORD-TEST-99999', 50000, [
            'AuthResultCode' => '2001',
            'AuthResultMsg' => '사용자 취소',
        ]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/callback', $params);

        $response->assertRedirect();
        $this->assertStringContainsString('error=2001', $response->headers->get('Location'));
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
        $this->assertStringContainsString('error=9999', $response->headers->get('Location'));
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

    // ===== 가상계좌 입금 통보 테스트 =====

    public function test_vbank_notify_returns_ok_on_successful_deposit(): void
    {
        $order = $this->createTestOrder(30000);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify', [
            'TID' => 'VBANK_TID_001',
            'Moid' => $order->order_number,
            'Amt' => 30000,
            'VbankResult' => '1',
            'VbankAuthDate' => now()->format('YmdHis'),
            'VbankNum' => '1234567890',
            'VbankName' => '국민은행',
        ]);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PAYMENT_COMPLETE, $order->order_status);
    }

    public function test_vbank_notify_returns_ok_on_cancelled_deposit(): void
    {
        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify', [
            'TID' => 'VBANK_TID_002',
            'Moid' => 'ORD-TEST-CANCEL',
            'Amt' => 30000,
            'VbankResult' => '0',
        ]);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());
    }

    public function test_vbank_notify_returns_fail_on_order_not_found(): void
    {
        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify', [
            'TID' => 'VBANK_TID_003',
            'Moid' => 'NON_EXISTENT_ORDER',
            'Amt' => 30000,
            'VbankResult' => '1',
        ]);

        $response->assertOk();
        $this->assertEquals('FAIL', $response->getContent());
    }

    public function test_vbank_notify_is_idempotent_for_same_tid(): void
    {
        $tid = 'VBANK_TID_DUPLICATE';
        $order = $this->createTestOrder(30000);
        $order->payment()->update(['transaction_id' => $tid]);

        $response = $this->post('/plugins/sirsoft-pay_nicepayments/payment/vbank-notify', [
            'TID' => $tid,
            'Moid' => $order->order_number,
            'Amt' => 30000,
            'VbankResult' => '1',
        ]);

        $response->assertOk();
        $this->assertEquals('OK', $response->getContent());

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING_ORDER, $order->order_status);
    }
}
