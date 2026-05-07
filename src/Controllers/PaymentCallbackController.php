<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayNicepayments\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\PayNicepayments\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService;
use Plugins\Sirsoft\PayNicepayments\Support\UrlHelper;

/**
 * 나이스페이먼츠 결제 콜백 컨트롤러
 *
 * 나이스페이먼츠 결제는 2단계 인증 방식입니다:
 *  1단계: 브라우저가 POST 콜백으로 인증 토큰 전달 → authCallback()
 *  2단계: 서버가 NextAppURL로 최종 승인 요청 → NicePaymentsApiService::authorizePayment()
 */
class PaymentCallbackController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay_nicepayments';

    /** 성공 결제 방법 ResultCode 목록 */
    private const SUCCESS_RESULT_CODES = ['3001', '4000', '4100', 'A000', '7001'];

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly NicePaymentsApiService $apiService,
    ) {}

    /**
     * 나이스페이먼츠 결제 승인 콜백
     *
     * POST /plugins/sirsoft-pay_nicepayments/payment/callback
     * (CSRF 제외 - 나이스페이먼츠가 브라우저 통해 POST 전달)
     */
    public function authCallback(AuthCallbackRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $authResultCode = $validated['AuthResultCode'];
        $authResultMsg = $validated['AuthResultMsg'] ?? '';
        $moid = $validated['Moid'] ?? '';

        // 1단계: 인증 결과 코드 확인 (실패/취소 케이스는 여기서 종료)
        if ($authResultCode !== '0000') {
            // 사용자가 결제창에서 '종료' 를 눌러 취소한 경우와 실제 결제 실패를 구분.
            // NicePay 의 사용자 취소 표준 코드는 '9999' 이며, 일부 PG 가 다른 코드 + 메시지로
            // 보내는 경우도 있어 메시지에 '사용자' 또는 '취소' 가 포함되면 같이 cancellation 으로 처리.
            $isUserCancelled = $authResultCode === '9999'
                || str_contains($authResultMsg, '사용자')
                || str_contains($authResultMsg, '취소');

            Log::info('NicePayments: auth ' . ($isUserCancelled ? 'cancelled by user' : 'failed'), [
                'moid' => $moid,
                'auth_result_code' => $authResultCode,
                'auth_result_msg' => $authResultMsg,
                'ip' => $request->ip(),
            ]);

            if ($isUserCancelled) {
                // 사용자 취소: 에러 query 없이 체크아웃으로 깨끗하게 복귀.
                // (NicePay 다이얼로그에서 이미 사용자가 취소를 선택했으므로 추가 toast 불필요)
                return redirect($this->resolveFailUrl([]));
            }

            return redirect($this->resolveFailUrl([
                'error' => 'auth_failed',
                'orderId' => $moid,
            ]));
        }

        // 인증 성공 케이스 — 추가 필드 추출
        $nextAppUrl = $validated['NextAppURL'];
        $txTid = $validated['TxTid'];
        $authToken = $validated['AuthToken'];
        $mid = $validated['MID'];
        $amt = (int) $validated['Amt'];
        $netCancelUrl = $validated['NetCancelURL'];
        $signature = $validated['Signature'];

        // 2단계: MID 일치 확인
        if ($mid !== $this->apiService->getMid()) {
            Log::error('NicePayments: MID mismatch', [
                'received_mid' => $mid,
                'config_mid' => $this->apiService->getMid(),
                'moid' => $moid,
                'ip' => $request->ip(),
            ]);

            return redirect($this->resolveFailUrl(['error' => 'mid_mismatch', 'orderId' => $moid]));
        }

        // 3단계: 서명 검증
        if (! $this->apiService->verifyCallbackSignature($authToken, $mid, $amt, $signature)) {
            Log::error('NicePayments: signature verification failed', ['moid' => $moid, 'ip' => $request->ip()]);

            return redirect($this->resolveFailUrl(['error' => 'signature_mismatch', 'orderId' => $moid]));
        }

        try {
            // 주문 조회
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('NicePayments: order not found', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $moid]));
            }

            HookManager::doAction('sirsoft-pay_nicepayments.payment.before_authorize', $order, $validated);

            // 4단계: 서버 승인 API 호출
            $pgResponse = $this->apiService->authorizePayment($nextAppUrl, $txTid, $authToken, $amt);

            HookManager::doAction('sirsoft-pay_nicepayments.payment.after_authorize', $order, $pgResponse);

            $resultCode = $pgResponse['ResultCode'] ?? '';

            if (! in_array($resultCode, self::SUCCESS_RESULT_CODES, true)) {
                Log::warning('NicePayments: authorize failed', [
                    'moid' => $moid,
                    'result_code' => $resultCode,
                    'result_msg' => $pgResponse['ResultMsg'] ?? '',
                ]);

                $this->orderService->failPayment($order, $resultCode, $pgResponse['ResultMsg'] ?? '');

                return redirect($this->resolveFailUrl([
                    'error' => 'authorize_failed',
                    'orderId' => $moid,
                ]));
            }

            // 5단계: 결제 수단별 처리
            $payMethod = $pgResponse['PayMethod'] ?? '';
            $isEscrow = ($pgResponse['EscrowYN'] ?? 'N') === 'Y'
                || $this->apiService->isEscrowEnabled();

            if ($payMethod === 'VBANK') {
                // 가상계좌: 계좌 발급 완료 → 입금 대기 상태로 전환
                // 실제 결제 완료(PAYMENT_COMPLETE)는 입금 후 vbankNotify()에서 처리
                $vbankDueAt = null;
                if (isset($pgResponse['VbankExpDate'])) {
                    $dateStr = $pgResponse['VbankExpDate'] . ($pgResponse['VbankExpTime'] ?? '235959');
                    $vbankDueAt = \Carbon\Carbon::createFromFormat('YmdHis', $dateStr);
                }

                $payment = $order->payment;
                if ($payment) {
                    $payment->payment_status = PaymentStatusEnum::WAITING_DEPOSIT;
                    $payment->pg_provider = 'nicepayments';
                    $payment->vbank_name = $pgResponse['VbankBankName'] ?? null;
                    $payment->vbank_number = $pgResponse['VbankNum'] ?? null;
                    $payment->vbank_due_at = $vbankDueAt;
                    $payment->vbank_issued_at = now();
                    $payment->is_escrow = $isEscrow;
                    $payment->payment_meta = [
                        'result_code' => $resultCode,
                        'pay_method' => $payMethod,
                        'auth_date' => $pgResponse['AuthDate'] ?? null,
                        'vbank_tid' => $pgResponse['TID'] ?? $txTid,
                        'vbank_num' => $pgResponse['VbankNum'] ?? null,
                        'vbank_name' => $pgResponse['VbankBankName'] ?? null,
                        'vbank_exp_date' => isset($pgResponse['VbankExpDate'])
                            ? $pgResponse['VbankExpDate'] . ($pgResponse['VbankExpTime'] ?? '235959')
                            : null,
                        'is_test_mode' => $this->apiService->isTestMode(),
                        'pg_raw_response' => $this->sanitizePgResponse($pgResponse),
                    ];
                    $payment->save();
                }

                Log::info('NicePayments: vbank account issued', [
                    'moid' => $moid,
                    'vbank_name' => $pgResponse['VbankBankName'] ?? null,
                    'vbank_number' => $pgResponse['VbankNum'] ?? null,
                ]);
            } else {
                // 신용카드/기타: 즉시 결제 완료 처리
                $this->orderService->completePayment($order, [
                    'transaction_id' => $pgResponse['TID'] ?? $txTid,
                    'card_approval_number' => $pgResponse['AppNo'] ?? null,
                    'card_number_masked' => $pgResponse['CardNum'] ?? null,
                    'card_name' => $pgResponse['IssuCardName'] ?? $pgResponse['CardName'] ?? null,
                    'card_installment_months' => (int) ($pgResponse['CardQuota'] ?? 0),
                    'is_interest_free' => false,
                    'embedded_pg_provider' => null,
                    'receipt_url' => 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=' . rawurlencode($pgResponse['TID'] ?? $txTid),
                    'payment_meta' => [
                        'result_code' => $resultCode,
                        'pay_method' => $payMethod,
                        'auth_date' => $pgResponse['AuthDate'] ?? null,
                        'is_test_mode' => $this->apiService->isTestMode(),
                        'rcpt_tid' => $pgResponse['RcptTID'] ?? null,
                        'pg_raw_response' => $this->sanitizePgResponse($pgResponse),
                    ],
                    'payment_device' => $this->detectDevice($request),
                ], $amt);

                // pg_provider 및 is_escrow 명시 업데이트
                // completePayment()가 이 두 필드를 지원하지 않으므로 별도 처리.
                // easy pay(nicepay_*)의 경우 fetch 인터셉터가 payment_method를 'card'로 교체해
                // 주문 생성 시 pg_provider=null이 될 수 있으므로 반드시 여기서 덮어쓴다.
                $order->refresh();
                $pgUpdates = ['pg_provider' => 'nicepayments'];
                if ($isEscrow) {
                    $pgUpdates['is_escrow'] = true;
                }
                $order->payment?->update($pgUpdates);
            }

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('NicePayments: amount mismatch', [
                'moid' => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (\Exception $e) {
            Log::error('NicePayments: authorize exception', [
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $txTid, $authToken, $amt);

            return redirect($this->resolveFailUrl([
                'error' => 'authorize_failed',
                'orderId' => $moid,
            ]));
        }
    }

    /**
     * 결제 요청 SignData 생성
     *
     * POST /plugins/sirsoft-pay_nicepayments/payment/sign-data
     */
    public function signData(Request $request): JsonResponse
    {
        $amt = (int) $request->input('amt', 0);
        $moid = (string) $request->input('moid', '');

        if ($amt <= 0 || $moid === '') {
            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_request')], 400);
        }

        // 주문 금액 검증: 클라이언트가 임의 금액으로 SignData를 요청하는 조작 방지
        $order = $this->orderService->findByOrderNumber($moid);

        if (! $order) {
            Log::warning('NicePayments: SignData - order not found', [
                'moid' => $moid,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.order_not_found')], 422);
        }

        if ((int) $order->total_amount !== $amt) {
            Log::warning('NicePayments: SignData amount mismatch', [
                'moid' => $moid,
                'requested_amt' => $amt,
                'actual_amt' => $order->total_amount,
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => __('sirsoft-pay_nicepayments::messages.errors.invalid_amount')], 422);
        }

        $ediDate = $this->apiService->generateEdiDate();
        $signData = $this->apiService->generateSignData($ediDate, $amt);

        return response()->json([
            'ediDate' => $ediDate,
            'signData' => $signData,
            'mid' => $this->apiService->getMid(),
        ]);
    }

    /**
     * 가상계좌 입금 통보 처리
     *
     * POST /plugins/sirsoft-pay_nicepayments/payment/vbank-notify
     * 공식 매뉴얼: https://developers.nicepay.co.kr/manual-noti.php
     *
     * 동작:
     *   - ResultCode === '4110' 입금완료 통보만 결제완료 처리
     *   - 그 외 ResultCode (계좌발급, 입금취소 등) 는 로깅만 하고 OK 응답
     *   - 어떤 결과든 200 + 정확히 "OK" (text/plain) 를 돌려줘야 NicePay 가 재시도하지 않음
     *   - 한글 인코딩(EUC-KR) 은 VbankNotifyRequest::prepareForValidation 에서 UTF-8 로 변환됨
     *
     * 공식 spec 에 입금통보 Signature 가 없어 위변조 검증은 하지 않으며, 대신:
     *   - 발송 IP 화이트리스트 (VbankNotifyRequest::authorize)
     *   - TID/MOID/Amt 가 우리 DB 의 임시 발급 정보와 일치하는지 비교
     *   - 동일 TID 중복 처리 방지 (행 잠금)
     *   세 단계로 위변조/재처리 방어.
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $tid = (string) $validated['TID'];
        $moid = (string) $validated['MOID'];
        $amt = (int) $validated['Amt'];
        $resultCode = (string) $validated['ResultCode'];

        // 입금완료 통보 (4110) 만 결제완료 처리.
        // 4100/계좌발급은 authCallback 에서 이미 처리됐고, 그 외 코드는 입금취소/오류 등.
        $isDeposited = $resultCode === '4110';
        $isCancellation = ! empty($validated['CancelDate'])
            || in_array((string) ($validated['StateCd'] ?? ''), ['1', '2'], true);

        // 통보 종류 라벨 (어드민/로그용)
        $notiType = $isDeposited ? 'deposited' : ($isCancellation ? 'cancelled' : 'other');

        if (! $isDeposited) {
            Log::info(
                'NicePayments: vbank notify ' . ($isCancellation ? 'cancellation' : 'non-deposit'),
                [
                    'tid' => $tid,
                    'moid' => $moid,
                    'result_code' => $resultCode,
                    'state_cd' => $validated['StateCd'] ?? null,
                    'cancel_date' => $validated['CancelDate'] ?? null,
                    'result_msg' => $validated['ResultMsg'] ?? null,
                ]
            );

            // 입금완료가 아니어도 어드민이 통보 시점/내용을 확인할 수 있도록 이력 저장.
            // 4100(계좌발급)·취소·재통보 모두 누적되어 어드민 패널에서 timeline 으로 보임.
            $this->recordVbankNotification($moid, $tid, $amt, $resultCode, $notiType, $validated);

            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('NicePayments: vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $alreadyProcessed = false;

            DB::transaction(function () use ($order, $tid, $amt, $validated, &$alreadyProcessed): void {
                // 동시 입금 통보 중복 처리 방지: 행 단위 잠금
                $payment = $order->payment()->lockForUpdate()->first();

                if ($payment && $payment->transaction_id === $tid) {
                    $alreadyProcessed = true;

                    return;
                }

                // 기존 통보 이력 보존 — completePayment 가 payment_meta 를 통째로 교체할 수 있어
                // 미리 머지한 메타를 만들어 전달
                $existingNotifications = is_array($payment?->payment_meta['vbank_notifications'] ?? null)
                    ? $payment->payment_meta['vbank_notifications']
                    : [];

                $newEntry = $this->buildVbankNotificationEntry('4110', $amt, 'deposited', $validated);
                $allNotifications = array_merge($existingNotifications, [$newEntry]);

                $this->orderService->completePayment($order, [
                    'transaction_id' => $tid,
                    'receipt_url' => 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=' . rawurlencode($tid),
                    'payment_meta' => [
                        'result_code' => '4110',
                        'auth_date' => $validated['AuthDate'] ?? null,
                        'auth_code' => $validated['AuthCode'] ?? null,
                        'vbank_num' => $validated['VbankNum'] ?? null,
                        'vbank_name' => $validated['VbankName'] ?? null,
                        'vbank_input_name' => $validated['VbankInputName'] ?? null,
                        'fn_cd' => $validated['FnCd'] ?? null,
                        'fn_name' => $validated['FnName'] ?? null,
                        'is_test_mode' => $this->apiService->isTestMode(),
                        'rcpt_tid' => $validated['RcptTID'] ?? null,
                        'pg_raw_response' => $this->sanitizePgResponse($validated),
                        // 어드민 표시용 통보 이력 (전체 누적)
                        'vbank_notifications' => $allNotifications,
                        'vbank_notification_summary' => $this->buildNotificationSummary($allNotifications),
                    ],
                ], $amt);

                // pg_provider 명시 설정 (completePayment가 이 필드를 업데이트하지 않을 수 있음)
                $order->refresh();
                $order->payment?->update(['pg_provider' => 'nicepayments']);
            });

            if ($alreadyProcessed) {
                Log::info('NicePayments: vbank notify - already processed', ['tid' => $tid, 'moid' => $moid]);
            } else {
                Log::info('NicePayments: vbank deposit confirmed', [
                    'tid' => $tid,
                    'moid' => $moid,
                    'amt' => $amt,
                    'depositor' => $validated['VbankInputName'] ?? null,
                    'auth_date' => $validated['AuthDate'] ?? null,
                ]);
            }

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('NicePayments: vbank notify failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        }
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';
        $url = str_replace('{orderId}', $orderId, $urlTemplate);

        return UrlHelper::toAbsolute($url);
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';

        if (! empty($queryParams)) {
            $query = http_build_query(array_filter($queryParams));
            $separator = str_contains($baseUrl, '?') ? '&' : '?';
            $baseUrl = $baseUrl . $separator . $query;
        }

        return UrlHelper::toAbsolute($baseUrl);
    }

    /** PG 응답에서 개인정보(PII) 필드 제거 후 반환 */
    private function sanitizePgResponse(array $response): array
    {
        $piiFields = ['BuyerName', 'BuyerEmail', 'BuyerTel', 'CardNum'];

        return array_diff_key($response, array_flip($piiFields));
    }

    /**
     * 입금통보 1건의 어드민 표시용 entry 생성.
     *
     * 어드민 패널에서 timeline 형태로 보여줄 핵심 필드만 추려둠. raw 는 전체 보존.
     */
    private function buildVbankNotificationEntry(
        string $resultCode,
        int $amt,
        string $type,
        array $validated
    ): array {
        return [
            'received_at' => now()->toIso8601String(),
            'type' => $type, // 'deposited' | 'cancelled' | 'other'
            'result_code' => $resultCode,
            'result_msg' => $validated['ResultMsg'] ?? null,
            'state_cd' => $validated['StateCd'] ?? null,
            'amt' => $amt,
            'tid' => $validated['TID'] ?? null,
            'auth_date' => $validated['AuthDate'] ?? null,
            'auth_code' => $validated['AuthCode'] ?? null,
            'depositor' => $validated['VbankInputName'] ?? null,
            'vbank_num' => $validated['VbankNum'] ?? null,
            'vbank_name' => $validated['VbankName'] ?? null,
            'cancel_date' => $validated['CancelDate'] ?? null,
            'raw' => $this->sanitizePgResponse($validated),
        ];
    }

    /**
     * 입금완료가 아닌 통보 (계좌발급/취소/오류/재통보) 를 payment_meta 에 누적.
     *
     * 어드민이 "언제 어떤 통보가 왔는지" 추적할 수 있도록 모든 이벤트를 기록.
     * 주문/결제가 없으면 조용히 skip (위변조 방어 — 우리 DB 에 없는 주문은 무시).
     */
    private function recordVbankNotification(
        string $moid,
        string $tid,
        int $amt,
        string $resultCode,
        string $type,
        array $validated
    ): void {
        try {
            DB::transaction(function () use ($moid, $tid, $amt, $resultCode, $type, $validated): void {
                $order = $this->orderService->findByOrderNumber($moid);
                if (! $order) {
                    return;
                }

                $payment = $order->payment()->lockForUpdate()->first();
                if (! $payment) {
                    return;
                }

                $existing = is_array($payment->payment_meta['vbank_notifications'] ?? null)
                    ? $payment->payment_meta['vbank_notifications']
                    : [];

                $entry = $this->buildVbankNotificationEntry($resultCode, $amt, $type, $validated);
                $existing[] = $entry;

                $payment->payment_meta = array_merge($payment->payment_meta ?? [], [
                    'vbank_notifications' => $existing,
                    'vbank_notification_summary' => $this->buildNotificationSummary($existing),
                ]);
                $payment->save();
            });
        } catch (\Throwable $e) {
            // 통보 이력 기록 실패가 OK 응답 자체를 막아 NicePay 재시도를 유발하지 않도록 swallow.
            Log::warning('NicePayments: failed to record vbank notification entry', [
                'moid' => $moid,
                'tid' => $tid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 어드민 패널 한 줄 요약용 데이터.
     *
     * - first_received_at / last_received_at: 첫·마지막 통보 시각
     * - count: 누적 통보 횟수 (재통보 포함)
     * - last_type / last_result_code: 마지막 통보 종류
     * - deposited_at / cancelled_at: 입금완료·입금취소가 있었던 경우의 시각
     */
    private function buildNotificationSummary(array $notifications): array
    {
        if (empty($notifications)) {
            return [];
        }

        $first = $notifications[0];
        $last = end($notifications);
        reset($notifications);

        $depositedAt = null;
        $cancelledAt = null;
        foreach ($notifications as $n) {
            if (($n['type'] ?? '') === 'deposited' && $depositedAt === null) {
                $depositedAt = $n['received_at'] ?? null;
            }
            if (($n['type'] ?? '') === 'cancelled') {
                $cancelledAt = $n['received_at'] ?? null;
            }
        }

        return [
            'count' => count($notifications),
            'first_received_at' => $first['received_at'] ?? null,
            'last_received_at' => $last['received_at'] ?? null,
            'last_type' => $last['type'] ?? null,
            'last_result_code' => $last['result_code'] ?? null,
            'deposited_at' => $depositedAt,
            'cancelled_at' => $cancelledAt,
            'last_depositor' => $last['depositor'] ?? null,
            'last_amt' => $last['amt'] ?? null,
        ];
    }

    private function detectDevice(Request $request): string
    {
        $userAgent = $request->userAgent() ?? '';
        $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod'];

        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return 'mobile';
            }
        }

        return 'pc';
    }
}
