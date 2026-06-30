<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;

/**
 * 가상계좌 입금 완료 건 환불 처리
 *
 * 입금이 완료된 가상계좌는 일반 환불 훅으로는 처리할 수 없습니다.
 * 나이스페이먼츠 API가 환불받을 계좌 정보(계좌번호·은행코드·예금주)를 요구하기 때문입니다.
 * 이 컨트롤러는 해당 정보를 직접 수집하여 취소 API를 호출합니다.
 */
class AdminVbankRefundController extends AdminBaseController
{
    public function __construct(
        private readonly NicePaymentsApiService $apiService,
    ) {
        parent::__construct();
    }

    /**
     * POST /api/plugins/sirsoft-pay_nicepayments/admin/vbank-refund
     */
    /**
     * 가상계좌 입금 완료 건 환불 처리
     *
     * 일반 환불 훅으로 처리할 수 없는 vbank 입금 완료 건의 환불을 직접 수행.
     * NicePay 가 요구하는 환불 계좌 정보(계좌번호·은행코드·예금주)를 직접 수집.
     *
     * @param  Request  $request  TID/MOID/CancelAmt + 환불계좌 정보
     * @return JsonResponse 환불 결과 또는 422 (입력 누락)
     */
    public function refund(Request $request): JsonResponse
    {
        $tid = trim((string) $request->input('tid', ''));
        $moid = trim((string) $request->input('moid', ''));
        $cancelAmt = (int) $request->input('cancel_amt', 0);
        $cancelMsg = trim((string) $request->input('cancel_msg', __('sirsoft-pay_nicepayments::messages.defaults.vbank_refund_msg')));
        $refundAcctNo = trim((string) $request->input('refund_acct_no', ''));
        $refundBankCd = trim((string) $request->input('refund_bank_cd', ''));
        $refundAcctNm = trim((string) $request->input('refund_acct_nm', ''));

        if ($tid === '' || $moid === '' || $cancelAmt <= 0
            || $refundAcctNo === '' || $refundBankCd === '' || $refundAcctNm === '') {
            return ResponseHelper::error('messages.failed', 422, [
                'message' => __('sirsoft-pay_nicepayments::messages.errors.vbank_refund_required_fields'),
            ]);
        }

        try {
            $result = $this->apiService->cancelPayment(
                $tid,
                $moid,
                $cancelAmt,
                $cancelMsg,
                0,
                $refundAcctNo,
                $refundBankCd,
                $refundAcctNm,
            );

            Log::info('NicePayments: admin vbank refund success', [
                'tid' => $tid,
                'moid' => $moid,
                'cancel_amt' => $cancelAmt,
            ]);

            return ResponseHelper::success('messages.success', $result);
        } catch (\Exception $e) {
            Log::error('NicePayments: admin vbank refund failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            return ResponseHelper::error('messages.failed', 502, null);
        }
    }
}
