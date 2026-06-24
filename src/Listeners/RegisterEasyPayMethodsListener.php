<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;

/**
 * 나이스페이먼츠 간편결제를 이커머스 결제수단 목록에 등록한다.
 *
 * 각 결제수단 ID는 프론트 requestPaymentHandler 의 nicepay_* 매핑과 일치한다.
 */
class RegisterEasyPayMethodsListener implements HookListenerInterface
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';

    private const EASY_PAY_METHODS = [
        [
            'id' => 'nicepay_naverpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.naverpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.naverpay.description',
            'icon' => 'wallet',
        ],
        [
            'id' => 'nicepay_kakaopay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.kakaopay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.kakaopay.description',
            'icon' => 'message-circle',
        ],
        [
            'id' => 'nicepay_samsungpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.samsungpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.samsungpay.description',
            'icon' => 'smartphone',
        ],
        [
            'id' => 'nicepay_applepay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.applepay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.applepay.description',
            'icon' => 'smartphone',
        ],
        [
            'id' => 'nicepay_payco',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.payco.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.payco.description',
            'icon' => 'wallet',
        ],
        [
            'id' => 'nicepay_skpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.skpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.skpay.description',
            'icon' => 'wallet-cards',
        ],
        [
            'id' => 'nicepay_ssgpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.ssgpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.ssgpay.description',
            'icon' => 'shopping-bag',
        ],
        [
            'id' => 'nicepay_lpay',
            'name_key' => 'sirsoft-pay_nicepayments::payment_methods.lpay.name',
            'description_key' => 'sirsoft-pay_nicepayments::payment_methods.lpay.description',
            'icon' => 'wallet',
        ],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.settings.filter_available_payment_methods' => [
                'method' => 'injectEasyPayMethods',
                'type' => 'filter',
                'priority' => 40,
            ],
        ];
    }

    /**
     * @param mixed ...$args
     */
    public function handle(...$args): void {}

    /**
     * @param array<int, array<string, mixed>> $methods
     * @return array<int, array<string, mixed>>
     */
    public function injectEasyPayMethods(array $methods): array
    {
        $easyPayMethods = array_map(
            fn (array $method): array => $this->buildEntry(
                id: $method['id'],
                nameKey: $method['name_key'],
                descriptionKey: $method['description_key'],
                icon: $method['icon'],
            ),
            self::EASY_PAY_METHODS
        );

        $easyPayIds = array_column($easyPayMethods, 'id');
        $methods = array_values(array_filter(
            $methods,
            fn (array $method): bool => ! in_array($method['id'] ?? null, $easyPayIds, true)
        ));

        $insertAfter = $this->resolveInsertionIndex($methods);
        if ($insertAfter === null) {
            return array_merge($methods, $easyPayMethods);
        }

        return array_merge(
            array_slice($methods, 0, $insertAfter + 1),
            $easyPayMethods,
            array_slice($methods, $insertAfter + 1),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $methods
     */
    private function resolveInsertionIndex(array $methods): ?int
    {
        $phoneIndex = null;
        foreach ($methods as $index => $method) {
            if (($method['id'] ?? null) === 'phone') {
                $phoneIndex = $index;
                break;
            }
        }

        if ($phoneIndex === null) {
            return null;
        }

        $insertAfter = $phoneIndex;
        for ($index = $phoneIndex + 1, $count = count($methods); $index < $count; $index++) {
            $id = (string) ($methods[$index]['id'] ?? '');
            if (
                ! str_starts_with($id, 'kginicis_')
                && ! str_starts_with($id, 'nhnkcp_')
                && ! str_starts_with($id, 'nicepay_')
            ) {
                break;
            }
            $insertAfter = $index;
        }

        return $insertAfter;
    }

    private function buildEntry(string $id, string $nameKey, string $descriptionKey, string $icon): array
    {
        return [
            'id' => $id,
            'name' => [
                'ko' => __($nameKey, [], 'ko'),
                'en' => __($nameKey, [], 'en'),
            ],
            'description' => [
                'ko' => __($descriptionKey, [], 'ko'),
                'en' => __($descriptionKey, [], 'en'),
            ],
            'icon' => $icon,
            'source' => 'plugin:' . self::PLUGIN_IDENTIFIER,
            'defaults' => [
                'pg_provider' => null,
                'is_active' => false,
                'min_order_amount' => 0,
                'stock_deduction_timing' => 'payment_complete',
            ],
        ];
    }
}
