# Changelog

이 프로젝트의 모든 주요 변경사항을 기록합니다.
형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따르며,
[Semantic Versioning](https://semver.org/lang/ko/)을 준수합니다.

## [Unreleased]

### Changed

- 플러그인 식별자(identifier)를 `sirsoft-pay-nicepayments`에서 `sirsoft-pay_nicepayments`로 변경 — G7 코어가 권장하는 `vendor-name` 2-part 명명 규칙에 맞추기 위함 (네임스페이스 `Plugins\Sirsoft\Pay\Nicepayments` → `Plugins\Sirsoft\PayNicepayments`)
- 백엔드 다국어 디렉토리(`lang/ko/messages.php`, `lang/en/messages.php`) 도입 — 사용자 노출 에러/환불 메시지를 모두 키화하여 한국어/영어 환경 모두 지원

### Added

- 사용자 노출 에러 메시지 다국어 키 신설: `errors.tid_required`, `errors.order_not_found`, `errors.invalid_request`, `errors.invalid_amount`, `errors.vbank_refund_required_fields`, `errors.vbank_completed_requires_bank_info`, `errors.invalid_refund_amount`
- 환불 메시지 다국어 키 신설: `refund.missing_tid`, `refund.default_reason`, `defaults.vbank_refund_msg`

## [1.0.0-beta.1] - 2026-04-22

### Added

- 오픈 베타 릴리즈
