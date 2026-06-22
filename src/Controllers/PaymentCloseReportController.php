<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNicepayments\Concerns\RecordsPaymentWindowClosure;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\PaymentCloseReportRequest;

class PaymentCloseReportController
{
    use RecordsPaymentWindowClosure;

    private const FAILURE_CODE = 'USER_CANCEL';

    private const FAILURE_MESSAGE = '사용자가 나이스페이먼츠 결제창을 닫았습니다.';

    public function __construct(
        private readonly OrderProcessingService $orderService,
    ) {}

    /**
     * PC 결제창 닫힘 보고를 검증하고 결제 실패/취소 이력을 기록합니다.
     *
     * @param  PaymentCloseReportRequest  $request  결제창 닫힘 보고 요청
     * @return JsonResponse 닫힘 보고 처리 결과
     */
    public function store(PaymentCloseReportRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $oid = $validated['oid'];
        $price = (int) $validated['price'];

        $rateLimitKey = $this->rateLimitKey($request, $oid);
        if (RateLimiter::tooManyAttempts($rateLimitKey, 20)) {
            return ResponseHelper::error('messages.failed', 429, [
                'message' => ['Too many NicePayments payment close reports. Please try again later.'],
            ]);
        }
        RateLimiter::hit($rateLimitKey, 60);

        $order = $this->orderService->findByOrderNumber($oid);
        if (! $order) {
            return ResponseHelper::error('messages.failed', 404, [
                'message' => ['Order not found.'],
            ]);
        }

        if (! $this->requestMatchesOrderBuyer($request, $order)) {
            return ResponseHelper::error('messages.failed', 403, [
                'message' => ['Order buyer verification failed.'],
            ]);
        }

        if ($price !== $this->expectedPaymentPrice($order)) {
            return ResponseHelper::error('messages.failed', 422, [
                'message' => ['Payment amount does not match the order amount.'],
            ]);
        }

        if (! $order->order_status->isBeforePayment()) {
            return ResponseHelper::success('messages.success', [
                'status' => 'ignored',
                'reason' => 'order_not_payable',
            ]);
        }

        // 결제 성공 콜백과 결제창 닫힘 보고가 경쟁할 때, 카드 주문은 승인 직전까지
        // order_status=PENDING_ORDER 라 위 가드를 통과한다. payment_status 가 이미 PAID 면 결제가
        // 성공한 것이므로 실패 처리하지 않는다(failPayment 가 옵션을 취소로 덮어 주문/옵션 상태가
        // 어긋나는 race 차단). failPayment 자체에도 동일 가드가 있으나 여기서 차단해 불필요한
        // 결제취소 이력 기록까지 미연에 방지한다.
        if ($order->payment?->isPaid()) {
            return ResponseHelper::success('messages.success', [
                'status' => 'ignored',
                'reason' => 'payment_already_paid',
            ]);
        }

        $closeReason = trim((string) ($validated['reason'] ?? ''));
        $this->markPaymentWindowClosed(
            $this->orderService,
            $order,
            self::FAILURE_CODE,
            self::FAILURE_MESSAGE,
            $closeReason !== '' ? $closeReason : self::FAILURE_MESSAGE,
        );

        return ResponseHelper::success('messages.success', [
            'status' => 'recorded',
        ]);
    }

    private function rateLimitKey(PaymentCloseReportRequest $request, string $oid): string
    {
        return 'sirsoft-pay_nicepayments:payment-close-report:'.sha1($request->ip().'|'.$oid);
    }
}
