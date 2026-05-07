<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

class PaymentRefundListener implements HookListenerInterface
{
    private const PG_PROVIDER_ID = 'nicepayments';

    public static function getSubscribedHooks(): array
    {
        return [
            'sirsoft-ecommerce.payment.refund' => [
                'method' => 'processRefund',
                'type' => 'filter',
                'priority' => 10,
            ],
        ];
    }

    public function handle(...$args): void {}

    public function processRefund(
        array $result,
        Order $order,
        OrderPayment $payment,
        float $refundAmount,
        ?string $reason = null,
    ): array {
        if ($payment->pg_provider !== self::PG_PROVIDER_ID) {
            return $result;
        }

        // 가상계좌 입금 완료 건은 환불 계좌 정보가 필요하므로 일반 훅으로 처리 불가
        // payment_status 는 훅 호출 전 updatePayment() 에서 이미 CANCELLED 로 변경되므로
        // 입금 여부 판별에는 paid_at (입금 확인 시 설정) 을 사용한다
        if ($payment->vbank_number !== null
            && $payment->paid_at !== null) {
            return [
                'success' => false,
                'error_code' => 'VBANK_REQUIRES_BANK_INFO',
                'error_message' => __('sirsoft-pay_nicepayments::messages.errors.vbank_completed_requires_bank_info'),
                'transaction_id' => null,
            ];
        }

        $tid = $payment->transaction_id;
        if (! $tid) {
            return [
                'success' => false,
                'error_code' => 'MISSING_TID',
                'error_message' => __('sirsoft-pay_nicepayments::messages.refund.missing_tid'),
                'transaction_id' => null,
            ];
        }

        try {
            $apiService = app(NicePaymentsApiService::class);

            $cancelMsg = $reason ?? __('sirsoft-pay_nicepayments::messages.refund.default_reason');
            $cancelAmt = (int) $refundAmount;

            if ($cancelAmt <= 0) {
                return [
                    'success' => false,
                    'error_code' => 'INVALID_REFUND_AMOUNT',
                    'error_message' => __('sirsoft-pay_nicepayments::messages.errors.invalid_refund_amount', ['amount' => $cancelAmt]),
                    'transaction_id' => null,
                ];
            }

            $moid = (string) $order->order_number;

            // 코어가 cancelled_amount를 훅 호출 전에 먼저 업데이트하므로
            // getCancellableAmount() > 0이면 부분취소, 0이면 전액취소
            $isPartial = $payment->getCancellableAmount() > 0;
            $response = $apiService->cancelPayment($tid, $moid, $cancelAmt, $cancelMsg, $isPartial ? 1 : 0);

            Log::info('NicePayments: refund success', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => $cancelAmt,
            ]);

            return [
                'success' => true,
                'error_code' => null,
                'error_message' => null,
                'transaction_id' => $response['TID'] ?? $tid,
            ];
        } catch (\Exception $e) {
            Log::error('NicePayments: refund failed', [
                'order_id' => $order->id,
                'tid' => $tid,
                'cancel_amt' => (int) $refundAmount,
                'error' => $e->getMessage(),
            ]);

            HookManager::doAction('sirsoft-pay_nicepayments.payment.refund_failed', $order, $payment, [
                'tid' => $tid,
                'cancel_amt' => (int) $refundAmount,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error_code' => 'PG_API_ERROR',
                'error_message' => $e->getMessage(),
                'transaction_id' => null,
            ];
        }
    }
}
