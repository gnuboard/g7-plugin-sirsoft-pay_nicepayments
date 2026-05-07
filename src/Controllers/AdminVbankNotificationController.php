<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;

/**
 * 어드민 — 가상계좌 입금통보 이력 조회 컨트롤러
 *
 * GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/vbank-notifications
 *
 * OrderPaymentResource 는 payment_meta 를 노출하지 않으므로 (PII 보호),
 * 어드민 전용으로 입금통보 이력만 따로 추출해 반환한다.
 *
 * 응답 shape:
 *   {
 *     "success": true,
 *     "data": {
 *       "notifications": [...],   // 시간순 통보 entry 배열
 *       "summary": {...}           // 한 줄 요약 (count/timestamps/depositor)
 *     }
 *   }
 *
 * 미들웨어: auth:sanctum + admin (routes/api.php 그룹에서 적용)
 */
class AdminVbankNotificationController
{
    public function __construct(
        private readonly OrderProcessingService $orderService
    ) {
    }

    public function show(string $orderNumber): JsonResponse
    {
        $order = $this->orderService->findByOrderNumber($orderNumber);

        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => __('sirsoft-pay_nicepayments::messages.errors.order_not_found'),
                'data' => ['notifications' => [], 'summary' => null],
            ], 404);
        }

        $payment = $order->payment;
        if (! $payment || $payment->payment_method?->value !== 'vbank') {
            return response()->json([
                'success' => true,
                'data' => ['notifications' => [], 'summary' => null],
            ]);
        }

        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        $notifications = is_array($meta['vbank_notifications'] ?? null) ? $meta['vbank_notifications'] : [];
        $summary = is_array($meta['vbank_notification_summary'] ?? null) ? $meta['vbank_notification_summary'] : null;

        return response()->json([
            'success' => true,
            'data' => [
                'notifications' => $notifications,
                'summary' => $summary,
            ],
        ]);
    }
}
