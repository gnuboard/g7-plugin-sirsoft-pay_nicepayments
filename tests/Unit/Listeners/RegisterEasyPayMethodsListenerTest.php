<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Tests\Unit\Listeners;

use Plugins\Sirsoft\PayNicepayments\Listeners\AdjustEcommercePaymentMethodsLayoutListener;
use Plugins\Sirsoft\PayNicepayments\Listeners\RegisterEasyPayMethodsListener;
use Plugins\Sirsoft\PayNicepayments\Plugin;
use Tests\TestCase;

class RegisterEasyPayMethodsListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app['translator']->addNamespace(
            'sirsoft-pay_nicepayments',
            base_path('plugins/_bundled/sirsoft-pay_nicepayments/lang')
        );
    }

    public function test_injects_easy_pay_methods_after_existing_pg_easy_pay_methods(): void
    {
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'card'],
            ['id' => 'phone'],
            ['id' => 'kginicis_samsung_pay'],
            ['id' => 'nhnkcp_payco'],
            ['id' => 'point'],
            ['id' => 'deposit'],
        ]);

        $this->assertSame([
            'card',
            'phone',
            'kginicis_samsung_pay',
            'nhnkcp_payco',
            'nicepay_naverpay',
            'nicepay_kakaopay',
            'nicepay_samsungpay',
            'nicepay_applepay',
            'nicepay_payco',
            'nicepay_skpay',
            'nicepay_ssgpay',
            'nicepay_lpay',
            'point',
            'deposit',
        ], array_column($methods, 'id'));
    }

    public function test_plugin_registers_easy_pay_hook_listeners(): void
    {
        $listeners = (new Plugin())->getHookListeners();

        $this->assertContains(RegisterEasyPayMethodsListener::class, $listeners);
        $this->assertContains(AdjustEcommercePaymentMethodsLayoutListener::class, $listeners);
    }

    public function test_easy_pay_methods_do_not_require_pg_provider_in_saved_defaults(): void
    {
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $easyPayMethods = array_filter(
            $methods,
            fn (array $method): bool => str_starts_with((string) ($method['id'] ?? ''), 'nicepay_')
        );

        $this->assertCount(8, $easyPayMethods);

        foreach ($easyPayMethods as $method) {
            $this->assertArrayHasKey('defaults', $method);
            $this->assertNull($method['defaults']['pg_provider'] ?? null);
            $this->assertFalse($method['defaults']['is_active'] ?? true);
            $this->assertSame('payment_complete', $method['defaults']['stock_deduction_timing'] ?? null);
        }
    }

    public function test_easy_pay_method_labels_match_admin_payment_method_names(): void
    {
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $naverpay = collect($methods)->firstWhere('id', 'nicepay_naverpay');
        $kakaopay = collect($methods)->firstWhere('id', 'nicepay_kakaopay');
        $payco = collect($methods)->firstWhere('id', 'nicepay_payco');

        $this->assertSame('네이' . "\u{200B}" . '버페이 (나이스페이먼츠)', $naverpay['name']['ko'] ?? null);
        $this->assertSame('카카' . "\u{200B}" . '오페이로 결제 (나이스페이먼츠)', $kakaopay['description']['ko'] ?? null);
        $this->assertSame('PAYCO (NicePayments)', $payco['name']['en'] ?? null);
    }

    public function test_easy_pay_method_labels_avoid_other_pg_brand_matchers(): void
    {
        $listener = new RegisterEasyPayMethodsListener();

        $methods = $listener->injectEasyPayMethods([
            ['id' => 'phone'],
            ['id' => 'point'],
        ]);

        $guardedTexts = [];
        foreach (['nicepay_naverpay', 'nicepay_kakaopay', 'nicepay_samsungpay', 'nicepay_lpay'] as $methodId) {
            $method = collect($methods)->firstWhere('id', $methodId);

            $guardedTexts[] = $method['name']['ko'] ?? '';
            $guardedTexts[] = $method['description']['ko'] ?? '';
            $guardedTexts[] = $method['name']['en'] ?? '';
            $guardedTexts[] = $method['description']['en'] ?? '';
        }

        $joined = implode(' ', $guardedTexts);

        $this->assertStringContainsString("\u{200B}", $joined);
        $this->assertStringNotContainsString('네이버페이', $joined);
        $this->assertStringNotContainsString('카카오페이', $joined);
        $this->assertStringNotContainsString('삼성페이', $joined);
        $this->assertStringNotContainsString('Naver Pay', $joined);
        $this->assertStringNotContainsString('Kakao Pay', $joined);
        $this->assertStringNotContainsString('Samsung Pay', $joined);
        $this->assertStringNotContainsString('L.pay', $joined);
    }
}
