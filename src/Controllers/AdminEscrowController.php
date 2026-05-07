<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

class AdminEscrowController extends AdminBaseController
{
    public function __construct(
        private readonly NicePaymentsApiService $apiService,
    ) {
        parent::__construct();
    }

    /**
     * 주문의 에스크로 결제 목록 조회
     *
     * GET /api/plugins/sirsoft-pay_nicepayments/admin/orders/{orderNumber}/escrow-payments
     */
    public function getEscrowPayments(string $orderNumber): JsonResponse
    {
        $payments = DB::table('ecommerce_order_payments')
            ->join('ecommerce_orders', 'ecommerce_orders.id', '=', 'ecommerce_order_payments.order_id')
            ->where('ecommerce_orders.order_number', $orderNumber)
            ->where('ecommerce_order_payments.pg_provider', 'nicepayments')
            ->where('ecommerce_order_payments.is_escrow', 1)
            ->get(['ecommerce_order_payments.id', 'ecommerce_order_payments.transaction_id', 'ecommerce_order_payments.payment_method', 'ecommerce_order_payments.payment_status']);

        return ResponseHelper::success('messages.success', [
            'escrow_payments' => $payments->map(fn ($p) => [
                'id' => $p->id,
                'transaction_id' => $p->transaction_id,
                'payment_method' => $p->payment_method,
                'payment_status' => $p->payment_status,
            ])->values()->all(),
        ]);
    }

    /**
     * 에스크로 배송 등록
     *
     * POST /api/plugins/sirsoft-pay_nicepayments/admin/escrow/register-delivery
     */
    public function registerDelivery(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tid' => 'required|string',
            'delivery_name' => 'required|string|max:100',
            'tracking_number' => 'required|string|max:100',
            'buyer_address' => 'required|string|max:200',
            'register_name' => 'required|string|max:50',
        ]);

        try {
            $result = $this->apiService->registerEscrowDelivery(
                tid: $validated['tid'],
                deliveryName: $validated['delivery_name'],
                trackingNumber: $validated['tracking_number'],
                buyerAddress: $validated['buyer_address'],
                registerName: $validated['register_name'],
            );

            Log::info('NicePayments: escrow delivery registered', [
                'tid' => $validated['tid'],
                'delivery_name' => $validated['delivery_name'],
                'tracking_number' => $validated['tracking_number'],
            ]);

            return ResponseHelper::success('messages.success', $result);
        } catch (\Exception $e) {
            return ResponseHelper::error($e->getMessage(), 502, null);
        }
    }
}
