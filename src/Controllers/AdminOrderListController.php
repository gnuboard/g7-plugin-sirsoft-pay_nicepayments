<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminOrderListController extends AdminBaseController
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * 나이스페이먼츠 테스트 모드 주문 맵 반환
     *
     * 최근 6개월 이내 nicepayments 결제 주문 중 payment_meta.is_test_mode = true 인
     * 주문번호를 { "order_number": true, ... } 맵으로 반환한다. 어드민 주문 목록에
     * 테스트 결제 배지 표시용으로 사용된다.
     *
     * @return JsonResponse 테스트 모드 주문 맵
     */
    public function testModeMap(): JsonResponse
    {
        $rows = DB::table('ecommerce_orders as o')
            ->join('ecommerce_order_payments as p', 'p.order_id', '=', 'o.id')
            ->whereIn('p.pg_provider', ['nicepayments', 'nicepay'])
            ->whereNotNull('p.payment_meta')
            ->where('p.created_at', '>=', now()->subMonths(6))
            ->select(['o.order_number', 'p.payment_meta'])
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $meta = json_decode($row->payment_meta, true) ?? [];
            if ($meta['is_test_mode'] ?? false) {
                $map[$row->order_number] = true;
            }
        }

        return ResponseHelper::success('messages.success', $map);
    }
}
