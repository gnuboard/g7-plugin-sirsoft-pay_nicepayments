<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

trait RecordsPaymentWindowClosure
{
    protected function expectedPaymentPrice(Order $order): int
    {
        // 결제 청구액 SSoT = 결제 통화(order_currency) 환산액. base(total_due_amount) 직접 비교 시
        // base≠결제 통화에서 PG 청구 통화와 단위가 어긋난다(buildPgPaymentData 와 동일 기준).
        return app(CurrencyConversionService::class)->resolveOrderPaymentChargeAmount($order);
    }

    protected function requestMatchesOrderBuyer(Request $request, Order $order): bool
    {
        /** @var OrderAddress|null $address */
        $address = $order->shippingAddress;
        if (! $address) {
            return true;
        }

        $expectedEmail = strtolower(trim((string) $address->orderer_email));
        if ($expectedEmail !== '') {
            $receivedEmail = strtolower(trim((string) $request->input('buyer_email', '')));
            if ($receivedEmail === '' || $receivedEmail !== $expectedEmail) {
                return false;
            }
        }

        $expectedPhone = $this->digitsOnly((string) $address->orderer_phone);
        if ($expectedPhone !== '') {
            $receivedPhone = $this->digitsOnly((string) $request->input('buyer_phone', ''));
            if ($receivedPhone === '' || $receivedPhone !== $expectedPhone) {
                return false;
            }
        }

        return true;
    }

    protected function markPaymentWindowClosed(
        OrderProcessingService $orderService,
        Order $order,
        string $failureCode,
        string $failureMessage,
        ?string $cancelMessage = null,
    ): Order {
        if (! $order->order_status->isBeforePayment()) {
            return $order;
        }

        $failedOrder = $orderService->failPayment($order, $failureCode, $failureMessage);

        return $this->markNicePaymentFailed($failedOrder, $failureCode, $failureMessage);
    }

    protected function markNicePaymentFailed(Order $order, string $failureCode, string $failureMessage): Order
    {
        $payment = $order->payment()->first();
        if (! $payment) {
            return $order;
        }

        $payment->update([
            'payment_status' => PaymentStatusEnum::FAILED->value,
            'payment_meta' => array_merge($payment->payment_meta ?? [], [
                'failure_code' => $failureCode,
                'failure_message' => $failureMessage,
                'failed_at' => now()->toIso8601String(),
                'failure_source' => 'nicepayments',
            ]),
        ]);

        return $order->fresh();
    }

    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }
}
