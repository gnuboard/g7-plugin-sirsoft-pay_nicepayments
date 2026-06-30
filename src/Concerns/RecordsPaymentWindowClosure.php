<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Concerns;

use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

trait RecordsPaymentWindowClosure
{
    protected function expectedPaymentPrice(Order $order): int
    {
        return (int) round((float) $order->total_due_amount);
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

        return $orderService->recordPaymentCancellation(
            $failedOrder,
            $failureCode,
            $cancelMessage ?: $failureMessage,
        );
    }

    protected function digitsOnly(string $value): string
    {
        return preg_replace('/[^0-9]/', '', $value) ?? '';
    }
}
