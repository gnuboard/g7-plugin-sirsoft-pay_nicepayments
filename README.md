# NicePayments Plugin for G7

나이스페이먼츠(NicePayments) PG 연동 플러그인입니다. G7 플랫폼의 sirsoft-ecommerce 모듈과 함께 동작합니다.

## 지원 결제 수단

| 결제 수단 | PayMethod |
|-----------|-----------|
| 신용카드 | CARD |
| 가상계좌 | VBANK |
| 계좌이체 | BANK |
| 휴대폰결제 | CELLPHONE |

## 설치

```bash
# 플러그인 디렉토리에 배치 후
composer install
npm install && npm run build
```

## 설정

관리자 → 플러그인 → NicePayments 설정에서 구성합니다.

| 항목 | 설명 |
|------|------|
| 테스트 모드 | 활성화 시 나이스페이 공용 테스트 MID 사용 (실결제 없음) |
| 테스트 MID | 테스트 가맹점 ID (`nicepay00m` 기본값) |
| 테스트 가맹점 키 | 나이스페이 공용 테스트 키 |
| 라이브 MID | 실서비스 가맹점 ID |
| 라이브 가맹점 키 | 실서비스 가맹점 키 (외부 노출 금지) |
| 결제 성공 URL | 결제 완료 후 리다이렉트 경로 (`{orderId}` 치환 지원) |
| 결제 실패 URL | 결제 실패 후 리다이렉트 경로 |

## 웹훅 (가상계좌 입금 통보)

나이스페이먼츠 관리자에서 가상계좌 입금 통보 URL을 아래로 설정하세요:

```
https://your-domain.com/plugins/sirsoft-pay_nicepayments/payment/vbank-notify
```

### IP 화이트리스트

나이스페이먼츠 서버 IP만 허용됩니다. 로컬/테스트 환경에서는 자동으로 우회됩니다.

| IP |
|----|
| 121.133.126.10 |
| 121.133.126.11 |
| 211.33.136.39 |

## 결제 흐름

```
브라우저  →  goPay(form)  →  나이스페이 결제창
결제창    →  POST /payment/callback  →  authCallback()
서버      →  POST NextAppURL  →  승인 API 호출
승인 완료 →  completePayment()  →  성공 페이지 리다이렉트
```

## 가용 훅 (Hook)

다른 플러그인이나 리스너에서 아래 훅에 연결할 수 있습니다.

### 액션 훅

| 훅 이름 | 시점 | 인수 |
|---------|------|------|
| `sirsoft-pay_nicepayments.payment.before_authorize` | 서버 승인 API 호출 직전 | `Order $order, array $pgParams` |
| `sirsoft-pay_nicepayments.payment.after_authorize` | 서버 승인 API 응답 직후 | `Order $order, array $pgResponse` |
| `sirsoft-pay_nicepayments.payment.refund_failed` | 환불 API 호출 실패 시 | `Order $order, OrderPayment $payment, array $context` |

#### `refund_failed` context 구조

```php
[
    'tid'        => string,  // 나이스페이 거래번호
    'cancel_amt' => int,     // 환불 시도 금액 (원)
    'error'      => string,  // 오류 메시지
]
```

### 훅 등록 예시

```php
use App\Extension\HookManager;

HookManager::addAction(
    'sirsoft-pay_nicepayments.payment.refund_failed',
    function (Order $order, OrderPayment $payment, array $context) {
        // 예: Slack 알림 발송
        SlackNotifier::send("환불 실패: 주문 #{$order->order_number}, 오류: {$context['error']}");
    },
    priority: 10
);
```

## API 단건 조회

`NicePaymentsApiService::queryTransaction(string $tid): array` 메서드로 거래 상태를 조회할 수 있습니다.

```php
$apiService = app(\Plugins\Sirsoft\PayNicepayments\Services\NicePaymentsApiService::class);
$result = $apiService->queryTransaction('NICE_TID_12345');
// $result['ResultCode'], $result['Amt'], ...
```

## 과세 처리

결제 요청 시 주문의 `total_tax_amount`, `total_vat_amount`, `total_tax_free_amount` 값을 자동으로 나이스페이 폼에 포함합니다. 세 값이 모두 0이면 과세 필드를 생략합니다.

## 테스트 실행

```bash
cd c:/g7
php artisan test --filter=Nicepayments
```

## 라이선스

MIT
